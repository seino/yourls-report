<?php

/**
 * PHPStan 用ブートストラップ
 *
 * config.php はGit管理外（環境ごとに存在）だが、静的解析時にはアプリ各所で参照される
 * 設定定数を既知にする必要がある。ここでスタブ値を定義しておくことで「未定義定数」の
 * 誤検知を防ぐ。実行環境の値ではなく解析専用のダミー値である点に注意。
 */

declare(strict_types=1);

// データベース接続情報
define('YOURLS_DB_USER', 'stub');
define('YOURLS_DB_PASS', 'stub');
define('YOURLS_DB_NAME', 'stub');
define('YOURLS_DB_HOST', 'localhost');
define('YOURLS_DB_PREFIX', 'yourls_');

// 集計・表示関連
define('EXCLUDED_IP', '0.0.0.0');
define('IS_PRODUCTION', false);

// API
define('API_KEY', '');
define('API_ALLOWED_ORIGIN', '*');
define('API_RATE_LIMIT', 60);
define('API_RATE_WINDOW', 60);

// ログイン認証
define('REQUIRE_LOGIN', false);
define('YOURLS_PATH', '/path/to/yourls');
define('YOURLS_USERS', []);
define('SESSION_TIMEOUT', 3600);
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900);
