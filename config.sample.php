<?php

/**
 * 設定ファイルサンプル
 *
 * このファイルを config.php にコピーして、環境に合わせて編集してください。
 * config.php は .gitignore に追加されているため、Git管理外です。
 */

// データベース接続情報（YOURLSの設定と同じ値を入力）
define('YOURLS_DB_USER', 'your_db_user');
define('YOURLS_DB_PASS', 'your_db_password');
define('YOURLS_DB_NAME', 'your_db_name');
define('YOURLS_DB_HOST', 'localhost');
define('YOURLS_DB_PREFIX', 'yourls_');

// 除外するIPアドレス（自社アクセス等を除外する場合に設定）
define('EXCLUDED_IP', '0.0.0.0');

// 本番環境フラグ（本番環境では true に設定してください）
// true: エラー表示を無効化、ログに記録
// false: エラーを画面に表示（開発用）
define('IS_PRODUCTION', false);

// =============================================================================
// API設定（オプション）
// =============================================================================

// APIキー（設定するとAPI認証が有効になります）
// 空文字の場合は認証なしでAPIにアクセス可能
// define('API_KEY', 'your_secret_api_key_here');

// API CORSの許可オリジン（デフォルト: '*'）
// 本番環境では適切なオリジンに制限してください
// define('API_ALLOWED_ORIGIN', 'https://your-domain.com');

// =============================================================================
// ログイン認証設定（オプション）
// =============================================================================

// ログイン認証を有効にする（true: 有効, false: 無効）
// 無効の場合、誰でもレポートにアクセス可能です
// 本番環境では有効にするか、Webサーバーでアクセス制限を行ってください
define('REQUIRE_LOGIN', false);

// YOURLSのインストールディレクトリへのパス（REQUIRE_LOGIN=true の場合に必要）
// YOURLSのuser/config.phpを読み込んでユーザー認証に使用します
// define('YOURLS_PATH', '/path/to/yourls');

// 手動でユーザーを定義する場合（YOURLS_PATHの代わりに使用可能）
// YOURLSの設定ファイルパースが失敗する場合のフォールバックとして使用できます
// define('YOURLS_USERS', [
//     'admin' => 'your_password_or_hash',
//     'user2' => 'another_password_or_hash'
// ]);

// セッションタイムアウト（秒）- デフォルト: 3600（1時間）
// define('SESSION_TIMEOUT', 3600);

// ログイン試行回数の上限 - デフォルト: 5回
// define('LOGIN_MAX_ATTEMPTS', 5);

// ロックアウト時間（秒）- デフォルト: 900（15分）
// define('LOGIN_LOCKOUT_TIME', 900);

// =============================================================================
// APIレート制限設定（オプション）
// =============================================================================

// 1ウィンドウあたりのリクエスト数上限 - デフォルト: 60
// define('API_RATE_LIMIT', 60);

// レート制限のウィンドウ（秒）- デフォルト: 60（1分）
// define('API_RATE_WINDOW', 60);
