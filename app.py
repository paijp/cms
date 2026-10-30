#!/usr/bin/env python3
"""
CMS アプリ本体（Flask）

記事データは「下書き」(drafts_dir) と「公開版」(data_dir) の2面構成の JSON ファイル。
管理画面での保存・削除・並べ替えはすべて下書きに対して行い、「公開」操作で
下書きを公開版へ同期する。

サイト固有の設定は site.json（既定: リポジトリ隣接の data/site.json、
環境変数 CMS_CONFIG で変更可）に置く。書式は site.json.example を参照。

画面:
  GET  /                       公開ページ（?topic=<slug> は該当記事へ302）
  GET  /cms/                   管理画面（?token=<管理者トークン> でログイン）
API（/api/articles と /api/uploads。旧URLの .php 付きでも受け付ける）:
  GET    ?genre=news           記事一覧（先頭ブロックのみ。&full=1 で全ブロック）
  GET    ?id=news-001          記事詳細
  GET    ?link=<id>&target=<g> クロスリンクの詳細
  GET    ?topic=<slug>         固定リンクの記事
  GET    ?site=1               サイト情報
  GET    ?diff=1               下書きと公開版の差分 ※管理者
  GET    ?export=1             全記事JSONダウンロード ※管理者
  GET    ?permalink_check=<slug>&exclude=<id>        ※管理者
  GET    ?short_id_check=<n>&genre=<g>&exclude=<id>  ※管理者
  GET    ?access_log=1&days=<n>&gap=<min>            ※管理者
  GET    ?include_hidden=1     非表示記事も含める ※管理者
  POST                         記事作成・更新（下書きへ） ※管理者
  POST   ?publish=1            下書きを公開版へ反映 ※管理者
  POST   ?reorder=1            並び順を保存 body: {"ids": [...]} ※管理者
  POST   ?duplicate=1&id=X     記事を複製 ※管理者
  DELETE ?id=X                 記事削除（下書きから） ※管理者
  閲覧系は管理者ログイン中なら下書き版、未ログインなら公開版を返す。
"""

import difflib
import glob
import hashlib
import hmac
import json
import os
import re
import secrets
import threading
import time
import urllib.request
from datetime import datetime, timezone
from urllib.parse import parse_qs, quote, urlparse

from flask import Flask, Response, g, jsonify, redirect, request, send_from_directory

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
TEMPLATE_DIR = os.path.join(BASE_DIR, 'templates')
ASSET_DIR = os.path.join(BASE_DIR, 'assets')

COOKIE_NAME = 'cms_session'
COOKIE_TTL = 60 * 60 * 24 * 400          # ブラウザ側Cookieの期限（アクセスごとに延長）
MAX_UPLOAD_BYTES = 25 * 1024 * 1024
ID_RE = re.compile(r'^[a-zA-Z0-9_\-]+$')
SLUG_RE = re.compile(r'^[a-zA-Z0-9_\-]{1,64}$')
SESSION_RE = re.compile(r'^[a-f0-9]{64}$')
UPLOAD_NAME_RE = re.compile(r'^[a-f0-9]{64}\.(jpg|png|gif|webp|pdf)$')
TABLE_STYLES = ('plain', 'header-dark', 'striped', 'form', 'borderless')
BOLD_COLOR_KEYS = ('bold_color', 'bold_bg_color', 'bold_ul_color')
BOLD_KEYS = BOLD_COLOR_KEYS + ('bold_ul_thick',)

app = Flask(__name__, static_folder=None)
app.config['MAX_CONTENT_LENGTH'] = MAX_UPLOAD_BYTES + 1024 * 1024


# ---------------------------------------------------------------- 設定

_cfg = None

def cfg():
    global _cfg
    if _cfg is None:
        with open(config_path(), encoding='utf-8') as f:
            _cfg = json.load(f)
    return _cfg

def config_path():
    return os.environ.get('CMS_CONFIG') or os.path.join(BASE_DIR, 'data', 'site.json')

def genres_path():
    """管理画面のジャンル設定の保存先（site.json と同じディレクトリの genres.json）"""
    return os.path.join(os.path.dirname(config_path()), 'genres.json')

def genres():
    """ジャンル一覧。genres.json があればそれを、無ければ site.json の genres を使う"""
    try:
        with open(genres_path(), encoding='utf-8') as f:
            g = json.load(f)
        if isinstance(g, list) and g:
            return g
    except (OSError, ValueError):
        pass
    return cfg().get('genres', [])

def base():
    """サブパス配置時の接頭辞（例: '/kex'）。ルート配置なら ''"""
    return '/' + cfg().get('base_path', '').strip('/') if cfg().get('base_path', '').strip('/') else ''

def dir_of(key):
    return cfg()[key].rstrip('/') + '/'

def pub_dir():   return dir_of('data_dir')
def draft_dir():
    d = dir_of('drafts_dir')
    os.makedirs(d, mode=0o700, exist_ok=True)
    return d


# ---------------------------------------------------------------- 認証

