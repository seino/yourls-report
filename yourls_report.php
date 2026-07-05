<?php

/**
 * YOURLS 集計レポートシステム
 */

// 認証チェック
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/StatsRepository.php';
requireAuth();

// 設定ファイルとユーティリティはauth.phpで読み込み済み

// セキュリティヘッダー
setSecurityHeaders(false);

// 共通初期化
initApplication();

// データベース接続
$pdo = getDatabaseConnection();

// パラメータ取得とバリデーション
$dateRange = normalizeDateRange(
    $_GET['start_date'] ?? null,
    $_GET['end_date'] ?? null
);
$start_date = $dateRange['start_date'];
$end_date = $dateRange['end_date'];
$start_datetime = $dateRange['start_datetime'];
$end_datetime = $dateRange['end_datetime'];

// 表示件数の検証（MIN_PER_PAGE〜MAX_PER_PAGEの範囲）
$per_page = filter_input(INPUT_GET, 'per_page', FILTER_VALIDATE_INT, [
    'options' => ['default' => DEFAULT_PER_PAGE, 'min_range' => MIN_PER_PAGE, 'max_range' => MAX_PER_PAGE]
]);

// ページ番号の検証
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, [
    'options' => ['default' => 1, 'min_range' => 1]
]);

// 検索キーワードのサニタイズ
$search_keyword = sanitizeInput($_GET['search_keyword'] ?? '', MAX_SEARCH_KEYWORD_LENGTH);

// 除外IP
$excluded_ip = getExcludedIp();

// 集計リポジトリ
$repo = new StatsRepository($pdo, YOURLS_DB_PREFIX, $excluded_ip);

