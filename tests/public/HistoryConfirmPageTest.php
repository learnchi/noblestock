<?php

declare(strict_types=1);

use Noblestock\Logic\MessageConst;
use Tests\Support\Response;
use Tests\Support\TestDatabase;
use Tests\Support\WebClient;
use Tests\Support\WebTestCase;

final class HistoryConfirmPageTest extends WebTestCase
{
    // ログインしていない状態ではこの画面に直接アクセスできず、index.php に戻される
    public function testHistoryConfirmRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('history_confirm.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // セッション切れ時も index.php に戻り、期限切れメッセージが表示される
    public function testHistoryConfirmRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('history_confirm.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    public function testHistoryConfirmDisplays(): void
    {
        $response = $this->openHistoryConfirmAsAdmin([
            'mode' => 'confirm',
            'stock_in' => '130',
            'quantity' => '0',
            'move_stock' => '0',
            'location_stock' => '130',
            'stock' => '130',
        ]);

        $this->assertOk($response);
    }

    // 実績更新権限を持つ perm09 でも表示できる
    public function testHistoryConfirmDisplaysForPerm09(): void
    {
        $this->prepareHistoryConfirmAs('perm09', 'perm0900');
        $editResponse = $this->openHistoryEditFromSalesShow();

        $response = $this->postHistoryConfirm([
            'mode' => 'confirm',
            'stock_in' => '130',
            'quantity' => '0',
            'move_stock' => '0',
            'location_stock' => '130',
            'stock' => '130',
        ], $editResponse);

        $this->assertOk($response);
    }

    // stock_in が数値以外の場合は history_edit.php に戻してエラーを表示する
    public function testHistoryConfirmRedirectsToHistoryEditWhenStockInIsNotNumeric(): void
    {
        $this->assertHistoryConfirmValidationError(
            21,
            ['stock_in' => 'invalid'],
            'stock_in',
            MessageConst::MSG_VAL_PRODUCT_004
        );
    }

    // quantity が数値以外の場合は history_edit.php に戻してエラーを表示する
    public function testHistoryConfirmRedirectsToHistoryEditWhenQuantityIsNotNumeric(): void
    {
        $this->assertHistoryConfirmValidationError(
            23,
            ['quantity' => 'invalid'],
            'quantity',
            MessageConst::MSG_VAL_PRODUCT_004
        );
    }

    // move_stock が数値以外の場合は history_edit.php に戻してエラーを表示する
    public function testHistoryConfirmRedirectsToHistoryEditWhenMoveStockIsNotNumeric(): void
    {
        $this->assertHistoryConfirmValidationError(
            25,
            ['move_stock' => 'invalid'],
            'move_stock',
            MessageConst::MSG_VAL_PRODUCT_004
        );
    }

    // location_stock が数値以外の場合は history_edit.php に戻してエラーを表示する
    public function testHistoryConfirmRedirectsToHistoryEditWhenLocationStockIsNotNumeric(): void
    {
        $this->assertHistoryConfirmValidationError(
            21,
            ['location_stock' => 'invalid'],
            'location_stock',
            MessageConst::MSG_VAL_PRODUCT_004
        );
    }

    // stock が数値以外の場合は history_edit.php に戻してエラーを表示する
    public function testHistoryConfirmRedirectsToHistoryEditWhenStockIsNotNumeric(): void
    {
        $this->assertHistoryConfirmValidationError(
            21,
            ['stock' => 'invalid'],
            'stock',
            MessageConst::MSG_VAL_PRODUCT_004
        );
    }

    // 権限のない noauth では 403 になる
    public function testHistoryConfirmReturns403ForNoAuth(): void
    {
        $this->prepareHistoryConfirmAs('noauth', 'noauth00');

        $response = $this->getClient()->post('history_confirm.php', [
            'mode' => 'confirm',
        ]);
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }

    // 確認画面では更新予定の数値がそのまま表示され、戻る・更新ボタンが並ぶ
    public function testHistoryConfirmShowsPostedValuesAndButtons(): void
    {
        $response = $this->openHistoryConfirmAsAdmin([
            'mode' => 'confirm',
            'stock_in' => '130',
            'quantity' => '0',
            'move_stock' => '0',
            'location_stock' => '130',
            'stock' => '130',
        ]);

        $this->assertStringContainsString('value="130"', $response->body);
        $this->assertStringContainsString('formaction="history_edit.php"', $response->body);
        $this->assertStringContainsString('formaction="history_confirm.php"', $response->body);
        $this->assertStringContainsString('value="update"', $response->body);
        $this->assertStringContainsString('value="back"', $response->body);
    }

    // 戻るでは history_edit.php に入力値を保持したまま戻る
    public function testHistoryConfirmBackReturnsToHistoryEditWithEditedValues(): void
    {
        $this->prepareHistoryConfirmAsAdmin();
        $editResponse = $this->openHistoryEdit(21, 'history_list');
        $confirmResponse = $this->postHistoryConfirm([
            'mode' => 'confirm',
            'stock_in' => '130',
            'quantity' => '0',
            'move_stock' => '0',
            'location_stock' => '130',
            'stock' => '130',
        ], $editResponse);

        $response = $this->getClient()->post('history_edit.php', [
            'mode' => 'back',
        ] + $this->extractCsrfPostData($confirmResponse));

        $this->assertOk($response);
        $this->assertInputValue($response, 'stock_in', '130');
        $this->assertInputValue($response, 'location_stock', '130');
        $this->assertInputValue($response, 'stock', '130');
    }

    // 更新では histories が書き換わり、戻り先画面へ戻る
    public function testHistoryConfirmUpdatePersistsEditedValuesAndRedirectsBack(): void
    {
        $this->prepareHistoryConfirmAsAdmin();
        $editResponse = $this->openHistoryEdit(21, 'history_list');
        $confirmResponse = $this->postHistoryConfirm([
            'mode' => 'confirm',
            'stock_in' => '130',
            'quantity' => '0',
            'move_stock' => '0',
            'location_stock' => '130',
            'stock' => '130',
        ], $editResponse);

        $response = $this->getClient()->post('history_confirm.php', [
            'mode' => 'update',
        ] + $this->extractCsrfPostData($confirmResponse));

        $this->assertOk($response);
        $this->assertStringContainsString('Location:history_list.php', str_replace(' ', '', $response->headers));
        $this->assertSame(130, $this->readHistoryInt(21, 'stock_in'));
        $this->assertSame(130, $this->readHistoryInt(21, 'location_stock'));
        $this->assertSame(130, $this->readHistoryInt(21, 'stock'));
    }

    // POST で正しい mode がなければ 400 にする
    public function testNonDataPostAccessReturns400(): void
    {
        $this->prepareHistoryConfirmAsAdmin();

        $response = $this->getClient()->post('history_confirm.php', [
            'mode' => 'invalid',
            '_skip_auto_csrf' => true,
        ]);
        $this->assertSame(400, $response->status);
        $this->assertStringContainsString(MessageConst::MSG_VAL_FILE_018, $response->body);
    }

    // GET だけで開いた場合も、セッションに確認対象がなければ 400 にする
    public function testNonDataGetAccessReturns400(): void
    {
        $this->prepareHistoryConfirmAsAdmin();

        $response = $this->getClient()->get('history_confirm.php');
        $this->assertSame(400, $response->status);
        $this->assertStringContainsString(MessageConst::MSG_VAL_FILE_018, $response->body);
    }

    private function prepareHistoryConfirmAsAdmin(): void
    {
        $this->prepareHistoryConfirmAs('admin', 'admin000');
    }

    private function prepareHistoryConfirmAs(string $loginId, string $password): void
    {
        TestDatabase::seed();
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->loginAs($loginId, $password);
        $this->getClient()->get('menu.php');
    }

    /**
     * @param array<string, string> $postData
     */
    private function openHistoryConfirmAsAdmin(array $postData): Response
    {
        $this->prepareHistoryConfirmAsAdmin();
        $editResponse = $this->openHistoryEdit(21, 'history_list');

        return $this->postHistoryConfirm($postData, $editResponse);
    }

    private function openHistoryEdit(int $historyNo, string $filename): Response
    {
        $sourceResponse = $this->openHistoryListWithRows();

        $response = $this->getClient()->post('history_edit.php', [
            'HISTORY_NO' => (string) $historyNo,
            'filename' => $filename,
        ] + $this->extractCsrfPostData($sourceResponse));
        $this->assertOk($response);

        return $response;
    }

    /**
     * @param array<string, string> $postDataOverrides
     */
    private function assertHistoryConfirmValidationError(
        int $historyNo,
        array $postDataOverrides,
        string $fieldName,
        string $expectedMessage
    ): void {
        $this->prepareHistoryConfirmAsAdmin();
        $editResponse = $this->openHistoryEdit($historyNo, 'history_list');

        $postData = [
            'mode' => 'confirm',
            'stock_in' => '130',
            'quantity' => '50',
            'move_stock' => '30',
            'location_stock' => '130',
            'stock' => '130',
        ];
        $postData = array_merge($postData, $postDataOverrides);

        $response = $this->getClient()->post('history_confirm.php', $postData + $this->extractCsrfPostData($editResponse));

        $this->assertOk($response);
        $this->assertStringContainsString('Location: history_edit.php', $response->headers);
        $this->assertStringContainsString($expectedMessage, $response->body);
        $this->assertInputValue($response, $fieldName, (string) $postData[$fieldName]);
    }

    private function openHistoryEditFromSalesShow(): Response
    {
        $sourceResponse = $this->getClient()->get('sales_show.php?' . http_build_query([
            'mn' => 'ABC001',
            'bn' => '2',
            'df' => '2026-01-01',
            'dt' => '2026-01-31',
        ]));
        $this->assertOk($sourceResponse);

        $xpath = new DOMXPath($sourceResponse->dom());
        $historyNo = (string) ($xpath->query('(//form[@action="history_edit.php"]//input[@name="HISTORY_NO"])[1]')?->item(0)?->attributes?->getNamedItem('value')?->nodeValue ?? '');
        $this->assertNotSame('', $historyNo, 'sales_show.php did not contain a history_edit form.');

        $response = $this->getClient()->post('history_edit.php', [
            'HISTORY_NO' => $historyNo,
            'filename' => 'sales_show',
        ] + $this->extractCsrfPostData($sourceResponse));
        $this->assertOk($response);

        return $response;
    }

    /**
     * @param array<string, string> $postData
     */
    private function postHistoryConfirm(array $postData, Response $sourceResponse): Response
    {
        $response = $this->getClient()->post('history_confirm.php', $postData + $this->extractCsrfPostData($sourceResponse));
        $this->assertOk($response);

        return $response;
    }

    private function openHistoryListWithRows(): Response
    {
        $response = $this->getClient()->get('history_list.php?' . http_build_query([
            'df' => '2026-01-01',
            'dt' => '2026-01-31',
        ]));
        $this->assertOk($response);

        return $response;
    }

    private function assertInputValue(Response $response, string $name, string $expectedValue): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//input[@name='{$name}']");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, "Input '{$name}' was not found.");

        $actual = (string) ($nodes->item(0)?->attributes?->getNamedItem('value')?->nodeValue ?? '');
        $this->assertSame($expectedValue, $actual, "Unexpected value for input '{$name}'.");
    }

    private function readHistoryInt(int $historyId, string $column): int
    {
        $pdo = $this->createTestPdo();
        $allowedColumns = ['stock_in', 'quantity', 'move_stock', 'location_stock', 'stock'];
        $this->assertContains($column, $allowedColumns, 'Unexpected history column requested.');

        $stmt = $pdo->prepare("SELECT {$column} FROM histories WHERE id = :id");
        $stmt->execute([
            'id' => $historyId,
        ]);

        return (int) $stmt->fetchColumn();
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
    public function testHistoryConfirmClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('history_confirm.php');
    }
}