def cookie_name():
    b = base().strip('/')
    return COOKIE_NAME + ('_' + re.sub(r'[^a-zA-Z0-9]', '_', b) if b else '')

def token_valid(given):
    try:
        with open(cfg()['token_file'], encoding='utf-8') as f:
            expected = f.read().strip()
    except OSError:
        return False
    given = str(given or '')
    return expected != '' and given != '' and hmac.compare_digest(expected, given)

def session_valid():
    key = request.cookies.get(cookie_name(), '')
    if not SESSION_RE.match(key):
        return False
    if not os.path.isfile(dir_of('session_dir') + key):
        return False
    # サーバー側セッションは無期限。ブラウザ側Cookieの期限をアクセスごとに延長する
    g.refresh_session = key
    return True

def session_start_new():
    d = dir_of('session_dir')
    os.makedirs(d, mode=0o700, exist_ok=True)
    key = secrets.token_hex(32)
    with open(d + key, 'w') as f:
        f.write(str(int(time.time())))
    g.refresh_session = key
    return key

def is_admin():
    if 'is_admin' not in g:
        token = request.headers.get('X-Admin-Token') or request.args.get('token', '')
        g.is_admin = session_valid() or token_valid(token)
    return g.is_admin

@app.after_request
def _refresh_cookie(resp):
    key = g.get('refresh_session')
    if key:
        resp.set_cookie(cookie_name(), key, max_age=COOKIE_TTL, path=base() + '/',
                        secure=True, httponly=True, samesite='Lax')
    return resp


# ---------------------------------------------------------------- 記事ファイル

def now_iso():
    return datetime.now(timezone.utc).astimezone().isoformat(timespec='seconds')

def read_json(path):
    with open(path, encoding='utf-8') as f:
        return json.load(f)

def write_json(path, obj):
    tmp = path + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as f:
        f.write(json.dumps(obj, ensure_ascii=False, indent=4))
    os.replace(tmp, path)

def safe_id(i):
    return i if isinstance(i, str) and ID_RE.match(i) else None

def load_article(d, i):
    if not safe_id(i):
        return None
    p = d + i + '.json'
    return read_json(p) if os.path.isfile(p) else None

def save_article(d, a):
    i = safe_id(a.get('id'))
    if not i:
        return False
    write_json(d + i + '.json', a)
    return True

def all_articles(d):
    out = []
    for f in sorted(glob.glob(d + '*.json')):
        try:
            out.append(read_json(f))
        except (OSError, ValueError):
            continue
    return out

def next_short_id(genre, exclude_id, dirs):
    m = 0
    for d in dirs:
        for a in all_articles(d):
            if a.get('id') == exclude_id or a.get('genre') != genre:
                continue
            m = max(m, int(a.get('short_id') or 0))
    return m + 1

def list_articles(d, genre=None, show_hidden=False, full=False):
    out = []
    for a in all_articles(d):
        if not show_hidden and a.get('hidden'):
            continue
        blocks = a.get('blocks') or []
        if not genre or a.get('genre') == genre:
            if not full:
                # ダイジェスト用に「見出し・クロスリンク・固定リンク以外の最初のブロック」を返す
                prev = next((b for b in blocks
                             if b.get('type') not in ('heading', 'link_from', 'permalink', 'contact_form', 'file')), None)
                a['blocks'] = [prev] if prev else []
            out.append(a)
        # 他ジャンル記事の link_from ブロック(target_genre==genre)を擬似カードとして追加
        if genre and a.get('genre') != genre:
            for b in blocks:
                if b.get('type') != 'link_from' or b.get('target_genre') != genre:
                    continue
                cross = {
                    'id': a.get('id', ''),
                    'genre': genre,
                    'src_genre': a.get('genre', ''),
                    'src_title': a.get('title', ''),
                    'title': b['title'] if b.get('title') is not None else a.get('title', ''),
                    'created_at': a.get('created_at'),
                    'updated_at': a.get('updated_at'),
                    'cross_link': True,
                    'blocks': [{'type': 'text', 'content': b.get('text', '')}],
                }
                for k in BOLD_KEYS:
                    if b.get(k):
                        cross[k] = b[k]
                out.append(cross)
    # sort 昇順（未設定は最後）、同順位は作成日の新しい順
    out.sort(key=lambda x: x.get('created_at') or '', reverse=True)
    out.sort(key=lambda x: x['sort'] if isinstance(x.get('sort'), (int, float)) else float('inf'))
    return out


# ---------------------------------------------------------------- ブロックのサニタイズ

def v_color(x):
    return x if isinstance(x, str) and re.match(r'^(#[0-9a-fA-F]{3,8}|[a-zA-Z]{1,20})$', x) else None

def v_length(x):
    return x if isinstance(x, str) and re.match(r'^\d+(\.\d+)?(px|pt|em|rem|%)?$', x) else None

