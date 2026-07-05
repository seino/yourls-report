<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\TestDox;

/**
 * StatsRepository の集計クエリを、既知のシードデータに対する期待値で固定する統合テスト。
 * MySQL が利用できない環境ではクラスごとスキップされる（DatabaseTestCase 参照）。
 */
final class StatsRepositoryTest extends DatabaseTestCase
{
    private const START = '2025-01-10 00:00:00';
    private const END = '2025-01-12 23:59:59';

    /**
     * 決定的なシードデータ:
     * - aaa: 4クリック（10.0.0.1×2/JP, 10.0.0.2/US, 192.168.0.1/JP=除外対象）
     * - bbb: 1クリック（10.0.0.3/JP）
     */
    private function seed(): void
    {
        $this->insertUrl('aaa', 'http://example.com/a', 'Alpha');
        $this->insertUrl('bbb', 'http://example.com/b', 'Beta');

        $this->insertLog('aaa', '2025-01-10 10:00:00', '10.0.0.1', 'JP', 'direct');
        $this->insertLog('aaa', '2025-01-10 11:00:00', '10.0.0.1', 'JP', 'https://google.com/');
        $this->insertLog('aaa', '2025-01-11 09:00:00', '10.0.0.2', 'US', 'direct');
        $this->insertLog('bbb', '2025-01-11 12:00:00', '10.0.0.3', 'JP', 'direct');
        $this->insertLog('aaa', '2025-01-11 13:00:00', '192.168.0.1', 'JP', 'direct');
    }

    private function repo(string $excludedIp = ''): StatsRepository
    {
        return new StatsRepository(self::$pdo, self::PREFIX, $excludedIp);
    }

    #[TestDox('基本統計は総クリック・ユニークURL・ユニークIPを正しく返す')]
    public function testBasicStats(): void
    {
        $this->seed();
        $stats = $this->repo()->getBasicStats(self::START, self::END);

        $this->assertEquals(5, $stats['total_clicks']);
        $this->assertEquals(2, $stats['unique_urls']);
        $this->assertEquals(4, $stats['unique_ips']);
    }

    #[TestDox('除外IP指定時、そのIPのクリックが集計から除かれる')]
    public function testBasicStatsWithExcludedIp(): void
    {
        $this->seed();
        $stats = $this->repo('192.168.0.1')->getBasicStats(self::START, self::END);

        $this->assertEquals(4, $stats['total_clicks']);
        $this->assertEquals(2, $stats['unique_urls']);
        $this->assertEquals(3, $stats['unique_ips']);
    }

    #[TestDox('URL件数は重複しないshorturl数を返す')]
    public function testTopUrlsCount(): void
    {
        $this->seed();
        $this->assertSame(2, $this->repo()->getTopUrlsCount(self::START, self::END));
    }

    #[TestDox('検索キーワード指定時、タイトル一致のURLのみ数える')]
    public function testTopUrlsCountWithSearch(): void
    {
        $this->seed();
        $this->assertSame(1, $this->repo()->getTopUrlsCount(self::START, self::END, 'Alpha'));
    }

    #[TestDox('検索キーワードが"0"のとき、（empty扱いで）絞り込まず全件を数える')]
    public function testTopUrlsCountWithZeroSearchIsNotFiltered(): void
    {
        // 旧実装の !empty() 判定を踏襲: "0" は検索条件として扱わない
        $this->seed();
        $this->assertSame(2, $this->repo()->getTopUrlsCount(self::START, self::END, '0'));
    }

    #[TestDox('トップURLはクリック数降順で返る')]
    public function testTopUrls(): void
    {
        $this->seed();
        $rows = $this->repo()->getTopUrls(self::START, self::END, 10);

        $this->assertCount(2, $rows);
        $this->assertSame('aaa', $rows[0]['shorturl']);
        $this->assertEquals(4, $rows[0]['click_count']);
        $this->assertSame('bbb', $rows[1]['shorturl']);
        $this->assertEquals(1, $rows[1]['click_count']);
    }

    #[TestDox('トップURLは除外IPを反映する')]
    public function testTopUrlsWithExcludedIp(): void
    {
        $this->seed();
        $rows = $this->repo('192.168.0.1')->getTopUrls(self::START, self::END, 10);

        $this->assertEquals(3, $rows[0]['click_count']);
    }

    #[TestDox('トップURLのLIMIT/OFFSETでページングできる')]
    public function testTopUrlsPagination(): void
    {
        $this->seed();
        $page1 = $this->repo()->getTopUrls(self::START, self::END, 1, 0);
        $page2 = $this->repo()->getTopUrls(self::START, self::END, 1, 1);

        $this->assertCount(1, $page1);
        $this->assertCount(1, $page2);
        $this->assertSame('aaa', $page1[0]['shorturl']);
        $this->assertSame('bbb', $page2[0]['shorturl']);
    }

