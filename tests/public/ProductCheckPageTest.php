<?php

declare(strict_types=1);

use Noblestock\Logic\LogicConst;
use Noblestock\Logic\MessageConst;
use Tests\Support\Response;
use Tests\Support\WebTestCase;

final class ProductCheckPageTest extends WebTestCase
{
    // ログインしていない状態ではこの画面にアクセスできず、index.php に戻される
    public function testProductCheckRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('product_check.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // セッション切れメッセージを出して index.php に戻されることも確認する
    public function testProductCheckRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('product_check.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    public function testProductCheckDisplays(): void
    {
        $this->loginAsAdmin();

        $response = $this->getClient()->get('product_check.php');
        $this->assertOk($response);
    }

    // 権限を持つ perm08 でも表示できる
    public function testProductCheckDisplaysForPerm08(): void
    {
        $this->loginAs('perm08', 'perm0800');

        $response = $this->getClient()->get('product_check.php');
        $this->assertOk($response);
    }

    // 権限のない noauth では 403 になる
    public function testProductCheckReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->get('product_check.php');
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }

    // 初期表示では最初の店舗が選ばれ、バーコード入力の案内が出る
    public function testProductCheckShowsInitialGuideMessageAndDefaultLocation(): void
    {
        $response = $this->openProductCheckAsAdmin();

        $this->assertGuideMessage($response, MessageConst::MSG_INF_BARCODE_001);
        $this->assertSelectedLocation($response, '10');
        $this->assertListManagementNos($response, ['ABC001']);
    }

    // 店舗検索フォームで店舗20を選ぶと、その店舗の在庫一覧へ切り替わる
    public function testProductCheckSearchChangesLocationAndDisplayedRows(): void
    {
        $this->prepareProductCheckAsAdmin();

        $response = $this->postProductCheck([
            'mode' => 'search',
            'locationNo' => '20',
            'pos' => '',
        ]);

        $this->assertSelectedLocation($response, '20');
        $this->assertListManagementNos($response, ['ABC002', 'ABC003']);
    }

    // 店舗バーコードでも検索中の店舗を切り替えられる
    public function testProductCheckLocationBarcodeChangesLocationAndDisplayedRows(): void
    {
        $this->prepareProductCheckAsAdmin();

        $response = $this->postBarcode(LogicConst::CMD_LOC . '20');

        $this->assertSelectedLocation($response, '20');
        $this->assertListManagementNos($response, ['ABC002', 'ABC003']);
    }

    // 商品バーコードを読むと、その店舗の在庫チェック数が 1 加算される
    public function testProductCheckBarcodeScanIncrementsCheckCount(): void
    {
        $this->prepareProductCheckAsAdmin();

        $response = $this->postBarcode('ABC001');

        $this->assertSame(1, $this->readCheckCount('admin', 10, 'ABC001'));
        $this->assertRowCheckCount($response, 'ABC001', '1');
    }

    // 直前の加算は取消バーコードで元に戻せる
    public function testProductCheckUndoBarcodeRestoresPreviousCheckCount(): void
    {
        $this->prepareProductCheckAsAdmin();
        $this->postBarcode('ABC001');

        $response = $this->postBarcode(LogicConst::CMD_UNDO);

        $this->assertSame(0, $this->readCheckCount('admin', 10, 'ABC001'));
        $this->assertRowCheckCount($response, 'ABC001', '0');
    }

    // チェック数を実在庫と同じ 70 に更新すると、結果列が ○ になり行にも OK クラスが付く
    public function testProductCheckChangeModeUpdatesCountAndMarksMatchedRowAsOk(): void
    {
        $this->prepareProductCheckAsAdmin();

        $response = $this->postProductCheck([
            'mode' => 'change',
            'pos' => '',
            'in_check_count' => '70',
            'old_check_count' => '0',
            'in_management_no' => 'ABC001',
            'in_location_no' => '10',
        ]);

        $this->assertSame(70, $this->readCheckCount('admin', 10, 'ABC001'));
        $this->assertRowCheckCount($response, 'ABC001', '70');
        $this->assertRowStatusMark($response, 'ABC001', '○');
        $this->assertRowClassContains($response, 'ABC001', 'check_ok');
    }

    // 全クリアを押すと、そのユーザーの在庫チェック数がまとめて削除される
    public function testProductCheckClearDeletesAllCheckCountsForCurrentUser(): void
    {
        $this->prepareProductCheckAsAdmin();
        $this->insertCheckRow('admin', 10, 'ABC001', 3, 0);
        $this->insertCheckRow('admin', 20, 'ABC002', 2, 0);

        $response = $this->postProductCheck([
            'mode' => 'clear',
            'pos' => '',
        ]);

        $this->assertSame(0, $this->countChecks('admin'));
        $this->assertSame(MessageConst::MSG_OK_CHECK_001, $this->readSuccessMessage($response));
        $this->assertRowCheckCount($response, 'ABC001', '0');
    }

    // 管理番号の降順ソートでは、追加した管理番号が大きい順に先頭へ並ぶ
    public function testProductCheckSortByManagementNoDescendingShowsExpectedOrder(): void
    {
        $this->prepareProductCheckAsAdmin();
        $this->insertProductWithStock('TST101', '商品ソート101', 10, 10, 10, 1);
        $this->insertProductWithStock('TST102', '商品ソート102', 10, 10, 10, 1);
        $this->insertProductWithStock('TST103', '商品ソート103', 10, 10, 10, 1);

        $response = $this->postProductCheck([
            'pgsort' => '2',
            'pos' => '',
        ]);

        $this->assertListStartsWith($response, ['TST103', 'TST102', 'TST101']);
    }

    // ページネーションは GET の p パラメータで切り替わり、2 ページ目には 21 件目以降だけが出る
    public function testProductCheckPaginationDisplaysSecondPageRows(): void
    {
        $this->prepareProductCheckAsAdmin();
        $this->insertPaginationStocksForLocation10(25);

        $response = $this->getClient()->get('product_check.php?p=2&s=1&pos=0');
        $this->assertOk($response);

        $rows = $this->extractListRows($response);
        $this->assertCount(6, $rows);
        $this->assertSame('PCT020', $rows[0]['management_no']);
        $this->assertSame('PCT025', $rows[5]['management_no']);
        $this->assertStringContainsString('aria-current="page"', $response->body);
        $this->assertStringContainsString('>2<', $response->body);
    }

    // カテゴリ昇順ではカテゴリ名が同じ行も管理番号昇順で安定して並ぶことを確認する
    public function testProductCheckSortByCategoryAscendingOrdersTiedRowsByManagementNo(): void
    {
        $this->prepareProductCheckAsAdmin();
        $this->insertProductWithStock('TST101', '商品カテゴリテスト01', 10, 10, 10, 1);
        $this->insertProductWithStock('TST102', '商品カテゴリテスト02', 10, 10, 10, 1);
        $this->insertProductWithStock('TST103', '商品カテゴリテスト03', 10, 10, 10, 1);

        $response = $this->postProductCheck([
            'pgsort' => '3',
            'pos' => '',
        ]);

        $this->assertListStartsWith($response, ['ABC001', 'TST101', 'TST102', 'TST103']);
    }
    private function openProductCheckAsAdmin(): Response
    {
        $this->prepareProductCheckAsAdmin();

        $response = $this->getClient()->get('product_check.php');
        $this->assertOk($response);

        return $response;
    }

    private function prepareProductCheckAsAdmin(): void
    {
        \Tests\Support\TestDatabase::seed();
        $this->loginAsAdmin();
        $this->getClient()->get('menu.php');
        $this->delSessionStructData('biz601', null, 'product_check.php');
    }

    private function postProductCheck(array $postData): Response
    {
        $response = $this->getClient()->post('product_check.php', $postData);
        $this->assertOk($response);

        return $response;
    }

    private function postBarcode(string $barcode): Response
    {
        return $this->postProductCheck([
            'barcode' => $barcode,
        ]);
    }

    // 一覧テーブルの内容を行単位で読み出す
    private function extractListRows(Response $response): array
    {
        $xpath = new DOMXPath($response->dom());
        $rowNodes = $xpath->query('(//table[contains(@class,"table")])[last()]//tbody/tr');
        $this->assertNotFalse($rowNodes);

        $rows = [];
        foreach ($rowNodes as $rowNode) {
            $cellNodes = $xpath->query('./td', $rowNode);
            if ($cellNodes === false || $cellNodes->length < 7) {
                continue;
            }

            $rows[] = [
                'management_no' => $this->normalizeText($cellNodes->item(0)?->textContent ?? ''),
                'category_name' => $this->normalizeText($cellNodes->item(1)?->textContent ?? ''),
                'maker_name' => $this->normalizeText($cellNodes->item(2)?->textContent ?? ''),
                'product_name' => $this->normalizeText($cellNodes->item(3)?->textContent ?? ''),
                'quantity' => $this->normalizeText($cellNodes->item(4)?->textContent ?? ''),
                'check_count' => $this->normalizeText($cellNodes->item(5)?->textContent ?? ''),
                'check_result' => $this->normalizeText($cellNodes->item(6)?->textContent ?? ''),
                'row_class' => (string) ($rowNode->attributes?->getNamedItem('class')?->nodeValue ?? ''),
            ];
        }

        return $rows;
    }

    private function assertGuideMessage(Response $response, string $expectedMessage): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//div[contains(@class,'alert-info')]/div");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Guide message alert was not found.');
        $this->assertSame($expectedMessage, $this->normalizeText($nodes->item(0)?->textContent ?? ''));
    }