def _bold_attrs(b, o):
    for k in BOLD_COLOR_KEYS:
        v = v_color(b.get(k))
        if v is not None:
            o[k] = v
    v = v_length(b.get('bold_ul_thick'))
    if v is not None and v != '0':
        o['bold_ul_thick'] = v
    return o

def sanitize_block(b):
    if not isinstance(b, dict):
        return None
    t = b.get('type')
    s = lambda k: str(b.get(k) if b.get(k) is not None else '')
    if t == 'heading':
        return {'type': 'heading', 'content': s('content')}
    if t == 'image':
        o = {'type': 'image', 'src': s('src'), 'caption': s('caption')}
        if b.get('decoration') == 'phone-frame':
            o['decoration'] = 'phone-frame'
        return o
    if t == 'file':
        try:
            size = max(0, int(b.get('size') or 0))
        except (TypeError, ValueError):
            size = 0
        return {'type': 'file', 'src': s('src'), 'name': s('name'), 'size': size}
    if t == 'text':
        return _bold_attrs(b, {'type': 'text', 'content': s('content')})
    if t == 'permalink':
        slug = s('slug')
        return {'type': 'permalink', 'slug': slug if SLUG_RE.match(slug) else ''}
    if t == 'table':
        style = b.get('style') if b.get('style') in TABLE_STYLES else 'plain'
        return {'type': 'table', 'markdown': s('markdown'), 'style': style}
    if t == 'contact_form':
        # お問い合わせフォーム。送信後に表示する文（md書式）だけを持つ
        return {'type': 'contact_form', 'thanks': s('thanks')}
    if t == 'link_from':
        tg = b.get('target_genre')
        tg = tg if isinstance(tg, str) and ID_RE.match(tg) else ''
        return _bold_attrs(b, {'type': 'link_from', 'target_genre': tg,
                               'title': s('title'), 'text': s('text')})
    return None

def sanitize_blocks(blocks):
    if not isinstance(blocks, list):
        return []
    return [x for x in (sanitize_block(b) for b in blocks) if x]


# ---------------------------------------------------------------- 下書きと公開版

def _read_text(p):
    with open(p, encoding='utf-8') as f:
        return f.read()

def draft_diff(pub, draft):
    names = sorted({os.path.basename(f) for d in (pub, draft) for f in glob.glob(d + '*.json')})
    out = []
    for n in names:
        a, b = pub + n, draft + n
        ta = _read_text(a) if os.path.isfile(a) else ''
        tb = _read_text(b) if os.path.isfile(b) else ''
        if ta == tb:
            continue
        out.append(f'diff -ruN {a} {b}')
        out.extend(difflib.unified_diff(ta.splitlines(), tb.splitlines(),
                                        fromfile=a, tofile=b, lineterm=''))
    return '\n'.join(out) + ('\n' if out else '')

def publish_drafts(pub, draft):
    updated = removed = 0
    for f in glob.glob(draft + '*.json'):
        target = pub + os.path.basename(f)
        body = _read_text(f)
        if not os.path.isfile(target) or _read_text(target) != body:
            with open(target, 'w', encoding='utf-8') as h:
                h.write(body)
            updated += 1
    for f in glob.glob(pub + '*.json'):
        if not os.path.isfile(draft + os.path.basename(f)):
            os.unlink(f)
            removed += 1
    return {'updated': updated, 'removed': removed}


# ---------------------------------------------------------------- アクセスログ集計

LOG_PATH = '/var/log/nginx/access.log'
LOG_RE = re.compile(r'^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+) (\S+) HTTP/[\d.]+" (\d+) \S+ "[^"]*" "([^"]*)"')
API_PATHS = ('/api/articles', '/api/articles.php')
ADMIN_PARAMS = ('diff', 'publish', 'export', 'permalink_check', 'short_id_check',
                'access_log', 'reorder', 'duplicate', 'include_hidden')

def _short_ua(ua):
    m = re.search(r'(Edg|OPR|Chrome|Firefox|Safari)/(\d+)', ua)
    if m:
        return f'{m.group(1)}/{m.group(2)}'
    if re.search(r'bot|spider|crawler', ua, re.I):
        m = re.search(r'([A-Za-z][\w.\-]*[Bb]ot)', ua)
        return m.group(1) if m else 'bot'
    return ua[:40] + '…' if len(ua) > 40 else ua

def _page_label(path, short_map):
    u = urlparse(path)
    q = {k: v[0] for k, v in parse_qs(u.query, keep_blank_values=True).items()}
    def art(i, fallback):
        m = short_map.get(i)
        return f"{m['genre']}/{m['short_id']}" if m else fallback
    if u.path == '/':
        if 'topic' in q: return 'topic:' + q['topic']
        if 'id' in q:    return art(q['id'], q.get('g', '?') + '/?')
        if 'link' in q:  return q.get('g', '?') + '/link'
        if 'g' in q:     return q['g'] + '/0'
        return 'top/0'
    if u.path in API_PATHS:
        if any(k in q for k in ('site', 'diff', 'permalink_check', 'short_id_check', 'export', 'access_log')):
            return None
        if 'topic' in q: return 'topic:' + q['topic']
        if 'id' in q:    return art(q['id'], '?/?')
        if 'link' in q:  return q.get('target', '?') + '/link'
        if 'genre' in q: return q['genre'] + '/0'
    return None

