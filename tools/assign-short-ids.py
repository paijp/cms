#!/usr/bin/env python3
"""既存の記事JSONに short_id を採番する。ジャンル毎に created_at 昇順で1,2,3…と付ける。

articles/ と drafts/ の両方に同じ ID の記事がある場合、両方に同じ short_id を書き込む。
既に short_id が付いている記事は上書きしない。

使い方:
  ./tools/assign-short-ids.py --data-dir /path/to/cms/data
"""
import argparse, glob, json, os, sys
from collections import defaultdict

def load(f):
    return json.load(open(f))
def save(f, obj):
    tmp = f + '.tmp'
    with open(tmp, 'w') as h:
        h.write(json.dumps(obj, ensure_ascii=False, indent=4))
    os.replace(tmp, f)

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--data-dir', required=True, help='site の data ディレクトリ（articles/ と drafts/ を含む）')
    args = ap.parse_args()

    articles_dir = os.path.join(args.data_dir, 'articles')
    drafts_dir   = os.path.join(args.data_dir, 'drafts')
    # ジャンル毎に 記事ID -> (created_at, [files]) を集める。drafts を優先ソースにする（新規記事は drafts のみに存在）
    per_id = {}
    for d in (drafts_dir, articles_dir):
        for f in glob.glob(os.path.join(d, '*.json')):
            a = load(f)
            aid = a.get('id')
            if not aid: continue
            entry = per_id.setdefault(aid, {'genre': a.get('genre', ''), 'created_at': a.get('created_at', ''), 'short_id': a.get('short_id'), 'files': []})
            entry['files'].append(f)
            if not entry['short_id'] and a.get('short_id'):
                entry['short_id'] = a['short_id']
    # ジャンル毎に既存 short_id を除いた記事を作成日順に採番
    by_genre = defaultdict(list)
    used = defaultdict(set)
    for aid, e in per_id.items():
        if e['short_id']:
            used[e['genre']].add(e['short_id'])
        else:
            by_genre[e['genre']].append((e['created_at'] or '', aid))
    for genre, items in by_genre.items():
        items.sort()  # created_at 昇順
        next_id = 1
        for created_at, aid in items:
            while next_id in used[genre]: next_id += 1
            per_id[aid]['short_id'] = next_id
            used[genre].add(next_id)
            next_id += 1
    # 書き戻し
    changed = 0
    for aid, e in per_id.items():
        for f in e['files']:
            a = load(f)
            if a.get('short_id') == e['short_id']: continue
            a['short_id'] = e['short_id']
            save(f, a)
            changed += 1
            print(f"{f}: short_id = {e['short_id']}")
    print(f"\n{changed} 件更新")

if __name__ == '__main__':
    main()
