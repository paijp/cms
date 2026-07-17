<?php
/**
 * CMS API
 *
 * 記事データは「下書き」(drafts_dir) と「公開版」(data_dir) の2面構成。
 * 管理画面での保存・削除・並べ替えはすべて下書きに対して行い、
 * 「公開」操作で下書きを公開版へ同期する。
 *
 * GET    ?genre=news       -> 記事一覧（先頭ブロックのみ）
 * GET    ?id=news-001      -> 記事詳細（全ブロック）
 * GET    ?link=<id>&target=<genre> -> クロスリンクの詳細（block.title/text/元記事情報）
 *        ※閲覧は管理者ログイン中なら下書き版、未ログインなら公開版
 * GET    ?site=1           -> サイト情報（サイト名・ジャンル）※公開
 * GET    ?diff=1           -> 下書きと公開版のテキスト差分 ※管理者
 * GET    ?export=1         -> 公開版・下書き全記事をまとめたJSONダウンロード ※管理者
 * GET    ?include_hidden=1 -> 一覧/詳細に非表示記事も含める ※管理者
 * POST                     -> 記事作成・更新（下書きへ） ※管理者
 * POST   ?publish=1        -> 下書きを公開版へ反映 ※管理者
 * POST   ?reorder=1        -> 記事の並び順を保存 body: {"ids": [...]} ※管理者
 * POST   ?duplicate=1&id=X -> 記事を複製（下書き） ※管理者
 * DELETE ?id=news-001      -> 記事削除（下書きから。公開時に公開版へ反映） ※管理者
 */
require __DIR__ . '/lib.php';
header('Content-Type: application/json; charset=utf-8');

$cfg = cms_config();
$PUB_DIR   = rtrim($cfg['data_dir'], '/') . '/';
$DRAFT_DIR = rtrim($cfg['drafts_dir'], '/') . '/';
if (!is_dir($DRAFT_DIR)) @mkdir($DRAFT_DIR, 0700, true);
$IS_ADMIN = cms_is_admin();
$READ_DIR = $IS_ADMIN ? $DRAFT_DIR : $PUB_DIR;
// 一覧/詳細で非表示記事を含めるのは管理者かつ include_hidden 指定時のみ
$SHOW_HIDDEN = $IS_ADMIN && isset($_GET['include_hidden']);

