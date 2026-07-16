# CMS

PHP + JSONファイルで動く、データベース不要のシンプルなCMSです。

- **閲覧ページ:** `/`（PHP。サイト設定を読み込んで表示）
- **管理画面:** `/cms/`（管理者トークン→Cookieセッションで保護）
- **API:** `/api/articles.php`

## 動作環境

| 項目 | 内容 |
|------|------|
| Web サーバー | nginx |
| スクリプト言語 | PHP 8.3 |
| PHP実行 | PHP-FPM |
| データ保存 | JSONファイル（下書きと公開版の2面） |

## ファイル構成

```
.
├── index.php               # 閲覧用フロントエンド（SPA）
├── cms/
│   └── index.php           # 管理画面フロントエンド（SPA、認証ゲート付き）
├── api/
│   ├── articles.php        # REST API（記事のCRUD・差分・公開・並べ替え）
│   └── lib.php             # 共通ライブラリ（設定読み込み・認証）
├── assets/
│   └── cms-render.js       # md/表レンダラ（閲覧・管理プレビュー共用）
├── site.php.example        # サイト固有設定のサンプル
└── data/articles/          # サンプル記事データ
```

## サイト固有設定（site.php）

サイト名・ジャンル・データ置き場などサイト固有の値は、リポジトリに
**含めない** 設定ファイル1つ（`site.php`）に集約します。
`site.php.example` をコピーして作成し、ドキュメントルート外に配置してください
（既定パス `data/site.php`、環境変数 `CMS_CONFIG` で変更可）。
`data/` 以下だけをバックアップすれば、記事本体と設定の両方が保存される。。

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
location /api/ {
    root /var/www/html;
    location ~ ^/api/.*\.php$ {
        fastcgi_pass unix:/run/php-fpm/www.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}

location /cms {
    root /var/www/html;
    index index.php;
    try_files $uri $uri/ =404;
    location ~ ^/cms/.*\.php$ {
        fastcgi_pass unix:/run/php-fpm/www.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}

location / {
    root /var/www/html;
    index index.php index.html;
    location = /index.php {
        fastcgi_pass unix:/run/php-fpm/www.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

## バックアップ対象

- **`data/` ディレクトリのみ**（`site.php` + `articles/` + `drafts/` が入っている）
- 管理者トークンは再発行できるので不要。セッションディレクトリも不要。
- nginx のサイト設定は環境依存なので、必要なら別途保存。