def build_access_report(days=30, gap_min=30):
    short_map = {a.get('id', ''): {'genre': a.get('genre', ''), 'short_id': int(a.get('short_id') or 0)}
                 for a in all_articles(draft_dir())}
    try:
        fh = open(LOG_PATH, encoding='utf-8', errors='replace')
    except OSError:
        return f'アクセスログを読み取れません: {LOG_PATH}\n'
    threshold = time.time() - days * 86400
    events = []
    with fh:
        for line in fh:
            m = LOG_RE.match(line)
            if not m:
                continue
            ip, ts, method, path, status, ua = m.groups()
            # サブパス配置時は自サイトの行だけを対象にし、接頭辞を外して判定する
            bp = base()
            if bp:
                if path != bp and not path.startswith(bp + '/') and not path.startswith(bp + '?'):
                    continue
                path = path[len(bp):] or '/'
                if path.startswith('?'):
                    path = '/' + path
            if status[0] not in '23':
                continue
            try:
                t = datetime.strptime(ts, '%d/%b/%Y:%H:%M:%S %z').timestamp()
            except ValueError:
                continue
            if t < threshold:
                continue
            u = urlparse(path)
            q = parse_qs(u.query, keep_blank_values=True)
            admin = ((method != 'GET' and u.path != '/api/contact') or u.path.startswith('/cms/')
                     or any(k in q for k in ADMIN_PARAMS))
            label = _page_label(path, short_map)
            if method == 'POST' and u.path == '/api/contact':
                label = 'contact:sent'
            if (method == 'GET' or label == 'contact:sent') and label is not None:
                events.append((ip, _short_ua(ua), t, label, admin))
            elif admin:
                # ページラベルは付かないが、セッションを管理者と印付けるためのマーカー
                events.append((ip, _short_ua(ua), t, None, True))
    # (ip, ua) でグルーピング → ギャップでセッション区切り
    events.sort(key=lambda e: (e[0], e[1], e[2]))
    sessions, cur, prev_key, prev_ts = [], [], None, None
    for e in events:
        k = (e[0], e[1])
        if k != prev_key or (prev_ts is not None and e[2] - prev_ts > gap_min * 60):
            if cur:
                sessions.append(cur)
            cur = []
        cur.append(e)
        prev_key, prev_ts = k, e[2]
    if cur:
        sessions.append(cur)
    sessions.sort(key=lambda s: s[0][2], reverse=True)
    out, cur_date = [], ''
    for s in sessions:
        if all(e[3] is None for e in s):
            continue
        dt = datetime.fromtimestamp(s[0][2])
        date = dt.strftime('%Y-%m-%d')
        if date != cur_date:
            if cur_date:
                out.append('')
            out.append(f'** {date}')
            cur_date = date
        prefix = '[admin] ' if any(e[4] for e in s) else ''
        parts, prev_label, prev_t = [], None, None
        for e in s:
            if e[3] is None or e[3] == prev_label:
                continue
            if prev_t is not None:
                parts[-1] += f'({int(e[2] - prev_t)})'
            parts.append(e[3])
            prev_label, prev_t = e[3], e[2]
        out.append(f"{dt.strftime('%H:%M')} {prefix}{''.join(parts)}")
    return '\n'.join(out) + ('\n' if out else '')


# ---------------------------------------------------------------- 画面

def _esc(s):
    return (str(s).replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')
            .replace('"', '&quot;').replace("'", '&#039;'))

def _hero_html(c):
    """tagline があればヘッダー下に大きく表示。tagline_html でHTML指定も可（site.json は管理者が書くもの）"""
    tag = c.get('tagline_html') or (_esc(c['tagline']) if c.get('tagline') else '')
    if not tag:
        return ''
    return ('  <section class="hero"><div class="hero-inner">'
            f'<h1 class="hero-tagline">{tag}</h1>'
            '</div></section>\n')

def render_page(name, preview=False):
    c = cfg()
    with open(os.path.join(TEMPLATE_DIR, name), encoding='utf-8') as f:
        html = f.read()
    try:
        ver = str(int(os.path.getmtime(os.path.join(ASSET_DIR, 'cms-render.js'))))
    except OSError:
        ver = '0'
    site = _esc(c.get('site_name', ''))
    subs = {
        '@@SITE_NAME_HTML@@': c.get('site_name_html') or site,
        '@@SITE_NAME@@': site,
        '@@COPYRIGHT@@': _esc(c.get('copyright', '')),
        '@@ASSET_VER@@': ver,
        # </script> 混入を防ぐため < をエスケープ
        '@@GENRES_JSON@@': json.dumps(genres(), ensure_ascii=False).replace('<', '\\u003c'),
        '@@IS_PREVIEW@@': 'true' if preview else 'false',
        '@@BASE@@': base(),
        '@@HERO@@': _hero_html(c),
    }
    for k, v in subs.items():
        html = html.replace(k, v)
    resp = Response(html, mimetype='text/html')
    resp.headers['Cache-Control'] = 'no-store'
    return resp

