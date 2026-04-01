<?php

declare(strict_types=1);

use Noblestock\Logic\MessageConst;
use Tests\Support\Response;
use Tests\Support\TestDatabase;
use Tests\Support\WebClient;
use Tests\Support\WebTestCase;

final class HistoryEditPageTest extends WebTestCase
{
    // ログインしていない状態ではこの画面に直接アクセスできず、index.php に戻される
    public function testHistoryEditRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('history_edit.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // セッション切れ時も index.php に戻り、期限切れメッセージが表示される
    public function testHistoryEditRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('history_edit.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    public function testHistoryEditDisplays(): void
    {
        $response = $this->openHistoryEditAsAdmin(21, 'history_list');
        $this->assertOk($response);
    }

    // 実績更新権限を持つ perm09 でも表示できる
    public function testHistoryEditDisplaysForPerm09(): void
    {
        $this->prepareHistoryEditAs('perm09', 'perm0900');

        $response = $this->openHistoryEditFromSalesShow();
        $this->assertOk($response);
    }

    // 権限のない noauth では 403 になる
    public function testHistoryEditReturns403ForNoAuth(): void
    {
        $this->prepareHistoryEditAs('noauth', 'noauth00');

        $response = $this->getClient()->post('history_edit.php', [
            'HISTORY_NO' => '21',
            'filename' => 'history_list',
        ]);
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }

    // 初回 POST で履歴を開くと、編集対象の数値欄と戻り先リンクが表示される
    public function testHistoryEditShowsCurrentHistoryValuesAndBackLink(): void
    {
        $response = $this->openHistoryEditAsAdmin(21, 'history_list');

        $this->assertReadOnlyFieldValue($response, '2026/1/27');
        $this->assertReadOnlyFieldValue($response, '入庫');
        $this->assertReadOnlyFieldValue($response, 'ABC001');
        $this->assertReadOnlyFieldValue($response, '2');
        $this->assertInputValue($response, 'stock_in', '120');
        $this->assertInputValue($response, 'location_stock', '120');
        $this->assertInputValue($response, 'stock', '120');
        $this->assertInputValue($response, 'quantity', '0');
        $this->assertBackLink($response, 'history_list.php');
        $this->assertDeleteHistoryId($response, '21');
    }

    // 一度開いた後なら GET 再表示でもセッションの履歴情報を使って同じ画面を出せる
    public function testHistoryEditCanRedisplayAsGetAfterInitialPost(): void
    {
        $this->prepareHistoryEditAsAdmin();
        $this->openHistoryEdit(21, 'history_list');

        $response = $this->getClient()->get('history_edit.php');
        $this->assertOk($response);
        $this->assertInputValue($response, 'stock_in', '120');
        $this->assertBackLink($response, 'history_list.php');
    }

    // sales_show から来た場合は、戻るリンクも sales_show.php を指す
    public function testHistoryEditOpenedFromSalesShowUsesSalesShowBackLink(): void
    {
        $this->prepareHistoryEditAsAdmin();
        $response = $this->openHistoryEditFromSalesShow();

        $this->assertBackLink($response, 'sales_show.php');
    }

    // 確認画面から戻ったあとにリセットすると、DB上の元の値へ戻る
    public function testHistoryEditResetRestoresOriginalValuesAfterBackFromConfirm(): void
    {
        $this->prepareHistoryEditAsAdmin();
        $editResponse = $this->openHistoryEdit(21, 'history_list');

        $confirmResponse = $this->postHistoryConfirm([
            'mode' => 'confirm',
            'stock_in' => '130',
            'quantity' => '0',
            'move_stock' => '0',
            'location_stock' => '130',
            'stock' => '130',
        ], $editResponse);

        $backResponse = $this->getClient()->post('history_edit.php', [
            'mode' => 'back',
        ] + $this->extractCsrfPostData($confirmResponse));
        $this->assertOk($backResponse);
        $this->assertInputValue($backResponse, 'stock_in', '130');

        $resetResponse = $this->getClient()->post('history_edit.php', [
            'mode' => 'reset',
        ] + $this->extractCsrfPostData($backResponse));
        $this->assertOk($resetResponse);
        $this->assertInputValue($resetResponse, 'stock_in', '120');
        $this->assertInputValue($resetResponse, 'location_stock', '120');
        $this->assertInputValue($resetResponse, 'stock', '120');
    }

    // 削除ボタンでは対象履歴の区分が 9 に更新され、一覧へ戻る
    public function testHistoryEditDeleteMarksHistoryAsDeletedAndRedirectsBack(): void
    {
        $this->prepareHistoryEditAsAdmin();
        $editResponse = $this->openHistoryEdit(21, 'history_list');

        $response = $this->getClient()->post('history_edit.php', [
            'mode' => 'delhis',
            'delhis' => '21',
        ] + $this->extractCsrfPostData($editResponse));

        $this->assertOk($response);
        $this->assertStringContainsString('Location: history_list.php', $response->headers);
        $this->assertSame(9, $this->readHistoryValue(21, 'history_kbn'));
    }

    // POST で必要データがなければ 400 にする
    public function testNonDataPostAccessReturns400(): void
    {
        $this->prepareHistoryEditAsAdmin();

        $response = $this->getClient()->post('history_edit.php', []);
        $this->assertSame(400, $response->status);
        $this->assertStringContainsString(MessageConst::MSG_VAL_FILE_018, $response->body);
    }

    // 許可されていない遷移元画面名が送られた場合は 400 を返す
    public function testHistoryEditReturns400ForInvalidFilename(): void
    {
        $this->prepareHistoryEditAsAdmin();
        $sourceResponse = $this->openHistoryListWithRows();

        $response = $this->getClient()->post('history_edit.php', [
            'HISTORY_NO' => '21',
            'filename' => 'evil',
        ] + $this->extractCsrfPostData($sourceResponse));

        $this->assertSame(400, $response->status);
        $this->assertStringContainsString(MessageConst::MSG_VAL_FILE_018, $response->body);
    }

    // GET だけで開いた場合も、セッションに対象履歴がなければ 400 にする
    public function testNonDataGetAccessReturns400(): void
    {
        $this->prepareHistoryEditAsAdmin();

        $response = $this->getClient()->get('history_edit.php');
        $this->assertSame(400, $response->status);
        $this->assertStringContainsString(MessageConst::MSG_VAL_FILE_018, $response->body);
    }

    private function prepareHistoryEditAsAdmin(): void
    {
        $this->prepareHistoryEditAs('admin', 'admin000');
    }

    private function prepareHistoryEditAs(string $loginId, string $password): void
    {
        TestDatabase::seed();
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->loginAs($loginId, $password);
        $this->getClient()->get('menu.php');
    }

    private function openHistoryEditAsAdmin(int $historyNo, string $filename): Response
    {
        $this->prepareHistoryEditAsAdmin();

        return $this->openHistoryEdit($historyNo, $filename);
    }

    private function openHistoryEdit(int $historyNo, string $filename): Response
    {
        $sourceResponse = $filename === 'sales_show'
            ? $this->openSalesShowWithRows()
            : $this->openHistoryListWithRows();

        $response = $this->getClient()->post('history_edit.php', [
            'HISTORY_NO' => (string) $historyNo,
            'filename' => $filename,
        ] + $this->extractCsrfPostData($sourceResponse));
        $this->assertOk($response);

        return $response;
    }

    private function openHistoryEditFromSalesShow(): Response
    {
        $sourceResponse = $this->openSalesShowWithRows();
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

    private function openSalesShowWithRows(): Response
    {
        $response = $this->getClient()->get('sales_show.php?' . http_build_query([
            'mn' => 'ABC001',
            'bn' => '2',
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

    private function assertReadOnlyFieldValue(Response $response, string $expectedValue): void
    {
        $this->assertStringContainsString('value="' . $expectedValue . '"', $response->body);
    }

    private function assertBackLink(Response $response, string $expectedHref): void
    {
        $this->assertStringContainsString('href="' . $expectedHref . '"', $response->body);
    }

    private function assertDeleteHistoryId(Response $response, string $expectedHistoryId): void
    {
        $this->assertStringContainsString('name="delhis" value="' . $expectedHistoryId . '"', $response->body);
    }

    /**
     * @return int|string|null
     */
    private function readHistoryValue(int $historyId, string $column)
    {
        $pdo = $this->createTestPdo();
        $allowedColumns = ['history_kbn', 'stock_in', 'quantity', 'move_stock', 'location_stock', 'stock'];
        $this->assertContains($column, $allowedColumns, 'Unexpected history column requested.');

        $stmt = $pdo->prepare("SELECT {$column} FROM histories WHERE id = :id");
        $stmt->execute([
            'id' => $historyId,
        ]);

        return $stmt->fetchColumn();
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
    public function testHistoryEditClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('history_edit.php');
    }
}
