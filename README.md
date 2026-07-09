# CMS

PHP + JSONファイルで動く、データベース不要のシンプルなCMSです。

- **閲覧ページ:** `/`
- **管理画面:** `/cms/`
- **API:** `/api/articles.php`

## 動作環境

| 項目 | 内容 |
|------|------|
| Web サーバー | nginx |
| スクリプト言語 | PHP 8.3 |
| PHP実行 | PHP-FPM |
| データ保存 | JSONファイル（`data/articles/`） |

## ファイル構成

```
.
├── index.html              # 閲覧用フロントエンド（SPA）
├── cms/
│   └── index.html          # 管理画面フロントエンド（SPA）
├── api/
│   └── articles.php        # REST API（記事のCRUD処理）
└── data/
    └── articles/           # 記事データ置き場（1記事1JSON）
```

### 各ファイルの役割

- **`index.html`** — バニラJavaScriptのSPA。APIを呼び出して記事を表示。編集機能なし。
- **`cms/index.html`** — 管理画面SPA。記事の作成・編集・削除ができる。**認証機能は未実装**。
- **`api/articles.php`** — HTTPメソッドで動作が変わるREST API。`data/articles/` 以下のJSONファイルを読み書き。
- **`data/articles/*.json`** — 記事1件につき1ファイル。ファイル名がそのまま記事IDになる。

## 記事データのJSON構造

```json
{
  "id": "news-001",
  "genre": "news",
  "title": "記事タイトル",
  "created_at": "2025-06-01T09:00:00+09:00",
  "updated_at": "2025-06-01T09:00:00+09:00",
  "blocks": [
    { "type": "heading", "content": "見出しテキスト" },
    { "type": "text",    "content": "本文テキスト（\\nで改行）" },
    { "type": "image",   "src": "https://example.com/image.jpg", "caption": "キャプション" }
  ]
}
```

### フィールド

| フィールド | 型 | 説明 |
|------------|-----|------|
| `id` | string | ファイル名と一致させる必要あり（英数字とハイフンのみ） |
| `genre` | string | `news` / `product` / `faq` / `about` のいずれか |
| `title` | string | 記事タイトル |
| `created_at` | string | ISO 8601形式。APIが初回保存時に自動セット |
| `updated_at` | string | ISO 8601形式。APIが保存のたびに自動更新 |
| `blocks` | array | ブロックの配列。順番通りに表示 |

### ブロックの種類

| `type` | フィールド | 説明 |
|--------|------------|------|
| `heading` | `content` | 見出し（h2相当） |
| `text` | `content` | 本文。`\n` が改行として表示される |
| `image` | `src`, `caption` | 画像URL指定。`caption` は省略可 |

## API仕様（`api/articles.php`）

### GET — 記事一覧

```
GET /api/articles.php?genre=news
```

指定ジャンルの記事一覧を返す。**先頭ブロック1件のみ**を含んだ配列（一覧表示用）。`genre` を省略すると全ジャンル。

### GET — 記事詳細

```
GET /api/articles.php?id=news-001
```

指定IDの記事を**全ブロック込み**で返す。

### POST — 記事の作成・更新

```
POST /api/articles.php
Content-Type: application/json

{ "id": "news-003", "genre": "news", "title": "タイトル", "blocks": [...] }
```

`id` に対応するJSONファイルが存在しなければ新規作成、存在すれば上書き更新。`created_at` は新規時のみAPIがセット。`updated_at` は常に上書き。

### DELETE — 記事の削除

```
DELETE /api/articles.php?id=news-001
```

対応するJSONファイルを削除。

### IDの制約

記事IDはファイル名に直結するため、**英数字とハイフンのみ**使用可能（例: `news-001`, `product-abc`）。スラッシュや日本語は不可。APIが内部でバリデーション。

## nginx 設定例

```nginx
server {
    listen 443 ssl;
    server_name example.com;

    root /var/www/example.com;
    index index.html;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php-fpm/www.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

## 管理画面とAPIをBasic認証で保護する

アプリ側に認証を持たないため、nginxのBasic認証で保護します。サーバ側の設定変更のみで完結します。

⚠️ **`/cms/` だけでなく `/api/articles.php` も必ず保護してください。** API はPOST/DELETEで記事を書き換えられるため、`/cms/` を隠しても API が公開されていれば `curl` から誰でも記事を作成・削除できます。実質の防御ラインは API 側です。

### 1. パスワードファイルを作る

```bash
# htpasswd がなければ apt install apache2-utils などで導入
sudo htpasswd -c /etc/nginx/.htpasswd admin
```

### 2. nginxの `server` ブロックに設定を追加

```nginx
# 管理画面のHTML
location /cms/ {
    auth_basic           "CMS Admin";
    auth_basic_user_file /etc/nginx/.htpasswd;
    try_files $uri $uri/ =404;
}

