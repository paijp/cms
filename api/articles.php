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
 * GET    ?topic=<slug>     -> 固定リンクブロックのslugが一致する記事を返す
 * GET    ?permalink_check=<slug>&exclude=<id> -> slug使用中の他記事一覧 ※管理者
 * GET    ?short_id_check=<n>&genre=<g>&exclude=<id> -> ジャンル内でN使用中の記事一覧 ※管理者
 * GET    ?access_log=1&days=<n> -> アクセスログ集計テキスト ※管理者
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

function next_short_id($genre, $exclude_id, $dirs) {
    $max = 0;
    foreach ($dirs as $d) {
        foreach (glob($d . '*.json') as $f) {
            $a = json_decode(file_get_contents($f), true);
            if (($a['id'] ?? '') === $exclude_id) continue;
            if (($a['genre'] ?? '') !== $genre) continue;
            $s = (int)($a['short_id'] ?? 0);
            if ($s > $max) $max = $s;
        }
    }
    return $max + 1;
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
                if ($t !== 'heading' && $t !== 'link_from' && $t !== 'permalink') { $preview = $b; break; }
            }
            $a['blocks'] = $preview ? [$preview] : [];
            $articles[] = $a;
        }
        // 他ジャンル記事に「link_from」ブロック(target_genre==$genre)があれば擬似カードとして追加
        if ($genre && ($a['genre'] ?? '') !== $genre) {
            foreach ($blocks as $b) {
                if (($b['type'] ?? '') !== 'link_from') continue;
                if (($b['target_genre'] ?? '') !== $genre) continue;
                $cross = [
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
                foreach (['bold_color', 'bold_bg_color', 'bold_ul_thick', 'bold_ul_color'] as $k)
                    if (!empty($b[$k])) $cross[$k] = $b[$k];
                $articles[] = $cross;
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

/* ---- アクセスログ集計 ---- */
function _log_short_ua($ua) {
    if (preg_match('/(Edg|OPR|Chrome|Firefox|Safari)\/(\d+)/', $ua, $m)) return $m[1] . '/' . $m[2];
    if (preg_match('/(bot|spider|crawler)/i', $ua)) {
        if (preg_match('/([A-Za-z][\w\.\-]*[Bb]ot)/', $ua, $m)) return $m[1];
        return 'bot';
    }
    return strlen($ua) > 40 ? substr($ua, 0, 40) . '…' : $ua;
}

function _log_page_label($path, $short_map) {
    $u = parse_url($path);
    if (!isset($u['path'])) return null;
    parse_str($u['query'] ?? '', $q);
    if ($u['path'] === '/') {
        if (isset($q['topic']))     return 'topic:' . $q['topic'];
        if (isset($q['id'])) {
            $m = $short_map[$q['id']] ?? null;
            return $m ? ($m['genre'] . '/' . $m['short_id']) : (($q['g'] ?? '?') . '/?');
        }
        if (isset($q['link']))      return ($q['g'] ?? '?') . '/link';
        if (isset($q['g']))         return $q['g'] . '/0';
        return 'top/0';
    }
    if ($u['path'] === '/api/articles.php') {
        if (isset($q['site']) || isset($q['diff']) || isset($q['permalink_check'])
            || isset($q['short_id_check']) || isset($q['export']) || isset($q['access_log'])) return null;
        if (isset($q['topic']))     return 'topic:' . $q['topic'];
        if (isset($q['id'])) {
            $m = $short_map[$q['id']] ?? null;
            return $m ? ($m['genre'] . '/' . $m['short_id']) : '?/?';
        }
        if (isset($q['link']))      return ($q['target'] ?? '?') . '/link';
        if (isset($q['genre']))     return $q['genre'] . '/0';
        return null;
    }
    return null;
}

function build_access_report($draft_dir, $days = 30, $gap_min = 30) {
    // 記事ID -> ジャンル/short_id 対応表
    $short_map = [];
    foreach (glob($draft_dir . '*.json') as $f) {
        $a = json_decode(file_get_contents($f), true);
        $short_map[$a['id'] ?? ''] = ['genre' => $a['genre'] ?? '', 'short_id' => (int)($a['short_id'] ?? 0)];
    }
    $log = '/var/log/nginx/access.log';
    $fh = @fopen($log, 'r');
    if (!$fh) return "アクセスログを読み取れません: $log\n";
    $threshold = time() - $days * 86400;
    $events = [];
    while (($line = fgets($fh)) !== false) {
        if (!preg_match('/^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+) (\S+) HTTP\/[\d\.]+" (\d+) \S+ "[^"]*" "([^"]*)"/', $line, $m)) continue;
        [$_, $ip, $ts, $method, $path, $status, $ua] = $m;
        if ($method !== 'GET') continue;
        if ($status[0] !== '2' && $status[0] !== '3') continue;
        $t = DateTime::createFromFormat('d/M/Y:H:i:s O', $ts);
        if (!$t || $t->getTimestamp() < $threshold) continue;
        $label = _log_page_label($path, $short_map);
        if ($label === null) continue;
        $events[] = ['ts' => $t->getTimestamp(), 'ip' => $ip, 'ua' => _log_short_ua($ua), 'label' => $label];
    }
    fclose($fh);
    // (ip, ua) でグルーピング → ギャップでセッション区切り
    usort($events, function ($a, $b) {
        return [$a['ip'], $a['ua'], $a['ts']] <=> [$b['ip'], $b['ua'], $b['ts']];
    });
    $sessions = [];
    $current = [];
    $prev_key = null;
    $prev_ts = null;
    foreach ($events as $e) {
        $k = $e['ip'] . '|' . $e['ua'];
        if ($k !== $prev_key || ($prev_ts !== null && $e['ts'] - $prev_ts > $gap_min * 60)) {
            if ($current) $sessions[] = $current;
            $current = [];
        }
        $current[] = $e;
        $prev_key = $k;
        $prev_ts = $e['ts'];
    }
    if ($current) $sessions[] = $current;
    // 開始時刻順(降順=新しい順)
    usort($sessions, function ($a, $b) { return $b[0]['ts'] <=> $a[0]['ts']; });
    // 日付ごとにまとめて出力
    $out = '';
    $cur_date = '';
    foreach ($sessions as $s) {
        $date = date('Y-m-d', $s[0]['ts']);
        if ($date !== $cur_date) {
            if ($cur_date !== '') $out .= "\n";
            $out .= "** $date\n";
            $cur_date = $date;
        }
        $time = date('H:i', $s[0]['ts']);
        // ラベル列に (秒数) を挿入。連続する同一ページは省略
        $parts = [];
        $prev_label = null;
        $prev_ts = null;
        foreach ($s as $e) {
            if ($e['label'] === $prev_label) continue;
            if ($prev_ts !== null) $parts[count($parts) - 1] .= '(' . ($e['ts'] - $prev_ts) . ')';
            $parts[] = $e['label'];
            $prev_label = $e['label'];
            $prev_ts = $e['ts'];
        }
        $out .= "$time " . implode('', $parts) . "\n";
    }
    return $out;
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
    // 固定リンクブロック: 記事に永続slugを付与（詳細ページには非表示）
    if ($b['type'] === 'permalink') {
        $slug = (string)($b['slug'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_\-]{1,64}$/', $slug)) $slug = '';
        return ['type' => 'permalink', 'slug' => $slug];
    }
    if ($b['type'] === 'table') {
        $style = $b['style'] ?? 'plain';
        if (!in_array($style, ['plain', 'header-dark', 'striped', 'form', 'borderless'], true)) $style = 'plain';
        return ['type' => 'table', 'markdown' => (string)($b['markdown'] ?? ''), 'style' => $style];
    }
    // 他ジャンルへのクロスリンク: target_genre を1つ指定。強調表示属性も text ブロックと同じく保持
    if ($b['type'] === 'link_from') {
        $tg = $b['target_genre'] ?? '';
        if (!is_string($tg) || !preg_match('/^[a-zA-Z0-9_\-]+$/', $tg)) $tg = '';
        $o = ['type' => 'link_from', 'target_genre' => $tg,
              'title' => (string)($b['title'] ?? ''), 'text' => (string)($b['text'] ?? '')];
        foreach (['bold_color', 'bold_bg_color', 'bold_ul_color'] as $k)
            if (($v = v_color($b[$k] ?? null)) !== null) $o[$k] = $v;
        if (($v = v_length($b['bold_ul_thick'] ?? null)) !== null && $v !== '0') $o['bold_ul_thick'] = $v;
        return $o;
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
    if (isset($_GET['topic'])) {
        $slug = $_GET['topic'];
        foreach (glob($READ_DIR . '*.json') as $f) {
            $a = json_decode(file_get_contents($f), true);
            if (!$SHOW_HIDDEN && !empty($a['hidden'])) continue;
            foreach ($a['blocks'] ?? [] as $b) {
                if (($b['type'] ?? '') === 'permalink' && ($b['slug'] ?? '') === $slug) respond($a);
            }
        }
        respond(['error' => 'Not found'], 404);
    }
    if (isset($_GET['short_id_check'])) {
        require_admin();
        $sn = (int)$_GET['short_id_check'];
        $genre = $_GET['genre'] ?? '';
        $exclude = $_GET['exclude'] ?? '';
        $matches = [];
        foreach (glob($DRAFT_DIR . '*.json') as $f) {
            $a = json_decode(file_get_contents($f), true);
            if (($a['id'] ?? '') === $exclude) continue;
            if (($a['genre'] ?? '') !== $genre) continue;
            if ((int)($a['short_id'] ?? 0) === $sn) {
                $matches[] = ['id' => $a['id'] ?? '', 'title' => $a['title'] ?? ''];
            }
        }
        respond(['matches' => $matches]);
    }
    if (isset($_GET['access_log'])) {
        require_admin();
        header('Content-Type: text/plain; charset=utf-8');
        echo build_access_report($DRAFT_DIR, (int)($_GET['days'] ?? 30), (int)($_GET['gap'] ?? 30));
        exit;
    }
    if (isset($_GET['permalink_check'])) {
        require_admin();
        $slug = $_GET['permalink_check'];
        $exclude = $_GET['exclude'] ?? '';
        $matches = [];
        foreach (glob($DRAFT_DIR . '*.json') as $f) {
            $a = json_decode(file_get_contents($f), true);
            if (($a['id'] ?? '') === $exclude) continue;
            foreach ($a['blocks'] ?? [] as $b) {
                if (($b['type'] ?? '') === 'permalink' && ($b['slug'] ?? '') === $slug) {
                    $matches[] = ['id' => $a['id'] ?? '', 'title' => $a['title'] ?? '', 'genre' => $a['genre'] ?? ''];
                    break;
                }
            }
        }
        respond(['matches' => $matches]);
    }
    if (isset($_GET['link'])) {
        $src = load_article($READ_DIR, $_GET['link']);
        if (!$src) respond(['error' => 'Not found'], 404);
        if (!$SHOW_HIDDEN && !empty($src['hidden'])) respond(['error' => 'Not found'], 404);
        $target = $_GET['target'] ?? '';
        foreach ($src['blocks'] ?? [] as $b) {
            if (($b['type'] ?? '') !== 'link_from') continue;
            if (($b['target_genre'] ?? '') !== $target) continue;
            $r = [
                'src_id'       => $src['id'] ?? '',
                'src_genre'    => $src['genre'] ?? '',
                'src_title'    => $src['title'] ?? '',
                'target_genre' => $target,
                'title'        => $b['title'] ?? '',
                'text'         => $b['text'] ?? '',
                'created_at'   => $src['created_at'] ?? null,
                'updated_at'   => $src['updated_at'] ?? null,
            ];
            foreach (['bold_color', 'bold_bg_color', 'bold_ul_thick', 'bold_ul_color'] as $k)
                if (!empty($b[$k])) $r[$k] = $b[$k];
            respond($r);
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
    // short_id: 明示指定があればそれを、無ければ既存維持、それも無ければジャンル内で最大+1を採番
    $sid = isset($body['short_id']) ? (int)$body['short_id'] : 0;
    if ($sid <= 0) $sid = (int)($existing['short_id'] ?? 0);
    if ($sid <= 0) $sid = next_short_id($body['genre'], $body['id'], [$PUB_DIR, $DRAFT_DIR]);
    $body['short_id'] = $sid;
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
