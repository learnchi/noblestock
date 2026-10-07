<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Support\SessionHelper;
use Studiogau\Chandra\Support\Utility;

/**
 * Web画面向けテストの共通基底クラス。
 *
 * - WebClient の初期化
 * - ログイン共通処理
 * - HTTP 200 の共通アサート
 * - WebClient と同一セッションを操作する補助
 *
 * をまとめて提供する。
 */
abstract class WebTestCase extends TestCase
{
    protected static ?WebClient $client = null;
    private const BASE_URL = 'http://localhost/noblestock/public';

    /**
     * クラス単位の初期化。
     *
     * テストDBをシードし、全テストで共有する WebClient を生成する。
     */
    public static function setUpBeforeClass(): void
    {
        TestDatabase::seed();
        self::$client = new WebClient(self::BASE_URL);
    }

    /**
     * 共有 WebClient を返す。
     *
     * 使い方:
     * - `$response = $this->getClient()->get('menu.php');`
     */
    protected function getClient(): WebClient
    {
        if (self::$client === null) {
            self::$client = new WebClient(self::BASE_URL);
        }
        return self::$client;
    }

    /**
     * 管理者ユーザーでログインするショートカット。
     *
     * 使い方:
     * - `$this->loginAsAdmin();`
     */
    protected function loginAsAdmin(): void
    {
        $this->loginAs('admin', 'admin000');
    }

    /**
     * 指定ユーザーでログインする。
     *
     * 前提:
     * - ログイン前に `index.php` へアクセスして、ログイン判定が必ず実行される状態にする。
     *
     * 使い方:
     * - `$this->loginAs('perm01', 'perm0100');`
     */
    protected function loginAs(string $user, string $pass): void
    {
        // index.php に先にアクセスして既存の user セッションをクリアし、ログインPOSTを確実に評価させる
        $this->getClient()->get('index.php');
        $response = $this->getClient()->login($user, $pass);
        $this->assertSame(200, $response->status, "Login response should be 200 for {$user}");
    }

    /**
     * レスポンスが HTTP 200 であることを検証する共通アサート。
     *
     * 使い方:
     * - `$response = $this->getClient()->get('menu.php');`
     * - `$this->assertOk($response);`
     */
    protected function assertOk(Response $response, string $message = ''): void
    {
        $this->assertSame(200, $response->status, $message ?: "Expected 200 from {$response->url}");
    }

    /**
     * 自動リダイレクト前のステータスを検証する。100 Continue 等は除外する。
     * Response::status は遷移後のステータスなので、保存されたヘッダーを調べる。
     */
    protected function assertInitialStatus(Response $response, int $expected): void
    {
        $matched = preg_match('/^HTTP\/\S+ ([2-5][0-9]{2})\b/m', $response->headers, $matches);
        $this->assertSame(1, $matched, 'Initial HTTP status was not found.');
        $this->assertSame($expected, (int) $matches[1], "Unexpected initial status from {$response->url}");
    }

    /**
     * POST の303応答と Location ヘッダーの遷移先を検証する共通アサート。
     *
     * @param array<string, mixed> $postData
     */
    protected function assertPostRedirect(
        string $path,
        array $postData,
        string $expectedLocation,
        bool $loginAsAdminBeforeRequest = false
    ): Response {
        if ($loginAsAdminBeforeRequest) {
            $this->loginAsAdmin();
        }

        $response = $this->getClient()->post($path, $postData);
        $this->assertInitialStatus($response, 303);
        $this->assertOk($response);
        $this->assertStringContainsString("Location: {$expectedLocation}", $response->headers);

        return $response;
    }

    /**
     * 現在の WebClient が保持している PHPSESSID を返す。
     */
    protected function currentWebSessionId(): string
    {
        return $this->readPhpSessionIdFromCookieJar();
    }

    protected function assertSuccessfulLoginRegeneratesSessionId(
        string $user = 'admin',
        string $pass = 'admin000'
    ): void {
        $this->getClient()->get('index.php');
        $sessionIdBeforeLogin = (string) ($this->runAuthSessionBridge('snapshot')['sessionId'] ?? '');

        $response = $this->getClient()->login($user, $pass);
        $snapshotAfterLogin = $this->runAuthSessionBridge('snapshot');

        $this->assertOk($response);
        $this->assertNotSame($sessionIdBeforeLogin, (string) ($snapshotAfterLogin['sessionId'] ?? ''));
        $this->assertIsString($snapshotAfterLogin['user'] ?? null);
    }

    protected function assertLogoutClearsAuthenticatedSessionDataAndUser(
        string $logoutPath = 'index.php'
    ): void {
        $this->loginAsAdmin();
        $this->runAuthSessionBridge('seedMarker');

        $response = $this->getClient()->get($logoutPath);
        $snapshotAfterLogout = $this->runAuthSessionBridge('snapshot');

        $this->assertOk($response);
        $this->assertNull($snapshotAfterLogout['user'] ?? null);
        $this->assertNull($snapshotAfterLogout['marker'] ?? null);
    }

