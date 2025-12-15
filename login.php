<?php

/**
 * ログインページ
 */

require_once __DIR__ . '/auth.php';

// セキュリティヘッダー
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com");

// 既にログイン済みならリダイレクト
if (isLoggedIn()) {
    $return_url = $_GET['return'] ?? 'yourls_report.php';
    header('Location: ' . $return_url);
    exit;
}

$error = '';
$username = '';
$info = '';

// セッションタイムアウトのメッセージ
if (isset($_GET['timeout']) && $_GET['timeout'] === '1') {
    $info = 'セッションがタイムアウトしました。再度ログインしてください。';
}

// ログイン処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRFトークン検証
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = '不正なリクエストです。';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'ユーザー名とパスワードを入力してください。';
        } else {
            $result = login($username, $password);
            if ($result['success']) {
                $return_url = $_GET['return'] ?? 'yourls_report.php';
                // セキュリティ: オープンリダイレクト攻撃を防止
                if (!isSafeRedirectUrl($return_url)) {
                    $return_url = 'yourls_report.php';
                }
                header('Location: ' . $return_url);
                exit;
            } else {
                $error = $result['message'];
            }
        }
    }
}

// CSRFトークン生成
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ログイン - YOURLS Report</title>
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
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .login-card {
            background: #fff;
            border-radius: 4px;
            box-shadow: 0 3px 5px -1px rgba(0, 0, 0, 0.2),
                0 6px 10px 0 rgba(0, 0, 0, 0.14),
                0 1px 18px 0 rgba(0, 0, 0, 0.12);
            width: 100%;
            max-width: 400px;
            overflow: hidden;
        }

        .login-header {
            background: #1976d2;
            color: #fff;
            padding: 32px 24px;
            text-align: center;
        }

        .login-header h1 {
            font-size: 24px;
            font-weight: 400;
            margin-bottom: 8px;
        }

        .login-header p {
            font-size: 14px;
            opacity: 0.87;
        }

        .login-body {
            padding: 32px 24px;
        }

        .form-group {
            margin-bottom: 24px;
        }

        label {
            display: block;
            font-size: 12px;
            font-weight: 500;
            color: rgba(0, 0, 0, 0.6);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid rgba(0, 0, 0, 0.23);
            border-radius: 4px;
            font-size: 16px;
            font-family: 'Roboto', sans-serif;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #1976d2;
            box-shadow: 0 0 0 1px #1976d2;
        }

        button {
            width: 100%;
            padding: 14px 24px;
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

        .error-message {
            background: #ffebee;
            color: #c62828;
            padding: 12px 16px;
            border-radius: 4px;
            margin-bottom: 24px;
            font-size: 14px;
        }

        .info-message {
            background: #e3f2fd;
            color: #1565c0;
            padding: 12px 16px;
            border-radius: 4px;
            margin-bottom: 24px;
            font-size: 14px;
        }

        .info-text {
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid rgba(0, 0, 0, 0.12);
            font-size: 13px;
            color: rgba(0, 0, 0, 0.6);
            text-align: center;
        }
    </style>
</head>

<body>
    <div class="login-card">
        <div class="login-header">
            <h1>YOURLS Report</h1>
            <p>YOURLSアカウントでログイン</p>
        </div>

        <div class="login-body">
            <?php if ($info): ?>
                <div class="info-message"><?= htmlspecialchars($info) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="error-message"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <div class="form-group">
                    <label for="username">ユーザー名</label>
                    <input type="text" id="username" name="username" value="<?= htmlspecialchars($username) ?>" required autofocus>
                </div>

                <div class="form-group">
                    <label for="password">パスワード</label>
                    <input type="password" id="password" name="password" required>
                </div>

                <button type="submit">ログイン</button>
            </form>

            <div class="info-text">
                YOURLSの管理画面と同じユーザー名・パスワードを使用してください
            </div>
        </div>
    </div>
</body>

</html>