    private function assertSelectedLocation(Response $response, string $expectedLocationId): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//select[@name='locationNo']/option[@selected]");
        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length, 'Expected one selected location option.');
        $this->assertSame($expectedLocationId, (string) ($nodes->item(0)?->attributes?->getNamedItem('value')?->nodeValue ?? ''));
    }

    private function assertListManagementNos(Response $response, array $expectedManagementNos): void
    {
        $actual = array_column($this->extractListRows($response), 'management_no');
        $this->assertSame($expectedManagementNos, $actual);
    }

    private function assertListStartsWith(Response $response, array $expectedPrefix): void
    {
        $actual = array_column($this->extractListRows($response), 'management_no');
        $this->assertSame($expectedPrefix, array_slice($actual, 0, count($expectedPrefix)));
    }

    private function assertRowCheckCount(Response $response, string $managementNo, string $expectedCount): void
    {
        foreach ($this->extractListRows($response) as $row) {
            if ($row['management_no'] === $managementNo) {
                $this->assertSame($expectedCount, $row['check_count']);
                return;
            }
        }

        $this->fail("Management no {$managementNo} was not found in list rows.");
    }

    private function assertRowStatusMark(Response $response, string $managementNo, string $expectedMark): void
    {
        foreach ($this->extractListRows($response) as $row) {
            if ($row['management_no'] === $managementNo) {
                $this->assertSame($expectedMark, $row['check_result']);
                return;
            }
        }

        $this->fail("Management no {$managementNo} was not found in list rows.");
    }

    private function assertRowClassContains(Response $response, string $managementNo, string $expectedClass): void
    {
        foreach ($this->extractListRows($response) as $row) {
            if ($row['management_no'] === $managementNo) {
                $this->assertStringContainsString($expectedClass, $row['row_class']);
                return;
            }
        }

        $this->fail("Management no {$managementNo} was not found in list rows.");
    }

    private function readSuccessMessage(Response $response): string
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//div[contains(@class,'alert-success')]/div");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Success message alert was not found.');

        return $this->normalizeText($nodes->item(0)?->textContent ?? '');
    }

    private function readCheckCount(string $loginId, int $locationId, string $managementNo): int
    {
        $pdo = $this->createTestPdo();
        $stmt = $pdo->prepare(
            'SELECT check_count FROM checks WHERE login_id = :login_id AND location_id = :location_id AND management_no = :management_no'
        );
        $stmt->execute([
            'login_id' => $loginId,
            'location_id' => $locationId,
            'management_no' => $managementNo,
        ]);
        $value = $stmt->fetchColumn();

        return $value === false ? 0 : (int) $value;
    }

    private function countChecks(string $loginId): int
    {
        $pdo = $this->createTestPdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM checks WHERE login_id = :login_id');
        $stmt->execute([
            'login_id' => $loginId,
        ]);

        return (int) $stmt->fetchColumn();
    }

    private function insertCheckRow(string $loginId, int $locationId, string $managementNo, int $checkCount, int $resultFlg): void
    {
        $pdo = $this->createTestPdo();
        $stmt = $pdo->prepare(
            'INSERT INTO checks (login_id, location_id, management_no, check_count, result_flg, created_at, created_by, updated_at, updated_by)
             VALUES (:login_id, :location_id, :management_no, :check_count, :result_flg, NOW(), :created_by, NOW(), :updated_by)'
        );
        $stmt->execute([
            'login_id' => $loginId,
            'location_id' => $locationId,
            'management_no' => $managementNo,
            'check_count' => $checkCount,
            'result_flg' => $resultFlg,
            'created_by' => $loginId,
            'updated_by' => $loginId,
        ]);
    }

    // 並び替えとページネーション確認用に、同じ店舗へ追加商品を投入する
    private function insertProductWithStock(
        string $managementNo,
        string $productName,
        int $categoryId,
        int $makerId,
        int $locationId,
        int $quantity
    ): void {
        $pdo = $this->createTestPdo();

        $productStmt = $pdo->prepare(
            'INSERT INTO products (
                management_no, category_id, maker_id, product_name, wholesale_amount, retail_amount, sell_amount,
                quantity, unit_id, storage_place, image_file, remarks, remarks2, created_at, created_by, updated_at, updated_by
            ) VALUES (
                :management_no, :category_id, :maker_id, :product_name, 1, 1, 1,
                0, NULL, :storage_place, \'\', \'\', \'\', NOW(), \'admin\', NOW(), \'admin\'
            )'
        );
        $productStmt->execute([
            'management_no' => $managementNo,
            'category_id' => $categoryId,
            'maker_id' => $makerId,
            'product_name' => $productName,
            'storage_place' => '棚-' . $managementNo,
        ]);

        $stockStmt = $pdo->prepare(
            'INSERT INTO stocks (management_no, location_id, quantity, remarks, created_at, created_by, updated_at, updated_by)
             VALUES (:management_no, :location_id, :quantity, NULL, NOW(), \'admin\', NOW(), \'admin\')'
        );
        $stockStmt->execute([
            'management_no' => $managementNo,
            'location_id' => $locationId,
            'quantity' => $quantity,
        ]);
    }

    private function insertPaginationStocksForLocation10(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $managementNo = sprintf('PCT%03d', $i);
            $this->insertProductWithStock($managementNo, 'ページ商品' . sprintf('%03d', $i), 10, 10, 10, 1);
        }
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
    public function testProductCheckClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('product_check.php');
    }
}