    /**
     * @param array<string, mixed> $request
     */
    protected function assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage(
        string $page,
        array $request = []
    ): void {
        $this->loginAsAdmin();
        $this->runAuthSessionBridge('seedMarker');
        $this->runAuthSessionBridge('expireUser');

        $bridgeName = $this->createTimeoutBridge($page);

        try {
            $response = $this->requestTimedOutPage($bridgeName, $request);
        } finally {
            @unlink(dirname(__DIR__, 2) . '/public/' . $bridgeName);
        }

        $snapshotAfterTimeout = $this->runAuthSessionBridge('snapshot');

        $this->assertOk($response);
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(\Noblestock\Logic\MessageConst::MSG_INF_AUTH_002, $response->body);
        $this->assertNull($snapshotAfterTimeout['user'] ?? null);
        $this->assertNull($snapshotAfterTimeout['marker'] ?? null);
    }

    /**
     * WebClient が使っている PHP セッション（PHPSESSID）上で任意処理を実行する。
     *
     * 目的:
     * - PHPUnitプロセス側の `$_SESSION` ではなく、HTTPリクエスト側と同一セッションを操作するため。
     *
     * 引数:
     * - `$action`: 実行したい処理（SessionHelper::setData/delData など）
     * - `$scriptName`: SessionHelper の projectPrefix 判定に使う SCRIPT_NAME
     *
     * 使い方:
     * - `$this->withCurrentWebSession(function (): void {`
     * - `    SessionHelper::setData('biz002', 'selectedMngNos', ['ABC005']);`
     * - `}, 'product_barcode_list.php');`
     */
    protected function withCurrentWebSession(callable $action, string $scriptName = 'index.php'): void
    {
        $targetSessionId = $this->readPhpSessionIdFromCookieJar();
        $normalizedScriptName = $this->normalizeScriptName($scriptName);

        $wasSessionActive = session_status() === PHP_SESSION_ACTIVE;
        $previousSessionId = $wasSessionActive ? session_id() : null;
        $previousSession = $wasSessionActive ? ($_SESSION ?? []) : null;
        $previousScriptName = $_SERVER['SCRIPT_NAME'] ?? null;

        if ($wasSessionActive) {
            session_write_close();
        }

        session_id($targetSessionId);
        session_start();
        $_SERVER['SCRIPT_NAME'] = $normalizedScriptName;

        try {
            $action();
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            if ($wasSessionActive && $previousSessionId !== null) {
                session_id($previousSessionId);
                session_start();
                $_SESSION = $previousSession ?? [];
            }

            if ($previousScriptName === null) {
                unset($_SERVER['SCRIPT_NAME']);
            } else {
                $_SERVER['SCRIPT_NAME'] = $previousScriptName;
            }
        }
    }

    /**
     * 構造化セッション（SessionHelper::setData）へ値を設定するヘルパー。
     *
     * 使い方:
     * - `$this->setSessionStructData('biz002', 'selectedMngNos', ['ABC005', 'ABC002'], 'product_barcode_list.php');`
     */
    protected function setSessionStructData(
        string $func,
        string $key,
        mixed $value,
        string $scriptName = 'index.php'
    ): void {
        $this->withCurrentWebSession(
            static function () use ($func, $key, $value): void {
                SessionHelper::setData($func, $key, $value);
            },
            $scriptName
        );
    }

    /**
     * 構造化セッション（SessionHelper::delData）を削除するヘルパー。
     *
     * 使い方:
     * - キー単位削除: `$this->delSessionStructData('biz002', 'selectedMngNos', 'product_barcode_list.php');`
     * - func単位削除: `$this->delSessionStructData('biz002', null, 'product_barcode_list.php');`
     */
    protected function delSessionStructData(
        string $func,
        ?string $key = null,
        string $scriptName = 'index.php'
    ): void {
        $this->withCurrentWebSession(
            static function () use ($func, $key): void {
                SessionHelper::delData($func, $key);
            },
            $scriptName
        );
    }

    /**
     * 現在の Web セッションに対して、有効な CSRF hidden 項目一式を発行する。
     *
     * @return array<string, string>
     */
    protected function issueCsrfPostData(
        string $scope,
        string $scriptName = 'index.php'
    ): array {
        $token = '';

        $this->withCurrentWebSession(
            static function () use ($scope, &$token): void {
                $token = Utility::issueCsrfToken($scope);
            },
            $scriptName
        );

        return [
            Utility::getCsrfScopeFieldName() => $scope,
            Utility::getCsrfFieldName() => $token,
        ];
    }

