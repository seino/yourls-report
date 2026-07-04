<?php

/**
 * 共通ユーティリティ関数
 */

/**
 * 日付形式を検証
 *
 * @param string $date 検証する日付文字列
 * @param string $format 期待する日付フォーマット
 * @return bool 有効な日付ならtrue
 */
function validateDate($date, $format = 'Y-m-d')
{
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

/**
 * 入力値をサニタイズ
 *
 * @param string $input サニタイズする入力値
 * @param int $maxLength 最大文字数
 * @return string サニタイズされた文字列
 */
function sanitizeInput($input, $maxLength = 255)
{
    $input = trim($input);
    $input = mb_substr($input, 0, $maxLength);
    return $input;
}

/**
 * データベース接続を作成
 *
 * @return PDO データベース接続オブジェクト
 * @throws PDOException 接続エラー時
 */
function createDatabaseConnection()
{
    return new PDO(
        'mysql:host=' . YOURLS_DB_HOST . ';dbname=' . YOURLS_DB_NAME . ';charset=utf8mb4',
        YOURLS_DB_USER,
        YOURLS_DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
        ]
    );
}

/**
 * データベース接続を取得（エラーハンドリング付き）
 *
 * @param bool $exitOnError エラー時にスクリプトを終了するか
 * @return PDO|null 接続成功時はPDOオブジェクト、失敗時はnull
 */
function getDatabaseConnection($exitOnError = true)
{
    try {
        return createDatabaseConnection();
    } catch (PDOException $e) {
        if (defined('IS_PRODUCTION') && IS_PRODUCTION) {
            error_log('[YOURLS Report] DB接続エラー: ' . $e->getMessage());
            if ($exitOnError) {
                die('データベース接続エラーが発生しました。');
            }
        } else {
            if ($exitOnError) {
                die('データベース接続エラー: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
            }
        }
        return null;
    }
}

/**
 * 共通セキュリティヘッダーを設定
 *
 * @param bool $allowExternalScripts 外部スクリプト（Chart.js等）を許可するか
 */
function setSecurityHeaders($allowExternalScripts = false)
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    if ($allowExternalScripts) {
        header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; connect-src 'self' https://cdn.jsdelivr.net");
    } else {
        header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; script-src 'self'");
    }
}

/**
 * 日付パラメータを検証・正規化
 *
 * @param string|null $startDate 開始日
 * @param string|null $endDate 終了日
 * @param int $defaultDays デフォルトの日数（開始日のデフォルト計算用）
 * @return array ['start_date' => string, 'end_date' => string, 'start_datetime' => string, 'end_datetime' => string]
 */
function normalizeDateRange($startDate = null, $endDate = null, $defaultDays = 30)
{
    $start = $startDate ?? date('Y-m-d', strtotime("-{$defaultDays} days"));
    $end = $endDate ?? date('Y-m-d');

    // 日付形式の検証
    if (!validateDate($start)) {
        $start = date('Y-m-d', strtotime("-{$defaultDays} days"));
    }
    if (!validateDate($end)) {
        $end = date('Y-m-d');
    }

    // 開始日が終了日より後の場合は入れ替え
    if (strtotime($start) > strtotime($end)) {
        list($start, $end) = [$end, $start];
    }

    return [
        'start_date' => $start,
        'end_date' => $end,
        'start_datetime' => $start . ' 00:00:00',
        'end_datetime' => $end . ' 23:59:59'
    ];
}

/**
 * 除外IPアドレスを取得
 *
 * @return string 除外IPアドレス（未設定の場合は空文字）
 */
function getExcludedIp()
{
    return defined('EXCLUDED_IP') ? EXCLUDED_IP : '';
}

/**
 * アプリケーションの共通初期化処理
 * エラー表示設定とタイムゾーンを一括設定する
 */
function initApplication()
{
    if (defined('IS_PRODUCTION') && IS_PRODUCTION) {
        error_reporting(0);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
    } else {
        error_reporting(E_ALL);
        ini_set('display_errors', '1');
    }

    date_default_timezone_set('Asia/Tokyo');
}

