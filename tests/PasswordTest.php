<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * password.php のパスワード検証・ユーザー定義パースのふるまいを固定するテスト。
 */
final class PasswordTest extends TestCase
{
    // --- verifyPassword: プレーンテキスト -----------------------------------

    #[TestDox('平文パスワードが一致するとき、trueになる')]
    public function testVerifyPasswordAcceptsMatchingPlaintext(): void
    {
        $this->assertTrue(verifyPassword('secret', 'secret'));
    }

    #[TestDox('平文パスワードが一致しないとき、falseになる')]
    public function testVerifyPasswordRejectsWrongPlaintext(): void
    {
        $this->assertFalse(verifyPassword('secret', 'other'));
    }

    // --- verifyPassword: bcrypt ---------------------------------------------

    #[TestDox('bcryptハッシュと正しいパスワードのとき、trueになる')]
    public function testVerifyPasswordAcceptsValidBcrypt(): void
    {
        $hash = password_hash('correct horse', PASSWORD_BCRYPT);
        $this->assertTrue(verifyPassword('correct horse', $hash));
    }

    #[TestDox('bcryptハッシュと誤ったパスワードのとき、falseになる')]
    public function testVerifyPasswordRejectsInvalidBcrypt(): void
    {
        $hash = password_hash('correct horse', PASSWORD_BCRYPT);
        $this->assertFalse(verifyPassword('wrong horse', $hash));
    }

    // --- verifyPassword: MD5 ------------------------------------------------

    #[TestDox('MD5ハッシュと正しいパスワードのとき、trueになる')]
    public function testVerifyPasswordAcceptsValidMd5(): void
    {
        $this->assertTrue(verifyPassword('password', md5('password')));
    }

    #[TestDox('MD5ハッシュと誤ったパスワードのとき、falseになる')]
    public function testVerifyPasswordRejectsInvalidMd5(): void
    {
        $this->assertFalse(verifyPassword('wrong', md5('password')));
    }

    // --- verifyPassword: 異常系 ---------------------------------------------

    #[TestDox('空の保存値と空の入力のとき、平文一致でtrueになる')]
    public function testVerifyPasswordEmptyMatchesEmpty(): void
    {
        // 既存のふるまい（平文比較）を固定する characterization test
        $this->assertTrue(verifyPassword('', ''));
    }

    #[TestDox('phpassハッシュ形式だがライブラリがないとき、falseになる')]
    public function testVerifyPasswordPhpassWithoutLibraryReturnsFalse(): void
    {
        // YOURLS_PATH 未定義の環境では phpass 検証はできず false になる
        $this->assertFalse(verifyPassword('secret', '$P$Babc123def456ghi789jkl012mno345'));
    }

    // --- parseUserPasswordArray ---------------------------------------------

    #[TestDox('シングルクォートの定義のとき、ユーザーとパスワードを抽出する')]
    public function testParseUserPasswordArraySingleQuotes(): void
    {
        $content = "'admin' => 'pass1', 'editor' => 'pass2'";
        $this->assertSame(
            ['admin' => 'pass1', 'editor' => 'pass2'],
            parseUserPasswordArray($content)
        );
    }

    #[TestDox('ダブルクォートと複数行・コメント混在のとき、正しく抽出する')]
    public function testParseUserPasswordArrayMultilineWithComments(): void
    {
        $content = <<<'PHP'
            "admin" => "secret",
            // これはコメント
            'guest' => 'g!pass'
            PHP;
        $this->assertSame(
            ['admin' => 'secret', 'guest' => 'g!pass'],
            parseUserPasswordArray($content)
        );
    }

    #[TestDox('ユーザー定義がないとき、空配列を返す')]
    public function testParseUserPasswordArrayReturnsEmptyWhenNoMatch(): void
    {
        $this->assertSame([], parseUserPasswordArray('// 定義なし'));
    }
}
