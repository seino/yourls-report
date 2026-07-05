<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * MySQL を用いる統合テストの基底クラス。
 *
 * 接続情報は環境変数（DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASS）から取得する。
 * MySQL に接続できない環境では全テストをスキップするため、ユニットテストのみの実行を妨げない。
 */
abstract class DatabaseTestCase extends TestCase
{
    protected static ?PDO $pdo = null;

    /** テスト用のテーブル接頭辞 */
    protected const PREFIX = 'yourls_';

    public static function setUpBeforeClass(): void
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3306';
        $name = getenv('DB_NAME') ?: 'yourls_test';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS');
        $pass = $pass === false ? 'root' : $pass;

        try {
            self::$pdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            self::markTestSkipped('MySQL に接続できないため統合テストをスキップします: ' . $e->getMessage());
        }

        self::createSchema();
    }

    public static function tearDownAfterClass(): void
    {
        self::$pdo = null;
    }

    protected function setUp(): void
    {
        // 各テストの前にデータを空にする
        self::$pdo->exec('DELETE FROM ' . self::PREFIX . 'log');
        self::$pdo->exec('DELETE FROM ' . self::PREFIX . 'url');
    }

    private static function createSchema(): void
    {
        $prefix = self::PREFIX;

        self::$pdo->exec("DROP TABLE IF EXISTS {$prefix}log");
        self::$pdo->exec("DROP TABLE IF EXISTS {$prefix}url");

        self::$pdo->exec("
            CREATE TABLE {$prefix}url (
                keyword   VARCHAR(200) NOT NULL,
                url       TEXT NOT NULL,
                title     TEXT,
                timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ip        VARCHAR(41) NOT NULL DEFAULT '',
                clicks    INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (keyword)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        self::$pdo->exec("
            CREATE TABLE {$prefix}log (
                click_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
                click_time   DATETIME NOT NULL,
                shorturl     VARCHAR(200) NOT NULL DEFAULT '',
                referrer     VARCHAR(255) NOT NULL DEFAULT '',
                user_agent   VARCHAR(255) NOT NULL DEFAULT '',
                ip_address   VARCHAR(41) NOT NULL DEFAULT '',
                country_code VARCHAR(2) NOT NULL DEFAULT '',
                PRIMARY KEY (click_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    /**
     * URL を1件登録する。
     */
    protected function insertUrl(string $keyword, string $url, string $title = ''): void
    {
        $stmt = self::$pdo->prepare(
            'INSERT INTO ' . self::PREFIX . 'url (keyword, url, title, timestamp) VALUES (:k, :u, :t, NOW())'
        );
        $stmt->execute(['k' => $keyword, 'u' => $url, 't' => $title]);
    }

    /**
     * クリックログを1件登録する。
     */
    protected function insertLog(
        string $shorturl,
        string $clickTime,
        string $ip = '10.0.0.1',
        string $country = 'JP',
        string $referrer = 'direct'
    ): void {
        $stmt = self::$pdo->prepare(
            'INSERT INTO ' . self::PREFIX . 'log (shorturl, click_time, ip_address, country_code, referrer)
             VALUES (:s, :ct, :ip, :cc, :ref)'
        );
        $stmt->execute([
            's' => $shorturl,
            'ct' => $clickTime,
            'ip' => $ip,
            'cc' => $country,
            'ref' => $referrer,
        ]);
    }
}
