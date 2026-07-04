<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * utils.php の純粋なユーティリティ関数のふるまいを固定するテスト。
 */
final class UtilsTest extends TestCase
{
    // --- validateDate -------------------------------------------------------

    #[TestDox('正しい形式の日付のとき、trueになる')]
    public function testValidateDateAcceptsValidDate(): void
    {
        $this->assertTrue(validateDate('2025-12-31'));
    }

    #[TestDox('存在しない日付のとき、falseになる')]
    public function testValidateDateRejectsNonexistentDate(): void
    {
        $this->assertFalse(validateDate('2025-02-30'));
    }

    #[TestDox('形式が異なる日付のとき、falseになる')]
    public function testValidateDateRejectsWrongFormat(): void
    {
        $this->assertFalse(validateDate('2025/12/31'));
        $this->assertFalse(validateDate('not-a-date'));
    }

    // --- sanitizeInput ------------------------------------------------------

    #[TestDox('前後の空白があるとき、トリムされる')]
    public function testSanitizeInputTrims(): void
    {
        $this->assertSame('abc', sanitizeInput('  abc  '));
    }

    #[TestDox('最大長を超えるとき、切り詰められる')]
    public function testSanitizeInputTruncatesToMaxLength(): void
    {
        $this->assertSame('abcde', sanitizeInput('abcdefghij', 5));
    }

    #[TestDox('マルチバイト文字のとき、文字数で切り詰められる')]
    public function testSanitizeInputTruncatesMultibyteByCharacter(): void
    {
        $this->assertSame('あいう', sanitizeInput('あいうえお', 3));
    }

    // --- excludedIpClause ---------------------------------------------------

    #[TestDox('除外IPが空のとき、空文字を返す')]
    public function testExcludedIpClauseReturnsEmptyWhenNoIp(): void
    {
        $this->assertSame('', excludedIpClause(''));
    }

    #[TestDox('除外IPがあるとき、デフォルトカラムのAND句を返す')]
    public function testExcludedIpClauseUsesDefaultColumn(): void
    {
        $this->assertSame(' AND ip_address != :excluded_ip', excludedIpClause('1.2.3.4'));
    }

    #[TestDox('カラム名を指定したとき、そのカラムのAND句を返す')]
    public function testExcludedIpClauseUsesGivenColumn(): void
    {
        $this->assertSame(
            ' AND l.ip_address != :excluded_ip',
            excludedIpClause('1.2.3.4', 'l.ip_address')
        );
    }

    #[TestDox('除外IPが文字列0のとき、空ではないためAND句を返す')]
    public function testExcludedIpClauseTreatsZeroStringAsPresent(): void
    {
        $this->assertSame(' AND ip_address != :excluded_ip', excludedIpClause('0'));
    }

    // --- escapeLikeWildcards ------------------------------------------------

    #[TestDox('ワイルドカードを含まないとき、そのまま返す')]
    public function testEscapeLikeWildcardsLeavesPlainText(): void
    {
        $this->assertSame('abc', escapeLikeWildcards('abc'));
    }

    #[TestDox('パーセントとアンダースコアを含むとき、エスケープされる')]
    public function testEscapeLikeWildcardsEscapesPercentAndUnderscore(): void
    {
        $this->assertSame('100\\%', escapeLikeWildcards('100%'));
        $this->assertSame('a\\_b', escapeLikeWildcards('a_b'));
    }

    #[TestDox('バックスラッシュを含むとき、二重化される')]
    public function testEscapeLikeWildcardsDoublesBackslash(): void
    {
        // 入力: a \ b  → 期待: a \ \ b（バックスラッシュを最初に処理して二重エスケープを避ける）
        $this->assertSame("a\\\\b", escapeLikeWildcards("a\\b"));
    }

    // --- safeUrl ------------------------------------------------------------

    #[DataProvider('safeUrlProvider')]
    #[TestDox('safeUrl はスキームに応じて安全なURLを返す')]
    public function testSafeUrl(string $input, string $expected): void
    {
        $this->assertSame($expected, safeUrl($input));
    }

    public static function safeUrlProvider(): array
    {
        return [
            'http は許可' => ['http://example.com/x', 'http://example.com/x'],
            'https は許可' => ['https://example.com', 'https://example.com'],
            '相対パスは許可' => ['/detail?keyword=abc', '/detail?keyword=abc'],
            'javascript はブロック' => ['javascript:alert(1)', '#'],
            '大文字混在の javascript もブロック' => ['JavaScript:alert(1)', '#'],
            'data はブロック' => ['data:text/html,x', '#'],
            '空文字は#' => ['', '#'],
        ];
    }

    // --- sanitizeCsvField ---------------------------------------------------

    #[DataProvider('csvFieldProvider')]
    #[TestDox('sanitizeCsvField は数式先頭文字を無害化する')]
    public function testSanitizeCsvField(string $input, string $expected): void
    {
        $this->assertSame($expected, sanitizeCsvField($input));
    }

    public static function csvFieldProvider(): array
    {
        return [
            'イコール始まり' => ['=1+1', "'=1+1"],
            'プラス始まり' => ['+A1', "'+A1"],
            'マイナス始まり' => ['-5', "'-5"],
            'アット始まり' => ['@cmd', "'@cmd"],
            '通常文字列はそのまま' => ['normal', 'normal'],
            '空文字はそのまま' => ['', ''],
        ];
    }

