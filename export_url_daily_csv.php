<?php

/**
 * URL別日別データCSVエクスポート
 */

// 認証チェック
require_once __DIR__ . '/auth.php';
requireAuth();

// 設定ファイルとユーティリティはauth.phpで読み込み済み

// セキュリティヘッダー
setSecurityHeaders(false);

// 共通初期化
initApplication();

// データベース接続
$pdo = getDatabaseConnection();

// パラメータ取得
$keyword = sanitizeInput($_GET['keyword'] ?? '', MAX_KEYWORD_LENGTH);

if (empty($keyword)) {
    header('Location: yourls_report.php', true, 302);
    exit;
}

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

// 日別データ取得
$sql = "SELECT
            DATE(click_time) as date,
            COUNT(*) as clicks
        FROM " . YOURLS_DB_PREFIX . "log
        WHERE shorturl = :keyword
          AND click_time BETWEEN :start AND :end";

$params = [
    'keyword' => $keyword,
    'start' => $start_datetime,
    'end' => $end_datetime,
];
$sql .= excludedIpClause($excluded_ip);
if ($excluded_ip !== '') {
    $params['excluded_ip'] = $excluded_ip;
}

$sql .= " GROUP BY DATE(click_time)
        ORDER BY date ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$daily_stats = $stmt->fetchAll();

// CSVヘッダー
$safe_keyword = preg_replace('/[^a-zA-Z0-9_-]/', '_', $keyword);
$filename = 'daily_' . $safe_keyword . '_' . $start_date . '_' . $end_date . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// BOM for Excel
echo "\xEF\xBB\xBF";

// CSV出力
$output = fopen('php://output', 'w');

// ヘッダー行
fputcsv($output, ['日付', '曜日', 'クリック数']);

// データ行
$weekdays = ['日', '月', '火', '水', '木', '金', '土'];
foreach ($daily_stats as $row) {
    $w = date('w', strtotime($row['date']));
    fputcsv($output, [
        $row['date'],
        $weekdays[$w],
        $row['clicks']
    ]);
}

fclose($output);
exit;
