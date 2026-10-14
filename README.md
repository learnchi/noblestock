# NobleStock

NobleStock は、PHP フレームワーク Chandra で作成した在庫管理アプリケーションです。  
複数店舗間での商品の入出庫などの在庫管理をバーコードで操作できる、店舗や倉庫で役立つ業務アプリケーションです。

## 機能

- 商品情報・商品画像・店舗別在庫の管理
- バーコードの生成・出力と、読み取りによる入出庫・店舗間移動
- 棚卸の在庫チェックと、実績・履歴の確認
- Excelによる商品・在庫・各種マスタの一括登録・出力
- ユーザー管理と画面ごとの権限設定

## インストール

リポジトリを取得し、Composerで依存ライブラリをインストールします。

```bash
git clone https://github.com/learnchi/noblestock.git
cd noblestock
composer install
```

続いてデータベースを作成し、接続情報を設定します。環境の準備から初回ログインまでの手順は、[Windowsでの導入手順](https://learnchi.github.io/noblestock/setup.html)をご覧ください。

## ドキュメント

- [Windowsでの導入手順](https://learnchi.github.io/noblestock/setup.html)

  XAMPPとComposerの準備、DB設定、起動と初回ログインまでを案内します。
- [機能と使い方](https://learnchi.github.io/noblestock/guide.html)

  マスタ・商品の登録、商品画像、バーコードによる入出庫・移動、権限設定を紹介します。

## 動作環境

- PHP 8.2以上
- MySQL / MariaDB
- Apache
- Composer

## テスト

```bash
vendor/bin/phpunit
```

## ライセンス

MIT