function respond($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function require_admin() {
    global $IS_ADMIN;
    if (!$IS_ADMIN) respond(['error' => 'Unauthorized'], 401);
}

function safe_id($id) {
    return preg_match('/^[a-zA-Z0-9_\-]+$/', $id) ? $id : null;
}

function load_article($dir, $id) {
    if (!safe_id($id)) return null;
    $path = $dir . $id . '.json';
    if (!file_exists($path)) return null;
    return json_decode(file_get_contents($path), true);
}

function save_article($dir, $article) {
    $id = safe_id($article['id'] ?? '');
    if (!$id) return false;
    return file_put_contents($dir . $id . '.json',
        json_encode($article, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
}

function delete_article($dir, $id) {
    if (!safe_id($id)) return false;
    $path = $dir . $id . '.json';
    return file_exists($path) && unlink($path);
}

function list_articles($dir, $genre = null, $show_hidden = false) {
    $articles = [];
    foreach (glob($dir . '*.json') as $f) {
        $a = json_decode(file_get_contents($f), true);
        if (!$show_hidden && !empty($a['hidden'])) continue;
        $blocks = $a['blocks'] ?? [];
        if (!$genre || ($a['genre'] ?? '') === $genre) {
            // 通常の記事: ダイジェスト用に「見出しとクロスリンク以外の最初のブロック」を返す
            $preview = null;
            foreach ($blocks as $b) {
                $t = $b['type'] ?? '';
                if ($t !== 'heading' && $t !== 'link_from') { $preview = $b; break; }
            }
            $a['blocks'] = $preview ? [$preview] : [];
            $articles[] = $a;
        }
        // 他ジャンル記事に「link_from」ブロック(target_genre==$genre)があれば擬似カードとして追加
        if ($genre && ($a['genre'] ?? '') !== $genre) {
            foreach ($blocks as $b) {
                if (($b['type'] ?? '') !== 'link_from') continue;
                if (($b['target_genre'] ?? '') !== $genre) continue;
                $articles[] = [
                    'id'         => $a['id'] ?? '',
                    'genre'      => $genre,          // 一覧の表示上のジャンル
                    'src_genre'  => $a['genre'] ?? '',
                    'src_title'  => $a['title'] ?? '',
                    'title'      => $b['title'] ?? ($a['title'] ?? ''),
                    'created_at' => $a['created_at'] ?? null,
                    'updated_at' => $a['updated_at'] ?? null,
                    'cross_link' => true,
                    'blocks'     => [['type' => 'text', 'content' => $b['text'] ?? '']],
                ];
            }
        }
    }
    usort($articles, function ($a, $b) {
        $sa = $a['sort'] ?? PHP_INT_MAX;
        $sb = $b['sort'] ?? PHP_INT_MAX;
        if ($sa !== $sb) return $sa <=> $sb;
        return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
    });
    return $articles;
}

/* ---- ブロックのサニタイズ ---- */
function v_color($x) {
    return (is_string($x) && preg_match('/^(#[0-9a-fA-F]{3,8}|[a-zA-Z]{1,20})$/', $x)) ? $x : null;
}
function v_length($x) {
    return (is_string($x) && preg_match('/^\d+(\.\d+)?(px|pt|em|rem|%)?$/', $x)) ? $x : null;
}
function sanitize_block($b) {
    if (!is_array($b) || !isset($b['type'])) return null;
    if ($b['type'] === 'heading') return ['type' => 'heading', 'content' => (string)($b['content'] ?? '')];
    if ($b['type'] === 'image')   return ['type' => 'image', 'src' => (string)($b['src'] ?? ''), 'caption' => (string)($b['caption'] ?? '')];
    if ($b['type'] === 'text') {
        $o = ['type' => 'text', 'content' => (string)($b['content'] ?? '')];
        foreach (['bold_color', 'bold_bg_color', 'bold_ul_color'] as $k)
            if (($v = v_color($b[$k] ?? null)) !== null) $o[$k] = $v;
        if (($v = v_length($b['bold_ul_thick'] ?? null)) !== null && $v !== '0') $o['bold_ul_thick'] = $v;
        return $o;
    }
    if ($b['type'] === 'table') {
        $style = $b['style'] ?? 'plain';
        if (!in_array($style, ['plain', 'header-dark', 'striped', 'form', 'borderless'], true)) $style = 'plain';
        return ['type' => 'table', 'markdown' => (string)($b['markdown'] ?? ''), 'style' => $style];
    }
    // 他ジャンルへのクロスリンク: target_genre を1つ指定
    if ($b['type'] === 'link_from') {
        $tg = $b['target_genre'] ?? '';
        if (!is_string($tg) || !preg_match('/^[a-zA-Z0-9_\-]+$/', $tg)) $tg = '';
        return ['type' => 'link_from', 'target_genre' => $tg,
                'title' => (string)($b['title'] ?? ''), 'text' => (string)($b['text'] ?? '')];
    }
    return null;
}
function sanitize_blocks($blocks) {
    if (!is_array($blocks)) return [];
    return array_values(array_filter(array_map('sanitize_block', $blocks)));
}

/* ---- 下書きと公開版の差分・公開 ---- */
function draft_diff($pub, $draft) {
    $out = @shell_exec('diff -ruN -- ' . escapeshellarg(rtrim($pub, '/')) . ' ' . escapeshellarg(rtrim($draft, '/')) . ' 2>/dev/null');
    if (is_string($out) && trim($out) !== '') return $out;
    // diffコマンドが使えない場合のフォールバック（ファイル単位）
    $ids = [];
    foreach (glob($pub . '*.json') as $f) { $k = basename($f, '.json'); $ids[$k] = ($ids[$k] ?? 0) | 1; }
    foreach (glob($draft . '*.json') as $f) { $k = basename($f, '.json'); $ids[$k] = ($ids[$k] ?? 0) | 2; }
    $lines = [];
    foreach ($ids as $id => $where) {
        if ($where === 1) $lines[] = "削除: $id";
        elseif ($where === 2) $lines[] = "新規: $id";
        elseif (file_get_contents($pub . $id . '.json') !== file_get_contents($draft . $id . '.json'))
            $lines[] = "変更: $id";
    }
    return implode("\n", $lines);
}

function publish_drafts($pub, $draft) {
    $updated = 0; $removed = 0;
    foreach (glob($draft . '*.json') as $f) {
        $target = $pub . basename($f);
        if (!file_exists($target) || file_get_contents($target) !== file_get_contents($f)) {
            copy($f, $target);
            $updated++;
        }
    }
    foreach (glob($pub . '*.json') as $f) {
        if (!file_exists($draft . basename($f))) { unlink($f); $removed++; }
    }
    return ['updated' => $updated, 'removed' => $removed];
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (isset($_GET['site'])) {
        respond([
            'site_name' => $cfg['site_name'],
            'copyright' => $cfg['copyright'],
            'genres'    => $cfg['genres'],
        ]);
    }
    if (isset($_GET['diff'])) {
        require_admin();
        respond(['diff' => draft_diff($PUB_DIR, $DRAFT_DIR)]);
    }
    if (isset($_GET['export'])) {
        require_admin();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="cms-export-' . date('Ymd-His') . '.json"');
        $dump = function ($dir) {
            $out = [];
            foreach (glob($dir . '*.json') as $f) $out[] = json_decode(file_get_contents($f), true);
            return $out;
        };
        echo json_encode([
            'exported_at' => date('c'),
            'site'        => ['site_name' => $cfg['site_name'], 'genres' => $cfg['genres']],
            'articles'    => $dump($PUB_DIR),
            'drafts'      => $dump($DRAFT_DIR),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    if (isset($_GET['link'])) {
        $src = load_article($READ_DIR, $_GET['link']);
        if (!$src) respond(['error' => 'Not found'], 404);
        if (!$SHOW_HIDDEN && !empty($src['hidden'])) respond(['error' => 'Not found'], 404);
        $target = $_GET['target'] ?? '';
        foreach ($src['blocks'] ?? [] as $b) {
            if (($b['type'] ?? '') !== 'link_from') continue;
            if (($b['target_genre'] ?? '') !== $target) continue;
            respond([
                'src_id'       => $src['id'] ?? '',
                'src_genre'    => $src['genre'] ?? '',
                'src_title'    => $src['title'] ?? '',
                'target_genre' => $target,
                'title'        => $b['title'] ?? '',
                'text'         => $b['text'] ?? '',
                'created_at'   => $src['created_at'] ?? null,
                'updated_at'   => $src['updated_at'] ?? null,
            ]);
        }
        respond(['error' => 'Not found'], 404);
    }
    if (isset($_GET['id'])) {
        $a = load_article($READ_DIR, $_GET['id']);
        if (!$a) respond(['error' => 'Not found'], 404);
        if (!$SHOW_HIDDEN && !empty($a['hidden'])) respond(['error' => 'Not found'], 404);
        respond($a);
    }
    respond(list_articles($READ_DIR, $_GET['genre'] ?? null, $SHOW_HIDDEN));
}

if ($method === 'POST') {
    require_admin();
    if (isset($_GET['publish'])) {
        respond(publish_drafts($PUB_DIR, $DRAFT_DIR));
    }
    if (isset($_GET['duplicate'])) {
        $src = load_article($DRAFT_DIR, $_GET['id'] ?? '');
        if (!$src) respond(['error' => 'Not found'], 404);
        $copy = $src;
        $copy['id'] = ($src['genre'] ?? 'article') . '-' . time();
        $copy['title'] = ($src['title'] ?? '') . ' (コピー)';
        $copy['created_at'] = $copy['updated_at'] = date('c');
        if (isset($src['sort'])) $copy['sort'] = $src['sort'] + 5;
        save_article($DRAFT_DIR, $copy) ? respond($copy) : respond(['error' => 'Save failed'], 500);
    }
    $body = json_decode(file_get_contents('php://input'), true);
    if (isset($_GET['reorder'])) {
        $ids = $body['ids'] ?? null;
        if (!is_array($ids)) respond(['error' => 'ids required'], 400);
        $pos = 10;
        foreach ($ids as $id) {
            $a = load_article($DRAFT_DIR, (string)$id);
            if ($a) { $a['sort'] = $pos; save_article($DRAFT_DIR, $a); $pos += 10; }
        }
        respond(['success' => true]);
    }
    if (!$body || empty($body['id']) || empty($body['genre']) || empty($body['title']))
        respond(['error' => 'Invalid data'], 400);
    $now = date('c');
    $existing = load_article($DRAFT_DIR, $body['id']);
    if ($existing) {
        if (isset($existing['sort'])) $body['sort'] = $existing['sort'];
        $body['created_at'] = $existing['created_at'] ?? $now;
    } else {
        $body['created_at'] = $now;
    }
    $body['updated_at'] = $now;
    $body['hidden'] = !empty($body['hidden']);
    $body['blocks'] = sanitize_blocks($body['blocks'] ?? []);
    save_article($DRAFT_DIR, $body) ? respond($body) : respond(['error' => 'Save failed'], 500);
}

if ($method === 'DELETE') {
    require_admin();
    $id = $_GET['id'] ?? '';
    if (!$id) respond(['error' => 'id required'], 400);
    delete_article($DRAFT_DIR, $id) ? respond(['success' => true]) : respond(['error' => 'Delete failed'], 404);
}

respond(['error' => 'Method not allowed'], 405);