def _simple_page(status, title, body):
    html = ('<!DOCTYPE html><html lang="ja"><meta charset="UTF-8"><title>' + title + '</title>'
            '<body style="font-family:sans-serif;text-align:center;padding:4rem;color:#555">'
            + body + '</body></html>')
    return Response(html, status=status, mimetype='text/html')

@app.route('/')
def public_page():
    admin = session_valid()
    if 'topic' in request.args:
        # 固定リンク(?topic=<slug>) は該当記事へ302（広告のランディングページなどで利用可）
        slug = request.args.get('topic', '')
        if SLUG_RE.match(slug):
            for a in all_articles(draft_dir() if admin else pub_dir()):
                if not admin and a.get('hidden'):
                    continue
                if any(b.get('type') == 'permalink' and b.get('slug') == slug for b in a.get('blocks') or []):
                    return redirect(base() + '/?g=' + quote(a.get('genre', '')) + '&id=' + quote(a.get('id', '')), 302)
        return _simple_page(404, '404', '<h1>404</h1><p>指定の記事は見つかりませんでした。</p><p><a href="' + base() + '/">トップページへ</a></p>')
    return render_page('index.html', preview=admin)

@app.route('/index.php')
def legacy_index():
    qs = request.query_string.decode()
    return redirect(base() + '/' + ('?' + qs if qs else ''), 301)

@app.route('/cms')
def cms_noslash():
    return redirect(base() + '/cms/', 301)

@app.route('/cms/')
@app.route('/cms/index.php')
def cms_page():
    token = request.args.get('token', '')
    if token and token_valid(token):
        # トークンURLでのアクセス: セッションCookieを発行し、トークンをURLから消す
        session_start_new()
        return redirect(base() + '/cms/', 302)
    if not session_valid():
        return _simple_page(403, '403 Forbidden', '<h1>403</h1><p>このページは管理者専用です。管理者用のトークン付きURLでアクセスしてください。</p>')
    return render_page('cms.html')

@app.route('/assets/<path:name>')
def assets(name):
    return send_from_directory(ASSET_DIR, name)


# ---------------------------------------------------------------- 記事 API

def respond(data, status=200):
    body = json.dumps(data, ensure_ascii=False, indent=4)
    return Response(body, status=status, mimetype='application/json')

def unauthorized():
    return respond({'error': 'Unauthorized'}, 401)

