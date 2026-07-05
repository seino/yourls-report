<?php

declare(strict_types=1);

/**
 * 集計クエリのリポジトリ
 *
 * yourls_report.php と api.php で二重実装されていた集計SQLを1箇所に集約する。
 * DBアクセスをこのクラスに閉じ込め、テスト・最適化を容易にする。
 *
 * SQL断片の生成には utils.php の excludedIpClause() / escapeLikeWildcards() を利用する
 * （呼び出し側で utils.php を読み込んでおくこと）。
 */
class StatsRepository
{
    private PDO $pdo;
    private string $prefix;
    private string $excludedIp;

    public function __construct(PDO $pdo, string $tablePrefix, string $excludedIp = '')
    {
        $this->pdo = $pdo;
        $this->prefix = $tablePrefix;
        $this->excludedIp = $excludedIp;
    }

    /**
     * 除外IPが設定されていれば $params にバインド値を追加する。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function withExcludedIp(array $params): array
    {
        if ($this->excludedIp !== '') {
            $params['excluded_ip'] = $this->excludedIp;
        }
        return $params;
    }

    /**
     * 基本統計（総クリック数・ユニークURL数・ユニークIP数）
     *
     * @return array<string, mixed>
     */
    public function getBasicStats(string $start, string $end): array
    {
        $sql = "SELECT
                    COUNT(*) as total_clicks,
                    COUNT(DISTINCT shorturl) as unique_urls,
                    COUNT(DISTINCT ip_address) as unique_ips
                FROM " . $this->prefix . "log
                WHERE click_time BETWEEN :start AND :end";
        $sql .= excludedIpClause($this->excludedIp);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->withExcludedIp(['start' => $start, 'end' => $end]));
        $row = $stmt->fetch();

        return $row === false ? [] : $row;
    }

    /**
     * 検索条件に合致するURL件数
     */
    public function getTopUrlsCount(string $start, string $end, string $searchKeyword = ''): int
    {
        $sql = "SELECT COUNT(DISTINCT l.shorturl) as total
                FROM " . $this->prefix . "log l
                LEFT JOIN " . $this->prefix . "url u ON l.shorturl = u.keyword
                WHERE l.click_time BETWEEN :start AND :end";
        $sql .= excludedIpClause($this->excludedIp, 'l.ip_address');
        if ($searchKeyword !== '') {
            $sql .= " AND (u.title LIKE :search_title OR u.url LIKE :search_url)";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':start', $start);
        $stmt->bindValue(':end', $end);
        if ($this->excludedIp !== '') {
            $stmt->bindValue(':excluded_ip', $this->excludedIp);
        }
        if ($searchKeyword !== '') {
            $like = '%' . escapeLikeWildcards($searchKeyword) . '%';
            $stmt->bindValue(':search_title', $like);
            $stmt->bindValue(':search_url', $like);
        }
        $stmt->execute();

        return (int) ($stmt->fetch()['total'] ?? 0);
    }

    /**
     * URL別クリック数トップ（クリック数降順）
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTopUrls(
        string $start,
        string $end,
        int $limit,
        int $offset = 0,
        string $searchKeyword = ''
    ): array {
        $sql = "SELECT
                    l.shorturl,
                    u.keyword,
                    u.url,
                    u.title,
                    COUNT(*) as click_count,
                    MIN(l.click_time) as first_click,
                    MAX(l.click_time) as last_click
                FROM " . $this->prefix . "log l
                LEFT JOIN " . $this->prefix . "url u ON l.shorturl = u.keyword
                WHERE l.click_time BETWEEN :start AND :end";
        $sql .= excludedIpClause($this->excludedIp, 'l.ip_address');
        if ($searchKeyword !== '') {
            $sql .= " AND (u.title LIKE :search_title OR u.url LIKE :search_url)";
        }
        $sql .= " GROUP BY l.shorturl, u.keyword, u.url, u.title
                ORDER BY click_count DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':start', $start);
        $stmt->bindValue(':end', $end);
        if ($this->excludedIp !== '') {
            $stmt->bindValue(':excluded_ip', $this->excludedIp);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        if ($searchKeyword !== '') {
            $like = '%' . escapeLikeWildcards($searchKeyword) . '%';
            $stmt->bindValue(':search_title', $like);
            $stmt->bindValue(':search_url', $like);
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * 国別クリック数（降順）
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCountryStats(string $start, string $end, int $limit = 10): array
    {
        $sql = "SELECT
                    country_code,
                    COUNT(*) as clicks
                FROM " . $this->prefix . "log
                WHERE click_time BETWEEN :start AND :end";
        $sql .= excludedIpClause($this->excludedIp);
        $sql .= " GROUP BY country_code
                ORDER BY clicks DESC
                LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':start', $start);
        $stmt->bindValue(':end', $end);
        if ($this->excludedIp !== '') {
            $stmt->bindValue(':excluded_ip', $this->excludedIp);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * 日別推移（日付昇順）
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDailyStats(string $start, string $end): array
    {
        $sql = "SELECT
                    DATE(click_time) as date,
                    COUNT(*) as clicks,
                    COUNT(DISTINCT shorturl) as unique_urls,
                    COUNT(DISTINCT ip_address) as unique_ips
                FROM " . $this->prefix . "log
                WHERE click_time BETWEEN :start AND :end";
        $sql .= excludedIpClause($this->excludedIp);
        $sql .= " GROUP BY DATE(click_time)
                ORDER BY date ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->withExcludedIp(['start' => $start, 'end' => $end]));

        return $stmt->fetchAll();
    }

    /**
     * リファラー種別ごとの集計（クリック数降順）
     *
     * @return array<int, array<string, mixed>>
     */
    public function getReferrerStats(string $start, string $end): array
    {
        $baseWhere = "click_time BETWEEN :start AND :end";
        $baseWhere .= excludedIpClause($this->excludedIp);

        $sql = "SELECT
                    " . getReferrerCaseSql('referrer_type', false) . ",
                    COUNT(*) as clicks
                FROM " . $this->prefix . "log
                WHERE {$baseWhere}
                GROUP BY referrer_type
                ORDER BY clicks DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->withExcludedIp(['start' => $start, 'end' => $end]));

        return $stmt->fetchAll();
    }

    /**
     * 直近1時間のアクセス（新しい順）
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecentClicks(int $limit = 50): array
    {
        $sql = "SELECT
                    l.shorturl,
                    u.title,
                    l.click_time,
                    l.country_code
                FROM " . $this->prefix . "log l
                LEFT JOIN " . $this->prefix . "url u ON l.shorturl = u.keyword
                WHERE l.click_time >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";
        $sql .= excludedIpClause($this->excludedIp, 'l.ip_address');
        $sql .= " ORDER BY l.click_time DESC
                LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);
        if ($this->excludedIp !== '') {
            $stmt->bindValue(':excluded_ip', $this->excludedIp);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
