<?php

/**
 * PHPUnit ブートストラップ
 *
 * config.php（Git管理外）に依存しない純粋なユーティリティ関数をテスト対象とする。
 * utils.php は DB接続情報などの定数を関数内部でのみ参照するため、読み込むだけでは
 * 副作用を起こさない。
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../utils.php';