@app.route('/api/articles', methods=['GET', 'POST', 'DELETE'])
@app.route('/api/articles.php', methods=['GET', 'POST', 'DELETE'])
def api_articles():
    a = request.args
    admin = is_admin()
    pub, draft = pub_dir(), draft_dir()
    read_dir = draft if admin else pub
    show_hidden = admin and 'include_hidden' in a

    if request.method == 'GET':
        c = cfg()
        if 'site' in a:
            return respond({'site_name': c.get('site_name'), 'copyright': c.get('copyright'),
                            'genres': genres()})
        if 'diff' in a:
            if not admin: return unauthorized()
            return respond({'diff': draft_diff(pub, draft)})
        if 'genres' in a:
            if not admin: return unauthorized()
            return respond({'genres': genres(), 'counts': genre_counts(pub, draft)})
        if 'export' in a:
            if not admin: return unauthorized()
            body = json.dumps({
                'exported_at': now_iso(),
                'site': {'site_name': c.get('site_name'), 'genres': genres()},
                'articles': all_articles(pub),
                'drafts': all_articles(draft),
            }, ensure_ascii=False, indent=4)
            fname = 'cms-export-' + datetime.now().strftime('%Y%m%d-%H%M%S') + '.json'
            return Response(body, mimetype='application/json',
                            headers={'Content-Disposition': f'attachment; filename="{fname}"'})
        if 'topic' in a:
            slug = a.get('topic', '')
            for art in all_articles(read_dir):
                if not show_hidden and art.get('hidden'):
                    continue
                if any(b.get('type') == 'permalink' and b.get('slug') == slug for b in art.get('blocks') or []):
                    return respond(art)
            return respond({'error': 'Not found'}, 404)
        if 'short_id_check' in a:
            if not admin: return unauthorized()
            try:
                n = int(a.get('short_id_check', '0'))
            except ValueError:
                n = 0
            genre, exclude = a.get('genre', ''), a.get('exclude', '')
            matches = [{'id': x.get('id', ''), 'title': x.get('title', '')}
                       for x in all_articles(draft)
                       if x.get('id') != exclude and x.get('genre') == genre and int(x.get('short_id') or 0) == n]
            return respond({'matches': matches})
        if 'access_log' in a:
            if not admin: return unauthorized()
            try:
                days, gap = int(a.get('days', 30)), int(a.get('gap', 30))
            except ValueError:
                days, gap = 30, 30
            return Response(build_access_report(days, gap), mimetype='text/plain')
        if 'permalink_check' in a:
            if not admin: return unauthorized()
            slug, exclude = a.get('permalink_check', ''), a.get('exclude', '')
            matches = [{'id': x.get('id', ''), 'title': x.get('title', ''), 'genre': x.get('genre', '')}
                       for x in all_articles(draft)
                       if x.get('id') != exclude
                       and any(b.get('type') == 'permalink' and b.get('slug') == slug for b in x.get('blocks') or [])]
            return respond({'matches': matches})
        if 'link' in a:
            src = load_article(read_dir, a.get('link', ''))
            if not src or (not show_hidden and src.get('hidden')):
                return respond({'error': 'Not found'}, 404)
            target = a.get('target', '')
            for b in src.get('blocks') or []:
                if b.get('type') != 'link_from' or b.get('target_genre') != target:
                    continue
                r = {'src_id': src.get('id', ''), 'src_genre': src.get('genre', ''),
                     'src_title': src.get('title', ''), 'target_genre': target,
                     'title': b.get('title', ''), 'text': b.get('text', ''),
                     'created_at': src.get('created_at'), 'updated_at': src.get('updated_at')}
                for k in BOLD_KEYS:
                    if b.get(k):
                        r[k] = b[k]
                return respond(r)
            return respond({'error': 'Not found'}, 404)
        if 'id' in a:
            art = load_article(read_dir, a.get('id', ''))
            if not art or (not show_hidden and art.get('hidden')):
                return respond({'error': 'Not found'}, 404)
            return respond(art)
        return respond(list_articles(read_dir, a.get('genre') or None, show_hidden, 'full' in a))

    if not admin:
        return unauthorized()

    if request.method == 'DELETE':
        i = a.get('id', '')
        if not i:
            return respond({'error': 'id required'}, 400)
        p = draft + i + '.json' if safe_id(i) else None
        if p and os.path.isfile(p):
            os.unlink(p)
            return respond({'success': True})
        return respond({'error': 'Delete failed'}, 404)

    # POST
    if 'genres' in a:
        return save_genres(request.get_json(silent=True), pub, draft)
    if 'publish' in a:
        return respond(publish_drafts(pub, draft))
    if 'duplicate' in a:
        src = load_article(draft, a.get('id', ''))
        if not src:
            return respond({'error': 'Not found'}, 404)
        copy = dict(src)
        copy['id'] = (src.get('genre') or 'article') + '-' + str(int(time.time()))
        copy['title'] = (src.get('title') or '') + ' (コピー)'
        copy['created_at'] = copy['updated_at'] = now_iso()
        if isinstance(src.get('sort'), (int, float)):
            copy['sort'] = src['sort'] + 5
        return respond(copy) if save_article(draft, copy) else respond({'error': 'Save failed'}, 500)

    body = request.get_json(silent=True)
    if 'reorder' in a:
        ids = body.get('ids') if isinstance(body, dict) else None
        if not isinstance(ids, list):
            return respond({'error': 'ids required'}, 400)
        pos = 10
        for i in ids:
            art = load_article(draft, str(i))
            if art:
                art['sort'] = pos
                save_article(draft, art)
                pos += 10
        return respond({'success': True})

    if not isinstance(body, dict) or not body.get('id') or not body.get('genre') or not body.get('title'):
        return respond({'error': 'Invalid data'}, 400)
    if not safe_id(body.get('id')):
        return respond({'error': 'Save failed'}, 500)
    now = now_iso()
    existing = load_article(draft, body['id'])
    if existing:
        if 'sort' in existing:
            body['sort'] = existing['sort']
        body['created_at'] = existing.get('created_at') or now
    else:
        body['created_at'] = now
    body['updated_at'] = now
    body['hidden'] = bool(body.get('hidden'))
    body.pop('home', None)
    # short_id: 明示指定 → 既存 → ジャンル内最大+1 の順で決める
    try:
        sid = int(body.get('short_id') or 0)
    except (TypeError, ValueError):
        sid = 0
    if sid <= 0 and existing:
        sid = int(existing.get('short_id') or 0)
    if sid <= 0:
        sid = next_short_id(body['genre'], body['id'], [pub, draft])
    body['short_id'] = sid
    body['blocks'] = sanitize_blocks(body.get('blocks'))
    return respond(body) if save_article(draft, body) else respond({'error': 'Save failed'}, 500)


# ---------------------------------------------------------------- お問い合わせフォーム

CONTACT_FROM_MAX = 200
CONTACT_BODY_MAX = 5000
CONTACT_RATE = (5, 600)          # 同じIPから 600 秒に 5 件まで
_contact_lock = threading.Lock()
_contact_hits = {}

def contact_log_path():
    return cfg().get('contact_log') or os.path.join(os.path.dirname(config_path()), 'contactlog.txt')