/**
 * リファラー分類のCASE WHEN SQL断片を返す
 *
 * @param string $alias カラムエイリアス名
 * @param bool $useJapanese 日本語ラベルを使用するか
 * @return string SQL CASE WHEN句
 */
function getReferrerCaseSql($alias = 'referrer_type', $useJapanese = false)
{
    $labels = $useJapanese
        ? ['direct' => 'ダイレクト', 'google' => 'Google', 'facebook' => 'Facebook', 'twitter' => 'Twitter', 'line' => 'LINE', 'instagram' => 'Instagram', 'other' => 'その他']
        : ['direct' => 'direct', 'google' => 'google', 'facebook' => 'facebook', 'twitter' => 'twitter', 'line' => 'line', 'instagram' => 'instagram', 'other' => 'other'];

    $case = "CASE
                WHEN referrer = 'direct' THEN '{$labels['direct']}'
                WHEN referrer LIKE '%google%' THEN '{$labels['google']}'
                WHEN referrer LIKE '%facebook%' THEN '{$labels['facebook']}'
                WHEN referrer LIKE '%twitter%' OR referrer LIKE '%t.co%' THEN '{$labels['twitter']}'
                WHEN referrer LIKE '%line%' THEN '{$labels['line']}'
                WHEN referrer LIKE '%instagram%' THEN '{$labels['instagram']}'
                ELSE '{$labels['other']}'
            END";

    return $alias !== '' ? $case . " as {$alias}" : $case;
}

/**
 * HTTPSで接続されているかどうかを判定
 * リバースプロキシ（Nginx、AWS ALB等）にも対応
 *
 * @return bool HTTPS接続ならtrue
 */
function isHttps()
{
    // 直接HTTPS接続
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    // リバースプロキシ経由（X-Forwarded-Proto）
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
        return true;
    }

    // リバースプロキシ経由（X-Forwarded-SSL）
    if (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') {
        return true;
    }

    // ポート443
    if (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) {
        return true;
    }

    return false;
}

/**
 * href属性に安全に出力できるURLへ整形する
 * javascript: / data: 等の危険なスキームによる格納型XSSを防止する
 *
 * @param string $url DB等に由来するURL
 * @return string http/https の絶対URLまたは相対URLならそのまま、危険なら '#'
 */
function safeUrl($url)
{
    $url = trim((string) $url);
    if ($url === '') {
        return '#';
    }

    // スキーム付きURLは http / https のみ許可
    if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.-]*):/', $url, $matches)) {
        $scheme = strtolower($matches[1]);
        return in_array($scheme, ['http', 'https'], true) ? $url : '#';
    }

    // スキームなし（相対URL・プロトコル相対URL）はそのまま許可
    return $url;
}

/**
 * CSVフィールドのフォーミュラインジェクションを無害化する
 * Excel/Sheetsで数式として実行される先頭文字をエスケープする
 *
 * @param string|null $value CSVに出力する値
 * @return string 無害化された文字列
 */
function sanitizeCsvField($value)
{
    $value = (string) $value;
    if ($value === '') {
        return $value;
    }

    // 数式として解釈され得る先頭文字はシングルクォートで無害化
    if (in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $value;
    }

    return $value;
}

/**
 * 安全なリダイレクトURLかどうかを検証
 * オープンリダイレクト攻撃を防止
 *
 * @param string $url 検証するURL
 * @return bool 安全ならtrue
 */
function isSafeRedirectUrl($url)
{
    // 空の場合は安全ではない
    if (empty($url)) {
        return false;
    }

    // プロトコル相対URL（//example.com）をブロック
    if (strpos($url, '//') === 0) {
        return false;
    }

    // 絶対URL（http://、https://等）をブロック
    if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $url)) {
        return false;
    }

    // 相対パス（/で始まる）のみ許可
    if (strpos($url, '/') !== 0) {
        return false;
    }

    return true;
}
