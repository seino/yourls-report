<?php

/**
 * URL詳細ページ - 日別クリック数表示
 */

// 認証チェック
require_once __DIR__ . '/auth.php';
requireAuth();

// 設定ファイルとユーティリティはauth.phpで読み込み済み

// セキュリティヘッダー（外部スクリプトChart.js使用のため true）
setSecurityHeaders(true);

// エラー表示設定
if (defined('IS_PRODUCTION') && IS_PRODUCTION) {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
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
$dateRange = normalizeDateRange(
    $_GET['start_date'] ?? null,
    $_GET['end_date'] ?? null
);
$start_date = $dateRange['start_date'];
$end_date = $dateRange['end_date'];
$start_datetime = $dateRange['start_datetime'];
$end_datetime = $dateRange['end_datetime'];

// キーワードが指定されていない場合
if (empty($keyword)) {
    header('Location: yourls_report.php');
    exit;
}

/**
 * URL情報を取得
 */
function getUrlInfo($pdo, $keyword)
{
    $sql = "SELECT keyword, url, title, timestamp, clicks
            FROM " . YOURLS_DB_PREFIX . "url
            WHERE keyword = :keyword";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['keyword' => $keyword]);
    return $stmt->fetch();
}

/**
 * 日別クリック数を取得
 */
function getDailyClicksByUrl($pdo, $keyword, $start, $end)
{
    $sql = "SELECT
                DATE(click_time) as date,
                COUNT(*) as clicks
            FROM " . YOURLS_DB_PREFIX . "log
            WHERE shorturl = :keyword
              AND click_time BETWEEN :start AND :end
              AND ip_address != :excluded_ip
            GROUP BY DATE(click_time)
            ORDER BY date DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'keyword' => $keyword,
        'start' => $start,
        'end' => $end,
        'excluded_ip' => EXCLUDED_IP
    ]);
    return $stmt->fetchAll();
}

/**
 * 期間内の総クリック数を取得
 */
function getTotalClicksByUrl($pdo, $keyword, $start, $end)
{
    $sql = "SELECT COUNT(*) as total
            FROM " . YOURLS_DB_PREFIX . "log
            WHERE shorturl = :keyword
              AND click_time BETWEEN :start AND :end
              AND ip_address != :excluded_ip";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'keyword' => $keyword,
        'start' => $start,
        'end' => $end,
        'excluded_ip' => EXCLUDED_IP
    ]);
    return $stmt->fetch()['total'];
}

/**
 * リファラー別集計を取得
 */
function getReferrersByUrl($pdo, $keyword, $start, $end)
{
    $sql = "SELECT
                CASE
                    WHEN referrer = 'direct' THEN 'ダイレクト'
                    WHEN referrer LIKE '%google%' THEN 'Google'
                    WHEN referrer LIKE '%facebook%' THEN 'Facebook'
                    WHEN referrer LIKE '%twitter%' OR referrer LIKE '%t.co%' THEN 'Twitter'
                    WHEN referrer LIKE '%line%' THEN 'LINE'
                    WHEN referrer LIKE '%instagram%' THEN 'Instagram'
                    ELSE 'その他'
                END as referrer_type,
                COUNT(*) as clicks
            FROM " . YOURLS_DB_PREFIX . "log
            WHERE shorturl = :keyword
              AND click_time BETWEEN :start AND :end
              AND ip_address != :excluded_ip
            GROUP BY referrer_type
            ORDER BY clicks DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'keyword' => $keyword,
        'start' => $start,
        'end' => $end,
        'excluded_ip' => EXCLUDED_IP
    ]);
    return $stmt->fetchAll();
}

// データ取得
$url_info = getUrlInfo($pdo, $keyword);
if (!$url_info) {
    die('指定されたURLが見つかりません。');
}

$daily_clicks = getDailyClicksByUrl($pdo, $keyword, $start_datetime, $end_datetime);
$total_clicks = getTotalClicksByUrl($pdo, $keyword, $start_datetime, $end_datetime);
$referrers = getReferrersByUrl($pdo, $keyword, $start_datetime, $end_datetime);

