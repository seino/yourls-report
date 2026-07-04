<?php

/**
 * 認証処理
 * REQUIRE_LOGIN=true の場合、YOURLSのユーザー情報を使ってログイン認証を行う
 */

// 設定ファイル読み込み
$config_file = __DIR__ . '/config.php';
if (!file_exists($config_file)) {
    die('設定ファイルが見つかりません。');
}
require_once $config_file;

// 共通ユーティリティ読み込み
require_once __DIR__ . '/utils.php';

// デフォルト設定値
if (!defined('SESSION_TIMEOUT')) {
    define('SESSION_TIMEOUT', 3600); // 1時間
}
if (!defined('LOGIN_MAX_ATTEMPTS')) {
    define('LOGIN_MAX_ATTEMPTS', 5);
}
if (!defined('LOGIN_LOCKOUT_TIME')) {
    define('LOGIN_LOCKOUT_TIME', 900); // 15分
}

// セッション開始（ログイン有効時のみ）
if (defined('REQUIRE_LOGIN') && REQUIRE_LOGIN) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_secure' => isHttps(),
            'cookie_samesite' => 'Strict'
        ]);
    }
}

/**
 * ログイン認証が有効かどうか
 */
function isLoginRequired()
{
    return defined('REQUIRE_LOGIN') && REQUIRE_LOGIN === true;
}

/**
 * YOURLSのユーザー情報を取得
 *
 * 優先順位:
 * 1. config.phpで直接定義された YOURLS_USERS
 * 2. YOURLSの設定ファイルからパース
 */
function getYourlsUsers()
{
    // config.phpで直接ユーザーが定義されている場合はそれを使用
    if (defined('YOURLS_USERS')) {
        $users = YOURLS_USERS;
        if (is_array($users) && !empty($users)) {
            return $users;
        }
    }

    // YOURLSの設定ファイルからパース
    if (!defined('YOURLS_PATH')) {
        return [];
    }

    $yourls_config = YOURLS_PATH . '/user/config.php';
    if (!file_exists($yourls_config)) {
        return [];
    }

    $content = file_get_contents($yourls_config);

    // $yourls_user_passwords配列を探す
    // array() 形式と [] 形式の両方に対応
    $users = [];

    // パターン1: array() 形式
    if (preg_match('/\$yourls_user_passwords\s*=\s*array\s*\((.*?)\);/s', $content, $matches)) {
        $users = parseUserPasswordArray($matches[1]);
    }
    // パターン2: [] 形式（PHP 5.4+）
    elseif (preg_match('/\$yourls_user_passwords\s*=\s*\[(.*?)\];/s', $content, $matches)) {
        $users = parseUserPasswordArray($matches[1]);
    }

    return $users;
}

/**
 * ユーザー・パスワード配列の内容をパース
 *
 * @param string $arrayContent 配列の中身の文字列
 * @return array ['username' => 'password', ...]
 */
function parseUserPasswordArray($arrayContent)
{
    $users = [];

    // 'username' => 'password' または "username" => "password" の形式を解析
    // 複数行、コメント混在にも対応
    preg_match_all(
        "/['\"]([^'\"]+)['\"]\s*=>\s*['\"]([^'\"]+)['\"]/",
        $arrayContent,
        $userMatches,
        PREG_SET_ORDER
    );

    foreach ($userMatches as $match) {
        $users[$match[1]] = $match[2];
    }

    return $users;
}

/**
 * パスワードを検証（プレーンテキストまたはphpassハッシュ）
 */
function verifyPassword($inputPassword, $storedPassword)
{
    // プレーンテキストの場合
    if ($inputPassword === $storedPassword) {
        return true;
    }

    // phpassハッシュの場合（$P$で始まる）
    if (strpos($storedPassword, '$P$') === 0 || strpos($storedPassword, '$2') === 0) {
        // password_verify for bcrypt
        if (strpos($storedPassword, '$2') === 0) {
            return password_verify($inputPassword, $storedPassword);
        }

        // phpass形式のハッシュ検証
        // YOURLSのphpassライブラリを使用
        $phpass_file = defined('YOURLS_PATH') ? YOURLS_PATH . '/includes/phpass/PasswordHash.php' : '';
        if (file_exists($phpass_file)) {
            require_once $phpass_file;
            $hasher = new PasswordHash(8, true);
            return $hasher->CheckPassword($inputPassword, $storedPassword);
        }
    }

    // MD5ハッシュの場合（32文字の16進数）
    if (preg_match('/^[a-f0-9]{32}$/i', $storedPassword)) {
        return md5($inputPassword) === $storedPassword;
    }

    return false;
}

/**
 * ログイン試行回数の記録ファイルパスを取得
 *
 * セッションではなくサーバー側の永続ファイルにIP単位で記録することで、
 * Cookieを送らず毎回新規セッションを発行するブルートフォース回避を防ぐ。
 */
