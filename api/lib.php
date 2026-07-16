<?php
/**
 * CMS 共通ライブラリ: サイト設定の読み込みと管理者認証
 *
 * サイト固有の設定（サイト名・ジャンル・データ置き場など）は
 * リポジトリ外の設定ファイル1つにまとめる。既定パスは
 * /var/www/kimoken-cms/site.php（環境変数 CMS_CONFIG で変更可）。
 * 書式は site.php.example を参照。
 */

define('CMS_COOKIE', 'cms_session');
define('CMS_COOKIE_TTL', 60 * 60 * 24 * 400); // ブラウザ側Cookieの期限（アクセスごとに延長）

function cms_config() {
    static $cfg = null;
    if ($cfg === null) {
        $path = getenv('CMS_CONFIG') ?: '/var/www/kimoken-cms/site.php';
        $cfg = require $path;
    }
    return $cfg;
}

function cms_token_valid($given) {
    $expected = trim((string)@file_get_contents(cms_config()['token_file']));
    return $expected !== '' && (string)$given !== ''
        && hash_equals($expected, (string)$given);
}

function cms_set_cookie($key) {
    setcookie(CMS_COOKIE, $key, [
        'expires'  => time() + CMS_COOKIE_TTL,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function cms_session_valid() {
    $dir = rtrim(cms_config()['session_dir'], '/') . '/';
    $key = $_COOKIE[CMS_COOKIE] ?? '';
    if (!preg_match('/^[a-f0-9]{64}$/', $key)) return false;
    if (!is_file($dir . $key)) return false;
    // サーバー側セッションは無期限。ブラウザ側Cookieの期限をアクセスごとに延長する
    if (!headers_sent()) cms_set_cookie($key);
    return true;
}

function cms_session_start_new() {
    $dir = rtrim(cms_config()['session_dir'], '/') . '/';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    $key = bin2hex(random_bytes(32));
    file_put_contents($dir . $key, (string)time());
    cms_set_cookie($key);
    return $key;
}

function cms_is_admin() {
    $token = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ($_GET['token'] ?? '');
    return cms_session_valid() || cms_token_valid($token);
}
