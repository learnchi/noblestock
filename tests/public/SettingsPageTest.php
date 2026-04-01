<?php

declare(strict_types=1);

use Noblestock\Logic\LogicConst;
use Noblestock\Logic\MessageConst;
use Tests\Support\Response;
use Tests\Support\WebClient;
use Tests\Support\WebTestCase;

final class SettingsPageTest extends WebTestCase
{
    // ログインセッションをクリア後、この画面にアクセスすると、indexに遷移する
    public function testSettingsRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('settings.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // ログインセッションをクリア後、この画面にアクセスすると、認証エラーメッセージ付きでindexに遷移する
    public function testSettingsRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('settings.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    public function testSettingsDisplays(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->get('settings.php');
        $this->assertOk($response);
    }

    // この画面に権限があるユーザー[perm16]で200で表示される
    public function testSettingsDisplaysForPerm16(): void
    {
        $this->loginAs('perm16', 'perm1600');

        $response = $this->getClient()->get('settings.php');
        $this->assertOk($response);
    }

    // この画面に権限がないユーザーnoauthで403エラーとなる
    public function testSettingsReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->get('settings.php');
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }

    // 初期表示では案内文が出ており、DBに入っている現在設定が各 select に選択された状態で表示される
    public function testSettingsShowsGuideAndCurrentSelectedValues(): void
    {
        $response = $this->openSettingsAsAdmin();

        $this->assertGuideMessage($response, MessageConst::MSG_INF_CONF_001);
        $this->assertSelectedOption($response, 'EXCEL_VAR', '1');
        $this->assertSelectedOption($response, 'BAR_PRT_SIZE', '2');
        $this->assertSelectedOption($response, 'LABEL_UPPER', '2');
        $this->assertSelectedOption($response, 'LABEL_LOWER', '4');
        $this->assertElementExists($response, "//a[@href='menu_master.php' and normalize-space()='戻る']");
        $this->assertElementExists($response, "//button[@name='mode' and @value='update' and normalize-space()='登録']");
    }

    // 4項目をまとめて更新すると、成功メッセージが出て再表示の selected と configs テーブルの値も変わる
    public function testSettingsUpdatePersistsSelectedValuesAndShowsSuccessMessage(): void
    {
        $this->prepareSettingsAsAdmin();

        $response = $this->getClient()->post('settings.php', [
            'mode' => 'update',
            'EXCEL_VAR' => '0',
            'BAR_PRT_SIZE' => '4',
            'LABEL_UPPER' => '9',
            'LABEL_LOWER' => '0',
        ]);
        $this->assertOk($response);

        $this->assertFlushSuccessMessage($response, MessageConst::MSG_OK_CONF_002);
        $this->assertSelectedOption($response, 'EXCEL_VAR', '0');
        $this->assertSelectedOption($response, 'BAR_PRT_SIZE', '4');
        $this->assertSelectedOption($response, 'LABEL_UPPER', '9');
        $this->assertSelectedOption($response, 'LABEL_LOWER', '0');

        $this->assertSame(0, $this->readConfigValueInt('EXCEL_VAR'));
        $this->assertSame(4, $this->readConfigValueInt('BAR_PRT_SIZE'));
        $this->assertSame(9, $this->readConfigValueInt('LABEL_UPPER'));
        $this->assertSame(0, $this->readConfigValueInt('LABEL_LOWER'));
    }

    // BAR_PRT_SIZE を更新したあとのセッション設定が barcode_create.php の案内文にも反映される
    public function testSettingsUpdateChangesBarcodeCreateGuideMessage(): void
    {
        $this->prepareSettingsAsAdmin();

        $updateResponse = $this->getClient()->post('settings.php', [
            'mode' => 'update',
            'EXCEL_VAR' => '1',
            'BAR_PRT_SIZE' => '5',
            'LABEL_UPPER' => '2',
            'LABEL_LOWER' => '4',
        ]);
        $this->assertOk($updateResponse);

        $response = $this->getClient()->get('barcode_create.php');
        $this->assertOk($response);
        $this->assertStringContainsString(
            MessageConst::MSG_INF_BARCODE_001 . LogicConst::BAR_PRT_SIZE_DEFS[5],
            $response->body
        );
    }

    // 選択肢にない設定値をPOSTした場合は 400 を返して更新しない
    public function testSettingsReturns400WhenPostedValueIsOutsideAllowedOptions(): void
    {
        $this->prepareSettingsAsAdmin();

        $response = $this->getClient()->post('settings.php', [
            'mode' => 'update',
            'EXCEL_VAR' => '999',
            'BAR_PRT_SIZE' => '2',
            'LABEL_UPPER' => '2',
            'LABEL_LOWER' => '4',
        ]);

        $this->assertSame(400, $response->status);
        $this->assertStringContainsString(MessageConst::MSG_VAL_FILE_018, $response->body);
        $this->assertSame(1, $this->readConfigValueInt('EXCEL_VAR'));
    }

    private function openSettingsAsAdmin(): Response
    {
        $this->prepareSettingsAsAdmin();

        $response = $this->getClient()->get('settings.php');
        $this->assertOk($response);

        return $response;
    }

    private function prepareSettingsAsAdmin(): void
    {
        \Tests\Support\TestDatabase::seed();
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->loginAsAdmin();
        $this->getClient()->get('menu.php');
    }

    // info アラートの案内文を確認する
    private function assertGuideMessage(Response $response, string $expectedMessage): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//div[contains(@class,'alert-info')]/div");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Guide message alert was not found.');
        $this->assertSame($expectedMessage, $this->normalizeText($nodes->item(0)?->textContent ?? ''));
    }

    // success アラートに更新成功メッセージが出ていることを確認する
    private function assertFlushSuccessMessage(Response $response, string $expectedMessage): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//div[contains(@class,'alert-success')]/div");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Flush success alert was not found.');
        $this->assertSame($expectedMessage, $this->normalizeText($nodes->item(0)?->textContent ?? ''));
    }

    // select の selected 状態が期待どおりか確認する
    private function assertSelectedOption(Response $response, string $selectName, string $expectedValue): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//select[@name='{$selectName}']/option[@selected='selected']");
        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length, "Selected option for {$selectName} was not found.");
        $this->assertSame($expectedValue, (string) $nodes->item(0)?->attributes?->getNamedItem('value')?->nodeValue);
    }

    private function assertElementExists(Response $response, string $xpathExpression): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query($xpathExpression);
        $this->assertNotFalse($nodes, "Invalid XPath: {$xpathExpression}");
        $this->assertSame(1, $nodes->length, "Unexpected node count for XPath: {$xpathExpression}");
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

    private function normalizeText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
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
    // セッション期限切れ時に認証情報と画面用セッションが破棄されることを確認する
    public function testSettingsClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('settings.php');
    }
}
