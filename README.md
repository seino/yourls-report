# YOURLS Analytics Report

YOURLSの短縮URLアクセス解析レポートシステム

## 機能

- URL別クリック数集計（ページネーション対応）
- タイトル/URLで検索・フィルタリング
- 国別アクセス統計
- URL詳細ページ（日別クリック推移グラフ）
- CSV出力（URL別、日別）
- 特定IPアドレスの除外設定
- JSON API（認証対応）
- Material Design UI

## 必要要件

- PHP 8.1以上
- MySQL 5.7以上
- YOURLS 1.7以上

> 開発ツール（PHPUnit 11）を使う場合は PHP 8.2 以上が必要です。

## インストール

### 1. ファイルを配置

```bash
git clone https://github.com/seino/yourls-report.git
```

YOURLSがインストールされているサーバーの任意のディレクトリに配置してください。

### 2. 設定ファイルを作成

```bash
cp config.sample.php config.php
```

### 3. config.phpを編集

```php
// データベース接続情報（YOURLSと同じ設定）
define('YOURLS_DB_USER', 'your_db_user');
define('YOURLS_DB_PASS', 'your_db_password');
define('YOURLS_DB_NAME', 'your_db_name');
define('YOURLS_DB_HOST', 'localhost');
define('YOURLS_DB_PREFIX', 'yourls_');

// 除外するIPアドレス（自社アクセス等）
define('EXCLUDED_IP', '0.0.0.0');

// 本番環境フラグ（本番では true に設定）
define('IS_PRODUCTION', true);

// API認証（オプション）
define('API_KEY', 'your_secret_api_key');
define('API_ALLOWED_ORIGIN', 'https://your-domain.com');

// ログイン認証（オプション）
define('REQUIRE_LOGIN', false);  // true: 有効, false: 無効
define('YOURLS_PATH', '/var/www/yourls');  // REQUIRE_LOGIN=true の場合に必要
```

### 4. ログイン設定（オプション）

ログイン認証はデフォルトで**無効**です。

認証を有効にする場合:

1. `REQUIRE_LOGIN`を`true`に設定
2. `YOURLS_PATH`にYOURLSのインストールディレクトリを設定

YOURLSの管理画面と同じユーザー名・パスワードでログインできます。

認証が無効の場合は、Webサーバー側でBasic認証やIP制限を行うことを推奨します。

## ファイル構成

```text
yourls-report/
├── login.php                # ログインページ
├── logout.php               # ログアウト処理
├── auth.php                 # 認証処理
├── yourls_report.php        # メインレポートページ
├── url_detail.php           # URL詳細ページ（日別グラフ）
├── export_csv.php           # CSV出力（URL別、詳細、日別）
├── export_url_daily_csv.php # URL別日別CSV出力
├── api.php                  # JSON API
├── utils.php                # 共通ユーティリティ
├── composer.json            # 依存・スクリプト定義
├── phpstan.neon             # 静的解析設定
├── phpunit.xml              # テスト設定
├── tests/                   # ユニットテスト
├── .github/workflows/       # CI（GitHub Actions）
├── config.php               # 設定ファイル（Git管理外）
├── config.sample.php        # 設定サンプル
├── .gitignore
└── README.md
```

## 開発

### セットアップ

```bash
composer install
```

### テスト（PHPUnit）

`config.php` に依存しない純粋なユーティリティ関数（`utils.php`）を対象にテストを実行する。

```bash
composer test          # または ./vendor/bin/phpunit
```

### 静的解析（PHPStan / level 5）

```bash
composer analyse       # または ./vendor/bin/phpstan analyse
```

### まとめて実行

```bash
composer check         # analyse → test
```

`main` への push と Pull Request では、GitHub Actions（`.github/workflows/ci.yml`）が
PHP 8.2 / 8.3 / 8.4 上で `composer validate` → PHPStan → PHPUnit を実行する。

## 使い方

### メインレポート

1. ブラウザで `yourls_report.php` にアクセス
2. 期間を指定して「集計実行」をクリック
3. タイトル/URLで検索可能
4. タイトルをクリックすると詳細ページへ遷移

### 詳細ページ

- 日別クリック数の折れ線グラフ
- 流入元（リファラー）の内訳
- CSV出力ボタン

### CSV出力

| 種類 | URL | 内容 |
|------|-----|------|
| URL別 | `export_csv.php` | URL毎のクリック数集計 |
| 詳細 | `export_csv.php?type=detail` | 全クリックログの詳細 |
| 日別 | `export_csv.php?type=daily` | 全体の日別クリック数 |
| URL日別 | `export_url_daily_csv.php` | 特定URLの日別クリック数 |

## JSON API

プログラムからデータを取得するためのJSON APIを提供しています。

### 認証

API はフェイルクローズで動作し、**有効なAPIキー、またはログインセッションのいずれか**が必須です。
どちらも無い場合は 401 を返します（`API_KEY` 未設定かつ `REQUIRE_LOGIN=false` の場合、API は常に 401）。

認証方法:

- HTTPヘッダー: `X-API-Key: your_api_key`（推奨。キーがログや Referer に残らない）
- `REQUIRE_LOGIN=true` のとき、ログイン済みセッションでもアクセス可能

> セキュリティ上の理由から、クエリパラメータ（`?api_key=`）でのキー送信は廃止しました。

### エンドポイント

#### 基本統計

```http
GET api.php?action=stats&start_date=2025-01-01&end_date=2025-01-31
```

レスポンス:

```json
{
  "total_clicks": 1234,
  "unique_urls": 50,
  "unique_ips": 800,
  "days": 31,
  "avg_per_day": 39.81
}
```

#### トップURL

```http
GET api.php?action=top_urls&start_date=2025-01-01&end_date=2025-01-31&limit=20
```

#### 日別推移

```http
GET api.php?action=daily&start_date=2025-01-01&end_date=2025-01-31
```

#### リファラー統計

```http
GET api.php?action=referrers&start_date=2025-01-01&end_date=2025-01-31
```

#### 国別統計

```http
GET api.php?action=countries&start_date=2025-01-01&end_date=2025-01-31&limit=10
```

#### URL詳細

```http
GET api.php?action=url_detail&keyword=abc123&start_date=2025-01-01&end_date=2025-01-31
```

#### リアルタイム（直近1時間）

```http
GET api.php?action=realtime
```

### CORSの設定

デフォルトでは全オリジンからのアクセスを許可しています。
本番環境では`API_ALLOWED_ORIGIN`を設定してください。

```php
define('API_ALLOWED_ORIGIN', 'https://your-domain.com');
```

## セキュリティ

- 設定ファイル（config.php）はGit管理外
- プリペアドステートメントによるSQLインジェクション対策
- 入力値のバリデーション・サニタイズ
- セキュリティヘッダー設定済み
  - X-Content-Type-Options
  - X-Frame-Options
  - X-XSS-Protection
  - Referrer-Policy
  - Content-Security-Policy
- 本番環境ではエラー詳細を非表示（ログには記録）
- API認証対応

## エラーログ

本番環境（`IS_PRODUCTION = true`）では、エラーの詳細はサーバーのエラーログに記録されます。

ログの確認方法（例）:

```bash
tail -f /var/log/apache2/error.log | grep "YOURLS Report"
```

## パフォーマンス最適化

大量データがある場合は、以下のインデックスを追加してください。

```sql
ALTER TABLE yourls_log ADD INDEX idx_click_time (click_time);
ALTER TABLE yourls_log ADD INDEX idx_shorturl (shorturl);
ALTER TABLE yourls_log ADD INDEX idx_time_url (click_time, shorturl);
```

## ライセンス

MIT License