// データ取得
$basic_stats = $repo->getBasicStats($start_datetime, $end_datetime);
$total_urls = $repo->getTopUrlsCount($start_datetime, $end_datetime, $search_keyword);
$total_pages = ceil($total_urls / $per_page);
if ($page > $total_pages && $total_pages > 0) $page = $total_pages;
$offset = ($page - 1) * $per_page;
$top_urls = $repo->getTopUrls($start_datetime, $end_datetime, $per_page, $offset, $search_keyword);
$country_stats = $repo->getCountryStats($start_datetime, $end_datetime);
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>YOURLS 集計レポート</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
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
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Material Card */
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
            font-size: 24px;
            font-weight: 400;
            margin-bottom: 4px;
        }

        header p {
            font-size: 14px;
            opacity: 0.87;
        }

        /* Filter Form */
        .filter-form {
            padding: 24px 32px;
            display: flex;
            gap: 24px;
            align-items: flex-end;
            flex-wrap: wrap;
            border-bottom: 1px solid rgba(0, 0, 0, 0.12);
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        label {
            font-size: 12px;
            font-weight: 500;
            color: rgba(0, 0, 0, 0.6);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        input[type="date"],
        input[type="number"],
        input[type="text"] {
            padding: 12px 16px;
            border: 1px solid rgba(0, 0, 0, 0.23);
            border-radius: 4px;
            font-size: 16px;
            font-family: 'Roboto', sans-serif;
            transition: border-color 0.2s, box-shadow 0.2s;
            background: #fff;
        }

        input[type="date"]:focus,
        input[type="number"]:focus,
        input[type="text"]:focus {
            outline: none;
            border-color: #1976d2;
            box-shadow: 0 0 0 1px #1976d2;
        }

        /* Material Button */
        button {
            padding: 0 24px;
            height: 44px;
            background: #1976d2;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            font-family: 'Roboto', sans-serif;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: background 0.2s, box-shadow 0.2s;
            box-shadow: 0 3px 1px -2px rgba(0, 0, 0, 0.2),
                0 2px 2px 0 rgba(0, 0, 0, 0.14),
                0 1px 5px 0 rgba(0, 0, 0, 0.12);
        }

        button:hover {
            background: #1565c0;
            box-shadow: 0 2px 4px -1px rgba(0, 0, 0, 0.2),
                0 4px 5px 0 rgba(0, 0, 0, 0.14),
                0 1px 10px 0 rgba(0, 0, 0, 0.12);
        }

        button:active {
            box-shadow: 0 5px 5px -3px rgba(0, 0, 0, 0.2),
                0 8px 10px 1px rgba(0, 0, 0, 0.14),
                0 3px 14px 2px rgba(0, 0, 0, 0.12);
        }

        .content {
            padding: 24px 32px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
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

        /* Sections */
        .section {
            margin-bottom: 32px;
        }

        h2 {
            font-size: 20px;
            font-weight: 500;
            margin-bottom: 16px;
            color: rgba(0, 0, 0, 0.87);
        }

        /* Table */
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

        .rank {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            background: #1976d2;
            color: #fff;
            border-radius: 50%;
            font-weight: 500;
            font-size: 14px;
        }

        .url-link {
            color: #1976d2;
            text-decoration: none;
            word-break: break-all;
        }

        .url-link:hover {
            text-decoration: underline;
        }

        /* Chart Container */
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

        /* Export Button */
        .export-buttons {
            margin-bottom: 24px;
            display: flex;
            gap: 12px;
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

        /* FAB Style for button (optional) */
        .fab {
            position: fixed;
            bottom: 24px;
            right: 24px;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: #1976d2;
            color: #fff;
            border: none;
            box-shadow: 0 3px 5px -1px rgba(0, 0, 0, 0.2),
                0 6px 10px 0 rgba(0, 0, 0, 0.14),
                0 1px 18px 0 rgba(0, 0, 0, 0.12);
            cursor: pointer;
            transition: background 0.2s, box-shadow 0.2s;
        }

        .fab:hover {
            background: #1565c0;
            box-shadow: 0 5px 5px -3px rgba(0, 0, 0, 0.2),
                0 8px 10px 1px rgba(0, 0, 0, 0.14),
                0 3px 14px 2px rgba(0, 0, 0, 0.12);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 24px;
            flex-wrap: wrap;
        }

        .pagination a,
        .pagination span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            height: 36px;
            padding: 0 12px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.2s, box-shadow 0.2s;
        }

        .pagination a {
            color: #1976d2;
            background: #fff;
            border: 1px solid rgba(0, 0, 0, 0.12);
        }

        .pagination a:hover {
            background: rgba(25, 118, 210, 0.08);
        }

        .pagination span.current {
            background: #1976d2;
            color: #fff;
            border: 1px solid #1976d2;
        }

        .pagination span.disabled {
            color: rgba(0, 0, 0, 0.26);
            background: #fafafa;
            border: 1px solid rgba(0, 0, 0, 0.12);
            cursor: not-allowed;
        }

        .pagination-info {
            text-align: center;
            margin-top: 16px;
            font-size: 14px;
            color: rgba(0, 0, 0, 0.6);
        }

        /* Responsive */
        @media (max-width: 768px) {
            body {
                padding: 16px;
            }

            .filter-form {
                flex-direction: column;
                align-items: stretch;
                padding: 16px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .content {
                padding: 16px;
            }

            table {
                font-size: 14px;
            }

            th,
            td {
                padding: 12px 8px;
            }

            header {
                padding: 16px;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="card card-elevated">
            <header style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h1>YOURLS 集計レポート</h1>
                    <p>短縮URLのアクセス解析とパフォーマンストラッキング</p>
                </div>
                <?php if (isLoginRequired()): ?>
                    <div style="text-align: right;">
                        <span style="font-size: 14px; opacity: 0.87;"><?= htmlspecialchars(getCurrentUser()) ?></span>
                        <a href="logout.php" style="display: inline-block; margin-left: 16px; padding: 8px 16px; background: rgba(255,255,255,0.2); color: #fff; text-decoration: none; border-radius: 4px; font-size: 14px;">ログアウト</a>
                    </div>
                <?php endif; ?>
            </header>

            <form class="filter-form" method="GET">
                <div class="form-group">
                    <label for="start_date">開始日</label>
                    <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" required>
                </div>

                <div class="form-group">
                    <label for="end_date">終了日</label>
                    <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" required>
                </div>

                <div class="form-group">
                    <label for="per_page">表示件数</label>
                    <input type="number" id="per_page" name="per_page" value="<?= $per_page ?>" min="5" max="100" required>
                </div>

                <div class="form-group">
                    <label for="search_keyword">タイトル/URL</label>
                    <input type="text" id="search_keyword" name="search_keyword" value="<?= htmlspecialchars($search_keyword) ?>" placeholder="キーワードで絞り込み">
                </div>

                <button type="submit">集計実行</button>
            </form>

            <div class="content">
                <div class="export-buttons">
                    <a href="export_csv.php?start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>" class="export-btn">CSV出力</a>
                </div>

                <!-- 基本統計 -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-label">総クリック数</div>
                        <div class="stat-value"><?= number_format($basic_stats['total_clicks']) ?></div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-label">ユニークURL数</div>
                        <div class="stat-value"><?= number_format($basic_stats['unique_urls']) ?></div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-label">ユニークIP数</div>
                        <div class="stat-value"><?= number_format($basic_stats['unique_ips']) ?></div>
                    </div>
                </div>

                <!-- トップURL -->
                <div class="section">
                    <h2>URL別クリック数 (<?= number_format($total_urls) ?>件)</h2>
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 50px;">順位</th>
                                <th>キーワード</th>
                                <th>タイトル</th>
                                <th style="width: 100px;">クリック数</th>
                                <th style="width: 150px;">初回</th>
                                <th style="width: 150px;">最終</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_urls as $index => $row): ?>
                                <tr>
                                    <td><span class="rank"><?= ($page - 1) * $per_page + $index + 1 ?></span></td>
                                    <td>
                                        <strong><?= htmlspecialchars($row['keyword'] ?: $row['shorturl']) ?></strong><br>
                                        <a href="<?= htmlspecialchars(safeUrl($row['url'])) ?>" target="_blank" rel="noopener noreferrer" class="url-link" style="font-size: 12px;">
                                            <?= htmlspecialchars(substr($row['url'], 0, URL_DISPLAY_MAX_LENGTH)) ?><?= strlen($row['url']) > URL_DISPLAY_MAX_LENGTH ? '...' : '' ?>
                                        </a>
                                    </td>
                                    <td>
                                        <a href="url_detail.php?keyword=<?= urlencode($row['keyword'] ?: $row['shorturl']) ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>" class="url-link">
                                            <?= htmlspecialchars($row['title'] ?: '(タイトルなし)') ?>
                                        </a>
                                    </td>
                                    <td><strong><?= number_format($row['click_count']) ?></strong></td>
                                    <td style="font-size: 12px;"><?= date('m/d H:i', strtotime($row['first_click'])) ?></td>
                                    <td style="font-size: 12px;"><?= date('m/d H:i', strtotime($row['last_click'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if ($total_pages > 1): ?>
                        <?php
                        $base_url = '?' . http_build_query([
                            'start_date' => $start_date,
                            'end_date' => $end_date,
                            'per_page' => $per_page,
                            'search_keyword' => $search_keyword
                        ]);
                        ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="<?= $base_url ?>&page=1">最初</a>
                                <a href="<?= $base_url ?>&page=<?= $page - 1 ?>">前へ</a>
                            <?php else: ?>
                                <span class="disabled">最初</span>
                                <span class="disabled">前へ</span>
                            <?php endif; ?>

                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            for ($i = $start_page; $i <= $end_page; $i++):
                            ?>
                                <?php if ($i == $page): ?>
                                    <span class="current"><?= $i ?></span>
                                <?php else: ?>
                                    <a href="<?= $base_url ?>&page=<?= $i ?>"><?= $i ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="<?= $base_url ?>&page=<?= $page + 1 ?>">次へ</a>
                                <a href="<?= $base_url ?>&page=<?= $total_pages ?>">最後</a>
                            <?php else: ?>
                                <span class="disabled">次へ</span>
                                <span class="disabled">最後</span>
                            <?php endif; ?>
                        </div>
                        <div class="pagination-info">
                            <?= number_format(($page - 1) * $per_page + 1) ?> - <?= number_format(min($page * $per_page, $total_urls)) ?> / <?= number_format($total_urls) ?>件 (<?= $page ?> / <?= $total_pages ?>ページ)
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 国別統計 -->
                <div class="section">
                    <h2>国別アクセス</h2>
                    <div class="chart-container">
                        <?php if (empty($country_stats)): ?>
                            <p style="color: rgba(0, 0, 0, 0.6); font-size: 14px;">この期間のデータはありません。</p>
                        <?php else: ?>
                            <?php
                            $max_country = max(array_column($country_stats, 'clicks'));
                            $max_country = $max_country > 0 ? $max_country : 1;
                            foreach ($country_stats as $row):
                                $percentage = ($row['clicks'] / $max_country) * 100;
                            ?>
                                <div class="bar">
                                    <div class="bar-label"><?= htmlspecialchars($row['country_code']) ?></div>
                                    <div class="bar-fill" style="width: <?= $percentage ?>%; min-width: 80px;">
                                        <?= number_format($row['clicks']) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
    </div>

</body>

</html>