<?php

/**
 * パスワード検証・ユーザー定義パースのロジック
 *
 * config.php に依存しない純粋なロジックとして auth.php から切り出し、テスト可能にする。
 * （phpassハッシュ検証のみ YOURLS_PATH 定数を参照するが、その分岐に入るときだけ評価される）
 */

/**
 * ユーザー・パスワード配列の内容をパースする
 *
 * @param string $arrayContent 配列の中身の文字列
 * @return array<string, string> ['username' => 'password', ...]
 */
function parseUserPasswordArray($arrayContent)
{
    $users = [];

    // 'username' => 'password' または "username" => "password" の形式を解析
    // 複数行、コメント混在にも対応
    preg_match_all(
        "/['\"]([^'\"]+)['\"]\s*=>\s*['\"]([^'\"]+)['\"]/",
        $arrayContent,
        $userMatches,
        PREG_SET_ORDER
    );

    foreach ($userMatches as $match) {
        $users[$match[1]] = $match[2];
    }

    return $users;
}

/**
 * パスワードを検証する（プレーンテキスト / bcrypt / phpass / MD5 に対応）
 *
 * @param string $inputPassword 入力されたパスワード
 * @param string $storedPassword 保存されているパスワード（平文またはハッシュ）
 * @return bool 一致すればtrue
 */
function verifyPassword($inputPassword, $storedPassword)
{
    // プレーンテキストの場合
    if ($inputPassword === $storedPassword) {
        return true;
    }

    // phpassハッシュの場合（$P$で始まる）またはbcrypt（$2で始まる）
    if (strpos($storedPassword, '$P$') === 0 || strpos($storedPassword, '$2') === 0) {
        // bcrypt
        if (strpos($storedPassword, '$2') === 0) {
            return password_verify($inputPassword, $storedPassword);
        }

        // phpass形式のハッシュ検証（YOURLSのphpassライブラリを使用）
        $phpass_file = defined('YOURLS_PATH') ? YOURLS_PATH . '/includes/phpass/PasswordHash.php' : '';
        if (file_exists($phpass_file)) {
            require_once $phpass_file;
            $hasher = new PasswordHash(8, true);
            return $hasher->CheckPassword($inputPassword, $storedPassword);
        }
    }

    // MD5ハッシュの場合（32文字の16進数）
    if (preg_match('/^[a-f0-9]{32}$/i', $storedPassword)) {
        return md5($inputPassword) === $storedPassword;
    }

    return false;
}
