<?php

/**
 * ログアウト処理
 */

require_once __DIR__ . '/auth.php';

logout();

header('Location: login.php');
exit;
