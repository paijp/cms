<?php
/**
 * 画像アップロード API
 *
 * 保存先とURLは site.php の upload_dir / upload_url で指定する。
 * ファイル名は SHA-256 で正規化（同一内容は1ファイル）。
 *
 * GET                 -> アップロード済み画像の一覧
 * POST (multipart)    -> field=file を保存し {filename,url,mime,width,height} を返す
 * DELETE ?filename=X  -> 指定ファイルを削除
 */
require __DIR__ . '/lib.php';
header('Content-Type: application/json; charset=utf-8');

function u_respond($d, $s = 200) {
    http_response_code($s);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!cms_is_admin()) u_respond(['error' => 'Unauthorized'], 401);

$cfg = cms_config();
if (empty($cfg['upload_dir']) || empty($cfg['upload_url'])) {
    u_respond(['error' => 'upload not configured'], 500);
}
$DIR = rtrim($cfg['upload_dir'], '/') . '/';
$URL = rtrim($cfg['upload_url'], '/') . '/';
if (!is_dir($DIR)) @mkdir($DIR, 0755, true);

$ALLOWED = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];
$MAX_BYTES = 25 * 1024 * 1024;

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $out = [];
    foreach (glob($DIR . '*') as $p) {
        if (!is_file($p)) continue;
        $info = @getimagesize($p);
        $out[] = [
            'filename' => basename($p),
            'url'      => $URL . basename($p),
            'size'     => filesize($p),
            'mtime'    => filemtime($p),
            'width'    => $info ? $info[0] : null,
            'height'   => $info ? $info[1] : null,
            'mime'     => $info ? $info['mime'] : null,
        ];
    }
    usort($out, function ($a, $b) { return $b['mtime'] <=> $a['mtime']; });
    u_respond(['files' => $out]);
}

if ($method === 'POST') {
    if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        u_respond(['error' => 'file required'], 400);
    }
    $tmp = $_FILES['file']['tmp_name'];
    if (filesize($tmp) > $MAX_BYTES) u_respond(['error' => 'file too large'], 400);
    $info = @getimagesize($tmp);
    if (!$info || !isset($ALLOWED[$info['mime']])) {
        u_respond(['error' => 'unsupported image (jpeg/png/gif/webp のみ)'], 400);
    }
    $ext = $ALLOWED[$info['mime']];
    $sha = hash_file('sha256', $tmp);
    $name = $sha . '.' . $ext;
    $dst = $DIR . $name;
    if (!file_exists($dst)) {
        if (!move_uploaded_file($tmp, $dst)) u_respond(['error' => 'save failed'], 500);
        @chmod($dst, 0644);
    }
    u_respond([
        'filename' => $name,
        'url'      => $URL . $name,
        'width'    => $info[0],
        'height'   => $info[1],
        'mime'     => $info['mime'],
    ], 201);
}

if ($method === 'DELETE') {
    $name = $_GET['filename'] ?? '';
    if (!preg_match('/^[a-f0-9]{64}\.(jpg|png|gif|webp)$/', $name)) {
        u_respond(['error' => 'invalid filename'], 400);
    }
    $p = $DIR . $name;
    if (is_file($p)) @unlink($p);
    u_respond(['ok' => true]);
}

u_respond(['error' => 'method not allowed'], 405);
