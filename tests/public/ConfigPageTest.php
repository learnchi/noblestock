<?php

declare(strict_types=1);

use Noblestock\Logic\MessageConst;
use Tests\Support\Response;
use Tests\Support\WebClient;
use Tests\Support\WebTestCase;

final class ConfigPageTest extends WebTestCase
{
    // ログインセッションをクリア後、この画面にアクセスすると、indexに遷移する
    public function testConfigRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('config.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // ログインセッションをクリア後、この画面にアクセスすると、認証エラーメッセージ付きでindexに遷移する
    public function testConfigRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('config.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    public function testConfigDisplays(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->get('config.php');
        $this->assertOk($response);
    }

    // この画面に権限があるユーザー[perm19]で200で表示される
    public function testConfigDisplaysForPerm19(): void
    {
        $this->loginAs('perm19', 'perm1900');

        $response = $this->getClient()->get('config.php');
        $this->assertOk($response);
    }

    // この画面に権限がないユーザーnoauthで403エラーとなる
    public function testConfigReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->get('config.php');
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }

    // 初期表示では案内文と現在の機能設定一覧が表示される
    public function testConfigShowsGuideAndCurrentConfigRows(): void
    {
        $response = $this->openConfigAsAdmin();

        $this->assertGuideMessage($response, MessageConst::MSG_INF_CONF_003);
        $this->assertConfigRow($response, 'BAR_PRO_FLG', '1');
        $this->assertConfigRow($response, 'SHIP_FLG', '3');
        $this->assertConfigRow($response, 'STOCK_LOW_INTERVAL', '0');
        $this->assertConfigRow($response, 'STOCK_LOW_LIMIT', '10');
        $this->assertElementExists($response, "//a[@href='menu_master.php' and normalize-space()='戻る']");
        $this->assertElementExists($response, "//button[@name='mode' and @value='update' and normalize-space()='登録']");
    }

    // 4項目をまとめて更新すると、成功メッセージが出てDBと再表示の値も変わる
    public function testConfigUpdatePersistsValuesAndShowsSuccessMessage(): void
    {
        $this->prepareConfigAsAdmin();
        $configResponse = $this->getClient()->get('config.php');
        $this->assertOk($configResponse);

        $response = $this->getClient()->post('config.php', [
            'mode' => 'update',
            'BAR_PRO_FLG' => '0',
            'SHIP_FLG' => '1',
            'STOCK_LOW_INTERVAL' => '7',
            'STOCK_LOW_LIMIT' => '12',
        ] + $this->extractCsrfPostData($configResponse));
        $this->assertOk($response);

        $this->assertFlushSuccessMessage($response, MessageConst::MSG_OK_CONF_002);
        $this->assertConfigInputValue($response, 'BAR_PRO_FLG', '0');
        $this->assertConfigInputValue($response, 'SHIP_FLG', '1');
        $this->assertConfigInputValue($response, 'STOCK_LOW_INTERVAL', '7');
        $this->assertConfigInputValue($response, 'STOCK_LOW_LIMIT', '12');

        $this->assertSame(0, $this->readConfigValueInt('BAR_PRO_FLG'));
        $this->assertSame(1, $this->readConfigValueInt('SHIP_FLG'));
        $this->assertSame(7, $this->readConfigValueInt('STOCK_LOW_INTERVAL'));
        $this->assertSame(12, $this->readConfigValueInt('STOCK_LOW_LIMIT'));
    }

    // BAR_PRO_FLG を 0 にすると product_list のバーコード選択列が消える
    public function testConfigUpdateChangesProductListBarcodeSelectionColumn(): void
    {
        $this->prepareConfigAsAdmin();
        $configResponse = $this->getClient()->get('config.php');
        $this->assertOk($configResponse);

        $this->getClient()->post('config.php', [
            'mode' => 'update',
            'BAR_PRO_FLG' => '0',
            'SHIP_FLG' => '3',
            'STOCK_LOW_INTERVAL' => '0',
            'STOCK_LOW_LIMIT' => '0',
        ] + $this->extractCsrfPostData($configResponse));

        $response = $this->getClient()->get('product_list.php');
        $this->assertOk($response);

        $this->assertXPathCount($response, "//button[@id='btnSelectAll']", 0);
        $this->assertXPathCount($response, "//input[contains(@class,'js-product-check')]", 0);
    }

    private function openConfigAsAdmin(): Response
    {
        $this->prepareConfigAsAdmin();

        $response = $this->getClient()->get('config.php');
        $this->assertOk($response);

        return $response;
    }

    private function prepareConfigAsAdmin(): void
    {
        \Tests\Support\TestDatabase::seed();
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->loginAsAdmin();
        $this->getClient()->get('menu.php');
    }

    private function assertGuideMessage(Response $response, string $expectedMessage): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//div[contains(@class,'alert-info')]/div");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Guide message alert was not found.');
        $this->assertSame($expectedMessage, $this->normalizeText($nodes->item(0)?->textContent ?? ''));
    }

    private function assertFlushSuccessMessage(Response $response, string $expectedMessage): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//div[contains(@class,'alert-success')]/div");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Flush success alert was not found.');
        $this->assertSame($expectedMessage, $this->normalizeText($nodes->item(0)?->textContent ?? ''));
    }

    // 項目名と設定値の組み合わせが一覧表にあることを確認する
    private function assertConfigRow(Response $response, string $configKey, string $expectedValue): void
    {
        $this->assertElementExists(
            $response,
            "//table//tr[td/input[@value='{$configKey}'] and td/input[@name='{$configKey}' and @value='{$expectedValue}']]"
        );
    }

    private function assertConfigInputValue(Response $response, string $configKey, string $expectedValue): void
    {
        $this->assertElementExists(
            $response,
            "//input[@name='{$configKey}' and @value='{$expectedValue}']"
        );
    }

    private function assertElementExists(Response $response, string $xpathExpression): void
    {
        $this->assertXPathCount($response, $xpathExpression, 1);
    }

    private function assertXPathCount(Response $response, string $xpathExpression, int $expectedCount): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query($xpathExpression);
        $this->assertNotFalse($nodes, "Invalid XPath: {$xpathExpression}");
        $this->assertSame($expectedCount, $nodes->length, "Unexpected node count for XPath: {$xpathExpression}");
    }

    private function readConfigValueInt(string $configKey): int
    {
        $pdo = $this->createTestPdo();
        $stmt = $pdo->prepare('SELECT value_int FROM configs WHERE config_key = :config_key');
        $stmt->execute([
            'config_key' => $configKey,
        ]);
        $value = $stmt->fetchColumn();

        $this->assertNotFalse($value, "Config {$configKey} was not found.");

        return (int) $value;
    }

    private function createTestPdo(): PDO
    {
        $configPath = dirname(__DIR__, 2) . '/config/dbconfig.ini';
        $config = parse_ini_file($configPath);

        $this->assertIsArray($config, 'Failed to read dbconfig.ini.');
        foreach (['dbhost', 'dbuser', 'dbpass', 'dbname'] as $requiredKey) {
            $this->assertArrayHasKey($requiredKey, $config, "Missing {$requiredKey} in dbconfig.ini.");
        }

        return new PDO(
            sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                (string) $config['dbhost'],
                (string) $config['dbname']
            ),
            (string) $config['dbuser'],
            (string) $config['dbpass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private function normalizeText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
    // セッション期限切れ時に認証情報と画面用セッションが破棄されることを確認する
    public function testConfigClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('config.php');
    }
}
