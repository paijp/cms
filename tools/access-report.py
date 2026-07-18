#!/usr/bin/env python3
"""
nginx access.log を読んで CMS のセッション別アクセスを1行にまとめて出力する。

セッション = 同じ IP + User-Agent の連続アクセスで、間隔が --gap-minutes 未満のもの。

出力例:
  2026-07-16T10:30:15+00:00  106.146.15.13  Chrome/117.0    top (5s) news (12s) news/1784208194 (30s) faq

使い方:
  ./tools/access-report.py /var/log/nginx/access.log
  cat /var/log/nginx/access.log | ./tools/access-report.py -
  ./tools/access-report.py --since 2026-07-16 --gap-minutes 30 /var/log/nginx/access.log
"""

import argparse
import re
import sys
from datetime import datetime, timedelta
from urllib.parse import parse_qs, urlparse

LOG_RE = re.compile(
    r'^(?P<ip>\S+) \S+ \S+ \[(?P<time>[^\]]+)\] '
    r'"(?P<method>\S+) (?P<path>\S+) HTTP/[\d.]+" '
    r'(?P<status>\d+) (?P<size>\S+) '
    r'"(?P<ref>[^"]*)" "(?P<ua>[^"]*)"'
)
TIME_FMT = '%d/%b/%Y:%H:%M:%S %z'

def short_ua(ua):
    """User-Agent から代表的なブラウザ名+メジャーバージョンを抜き出す"""
    m = re.search(r'(Edg|OPR|Chrome|Firefox|Safari)/([\d]+)', ua)
    if m:
        return f'{m.group(1)}/{m.group(2)}'
    if 'bot' in ua.lower() or 'spider' in ua.lower() or 'crawler' in ua.lower():
        m2 = re.search(r'([A-Za-z][\w\.-]*[Bb]ot)', ua)
        return m2.group(1) if m2 else 'bot'
    return (ua[:40] + '…') if len(ua) > 40 else ua

def page_label(path):
    """アクセスURLから可読ラベルを作る。追跡対象でなければ None"""
    u = urlparse(path)
    q = parse_qs(u.query)
    if u.path == '/':
        if 'topic' in q: return f"topic:{q['topic'][0]}"
        if 'id' in q:    return f"{q.get('g', ['?'])[0]}/{_short_id(q['id'][0])}"
        if 'link' in q:  return f"{q.get('g', ['?'])[0]}/link:{_short_id(q['link'][0])}"
        if 'g' in q:     return q['g'][0]
        return 'top'
    if u.path == '/api/articles.php':
        if 'site' in q:       return None   # SPA初期化ノイズ
        if 'diff' in q or 'permalink_check' in q or 'export' in q: return None
        if 'topic' in q:      return f"topic:{q['topic'][0]}"
        if 'id' in q:         return f"{_short_id(q['id'][0], with_genre=True)}"
        if 'link' in q:       return f"{q.get('target', ['?'])[0]}/link:{_short_id(q['link'][0])}"
        if 'genre' in q:      return q['genre'][0]
        return None
    return None

def _short_id(idstr, with_genre=False):
    """記事IDを短縮表示。例: news-1784208194383 -> news/208194"""
    m = re.match(r'^([a-zA-Z][a-zA-Z0-9_\-]*?)-(\d+)$', idstr)
    if m:
        genre, num = m.group(1), m.group(2)
        return f"{genre}/{num[-6:]}"
    return idstr

def parse(line):
    m = LOG_RE.match(line.strip())
    if not m: return None
    label = page_label(m['path'])
    if label is None: return None
    if m['method'] != 'GET' or not m['status'].startswith('2') and not m['status'].startswith('3'):
        return None
    try:
        ts = datetime.strptime(m['time'], TIME_FMT)
    except ValueError:
        return None
    return {'ts': ts, 'ip': m['ip'], 'ua': short_ua(m['ua']), 'label': label}

def group_sessions(events, gap):
    """events を (ip, ua) ごとに時間順に並べ、gap 以内で連続するものを1セッションにまとめる"""
    key = lambda e: (e['ip'], e['ua'])
    events.sort(key=lambda e: (e['ip'], e['ua'], e['ts']))
    session = []
    prev_key, prev_ts = None, None
    for e in events:
        k = key(e)
        if prev_key != k or (prev_ts and e['ts'] - prev_ts > gap):
            if session: yield session
            session = []
        session.append(e)
        prev_key, prev_ts = k, e['ts']
    if session: yield session

def format_line(session):
    parts = []
    prev_label, prev_ts = None, None
    for e in session:
        if e['label'] == prev_label:
            continue                  # 直前と同じページは重複を1つにまとめる
        if prev_ts is not None:
            secs = int((e['ts'] - prev_ts).total_seconds())
            parts[-1] += f' ({secs}s)'
        parts.append(e['label'])
        prev_label, prev_ts = e['label'], e['ts']
    start = session[0]['ts'].isoformat()
    return f"{start}  {session[0]['ip']:<15}  {session[0]['ua']:<15}  " + ' '.join(parts)

def main():
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument('logfile', nargs='?', default='/var/log/nginx/access.log',
                   help='nginx access.log (デフォルト: /var/log/nginx/access.log。- で標準入力)')
    p.add_argument('--gap-minutes', type=int, default=30,
                   help='この分数以上のギャップでセッションを区切る（デフォルト30分）')
    p.add_argument('--since', help='YYYY-MM-DD 以降のみ集計')
    p.add_argument('--min-hits', type=int, default=1, help='この件数未満のセッションは除外')
    args = p.parse_args()

    f = sys.stdin if args.logfile == '-' else open(args.logfile)
    since = datetime.strptime(args.since, '%Y-%m-%d') if args.since else None
    events = []
    for line in f:
        e = parse(line)
        if not e: continue
        if since and e['ts'].replace(tzinfo=None) < since: continue
        events.append(e)

    sessions = [s for s in group_sessions(events, timedelta(minutes=args.gap_minutes)) if len(s) >= args.min_hits]
    sessions.sort(key=lambda s: s[0]['ts'])
    for s in sessions:
        print(format_line(s))

if __name__ == '__main__':
    main()
