# NobleStock

NobleStock は、PHP フレームワーク Chandra 上で動作する在庫管理アプリケーションです。  
バーコード発行、入庫・出庫・移動、棚卸、各種マスタ管理、Excel 入出力を中心とした業務向けの構成になっています。

## 主な機能

- 商品・在庫・画像・ユーザー・各種マスタの管理
- バーコード生成とバーコードを使った在庫操作
- 棚卸、履歴参照、売上参照
- Excel による一括登録・出力

## 技術構成

- PHP
- Chandra
- MySQL / MariaDB
- Bootstrap 5
- PhpSpreadsheet
- PHPMailer

## セットアップ

1. 依存関係をインストールします。

```bash
composer install
```

2. データベース設定を用意します。

- `config/dbconfig.sample.ini` を `config/dbconfig.ini` としてコピーして編集する
- または環境変数 `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_CHARSET` を設定する

3. メール設定が必要な場合は、以下のいずれかで設定します。

- `config/mailconfig.sample.ini` を `config/mailconfig.ini` としてコピーして編集する
- または環境変数 `MAIL_SMTP_HOST`, `MAIL_SMTP_PORT`, `MAIL_SMTP_AUTH`, `MAIL_SMTP_USER`, `MAIL_SMTP_PASS`, `MAIL_SMTP_SECURE`, `MAIL_FROM` を設定する

4. Web サーバの公開ディレクトリを `public/` に向けます。

## テスト

```bash
vendor/bin/phpunit
```

## 公開時の注意

- `config/dbconfig.ini`, `config/mailconfig.ini` のような実運用設定は公開しないでください
- `log/`, `tmp/`, `public/uploads/` などの生成物や運用データは公開対象から除外してください
- 本リポジトリにはソースコードのみを含め、環境依存の認証情報は含めない想定です
