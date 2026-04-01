<?php

declare(strict_types=1);

use Noblestock\Logic\MessageConst;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Support\Response;
use Tests\Support\WebTestCase;

final class StockBulkCreatePageTest extends WebTestCase
{
    // ログインしていない状態ではこの画面にアクセスできず、index.php に戻される
    public function testStockBulkCreateRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('stock_bulk_create.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // セッション切れメッセージを出して index.php に戻されることも確認する
    public function testStockBulkCreateRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('stock_bulk_create.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    public function testStockBulkCreateDisplays(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->get('stock_bulk_create.php');
        $this->assertOk($response);
    }

    // 権限を持つ perm13 でも表示できる
    public function testStockBulkCreateDisplaysForPerm13(): void
    {
        $this->loginAs('perm13', 'perm1300');

        $response = $this->getClient()->get('stock_bulk_create.php');
        $this->assertOk($response);
    }

    // 権限のない noauth では 403 になる
    public function testStockBulkCreateReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->get('stock_bulk_create.php');
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }

    // 初期表示ではファイル読込の案内が出ていて、結果一覧はまだ出ていない
    public function testStockBulkCreateShowsInitialGuideMessage(): void
    {
        $response = $this->openStockBulkCreateAsAdmin();

        $this->assertGuideMessage($response, MessageConst::MSG_INF_FILE_002);
        $this->assertResultRows($response, []);
    }

    // ファイル未選択のまま読込するとエラーになる
    public function testStockBulkCreateUploadWithoutFileShowsValidationError(): void
    {
        $this->prepareStockBulkCreateAsAdmin();

        $response = $this->getClient()->post('stock_bulk_create.php', [
            'mode' => 'upload',
        ]);
        $this->assertOk($response);

        $this->assertFlushErrorMessage($response, MessageConst::MSG_VAL_FILE_001);
        $this->assertResultRows($response, []);
    }

    // Excel 以外の拡張子を読ませたときは拡張子エラーになる
    public function testStockBulkCreateUploadWithInvalidExtensionShowsValidationError(): void
    {
        $this->prepareStockBulkCreateAsAdmin();
        $tempFile = $this->createTempFile('not excel');

        try {
            $response = $this->getClient()->post('stock_bulk_create.php', [
                'mode' => 'upload',
                'upfile' => curl_file_create($tempFile, 'text/plain', 'stocks.txt'),
            ]);
            $this->assertOk($response);

            $this->assertFlushErrorMessage($response, MessageConst::MSG_VAL_FILE_005);
            $this->assertResultRows($response, []);
        } finally {
            @unlink($tempFile);
        }
    }

    // 正しい Excel を読ませると、更新・削除・登録の結果一覧が表示され、stocks テーブルにも反映される
    public function testStockBulkCreateUploadValidExcelDisplaysResultRowsAndUpdatesDatabase(): void
    {
        $this->prepareStockBulkCreateAsAdmin();
        $fixturePath = $this->createStockBulkExcelFixture([
            ['management_no' => 'ABC001', 'location_id' => '10', 'location_name' => '店舗10', 'quantity' => '15'],
            ['management_no' => 'ABC003', 'location_id' => '20', 'location_name' => '店舗20', 'quantity' => '0'],
            ['management_no' => 'ABC001', 'location_id' => '20', 'location_name' => '店舗20', 'quantity' => '8'],
        ]);

        try {
            $response = $this->uploadStockExcelFixture($fixturePath, 'stocks.xlsx');

            $this->assertStringContainsString('stocks.xlsx', $response->body);
            $this->assertResultRows($response, [
                ['status' => '更新', 'management_no' => 'ABC001', 'location_name' => '店舗10', 'quantity' => '15'],
                ['status' => '削除', 'management_no' => 'ABC003', 'location_name' => '店舗20', 'quantity' => '0'],
                ['status' => '登録', 'management_no' => 'ABC001', 'location_name' => '店舗20', 'quantity' => '8'],
            ]);

            $this->assertSame(15, $this->readStockQuantity('ABC001', 10));
            $this->assertNull($this->readStockQuantityNullable('ABC003', 20));
            $this->assertSame(8, $this->readStockQuantity('ABC001', 20));
        } finally {
            @unlink($fixturePath);
        }
    }

    private function openStockBulkCreateAsAdmin(): Response
    {
        $this->prepareStockBulkCreateAsAdmin();

        $response = $this->getClient()->get('stock_bulk_create.php');
        $this->assertOk($response);

        return $response;
    }

