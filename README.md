# CMS

PHP + JSONファイルで動く、データベース不要のシンプルなCMSです。

- **閲覧ページ:** `/`（PHP。サイト設定を読み込んで表示）
- **管理画面:** `/cms/`（管理者トークン→Cookieセッションで保護）
- **API:** `/api/articles`

## 動作環境

| 項目 | 内容 |
|------|------|
| Web サーバー | nginx（リバースプロキシ） |
| アプリ | Python 3 + Flask（`app.py`） |
| 画像処理 | Pillow（アップロード画像の形式・サイズ判定） |
| データ保存 | JSONファイル（下書きと公開版の2面） |

## ファイル構成

```
.
├── app.py                  # アプリ本体（公開ページ・管理画面・API）
├── templates/
│   ├── index.html          # 閲覧用フロントエンド（SPA）
│   └── cms.html            # 管理画面フロントエンド（SPA）
├── assets/
│   └── cms-render.js       # md/表レンダラ（閲覧・管理プレビュー共用）
├── site.json.example       # サイト固有設定のサンプル
├── tools/                  # アクセスログ集計・short_id 採番などの補助スクリプト
└── data/articles/          # サンプル記事データ
```

## 起動

```
CMS_CONFIG=/path/to/data/site.json CMS_PORT=5090 python3 app.py
```

127.0.0.1:5090 で待ち受けるので、nginx から `/`・`/cms`・`/api/`・`/assets/` を
プロキシする。systemd で常駐させる例:

```ini
[Service]
WorkingDirectory=/path/to/cms
Environment=CMS_CONFIG=/path/to/data/site.json
Environment=CMS_PORT=5090
ExecStart=/usr/bin/python3 /path/to/cms/app.py
Restart=on-failure
```

## ジャンル設定

管理画面の「⚙ ジャンル設定」で、タブの表示名・並び順・展開表示の有無を変更し、
ジャンルを追加・削除できる（記事が残っているジャンルは削除不可。キーは作成後変更不可）。
設定は `site.json` と同じディレクトリの `genres.json` に保存され、`site.json` の
`genres` より優先される。保存するとすぐ公開ページに反映される。

## サブパスへの配置（1台に複数のCMS）

`site.json` に `"base_path": "/kex"` を書き、別ポート（`CMS_PORT`）で起動すると、
`https://example.com/kex/` 配下で独立したCMSとして動く。nginx は接頭辞を残したまま転送する:

```nginx
location /kex/ {
    proxy_pass http://127.0.0.1:5091;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    client_max_body_size 30m;
}
location = /kex { return 301 /kex/; }
```

ログインCookieは名前とパスがサブパスごとに分かれるので、同じホスト上の他のCMSとは干渉しない。

## サイト固有設定（site.json）

サイト名・ジャンル・データ置き場などサイト固有の値は、リポジトリに
**含めない** 設定ファイル1つ（`site.json`）に集約します。
`site.json.example` をコピーして作成し、`data/site.json` として置いてください
（環境変数 `CMS_CONFIG` で変更可）。
`data/` 以下だけをバックアップすれば、記事本体と設定の両方が保存される。

## 認証

- 管理者トークン（`token_file` のファイル内容）を `?token=` に付けて `/cms/` へ
  アクセスすると、セッションキーを Cookie（HttpOnly / Secure）にセットして
  トークンなしの URL にリダイレクトする。以降は Cookie でアクセスできる。
- API の GET は公開。POST / DELETE は管理者のみ
  （Cookie セッション、または `X-Admin-Token` ヘッダ）。

## 下書きと公開

- 管理画面での保存・削除・並べ替えはすべて **下書き**（`drafts_dir`）に反映される。
- 管理者ログイン中の閲覧ページは下書き版、未ログインは公開版（`data_dir`）を表示。
- 管理画面の「公開」ボタンで下書きと公開版の **テキスト差分（unified diff）** を
  確認してから、公開版へ同期する。