    /**
     * 画面に埋め込まれた CSRF hidden 2項目を取り出す。
     *
     * @return array<string, string>
     */
    protected function extractCsrfPostData(Response $response): array
    {
        $xpath = new \DOMXPath($response->dom());
        $token = (string) ($xpath->query("//input[@name='" . Utility::getCsrfFieldName() . "']")?->item(0)?->attributes?->getNamedItem('value')?->nodeValue ?? '');
        $scope = (string) ($xpath->query("//input[@name='" . Utility::getCsrfScopeFieldName() . "']")?->item(0)?->attributes?->getNamedItem('value')?->nodeValue ?? '');

        $this->assertNotSame('', $scope, 'CSRF scope hidden input was not found.');
        $this->assertNotSame('', $token, 'CSRF token hidden input was not found.');

        return [
            Utility::getCsrfScopeFieldName() => $scope,
            Utility::getCsrfFieldName() => $token,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function runAuthSessionBridge(string $mode): array
    {
        $bridgeName = '__auth_session_' . bin2hex(random_bytes(8)) . '.php';
        $bridgePath = dirname(__DIR__, 2) . '/public/' . $bridgeName;
        $bridgeCode = <<<'PHP'
<?php
declare(strict_types=1);

session_cache_limiter("none");
session_start();
date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../vendor/autoload.php';

use Studiogau\Chandra\Auth\LoginUser;
use Studiogau\Chandra\Support\SessionHelper;

$mode = (string) ($_POST['mode'] ?? 'snapshot');

if ($mode === 'seedMarker') {
    SessionHelper::setData('auth_session_behavior', 'marker', 'alive');
} elseif ($mode === 'expireUser') {
    $serialized = SessionHelper::getUser();
    if (is_string($serialized) && $serialized !== '') {
        $loginUser = @unserialize($serialized, ['allowed_classes' => [LoginUser::class]]);
        if ($loginUser instanceof LoginUser) {
            $loginUser->setStartTime(time() - 10);
            SessionHelper::setUser($loginUser);
        }
    }
}

header('Content-Type: application/json');
echo json_encode([
    'sessionId' => session_id(),
    'user' => SessionHelper::getUser(),
    'marker' => SessionHelper::getData('auth_session_behavior', 'marker'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
PHP;

        file_put_contents($bridgePath, $bridgeCode);

        try {
            $response = $this->getClient()->post($bridgeName, [
                'mode' => $mode,
                '_skip_auto_csrf' => true,
            ]);
        } finally {
            @unlink($bridgePath);
        }

        $this->assertSame(200, $response->status);

        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    protected function createTimeoutBridge(string $targetPage): string
    {
        $bridgeName = '__auth_timeout_' . bin2hex(random_bytes(8)) . '.php';
        $bridgePath = dirname(__DIR__, 2) . '/public/' . $bridgeName;
        $normalizedTarget = ltrim($targetPage, '/');
        $bridgeCode = <<<PHP
<?php
declare(strict_types=1);

namespace Studiogau\Chandra\Config {
    class ChandraConst
    {
        public const LOGIN_TIMEOUT = 1;
        public const MAX_UPLOAD_IMAGE_SIZE = 2000000;
    }
}

namespace {
    require __DIR__ . '/{$normalizedTarget}';
}
PHP;

        file_put_contents($bridgePath, $bridgeCode);

        return $bridgeName;
    }

    /**
     * @param array<string, mixed> $request
     */
    protected function requestTimedOutPage(string $bridgeName, array $request): Response
    {
        $method = strtoupper((string) ($request['method'] ?? 'GET'));
        $payload = is_array($request['payload'] ?? null) ? $request['payload'] : [];

        if ($method === 'POST') {
            return $this->getClient()->post($bridgeName, $payload);
        }

        return $this->getClient()->get($bridgeName);
    }

    /**
     * SCRIPT_NAME を SessionHelper 用に正規化する。
     *
     * - 空文字: `/your-project/public/index.php`
     * - 先頭 `/` なし: `/your-project/public/` を補完
     * - 先頭 `/` あり: そのまま使用
     */
    private function normalizeScriptName(string $scriptName): string
    {
        if ($scriptName === '') {
            return '/noblestock/public/index.php';
        }

        if (str_starts_with($scriptName, '/')) {
            return $scriptName;
        }

        return '/noblestock/public/' . ltrim($scriptName, '/');
    }

    /**
     * WebClient の cookie jar から PHPSESSID を取得する。
     *
     * 取得できない場合はテスト失敗とする。
     */
    private function readPhpSessionIdFromCookieJar(): string
    {
        $ref = new \ReflectionClass($this->getClient());
        $cookieFileProp = $ref->getProperty('cookieFile');
        $cookieFileProp->setAccessible(true);

        $cookieFile = (string) $cookieFileProp->getValue($this->getClient());
        $cookieLines = @file($cookieFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach (array_reverse($cookieLines) as $line) {
            if (str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode("\t", $line);
            if (count($parts) >= 7 && $parts[5] === 'PHPSESSID') {
                return trim($parts[6]);
            }
        }

        $this->fail('PHPSESSID cookie was not found in WebClient cookie jar.');
    }
}
