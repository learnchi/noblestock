<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Web 系テストで使う URL 設定をまとめて扱うヘルパーです。
 *
 * 役割:
 * - `tests/testweb.ini` からテスト用の base_url を読む
 * - `/public` 配下のパスを組み立てる
 * - `$_SERVER['SCRIPT_NAME']` 用の値を統一する
 *
 * テストコード側で URL を直接書かず、このクラス経由で取得することで、
 * 実行環境ごとの差分を 1 か所の設定で吸収できるようにしています。
 */
final class TestWebConfig
{
    /**
     * テスト専用の Web URL 設定ファイルです。
     *
     * 例:
     * - `tests/testweb.ini`
     */
    private const CONFIG_PATH = '/tests/testweb.ini';

    /**
     * 設定ファイルは毎回読み直さず、最初に読んだ値を保持します。
     */
    private static ?string $baseUrl = null;

    /**
     * テストで使う base_url を返します。
     *
     * `tests/testweb.ini` の `base_url` を読み、
     * 末尾の `/` は扱いやすいように削って返します。
     *
     * 設定ファイルが無い、または `base_url` が空のときは、
     * 何を用意すればよいか分かるように例外メッセージを出します。
     */
    public static function baseUrl(): string
    {
        if (self::$baseUrl !== null) {
            return self::$baseUrl;
        }

        $configPath = dirname(__DIR__, 2) . self::CONFIG_PATH;
        $config = parse_ini_file($configPath);

        if (!is_array($config)) {
            throw new \RuntimeException(
                'Failed to read test web config: ' . $configPath
                . '. Copy tests/testweb.sample.ini to tests/testweb.ini and set base_url.'
            );
        }

        $baseUrl = trim((string) ($config['base_url'] ?? ''));
        if ($baseUrl === '') {
            throw new \RuntimeException(
                'Missing base_url in ' . $configPath
                . '. Set the public URL used by web tests.'
            );
        }

        return self::$baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * base_url のうち、パス部分だけを返します。
     *
     * 例:
     * - `http://localhost/noblestock/public`
     *   → `/noblestock/public`
     */
    public static function publicBasePath(): string
    {
        return rtrim((string) parse_url(self::baseUrl(), PHP_URL_PATH), '/');
    }

    /**
     * `public` 配下の URL パスを返します。
     *
     * 例:
     * - `publicPath()` → `/noblestock/public`
     * - `publicPath('menu.php')` → `/noblestock/public/menu.php`
     *
     * 先頭に `/` が付いた値は、そのまま使います。
     */
    public static function publicPath(string $path = ''): string
    {
        if ($path === '') {
            return self::publicBasePath();
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return self::publicBasePath() . '/' . ltrim($path, '/');
    }

    /**
     * `$_SERVER['SCRIPT_NAME']` に入れる想定のパスを返します。
     *
     * 空文字が渡された場合は `index.php` を補います。
     * セッション操作や CSRF 発行のテストで、
     * 実際の画面と同じ `SCRIPT_NAME` を再現したいときに使います。
     */
    public static function scriptName(string $path = 'index.php'): string
    {
        return self::publicPath($path === '' ? 'index.php' : $path);
    }
}