    private function prepareStockBulkCreateAsAdmin(): void
    {
        \Tests\Support\TestDatabase::seed();
        $this->loginAsAdmin();
        $this->getClient()->get('menu.php');
        $this->delSessionStructData('mst007', null, 'stock_bulk_create.php');
    }

    // 実際の画面と同じ multipart/form-data で Excel を投げる
    private function uploadStockExcelFixture(string $fixturePath, string $uploadName): Response
    {
        $response = $this->getClient()->post('stock_bulk_create.php', [
            'mode' => 'upload',
            'upfile' => curl_file_create(
                $fixturePath,
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                $uploadName
            ),
        ]);
        $this->assertOk($response);

        return $response;
    }

    // getStockExcelData() の短い 4 列形式で在庫一括登録用 Excel を一時生成する
    private function createStockBulkExcelFixture(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headers = ['管理番号', '店舗No', '店舗名', '在庫数'];
        foreach ($headers as $index => $header) {
            $sheet->setCellValue(chr(ord('A') + $index) . '1', $header);
        }

        $rowNo = 2;
        foreach ($rows as $row) {
            $values = [
                (string) ($row['management_no'] ?? ''),
                (string) ($row['location_id'] ?? ''),
                (string) ($row['location_name'] ?? ''),
                (string) ($row['quantity'] ?? ''),
            ];

            foreach ($values as $index => $value) {
                $sheet->setCellValue(chr(ord('A') + $index) . $rowNo, $value);
            }
            $rowNo++;
        }

        $tempBase = tempnam(sys_get_temp_dir(), 'stock-bulk-create-');
        $this->assertNotFalse($tempBase, 'Failed to create temporary Excel base path.');

        $tempFile = $tempBase . '.xlsx';
        if (!@rename($tempBase, $tempFile)) {
            @unlink($tempBase);
            $this->fail('Failed to prepare temporary Excel file path.');
        }

        try {
            IOFactory::createWriter($spreadsheet, 'Xlsx')->save($tempFile);
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }

        return $tempFile;
    }

    private function createTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'stock-bulk-create-');
        $this->assertNotFalse($path, 'Failed to create temporary upload file.');
        file_put_contents($path, $contents);

        return $path;
    }

    // danger アラートに出るファイル関連エラーを確認する
    private function assertFlushErrorMessage(Response $response, string $expectedMessage): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//div[contains(@class,'alert-danger')]/div");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Flush error alert was not found.');
        $this->assertSame($expectedMessage, $this->normalizeText($nodes->item(0)?->textContent ?? ''));
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

    // 結果一覧の行を status / 管理番号 / 店舗名 / 在庫数単位で確認する
    private function assertResultRows(Response $response, array $expectedRows): void
    {
        $xpath = new DOMXPath($response->dom());
        $rowNodes = $xpath->query("//div[@id='table-wrapper']//tbody/tr");
        $this->assertNotFalse($rowNodes);
        $this->assertSame(count($expectedRows), $rowNodes->length, 'Unexpected result row count.');

        foreach ($expectedRows as $index => $expectedRow) {
            $cellNodes = $xpath->query('./td', $rowNodes->item($index));
            $this->assertNotFalse($cellNodes);
            $this->assertSame(4, $cellNodes->length);

            $this->assertSame((string) $expectedRow['status'], $this->normalizeText($cellNodes->item(0)?->textContent ?? ''));
            $this->assertSame((string) $expectedRow['management_no'], $this->normalizeText($cellNodes->item(1)?->textContent ?? ''));
            $this->assertSame((string) $expectedRow['location_name'], $this->normalizeText($cellNodes->item(2)?->textContent ?? ''));
            $this->assertSame((string) $expectedRow['quantity'], $this->normalizeText($cellNodes->item(3)?->textContent ?? ''));
        }
    }

    private function readStockQuantity(string $managementNo, int $locationId): int
    {
        $value = $this->readStockQuantityNullable($managementNo, $locationId);
        $this->assertNotNull($value, "Stock {$managementNo} at {$locationId} was not found.");

        return $value;
    }

    private function readStockQuantityNullable(string $managementNo, int $locationId): ?int
    {
        $pdo = $this->createTestPdo();
        $stmt = $pdo->prepare(
            'SELECT quantity FROM stocks WHERE management_no = :management_no AND location_id = :location_id'
        );
        $stmt->execute([
            'management_no' => $managementNo,
            'location_id' => $locationId,
        ]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
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
    public function testStockBulkCreateClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('stock_bulk_create.php');
    }
}
