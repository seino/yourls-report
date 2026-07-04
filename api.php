<?php

/**
 * YOURLS 集計データ JSON API
 *
 * 使用例:
 * - 基本統計: api.php?action=stats&start_date=2025-11-10&end_date=2025-12-10
 * - トップURL: api.php?action=top_urls&start_date=2025-11-10&end_date=2025-12-10&limit=20
 * - 日別推移: api.php?action=daily&start_date=2025-11-10&end_date=2025-12-10
 * - リファラー: api.php?action=referrers&start_date=2025-11-10&end_date=2025-12-10
 *
 * 認証: APIキーをリクエストヘッダーで送信
 * - ヘッダー: X-API-Key: your_api_key
 * ログインが有効な場合（REQUIRE_LOGIN=true）はログインセッションでもアクセス可能。
 */

// 設定ファイル読み込み
$config_file = __DIR__ . '/config.php';
if (!file_exists($config_file)) {
    http_response_code(500);
    echo json_encode(['error' => '設定ファイルが見つかりません']);
    exit;
}
require_once $config_file;

// 共通ユーティリティ・認証処理読み込み
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/auth.php';

// 共通初期化
initApplication();

// CORS設定（本番環境では適切なオリジンに制限してください）
$allowed_origin = defined('API_ALLOWED_ORIGIN') ? API_ALLOWED_ORIGIN : '*';
header('Access-Control-Allow-Origin: ' . $allowed_origin);
header('Content-Type: application/json; charset=UTF-8');

// デフォルト設定値
if (!defined('API_RATE_LIMIT')) {
    define('API_RATE_LIMIT', 60); // 1分あたりのリクエスト数
}
if (!defined('API_RATE_WINDOW')) {
    define('API_RATE_WINDOW', 60); // レート制限のウィンドウ（秒）
}

/**
 * レスポンスを返す
 */
function sendResponse($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * エラーレスポンスを返す
 */
function sendError($message, $status = 400)
{
    sendResponse(['error' => $message], $status);
}

/**
 * レート制限をチェック
 */
function checkRateLimit()
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rate_file = sys_get_temp_dir() . '/yourls_report_rate_' . md5($ip) . '.json';

    $now = time();
    $data = ['requests' => [], 'blocked_until' => 0];

    if (file_exists($rate_file)) {
        $content = file_get_contents($rate_file);
        $data = json_decode($content, true) ?: $data;
    }

    // ブロック中かチェック
    if ($data['blocked_until'] > $now) {
        $remaining = $data['blocked_until'] - $now;
        header('Retry-After: ' . $remaining);
        sendError("レート制限を超過しました。{$remaining}秒後に再試行してください。", 429);
    }

    // 古いリクエストを削除
    $window_start = $now - API_RATE_WINDOW;
    $data['requests'] = array_filter($data['requests'], function ($timestamp) use ($window_start) {
        return $timestamp > $window_start;
    });

    // リクエスト数をチェック
    if (count($data['requests']) >= API_RATE_LIMIT) {
        $data['blocked_until'] = $now + API_RATE_WINDOW;
        file_put_contents($rate_file, json_encode($data));
        header('Retry-After: ' . API_RATE_WINDOW);
        sendError("レート制限を超過しました。" . API_RATE_WINDOW . "秒後に再試行してください。", 429);
    }

    // 現在のリクエストを記録
    $data['requests'][] = $now;
    file_put_contents($rate_file, json_encode($data));

    // レート制限ヘッダーを追加
    $remaining = API_RATE_LIMIT - count($data['requests']);
    header('X-RateLimit-Limit: ' . API_RATE_LIMIT);
    header('X-RateLimit-Remaining: ' . $remaining);
    header('X-RateLimit-Reset: ' . ($now + API_RATE_WINDOW));
}

// レート制限チェック
checkRateLimit();

// API認証（フェイルクローズ: 有効なAPIキー or ログインセッションが必須）
$authenticated = false;

// APIキー認証（設定されている場合のみ・ヘッダーのみ受理）
if (defined('API_KEY') && API_KEY !== '') {
    $provided_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (hash_equals(API_KEY, $provided_key)) {
        $authenticated = true;
    }
}

// ログインセッション認証（REQUIRE_LOGIN=true の場合のみ有効）
if (!$authenticated && isLoginRequired() && isLoggedIn()) {
    $authenticated = true;
}