function getLoginAttemptFile()
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return sys_get_temp_dir() . '/yourls_report_login_' . md5($ip) . '.json';
}

/**
 * ログイン試行回数を取得
 */
function getLoginAttempts()
{
    $file = getLoginAttemptFile();
    if (!is_file($file)) {
        return ['count' => 0, 'first_attempt' => 0];
    }

    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data) || !isset($data['count'], $data['first_attempt'])) {
        return ['count' => 0, 'first_attempt' => 0];
    }

    return ['count' => (int) $data['count'], 'first_attempt' => (int) $data['first_attempt']];
}

/**
 * ログイン試行回数を記録
 */
function recordLoginAttempt()
{
    $attempts = getLoginAttempts();

    // ロックアウト時間が経過していたらリセット
    if ($attempts['first_attempt'] > 0 && (time() - $attempts['first_attempt']) > LOGIN_LOCKOUT_TIME) {
        $attempts = ['count' => 0, 'first_attempt' => 0];
    }

    if ($attempts['count'] === 0) {
        $attempts['first_attempt'] = time();
    }
    $attempts['count']++;

    file_put_contents(getLoginAttemptFile(), json_encode($attempts), LOCK_EX);
}

/**
 * ログイン試行回数をリセット
 */
function resetLoginAttempts()
{
    $file = getLoginAttemptFile();
    if (is_file($file)) {
        @unlink($file);
    }
}

/**
 * ロックアウト中かどうか
 */
function isLockedOut()
{
    $attempts = getLoginAttempts();

    if ($attempts['count'] >= LOGIN_MAX_ATTEMPTS) {
        $elapsed = time() - $attempts['first_attempt'];
        if ($elapsed < LOGIN_LOCKOUT_TIME) {
            return true;
        }
        // ロックアウト時間が経過したらリセット
        resetLoginAttempts();
    }

    return false;
}

/**
 * ロックアウト残り時間（秒）
 */
function getLockoutRemaining()
{
    $attempts = getLoginAttempts();
    $elapsed = time() - $attempts['first_attempt'];
    return max(0, LOGIN_LOCKOUT_TIME - $elapsed);
}

/**
 * ログイン処理
 */
function login($username, $password)
{
    // ロックアウトチェック
    if (isLockedOut()) {
        $remaining = getLockoutRemaining();
        $minutes = ceil($remaining / 60);
        return ['success' => false, 'message' => "ログイン試行回数が上限に達しました。{$minutes}分後に再試行してください。"];
    }

    $users = getYourlsUsers();

    if (empty($users)) {
        return ['success' => false, 'message' => 'YOURLSの設定が見つかりません。YOURLS_PATHを確認してください。'];
    }

    if (!isset($users[$username])) {
        recordLoginAttempt();
        return ['success' => false, 'message' => 'ユーザー名またはパスワードが正しくありません。'];
    }

    if (!verifyPassword($password, $users[$username])) {
        recordLoginAttempt();
        return ['success' => false, 'message' => 'ユーザー名またはパスワードが正しくありません。'];
    }

    // ログイン成功 - 試行回数リセット
    resetLoginAttempts();

    // セッション固定攻撃対策: 認証成功時にセッションIDを再生成
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    $_SESSION['logged_in'] = true;
    $_SESSION['username'] = $username;
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();

    return ['success' => true, 'message' => 'ログインしました。'];
}

/**
 * ログアウト処理
 */
function logout()
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
}

/**
 * セッションタイムアウトをチェック
 */
function checkSessionTimeout()
{
    if (!isLoginRequired()) {
        return true;
    }

    if (!isset($_SESSION['last_activity'])) {
        return false;
    }

    if ((time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        logout();
        return false;
    }

    // アクティビティ時間を更新
    $_SESSION['last_activity'] = time();
    return true;
}

/**
 * ログイン状態を確認
 */
function isLoggedIn()
{
    // ログイン不要の場合は常にtrue
    if (!isLoginRequired()) {
        return true;
    }

    // セッションタイムアウトチェック
    if (!checkSessionTimeout()) {
        return false;
    }

    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

/**
 * 認証を要求（未ログインならログインページへリダイレクト）
 */
function requireAuth()
{
    // ログイン不要の場合はスキップ
    if (!isLoginRequired()) {
        return;
    }

    if (!isLoggedIn()) {
        $login_url = dirname($_SERVER['SCRIPT_NAME']) . '/login.php';
        $return_url = $_SERVER['REQUEST_URI'];
        $timeout_param = '';

        // セッションタイムアウトの場合はパラメータを追加
        if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
            $timeout_param = '&timeout=1';
        }

        header('Location: ' . $login_url . '?return=' . urlencode($return_url) . $timeout_param);
        exit;
    }
}

/**
 * 現在のユーザー名を取得
 */
function getCurrentUser()
{
    if (!isLoginRequired()) {
        return null;
    }
    return $_SESSION['username'] ?? null;
}