def _client_ip():
    # nginx の背後で動くので X-Real-IP / X-Forwarded-For を優先（直結時は remote_addr）
    return (request.headers.get('X-Real-IP')
            or (request.headers.get('X-Forwarded-For') or '').split(',')[0].strip()
            or request.remote_addr or '')

def _rate_limited(ip):
    n, window = CONTACT_RATE
    now = time.time()
    with _contact_lock:
        hits = [t for t in _contact_hits.get(ip, []) if now - t < window]
        if len(hits) >= n:
            _contact_hits[ip] = hits
            return True
        hits.append(now)
        _contact_hits[ip] = hits
        return False

def _send_zulip(url, text):
    """Zulip の Webhook へ {"text": ...} を POST。失敗時は 5 秒おきに 10 回まで再試行（旧サイトと同じ）"""
    data = json.dumps({'text': text}, ensure_ascii=False).encode('utf-8')
    for _ in range(10):
        try:
            req = urllib.request.Request(url, data=data, method='POST',
                                         headers={'Content-Type': 'application/json'})
            with urllib.request.urlopen(req, timeout=20) as res:
                if 200 <= res.status < 300:
                    return
        except Exception as e:
            app.logger.warning('zulip send failed: %s', e)
        time.sleep(5)
    app.logger.error('zulip send gave up')

@app.route('/api/contact', methods=['POST'])
def api_contact():
    body = request.get_json(silent=True) or {}
    sender = str(body.get('from') or '').strip()
    message = str(body.get('body') or '').strip()
    errors = {}
    if not sender:
        errors['from'] = 'メールアドレスを入力してください'
    elif len(sender) > CONTACT_FROM_MAX:
        errors['from'] = f'{CONTACT_FROM_MAX}字以内で入力してください'
    if not message:
        errors['body'] = '本文を入力してください'
    elif len(message) > CONTACT_BODY_MAX:
        errors['body'] = f'{CONTACT_BODY_MAX}字以内で入力してください'
    if errors:
        return respond({'error': '入力内容を確認してください', 'fields': errors}, 400)
    ip = _client_ip()
    if _rate_limited(ip):
        return respond({'error': '短時間に送信が続いたため受け付けられませんでした。しばらくしてから再度お試しください'}, 429)
    # ボット対策: 画面側は @ のないアドレスを送らせないので、@ がなければ JavaScript を
    # 実行しないボットとみなす。成功を返して諦めさせ、ログには印を付けて残し通知はしない
    bot = '@' not in sender
    stamp = datetime.now().strftime('%y%m%d-%H%M%S')
    entry = f'==== {stamp}{" [@なし・未通知]" if bot else ""} ====\n{sender}\n{message}\n[ip: {ip}] [ua: {request.headers.get("User-Agent", "")[:200]}]\n\x0c\n'
    path = contact_log_path()
    with _contact_lock:
        with open(path, 'a', encoding='utf-8') as f:
            f.write(entry)
        try:
            os.chmod(path, 0o600)
        except OSError:
            pass
    url = cfg().get('zulip_webhook_url')
    if url and not bot:
        # Zulip 側で自動リンクされないよう . と @ の前後に空白を入れる（旧サイトと同じ）
        safe = f'{sender}\n{message}'.replace('.', ' . ').replace('@', ' @ ')
        text = f'お問い合わせ ({cfg().get("site_name", "")}) {stamp}\n{safe}'
        threading.Thread(target=_send_zulip, args=(url, text), daemon=True).start()
    return respond({'ok': True})


# ---------------------------------------------------------------- ジャンル設定

def genre_counts(pub, draft):
    """ジャンルごとの記事数（下書き・公開版のどちらかにある記事を1件と数える）"""
    seen = {}
    for d in (draft, pub):
        for x in all_articles(d):
            seen.setdefault(x.get('id'), x.get('genre'))
    counts = {}
    for gk in seen.values():
        counts[gk] = counts.get(gk, 0) + 1
    return counts

def save_genres(body, pub, draft):
    items = body.get('genres') if isinstance(body, dict) else None
    if not isinstance(items, list) or not items:
        return respond({'error': 'ジャンルを1つ以上指定してください'}, 400)
    out, keys = [], set()
    # エラー時は行番号(index)と欄(field)も返し、画面側で該当欄を示せるようにする
    bad = lambda i, field, msg: respond({'error': msg, 'index': i, 'field': field}, 400)
    for i, it in enumerate(items):
        if not isinstance(it, dict):
            return respond({'error': '不正なデータです'}, 400)
        key = str(it.get('key') or '').strip()
        label = str(it.get('label') or '').strip()
        if not key:
            return bad(i, 'key', 'キーを入力してください')
        if not ID_RE.match(key) or len(key) > 32:
            return bad(i, 'key', 'キーは半角英数字・_・- の32字以内にしてください')
        if key in keys:
            return bad(i, 'key', f'キー「{key}」は他のジャンルで使われています')
        if not label:
            return bad(i, 'label', '表示名を入力してください')
        keys.add(key)
        g_ = {'key': key, 'label': label}
        if it.get('expand'):
            g_['expand'] = True
        out.append(g_)
    counts = genre_counts(pub, draft)
    removed = [k for k in (x.get('key') for x in genres()) if k not in keys and counts.get(k)]
    if removed:
        return respond({'error': '記事が残っているジャンルは削除できません: ' + ', '.join(removed)}, 400)
    write_json(genres_path(), out)
    return respond({'genres': out})


