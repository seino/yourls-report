<?php

/**
 * YOURLS データCSV出力
 */

// 認証チェック
require_once __DIR__ . '/auth.php';
requireAuth();

// 設定ファイルとユーティリティはauth.phpで読み込み済み

// 共通初期化
initApplication();

// データベース接続
$pdo = getDatabaseConnection();

// パラメータ取得
$allowed_types = ['summary', 'detail', 'daily'];
$export_type = in_array($_GET['type'] ?? '', $allowed_types, true) ? $_GET['type'] : 'summary';
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

// ファイル名生成
$filename = 'yourls_' . $export_type . '_' . str_replace('-', '', $start_date) . '-' . str_replace('-', '', $end_date) . '.csv';

// ヘッダー設定
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// UTF-8 BOM追加（Excelで正しく表示されるため）
echo "\xEF\xBB\xBF";

// 出力バッファリング
$output = fopen('php://output', 'w');

if ($export_type === 'detail') {
    // 詳細ログの出力
    fputcsv($output, [
        'keyword',
        'shorturl',
        'url',
        'title',
        'click_time',
        'referrer',
        'user_agent',
        'ip_address',
        'country_code',
        'user'
    ]);

    $sql = "SELECT
                l.shorturl,
                u.keyword,
                u.url,
                u.title,
                l.click_time,
                l.referrer,
                l.user_agent,
                l.ip_address,
                l.country_code,
                u.user
            FROM " . YOURLS_DB_PREFIX . "log l
            LEFT JOIN " . YOURLS_DB_PREFIX . "url u ON l.shorturl = u.keyword
            WHERE l.click_time BETWEEN :start AND :end";

    if ($excluded_ip) {
        $sql .= " AND l.ip_address != :excluded_ip";
    }

    $sql .= " ORDER BY l.click_time DESC";

    $stmt = $pdo->prepare($sql);
    $params = ['start' => $start_datetime, 'end' => $end_datetime];
    if ($excluded_ip) {
        $params['excluded_ip'] = $excluded_ip;
    }
    $stmt->execute($params);

    while ($row = $stmt->fetch()) {
        fputcsv($output, [
            sanitizeCsvField($row['keyword'] ?: $row['shorturl']),
            sanitizeCsvField($row['shorturl']),
            sanitizeCsvField($row['url']),
            sanitizeCsvField($row['title']),
            $row['click_time'],
            sanitizeCsvField($row['referrer']),
            sanitizeCsvField($row['user_agent']),
            sanitizeCsvField($row['ip_address']),
            $row['country_code'],
            sanitizeCsvField($row['user'])
        ]);
    }
} elseif ($export_type === 'daily') {
    // 日別集計の出力
    fputcsv($output, [
        '日付',
        'クリック数',
        'ユニークURL数',
        'ユニークIP数'
    ]);

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

    while ($row = $stmt->fetch()) {
        fputcsv($output, [
            $row['date'],
            $row['clicks'],
            $row['unique_urls'],
            $row['unique_ips']
        ]);
    }
} else {
    // サマリーの出力（デフォルト）
    fputcsv($output, [
        'keyword',
        'url',
        'title',
        'クリック数',
        '初回クリック',
        '最終クリック',
        'リファラー内訳'
    ]);

    $sql = "SELECT
                l.shorturl,
                u.keyword,
                u.url,
                u.title,
                COUNT(*) as click_count,
                MIN(l.click_time) as first_click,
                MAX(l.click_time) as last_click,
                GROUP_CONCAT(
                    DISTINCT
                    " . getReferrerCaseSql('', true) . "
                    SEPARATOR ', '
                ) as referrers
            FROM " . YOURLS_DB_PREFIX . "log l
            LEFT JOIN " . YOURLS_DB_PREFIX . "url u ON l.shorturl = u.keyword
            WHERE l.click_time BETWEEN :start AND :end";

    if ($excluded_ip) {
        $sql .= " AND l.ip_address != :excluded_ip";
    }

    $sql .= " GROUP BY l.shorturl, u.keyword, u.url, u.title
            ORDER BY click_count DESC";

    $stmt = $pdo->prepare($sql);
    $params = ['start' => $start_datetime, 'end' => $end_datetime];
    if ($excluded_ip) {
        $params['excluded_ip'] = $excluded_ip;
    }
    $stmt->execute($params);

    while ($row = $stmt->fetch()) {
        fputcsv($output, [
            sanitizeCsvField($row['keyword'] ?: $row['shorturl']),
            sanitizeCsvField($row['url']),
            sanitizeCsvField($row['title']),
            $row['click_count'],
            $row['first_click'],
            $row['last_click'],
            sanitizeCsvField($row['referrers'])
        ]);
    }
}

fclose($output);
exit;