if (!$authenticated) {
    sendError('認証エラー: 有効なAPIキーまたはログインが必要です', 401);
}

// データベース接続
try {
    $pdo = createDatabaseConnection();
} catch (PDOException $e) {
    if (defined('IS_PRODUCTION') && IS_PRODUCTION) {
        error_log('API DB Error: ' . $e->getMessage());
        sendError('データベース接続エラー', 500);
    } else {
        sendError('データベース接続エラー: ' . $e->getMessage(), 500);
    }
}

// パラメータ取得
$action = $_GET['action'] ?? 'stats';
$limit = filter_var($_GET['limit'] ?? 20, FILTER_VALIDATE_INT, [
    'options' => ['default' => 20, 'min_range' => 1, 'max_range' => 100]
]);

// 日付パラメータの検証と正規化
$dateRange = normalizeDateRange(
    $_GET['start_date'] ?? null,
    $_GET['end_date'] ?? null
);
$start_date = $dateRange['start_date'];
$end_date = $dateRange['end_date'];
$start_datetime = $dateRange['start_datetime'];
$end_datetime = $dateRange['end_datetime'];

// 除外IP
$excluded_ip = getExcludedIp();

// アクション処理
switch ($action) {
    case 'stats':
        // 基本統計
        $sql = "SELECT
                    COUNT(*) as total_clicks,
                    COUNT(DISTINCT shorturl) as unique_urls,
                    COUNT(DISTINCT ip_address) as unique_ips
                FROM " . YOURLS_DB_PREFIX . "log
                WHERE click_time BETWEEN :start AND :end";

        if ($excluded_ip) {
            $sql .= " AND ip_address != :excluded_ip";
        }

        $stmt = $pdo->prepare($sql);
        $params = ['start' => $start_datetime, 'end' => $end_datetime];
        if ($excluded_ip) {
            $params['excluded_ip'] = $excluded_ip;
        }
        $stmt->execute($params);
        $stats = $stmt->fetch();

        // 日数計算
        $start_dt = new DateTime($start_date);
        $end_dt = new DateTime($end_date);
        $days = $end_dt->diff($start_dt)->days + 1;
        $stats['days'] = $days;
        $stats['avg_per_day'] = round($stats['total_clicks'] / $days, 2);

        sendResponse($stats);
        break;

    case 'top_urls':
        // トップURL
        $sql = "SELECT
                    l.shorturl,
                    u.keyword,
                    u.url,
                    u.title,
                    COUNT(*) as click_count,
                    MIN(l.click_time) as first_click,
                    MAX(l.click_time) as last_click
                FROM " . YOURLS_DB_PREFIX . "log l
                LEFT JOIN " . YOURLS_DB_PREFIX . "url u ON l.shorturl = u.keyword
                WHERE l.click_time BETWEEN :start AND :end";

        if ($excluded_ip) {
            $sql .= " AND l.ip_address != :excluded_ip";
        }

        $sql .= " GROUP BY l.shorturl, u.keyword, u.url, u.title
                ORDER BY click_count DESC
                LIMIT :limit";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':start', $start_datetime);
        $stmt->bindValue(':end', $end_datetime);
        if ($excluded_ip) {
            $stmt->bindValue(':excluded_ip', $excluded_ip);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $urls = $stmt->fetchAll();

        sendResponse($urls);
        break;

    case 'daily':
        // 日別推移
        $sql = "SELECT
                    DATE(click_time) as date,
                    COUNT(*) as clicks,
                    COUNT(DISTINCT shorturl) as unique_urls,
                    COUNT(DISTINCT ip_address) as unique_ips
                FROM " . YOURLS_DB_PREFIX . "log
                WHERE click_time BETWEEN :start AND :end";

        if ($excluded_ip) {
            $sql .= " AND ip_address != :excluded_ip";
        }

        $sql .= " GROUP BY DATE(click_time)
                ORDER BY date ASC";

        $stmt = $pdo->prepare($sql);
        $params = ['start' => $start_datetime, 'end' => $end_datetime];
        if ($excluded_ip) {
            $params['excluded_ip'] = $excluded_ip;
        }
        $stmt->execute($params);
        $daily = $stmt->fetchAll();

        sendResponse($daily);
        break;

    case 'referrers':
        // リファラー統計
        $base_where = "click_time BETWEEN :start AND :end";
        if ($excluded_ip) {
            $base_where .= " AND ip_address != :excluded_ip";
        }

        $sql = "SELECT
                    " . getReferrerCaseSql('referrer_type', false) . ",
                    COUNT(*) as clicks
                FROM " . YOURLS_DB_PREFIX . "log
                WHERE {$base_where}
                GROUP BY referrer_type
                ORDER BY clicks DESC";

        $stmt = $pdo->prepare($sql);
        $params = ['start' => $start_datetime, 'end' => $end_datetime];
        if ($excluded_ip) {
            $params['excluded_ip'] = $excluded_ip;
        }
        $stmt->execute($params);
        $referrers = $stmt->fetchAll();

        sendResponse($referrers);
        break;

    case 'countries':
        // 国別統計
        $sql = "SELECT
                    country_code,
                    COUNT(*) as clicks
                FROM " . YOURLS_DB_PREFIX . "log
                WHERE click_time BETWEEN :start AND :end";

        if ($excluded_ip) {
            $sql .= " AND ip_address != :excluded_ip";
        }

        $sql .= " GROUP BY country_code
                ORDER BY clicks DESC
                LIMIT :limit";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':start', $start_datetime);
        $stmt->bindValue(':end', $end_datetime);
        if ($excluded_ip) {
            $stmt->bindValue(':excluded_ip', $excluded_ip);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $countries = $stmt->fetchAll();

        sendResponse($countries);
        break;

    case 'url_detail':
        // 特定URLの詳細
        $keyword = sanitizeInput($_GET['keyword'] ?? '', 50);
        if (empty($keyword)) {
            sendError('keywordパラメータが必要です');
        }

        // URL基本情報
        $sql = "SELECT * FROM " . YOURLS_DB_PREFIX . "url WHERE keyword = :keyword";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['keyword' => $keyword]);
        $url_info = $stmt->fetch();

        if (!$url_info) {
            sendError('URLが見つかりません', 404);
        }

        // クリック統計
        $sql = "SELECT
                    COUNT(*) as total_clicks,
                    MIN(click_time) as first_click,
                    MAX(click_time) as last_click,
                    COUNT(DISTINCT ip_address) as unique_ips
                FROM " . YOURLS_DB_PREFIX . "log
                WHERE shorturl = :keyword
                AND click_time BETWEEN :start AND :end";

        if ($excluded_ip) {
            $sql .= " AND ip_address != :excluded_ip";
        }

        $stmt = $pdo->prepare($sql);
        $params = [
            'keyword' => $keyword,
            'start' => $start_datetime,
            'end' => $end_datetime
        ];
        if ($excluded_ip) {
            $params['excluded_ip'] = $excluded_ip;
        }
        $stmt->execute($params);
        $stats = $stmt->fetch();

        // 日別推移
        $sql = "SELECT
                    DATE(click_time) as date,
                    COUNT(*) as clicks
                FROM " . YOURLS_DB_PREFIX . "log
                WHERE shorturl = :keyword
                AND click_time BETWEEN :start AND :end";

        if ($excluded_ip) {
            $sql .= " AND ip_address != :excluded_ip";
        }

        $sql .= " GROUP BY DATE(click_time)
                ORDER BY date ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $daily = $stmt->fetchAll();

        sendResponse([
            'url_info' => $url_info,
            'stats' => $stats,
            'daily' => $daily
        ]);
        break;

    case 'realtime':
        // リアルタイム（直近1時間）
        $sql = "SELECT
                    l.shorturl,
                    u.title,
                    l.click_time,
                    l.country_code
                FROM " . YOURLS_DB_PREFIX . "log l
                LEFT JOIN " . YOURLS_DB_PREFIX . "url u ON l.shorturl = u.keyword
                WHERE l.click_time >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";

        if ($excluded_ip) {
            $sql .= " AND l.ip_address != :excluded_ip";
        }

        $sql .= " ORDER BY l.click_time DESC
                LIMIT 50";

        $stmt = $pdo->prepare($sql);
        if ($excluded_ip) {
            $stmt->execute(['excluded_ip' => $excluded_ip]);
        } else {
            $stmt->execute();
        }
        $recent = $stmt->fetchAll();

        sendResponse($recent);
        break;

    default:
        sendError('無効なアクションです。利用可能: stats, top_urls, daily, referrers, countries, url_detail, realtime');
}