    #[TestDox('国別統計はクリック数降順で返る')]
    public function testCountryStats(): void
    {
        $this->seed();
        $rows = $this->repo()->getCountryStats(self::START, self::END);

        $this->assertSame('JP', $rows[0]['country_code']);
        $this->assertEquals(4, $rows[0]['clicks']);
        $this->assertSame('US', $rows[1]['country_code']);
        $this->assertEquals(1, $rows[1]['clicks']);
    }

    #[TestDox('日別統計は日付昇順でクリック・ユニークURL・ユニークIPを返す')]
    public function testDailyStats(): void
    {
        $this->seed();
        $rows = $this->repo()->getDailyStats(self::START, self::END);

        $this->assertCount(2, $rows);
        $this->assertSame('2025-01-10', $rows[0]['date']);
        $this->assertEquals(2, $rows[0]['clicks']);
        $this->assertEquals(1, $rows[0]['unique_urls']);
        $this->assertEquals(1, $rows[0]['unique_ips']);
        $this->assertSame('2025-01-11', $rows[1]['date']);
        $this->assertEquals(3, $rows[1]['clicks']);
        $this->assertEquals(2, $rows[1]['unique_urls']);
        $this->assertEquals(3, $rows[1]['unique_ips']);
    }

    #[TestDox('リファラー統計は種別ごとにクリック数降順で返る')]
    public function testReferrerStats(): void
    {
        $this->seed();
        $rows = $this->repo()->getReferrerStats(self::START, self::END);

        $byType = [];
        foreach ($rows as $row) {
            $byType[$row['referrer_type']] = (int) $row['clicks'];
        }

        $this->assertSame(4, $byType['direct']);
        $this->assertSame(1, $byType['google']);
        // 降順のため先頭は direct
        $this->assertSame('direct', $rows[0]['referrer_type']);
    }

    #[TestDox('リアルタイムは直近1時間のアクセスのみ返す')]
    public function testRecentClicks(): void
    {
        $this->seed(); // 2025年の古いログ（1時間より前）

        // 直近のクリックを1件追加（NOW()）
        $stmt = self::$pdo->prepare(
            'INSERT INTO ' . self::PREFIX . 'log (shorturl, click_time, ip_address, country_code, referrer)
             VALUES (:s, NOW(), :ip, :cc, :ref)'
        );
        $stmt->execute(['s' => 'aaa', 'ip' => '10.0.0.9', 'cc' => 'JP', 'ref' => 'direct']);

        $rows = $this->repo()->getRecentClicks();

        $this->assertCount(1, $rows);
        $this->assertSame('aaa', $rows[0]['shorturl']);
        $this->assertSame('Alpha', $rows[0]['title']);
    }

    #[TestDox('国別統計は除外IPを反映する')]
    public function testCountryStatsWithExcludedIp(): void
    {
        $this->seed();
        $rows = $this->repo('192.168.0.1')->getCountryStats(self::START, self::END);

        $byCode = [];
        foreach ($rows as $row) {
            $byCode[$row['country_code']] = (int) $row['clicks'];
        }
        $this->assertSame(3, $byCode['JP']);
        $this->assertSame(1, $byCode['US']);
    }

    #[TestDox('リファラー統計は除外IPを反映する')]
    public function testReferrerStatsWithExcludedIp(): void
    {
        $this->seed();
        $rows = $this->repo('192.168.0.1')->getReferrerStats(self::START, self::END);

        $byType = [];
        foreach ($rows as $row) {
            $byType[$row['referrer_type']] = (int) $row['clicks'];
        }
        // 除外対象（192.168.0.1 の direct 1件）が減り direct=3、google=1
        $this->assertSame(3, $byType['direct']);
        $this->assertSame(1, $byType['google']);
    }

    #[TestDox('リアルタイムは除外IPのアクセスを含めない')]
    public function testRecentClicksWithExcludedIp(): void
    {
        $stmt = self::$pdo->prepare(
            'INSERT INTO ' . self::PREFIX . 'log (shorturl, click_time, ip_address, country_code, referrer)
             VALUES (:s, NOW(), :ip, :cc, :ref)'
        );
        $this->insertUrl('aaa', 'http://example.com/a', 'Alpha');
        // 除外IPと通常IPの直近アクセスを1件ずつ
        $stmt->execute(['s' => 'aaa', 'ip' => '192.168.0.1', 'cc' => 'JP', 'ref' => 'direct']);
        $stmt->execute(['s' => 'aaa', 'ip' => '10.0.0.9', 'cc' => 'JP', 'ref' => 'direct']);

        $rows = $this->repo('192.168.0.1')->getRecentClicks();

        $this->assertCount(1, $rows);
    }
}