## 記事データのJSON構造

```json
{
  "id": "news-001",
  "genre": "news",
  "title": "記事タイトル",
  "sort": 10,
  "created_at": "2025-06-01T09:00:00+09:00",
  "updated_at": "2025-06-01T09:00:00+09:00",
  "blocks": [
    { "type": "heading", "content": "見出しテキスト" },
    { "type": "text",    "content": "本文（md書式対応）", "bold_color": "#c00" },
    { "type": "table",   "markdown": "|列1|列2|\n|A|B|", "style": "plain" },
    { "type": "image",   "src": "https://example.com/image.jpg", "caption": "キャプション" }
  ]
}
```

- `sort` は表示順（昇順）。未設定の記事は作成日の新しい順で後ろに並ぶ。

### 文章ブロックの md 書式

- `**テキスト**` = 強調（表示方法は `bold_color` / `bold_bg_color` /
  `bold_ul_thick` / `bold_ul_color` で指定）
- `- ` / `* ` = 箇条書き（行頭2スペースごとにネスト）、`1. ` = 番号付き
- 空行 = 1行分の空き

### 表ブロックの md 表記法

- 行 = `|` で始まり `|` で終わる行。1行目がヘッダ（`|---|` 区切り行は無視）
- セル内の前後空白で寄せを指定: `|x|`=左 / `| x|`=右 / `| x |`=中央
- セル内容 `<` = 左のセルと結合、`^` = 上のセルと結合、`&br;` = セル内改行
- `style`: `plain` / `header-dark` / `striped` / `form` / `borderless`

## nginx 設定例

```nginx
location = /        { proxy_pass http://127.0.0.1:5090; }
location = /index.php { proxy_pass http://127.0.0.1:5090; }
location /cms       { proxy_pass http://127.0.0.1:5090; }
location /api/      { proxy_pass http://127.0.0.1:5090; client_max_body_size 30m; }
location /assets/   { proxy_pass http://127.0.0.1:5090; }
location /uploads/  { alias /path/to/uploads/; }
```

プロキシ先には `proxy_set_header Host $host; proxy_set_header X-Forwarded-Proto $scheme;` を付ける。

## バックアップ対象

- **`data/` ディレクトリのみ**（`site.json` + `articles/` + `drafts/` が入っている）
- 管理者トークンは再発行できるので不要。セッションディレクトリも不要。
- nginx のサイト設定は環境依存なので、必要なら別途保存。

## お問い合わせフォーム

記事に「お問い合わせフォーム」ブロックを置くと、メールアドレス・本文の入力欄と送信ボタンが表示されます。

- 送信内容は `contact_log`（既定は site.json と同じフォルダの `contactlog.txt`、パーミッション 0600）に追記されます。日時・IP・User-Agent も記録します。
- `zulip_webhook_url` を設定すると、同じ内容を `{"text": ...}` の JSON で POST します（バックグラウンドで最大10回再試行）。
- 同一IPからの送信は10分に5回まで。
- メールアドレスに「@」がない送信は、画面側では alert で止めます。サーバに届いた場合は JavaScript を実行しないボットとみなし、成功を返しつつ通知はしません（ログには `[@なし・未通知]` の印付きで記録）。
- ブロックの「送信完了メッセージ」で送信後の文面を変更できます（空欄なら標準文面）。

## PDFファイル

記事に「PDFファイル」ブロックを置き、PDFをアップロード（または「選択」でアップロード済みのものを指定）すると、
公開ページにファイル名・サイズとダウンロードボタンが表示されます。誰でも閲覧・ダウンロードできます。

- PDF は画像と同じ `upload_dir` に保存されます（中身が `%PDF-` で始まるもののみ受け付け、上限は画像と同じ 25MB）。
- 「表示名」がダウンロード時のファイル名（`表示名.pdf`）になります。カード部分を押すとブラウザで開きます。