    // --- isSafeRedirectUrl --------------------------------------------------

    #[DataProvider('safeRedirectProvider')]
    #[TestDox('isSafeRedirectUrl はオープンリダイレクトを防ぐ')]
    public function testIsSafeRedirectUrl(string $input, bool $expected): void
    {
        $this->assertSame($expected, isSafeRedirectUrl($input));
    }

    public static function safeRedirectProvider(): array
    {
        return [
            '相対パスは安全' => ['/dashboard', true],
            '空文字は不可' => ['', false],
            'プロトコル相対URLは不可' => ['//evil.com', false],
            '絶対URLは不可' => ['https://evil.com', false],
            'バックスラッシュ混在は不可' => ['/\\evil.com', false],
            'スキームなし相対でない値は不可' => ['evil.com', false],
        ];
    }

    // --- getReferrerCaseSql -------------------------------------------------

    #[TestDox('エイリアスを指定したとき、as句が付与される')]
    public function testGetReferrerCaseSqlAppendsAlias(): void
    {
        $sql = getReferrerCaseSql('referrer_type');
        $this->assertStringContainsString('CASE', $sql);
        $this->assertStringEndsWith('as referrer_type', $sql);
    }

    #[TestDox('エイリアスを空にしたとき、as句が付かない')]
    public function testGetReferrerCaseSqlWithoutAlias(): void
    {
        $sql = getReferrerCaseSql('');
        $this->assertStringContainsString('CASE', $sql);
        $this->assertStringNotContainsString(' as ', $sql);
    }

    #[TestDox('日本語ラベルを指定したとき、日本語が含まれる')]
    public function testGetReferrerCaseSqlUsesJapaneseLabels(): void
    {
        $sql = getReferrerCaseSql('referrer_type', true);
        $this->assertStringContainsString('ダイレクト', $sql);
    }

    // --- isHttps ------------------------------------------------------------

    #[TestDox('HTTPSがonのとき、trueになる')]
    public function testIsHttpsDirectConnection(): void
    {
        $this->withServer(['HTTPS' => 'on'], function (): void {
            $this->assertTrue(isHttps());
        });
    }

    #[TestDox('X-Forwarded-Protoがhttpsのとき、trueになる')]
    public function testIsHttpsBehindProxy(): void
    {
        $this->withServer(['HTTP_X_FORWARDED_PROTO' => 'https'], function (): void {
            $this->assertTrue(isHttps());
        });
    }

    #[TestDox('HTTPS関連の情報がないとき、falseになる')]
    public function testIsHttpsPlainHttp(): void
    {
        $this->withServer(['SERVER_PORT' => '80'], function (): void {
            $this->assertFalse(isHttps());
        });
    }

    // --- normalizeDateRange -------------------------------------------------

    #[TestDox('開始日が終了日より後のとき、入れ替えられる')]
    public function testNormalizeDateRangeSwapsReversedDates(): void
    {
        $range = normalizeDateRange('2025-01-10', '2025-01-05');
        $this->assertSame('2025-01-05', $range['start_date']);
        $this->assertSame('2025-01-10', $range['end_date']);
    }

    #[TestDox('日時の開始と終了に時刻が付与される')]
    public function testNormalizeDateRangeAppendsTime(): void
    {
        $range = normalizeDateRange('2025-01-05', '2025-01-10');
        $this->assertSame('2025-01-05 00:00:00', $range['start_datetime']);
        $this->assertSame('2025-01-10 23:59:59', $range['end_datetime']);
    }

    #[TestDox('不正な日付のとき、既定値にフォールバックする')]
    public function testNormalizeDateRangeFallsBackOnInvalidDate(): void
    {
        $range = normalizeDateRange('invalid', 'also-invalid');
        $this->assertSame(date('Y-m-d'), $range['end_date']);
        $this->assertTrue(validateDate($range['start_date']));
    }

    // --- 定数 ---------------------------------------------------------------

    #[TestDox('件数・長さの定数が定義されている')]
    public function testConstantsAreDefined(): void
    {
        $this->assertSame(20, DEFAULT_PER_PAGE);
        $this->assertSame(5, MIN_PER_PAGE);
        $this->assertSame(100, MAX_PER_PAGE);
        $this->assertSame(50, MAX_KEYWORD_LENGTH);
        $this->assertSame(100, MAX_SEARCH_KEYWORD_LENGTH);
        $this->assertSame(60, URL_DISPLAY_MAX_LENGTH);
    }

    /**
     * $_SERVER を一時的に差し替えてコールバックを実行する。
     *
     * @param array<string, string> $server
     */
    private function withServer(array $server, callable $callback): void
    {
        $original = $_SERVER;
        // HTTPS判定に関わるキーを一旦除去してから指定値を設定
        foreach (['HTTPS', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_SSL', 'SERVER_PORT'] as $key) {
            unset($_SERVER[$key]);
        }
        foreach ($server as $key => $value) {
            $_SERVER[$key] = $value;
        }

        try {
            $callback();
        } finally {
            $_SERVER = $original;
        }
    }
}
