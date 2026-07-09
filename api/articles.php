<?php
/**
 * CMS API
 * GET    ?genre=news     -> 記事一覧（先頭ブロックのみ）
 * GET    ?id=news-001    -> 記事詳細（全ブロック）
 * POST                   -> 記事作成・更新
 * DELETE ?id=news-001    -> 記事削除
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

define('DATA_DIR', __DIR__ . '/../data/articles/');

function respond($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function safe_id($id) {
    return preg_match('/^[a-zA-Z0-9\-]+$/', $id) ? $id : null;
}

function load_article($id) {
    if (!safe_id($id)) return null;
    $path = DATA_DIR . $id . '.json';
    if (!file_exists($path)) return null;
    return json_decode(file_get_contents($path), true);
}

function save_article($article) {
    $id = safe_id($article['id'] ?? '');
    if (!$id) return false;
    return file_put_contents(DATA_DIR . $id . '.json',
        json_encode($article, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
}

function delete_article($id) {
    if (!safe_id($id)) return false;
    $path = DATA_DIR . $id . '.json';
    return file_exists($path) && unlink($path);
}

function list_articles($genre = null) {
    $articles = [];
    foreach (glob(DATA_DIR . '*.json') as $f) {
        $a = json_decode(file_get_contents($f), true);
        if ($genre && ($a['genre'] ?? '') !== $genre) continue;
        $a['blocks'] = isset($a['blocks'][0]) ? [$a['blocks'][0]] : [];
        $articles[] = $a;
    }
    usort($articles, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
    return $articles;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $a = load_article($_GET['id']);
        $a ? respond($a) : respond(['error' => 'Not found'], 404);
    }
    respond(list_articles($_GET['genre'] ?? null));
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body || empty($body['id']) || empty($body['genre']) || empty($body['title']))
        respond(['error' => 'Invalid data'], 400);
    $now = date('c');
    if (!load_article($body['id'])) $body['created_at'] = $now;
    $body['updated_at'] = $now;
    if (!isset($body['blocks'])) $body['blocks'] = [];
    save_article($body) ? respond($body) : respond(['error' => 'Save failed'], 500);
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if (!$id) respond(['error' => 'id required'], 400);
    delete_article($id) ? respond(['success' => true]) : respond(['error' => 'Delete failed'], 404);
}

respond(['error' => 'Method not allowed'], 405);