# ---------------------------------------------------------------- 画像アップロード API

def image_info(path):
    """(mime, ext, width, height) を返す。対応外なら None"""
    try:
        from PIL import Image
    except ImportError:
        return None
    kinds = {'JPEG': ('image/jpeg', 'jpg'), 'PNG': ('image/png', 'png'),
             'GIF': ('image/gif', 'gif'), 'WEBP': ('image/webp', 'webp')}
    try:
        with Image.open(path) as im:
            k = kinds.get(im.format)
            return (k[0], k[1], im.width, im.height) if k else None
    except Exception:
        return None

def pdf_info(path):
    """PDF なら image_info と同じ形 (mime, 拡張子, 幅, 高さ) を返す。先頭の %PDF- で判定"""
    try:
        with open(path, 'rb') as f:
            if f.read(5) == b'%PDF-':
                return ('application/pdf', 'pdf', None, None)
    except OSError:
        pass
    return None

@app.route('/api/uploads', methods=['GET', 'POST', 'DELETE'])
@app.route('/api/uploads.php', methods=['GET', 'POST', 'DELETE'])
def api_uploads():
    if not is_admin():
        return jsonify({'error': 'Unauthorized'}), 401
    c = cfg()
    if not c.get('upload_dir') or not c.get('upload_url'):
        return jsonify({'error': 'upload not configured'}), 500
    d = c['upload_dir'].rstrip('/') + '/'
    url = c['upload_url'].rstrip('/') + '/'
    os.makedirs(d, mode=0o755, exist_ok=True)

    if request.method == 'GET':
        files = []
        for p in glob.glob(d + '*'):
            if not os.path.isfile(p):
                continue
            info = image_info(p) or pdf_info(p)
            st = os.stat(p)
            files.append({'filename': os.path.basename(p), 'url': url + os.path.basename(p),
                          'size': st.st_size, 'mtime': int(st.st_mtime),
                          'width': info[2] if info else None, 'height': info[3] if info else None,
                          'mime': info[0] if info else None})
        files.sort(key=lambda x: x['mtime'], reverse=True)
        return jsonify({'files': files})

    if request.method == 'DELETE':
        name = request.args.get('filename', '')
        if not UPLOAD_NAME_RE.match(name):
            return jsonify({'error': 'invalid filename'}), 400
        if os.path.isfile(d + name):
            os.unlink(d + name)
        return jsonify({'ok': True})

    f = request.files.get('file')
    if not f:
        return jsonify({'error': 'file required'}), 400
    tmp = d + '.upload-' + secrets.token_hex(8)
    f.save(tmp)
    try:
        if os.path.getsize(tmp) > MAX_UPLOAD_BYTES:
            return jsonify({'error': 'file too large'}), 400
        info = image_info(tmp) or pdf_info(tmp)
        if not info:
            return jsonify({'error': '対応していない形式です (jpeg/png/gif/webp/pdf のみ)'}), 400
        h = hashlib.sha256()
        with open(tmp, 'rb') as fh:
            for chunk in iter(lambda: fh.read(1 << 20), b''):
                h.update(chunk)
        name = h.hexdigest() + '.' + info[1]
        dst = d + name
        if not os.path.exists(dst):
            os.replace(tmp, dst)
            os.chmod(dst, 0o644)
        return jsonify({'filename': name, 'url': url + name, 'width': info[2],
                        'height': info[3], 'mime': info[0], 'size': os.path.getsize(dst)}), 201
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)

@app.errorhandler(413)
def too_large(_e):
    return jsonify({'error': 'file too large'}), 413


class _StripBase:
    """base_path 配下のリクエストから接頭辞を外してルーティングする（nginx は接頭辞付きのまま転送）"""
    def __init__(self, wsgi):
        self.wsgi = wsgi
    def __call__(self, environ, start_response):
        with app.app_context():
            bp = base()
        path = environ.get('PATH_INFO', '')
        if bp:
            if path == bp:
                start_response('301 Moved Permanently', [('Location', bp + '/')])
                return [b'']
            if not path.startswith(bp + '/'):
                start_response('404 Not Found', [('Content-Type', 'text/plain')])
                return [b'Not Found']
            environ['PATH_INFO'] = path[len(bp):]
            environ['SCRIPT_NAME'] = environ.get('SCRIPT_NAME', '') + bp
        return self.wsgi(environ, start_response)

app.wsgi_app = _StripBase(app.wsgi_app)


if __name__ == '__main__':
    host = os.environ.get('CMS_HOST', '127.0.0.1')
    port = int(os.environ.get('CMS_PORT', '5090'))
    app.run(host=host, port=port, threaded=True)
