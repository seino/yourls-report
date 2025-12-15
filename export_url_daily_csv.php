<?php

/**
 * URL別日別データCSVエクスポート
 */

// 認証チェック
require_once __DIR__ . '/auth.php';
requireAuth();

// 設定ファイルとユーティリティはauth.phpで読み込み済み

// エラー表示設定
if (defined('IS_PRODUCTION') && IS_PRODUCTION) {
    error_reporting(0);
    ini_set('display_errors', 0);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// タイムゾーン設定
date_default_timezone_set('Asia/Tokyo');

// データベース接続
$pdo = getDatabaseConnection();

// パラメータ取得
$keyword = sanitizeInput($_GET['keyword'] ?? '', 50);

if (empty($keyword)) {
    die('キーワードが指定されていません。');
}

$dateRange = normalizeDateRange(
    $_GET['start_date'] ?? null,
    $_GET['end_date'] ?? null
);
$start_date = $dateRange['start_date'];
$end_date = $dateRange['end_date'];
$start_datetime = $dateRange['start_datetime'];
$end_datetime = $dateRange['end_datetime'];

// 日別データ取得
$sql = "SELECT
            DATE(click_time) as date,
            COUNT(*) as clicks
        FROM " . YOURLS_DB_PREFIX . "log
        WHERE shorturl = :keyword
          AND click_time BETWEEN :start AND :end
          AND ip_address != :excluded_ip
        GROUP BY DATE(click_time)
        ORDER BY date ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    'keyword' => $keyword,
    'start' => $start_datetime,
    'end' => $end_datetime,
    'excluded_ip' => EXCLUDED_IP
]);
$daily_stats = $stmt->fetchAll();

// CSVヘッダー
$filename = 'daily_' . $keyword . '_' . $start_date . '_' . $end_date . '.csv';
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