// 戻りリンク用パラメータ
$back_params = http_build_query([
    'start_date' => $start_date,
    'end_date' => $end_date
]);
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($url_info['title'] ?: $keyword) ?> - URL詳細</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Roboto', sans-serif;
            background: #fafafa;
            color: rgba(0, 0, 0, 0.87);
            line-height: 1.5;
            padding: 24px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .card {
            background: #fff;
            border-radius: 4px;
            box-shadow: 0 2px 1px -1px rgba(0, 0, 0, 0.2),
                0 1px 1px 0 rgba(0, 0, 0, 0.14),
                0 1px 3px 0 rgba(0, 0, 0, 0.12);
            margin-bottom: 24px;
            overflow: hidden;
        }

        .card-elevated {
            box-shadow: 0 3px 5px -1px rgba(0, 0, 0, 0.2),
                0 6px 10px 0 rgba(0, 0, 0, 0.14),
                0 1px 18px 0 rgba(0, 0, 0, 0.12);
        }

        header {
            background: #1976d2;
            color: #fff;
            padding: 24px 32px;
        }

        h1 {
            font-size: 20px;
            font-weight: 400;
            margin-bottom: 8px;
            word-break: break-all;
        }

        .url-display {
            font-size: 14px;
            opacity: 0.87;
            word-break: break-all;
        }

        .url-display a {
            color: #fff;
            text-decoration: none;
        }

        .url-display a:hover {
            text-decoration: underline;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            margin-bottom: 16px;
            color: #1976d2;
            text-decoration: none;
            font-weight: 500;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .content {
            padding: 24px 32px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: #f5f5f5;
            padding: 24px;
            border-radius: 4px;
            box-shadow: 0 2px 1px -1px rgba(0, 0, 0, 0.2),
                0 1px 1px 0 rgba(0, 0, 0, 0.14),
                0 1px 3px 0 rgba(0, 0, 0, 0.12);
        }

        .stat-value {
            font-size: 34px;
            font-weight: 400;
            color: #1976d2;
            margin: 12px 0;
        }

        .stat-label {
            font-size: 12px;
            font-weight: 500;
            color: rgba(0, 0, 0, 0.6);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .section {
            margin-bottom: 32px;
        }

        h2 {
            font-size: 20px;
            font-weight: 500;
            margin-bottom: 16px;
            color: rgba(0, 0, 0, 0.87);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 16px;
            text-align: left;
            border-bottom: 1px solid rgba(0, 0, 0, 0.12);
        }

        th {
            font-size: 12px;
            font-weight: 500;
            color: rgba(0, 0, 0, 0.6);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        tr:hover {
            background: rgba(0, 0, 0, 0.04);
        }

        .chart-container {
            padding: 24px;
            background: #fafafa;
            border-radius: 4px;
        }

        .bar {
            display: flex;
            align-items: center;
            margin: 12px 0;
        }

        .bar-label {
            width: 120px;
            font-size: 14px;
            font-weight: 400;
            color: rgba(0, 0, 0, 0.87);
        }

        .bar-fill {
            height: 32px;
            background: #1976d2;
            border-radius: 4px;
            display: flex;
            align-items: center;
            padding: 0 12px;
            color: #fff;
            font-weight: 500;
            font-size: 14px;
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .period-info {
            font-size: 14px;
            color: rgba(0, 0, 0, 0.6);
            margin-bottom: 16px;
        }

        .export-btn {
            display: inline-flex;
            align-items: center;
            padding: 0 16px;
            height: 36px;
            background: #4caf50;
            color: #fff;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: background 0.2s, box-shadow 0.2s;
            box-shadow: 0 3px 1px -2px rgba(0, 0, 0, 0.2),
                0 2px 2px 0 rgba(0, 0, 0, 0.14),
                0 1px 5px 0 rgba(0, 0, 0, 0.12);
        }

        .export-btn:hover {
            background: #43a047;
            box-shadow: 0 2px 4px -1px rgba(0, 0, 0, 0.2),
                0 4px 5px 0 rgba(0, 0, 0, 0.14),
                0 1px 10px 0 rgba(0, 0, 0, 0.12);
        }

        @media (max-width: 768px) {
            body {
                padding: 16px;
            }

            .content {
                padding: 16px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            header {
                padding: 16px;
            }

            th,
            td {
                padding: 12px 8px;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <a href="yourls_report.php?<?= htmlspecialchars($back_params) ?>" class="back-link">← レポートに戻る</a>

        <div class="card card-elevated">
            <header>
                <h1><?= htmlspecialchars($url_info['title'] ?: $keyword) ?></h1>
                <div class="url-display">
                    <a href="<?= htmlspecialchars($url_info['url']) ?>" target="_blank">
                        <?= htmlspecialchars($url_info['url']) ?>
                    </a>
                </div>
            </header>

            <div class="content">
                <div class="period-info">
                    期間: <?= htmlspecialchars($start_date) ?> 〜 <?= htmlspecialchars($end_date) ?>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-label">期間内クリック数</div>
                        <div class="stat-value"><?= number_format($total_clicks) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">総クリック数</div>
                        <div class="stat-value"><?= number_format($url_info['clicks']) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">キーワード</div>
                        <div class="stat-value" style="font-size: 20px;"><?= htmlspecialchars($keyword) ?></div>
                    </div>
                </div>

                <?php if (!empty($referrers)): ?>
                    <div class="section">
                        <h2>流入元</h2>
                        <div class="chart-container">
                            <?php
                            $max_referrer = max(array_column($referrers, 'clicks'));
                            foreach ($referrers as $row):
                                $percentage = ($row['clicks'] / $max_referrer) * 100;
                            ?>
                                <div class="bar">
                                    <div class="bar-label"><?= htmlspecialchars($row['referrer_type']) ?></div>
                                    <div class="bar-fill" style="width: <?= $percentage ?>%; min-width: 80px;">
                                        <?= number_format($row['clicks']) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="section">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                        <h2 style="margin-bottom: 0;">日別クリック数</h2>
                        <?php if (!empty($daily_clicks)): ?>
                            <a href="export_url_daily_csv.php?keyword=<?= urlencode($keyword) ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>" class="export-btn">CSV出力</a>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($daily_clicks)): ?>

                        <!-- 折れ線グラフ -->
                        <div class="chart-container" style="margin-bottom: 24px; height: 300px; position: relative;">
                            <canvas id="dailyChart"></canvas>
                        </div>

                        <table>
                            <thead>
                                <tr>
                                    <th>日付</th>
                                    <th style="width: 150px;">クリック数</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $weekdays = ['日', '月', '火', '水', '木', '金', '土'];
                                foreach ($daily_clicks as $row):
                                    $w = date('w', strtotime($row['date']));
                                ?>
                                    <tr>
                                        <td><?= date('Y年m月d日', strtotime($row['date'])) ?> (<?= $weekdays[$w] ?>)</td>
                                        <td><strong><?= number_format($row['clicks']) ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p style="color: rgba(0,0,0,0.6); padding: 24px 0;">この期間内のクリックはありません。</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($daily_clicks)): ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const canvas = document.getElementById('dailyChart');
                if (!canvas) return;

                const ctx = canvas.getContext('2d');
                const dailyData = <?= json_encode(array_reverse($daily_clicks)) ?>;

                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: dailyData.map(d => d.date),
                        datasets: [{
                            label: 'クリック数',
                            data: dailyData.map(d => parseInt(d.clicks, 10)),
                            borderColor: '#1976d2',
                            backgroundColor: 'rgba(25, 118, 210, 0.1)',
                            fill: true,
                            tension: 0.3,
                            pointRadius: 4,
                            pointHoverRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1
                                }
                            }
                        }
                    }
                });
            });
        </script>
    <?php endif; ?>
</body>

</html>