# API本体（こちらが実質の防御）
location = /api/articles.php {
    auth_basic           "CMS Admin";
    auth_basic_user_file /etc/nginx/.htpasswd;

    fastcgi_pass unix:/run/php-fpm/www.sock;
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include fastcgi_params;
}
```

管理画面から API を呼ぶ際は、ブラウザが `/cms/` で入力した Basic 認証情報を同一オリジンの `/api/` へも自動で送るため、フロントエンドの変更は不要です。

### 3. 反映

```bash
sudo nginx -t && sudo nginx -s reload
```

### 補足: データファイルの直接アクセスも塞ぐ

`data/articles/*.json` は nginx から静的ファイルとして直接読めます。閲覧ページのAPI経由の取得と同じ内容なので機密ではありませんが、URLを推測されたくない場合は以下を追加してください。

```nginx
location /data/ { deny all; }
```

## フロントエンドの構造

`index.html`（閲覧）と `cms/index.html`（管理）はどちらもフレームワークを使わない**バニラJavaScript**で書かれています。

### 状態管理

`state` オブジェクト1つで全状態を保持。

```javascript
let state = {
  view: 'list',    // 現在の画面: 'list' | 'detail'（閲覧）/ 'list' | 'editor'（管理）
  genre: 'news',   // 選択中のジャンル
  articles: [],    // 一覧データ
  current: null,   // 詳細表示中の記事
  loading: false,
};
```

`render()` が呼ばれるたびに `state.view` に応じて `main` 要素の中身を丸ごと差し替えます（仮想DOMなし）。

### ジャンルの定義

両ファイルの先頭付近にある `GENRES` 配列がジャンルの定義です。ここを変更するとタブが増減します。

```javascript
const GENRES = [
  { key: 'news',    label: 'お知らせ' },
  { key: 'product', label: '製品情報' },
  { key: 'faq',     label: 'よくある質問' },
  { key: 'about',   label: '企業情報' },
];
```

## ブロックの追加方法

ブロック種別を追加するには、以下3箇所を編集します。

### (1) `cms/index.html` — `BLOCK_TYPES` 配列に追加

```javascript
const BLOCK_TYPES = [
  { type: 'heading', label: '見出し' },
  { type: 'text',    label: '文章' },
  { type: 'image',   label: '画像' },
  { type: 'table',   label: '表' },  // ← 追加例
];
```

### (2) `cms/index.html` — `buildBlockEditor()` に編集UIを追加

```javascript
} else if (block.type === 'table') {
  const ta = document.createElement('textarea');
  ta.rows = 4;
  ta.placeholder = 'CSV形式などで入力';
  ta.value = block.content || '';
  ta.oninput = ev => { e.blocks[idx].content = ev.target.value; };
  bce.appendChild(ta);
}
```

### (3) `index.html` — `makeBlock()` に表示処理を追加

```javascript
} else if (block.type === 'table') {
  div.classList.add('block-table');
  // 表示ロジックをここに記述
}
```

## 注意事項・既知の制約

| 項目 | 内容 |
|------|------|
| 認証なし | `/cms/` はURLを知っていれば誰でも操作可能。本番運用前にBasic認証などを追加することを推奨 |
| 同時書き込み | PHP の `file_put_contents` を使っているため、複数人が同時に同じ記事を保存するとデータが壊れる可能性あり |
| 画像アップロード | 現在は画像URLの入力のみ対応。ファイルアップロードは未実装 |
| IDの変更不可 | 記事IDはファイル名と紐付いているため、保存後に変更できない（変更する場合は手動でファイルをリネームし、JSONの `id` フィールドも書き換える） |
| データ置き場 | `data/articles/` はnginxから直接アクセス可能（`/data/articles/news-001.json` で取得できる）。機密データを置く場合はnginxでブロックすること |

## サンプル記事

`data/articles/` に配置されているJSONはすべてサンプルデータです。実運用時は削除・置き換えてください。
