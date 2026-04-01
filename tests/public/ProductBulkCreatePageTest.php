<?php

declare(strict_types=1);

use Noblestock\Logic\MessageConst;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Support\Response;
use Tests\Support\WebTestCase;

final class ProductBulkCreatePageTest extends WebTestCase
{
    // ログインしていない状態ではこの画面にアクセスできず、index.php に戻される
    public function testProductBulkCreateRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('product_bulk_create.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // セッション切れメッセージを出して index.php に戻されることも確認する
    public function testProductBulkCreateRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('product_bulk_create.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    public function testProductBulkCreateDisplays(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->get('product_bulk_create.php');
        $this->assertOk($response);
    }

    // 権限を持つ perm12 でも表示できる
    public function testProductBulkCreateDisplaysForPerm12(): void
    {
        $this->loginAs('perm12', 'perm1200');

        $response = $this->getClient()->get('product_bulk_create.php');
        $this->assertOk($response);
    }

    // 権限のない noauth では 403 になる
    public function testProductBulkCreateReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->get('product_bulk_create.php');
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }

    // 初期表示ではファイル読込の案内が出ていて、結果一覧はまだ出ていない
    public function testProductBulkCreateShowsInitialGuideMessage(): void
    {
        $response = $this->openProductBulkCreateAsAdmin();

        $this->assertGuideMessage($response, MessageConst::MSG_INF_FILE_002);
        $this->assertResultRows($response, []);
    }

    // ファイル未選択のまま読込するとエラーになる
    public function testProductBulkCreateUploadWithoutFileShowsValidationError(): void
    {
        $this->prepareProductBulkCreateAsAdmin();

        $response = $this->getClient()->post('product_bulk_create.php', [
            'mode' => 'upload',
        ]);
        $this->assertOk($response);

        $this->assertFlushErrorMessage($response, MessageConst::MSG_VAL_FILE_001);
        $this->assertResultRows($response, []);
    }

    // Excel 以外の拡張子を読ませたときは拡張子エラーになる
    public function testProductBulkCreateUploadWithInvalidExtensionShowsValidationError(): void
    {
        $this->prepareProductBulkCreateAsAdmin();
        $tempFile = $this->createTempFile('not excel');

        try {
            $response = $this->getClient()->post('product_bulk_create.php', [
                'mode' => 'upload',
                'upfile' => curl_file_create($tempFile, 'text/plain', 'products.txt'),
            ]);
            $this->assertOk($response);

            $this->assertFlushErrorMessage($response, MessageConst::MSG_VAL_FILE_005);
            $this->assertResultRows($response, []);
        } finally {
            @unlink($tempFile);
        }
    }

    // 正しい Excel を読ませると、更新と登録の結果一覧が表示され、DB にも反映される
    public function testProductBulkCreateUploadValidExcelDisplaysResultRowsAndUpdatesDatabase(): void
    {
        $this->prepareProductBulkCreateAsAdmin();
        $fixturePath = $this->createProductBulkExcelFixture([
            [
                'management_no' => 'ABC001',
                'category_id' => '20',
                'category_name' => 'カテゴリ20',
                'maker_id' => '20',
                'maker_name' => 'メーカー20',
                'product_name' => '更新商品ABC001',
                'unit_id' => '10',
                'unit_name' => '個',
                'storage_place' => '更新棚01',
                'remarks' => '更新備考1',
                'remarks2' => '更新備考2',
            ],
            [
                'management_no' => 'TST901',
                'category_id' => '30',
                'category_name' => 'カテゴリ30',
                'maker_id' => '30',
                'maker_name' => 'メーカー30',
                'product_name' => '新規商品TST901',
                'unit_id' => '20',
                'unit_name' => '台',
                'storage_place' => '新規棚01',
                'remarks' => '新規備考1',
                'remarks2' => '新規備考2',
            ],
        ]);

        try {
            $response = $this->uploadProductExcelFixture($fixturePath, 'products.xlsx');

            $this->assertStringContainsString('products.xlsx', $response->body);
            $this->assertResultRows($response, [
                ['status' => '更新', 'management_no' => 'ABC001', 'product_name' => '更新商品ABC001'],
                ['status' => '登録', 'management_no' => 'TST901', 'product_name' => '新規商品TST901'],
            ]);

            $updated = $this->selectProductRow('ABC001');
            $this->assertSame('更新商品ABC001', (string) ($updated['product_name'] ?? ''));
            $this->assertSame('20', (string) ($updated['category_id'] ?? ''));
            $this->assertSame('20', (string) ($updated['maker_id'] ?? ''));
            $this->assertSame('更新棚01', (string) ($updated['storage_place'] ?? ''));
            $this->assertSame('更新備考1', (string) ($updated['remarks'] ?? ''));
            $this->assertSame('更新備考2', (string) ($updated['remarks2'] ?? ''));

            $inserted = $this->selectProductRow('TST901');
            $this->assertSame('新規商品TST901', (string) ($inserted['product_name'] ?? ''));
            $this->assertSame('30', (string) ($inserted['category_id'] ?? ''));
            $this->assertSame('30', (string) ($inserted['maker_id'] ?? ''));
            $this->assertSame('20', (string) ($inserted['unit_id'] ?? ''));
            $this->assertSame('新規棚01', (string) ($inserted['storage_place'] ?? ''));
            $this->assertSame('新規備考1', (string) ($inserted['remarks'] ?? ''));
            $this->assertSame('新規備考2', (string) ($inserted['remarks2'] ?? ''));
        } finally {
            @unlink($fixturePath);
        }
    }

    private function openProductBulkCreateAsAdmin(): Response
    {
        $this->prepareProductBulkCreateAsAdmin();

        $response = $this->getClient()->get('product_bulk_create.php');
        $this->assertOk($response);

        return $response;
    }

    private function prepareProductBulkCreateAsAdmin(): void
    {
        \Tests\Support\TestDatabase::seed();
        $this->loginAsAdmin();
        $this->getClient()->get('menu.php');
        $this->delSessionStructData('mst004', null, 'product_bulk_create.php');
    }

    // 実際の画面と同じ multipart/form-data で Excel を投げる
    private function uploadProductExcelFixture(string $fixturePath, string $uploadName): Response
    {
        $response = $this->getClient()->post('product_bulk_create.php', [
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

    // getProductExcelData() が受ける商品一括登録用 Excel を一時生成する
    private function createProductBulkExcelFixture(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headers = [
            '管理番号',
            'カテゴリNo',
            'カテゴリ',
            'メーカーNo',
            'メーカー',
            '商品名',
            '卸価格',
            '小売価格',
            '仕入原価',
            '在庫数',
            '単位区分',
            '単位',
            '保管場所',
            '店舗在庫数',
            '画像',
            '備考',
            '備考２',
        ];

        foreach ($headers as $index => $header) {
            $sheet->setCellValue(chr(ord('A') + $index) . '1', $header);
        }

        $rowNo = 2;
        foreach ($rows as $row) {
            $values = [
                (string) ($row['management_no'] ?? ''),
                (string) ($row['category_id'] ?? ''),
                (string) ($row['category_name'] ?? ''),
                (string) ($row['maker_id'] ?? ''),
                (string) ($row['maker_name'] ?? ''),
                (string) ($row['product_name'] ?? ''),
                '100',
                '200',
                '300',
                '0',
                (string) ($row['unit_id'] ?? ''),
                (string) ($row['unit_name'] ?? ''),
                (string) ($row['storage_place'] ?? ''),
                '',
                '',
                (string) ($row['remarks'] ?? ''),
                (string) ($row['remarks2'] ?? ''),
            ];

            foreach ($values as $index => $value) {
                $sheet->setCellValue(chr(ord('A') + $index) . $rowNo, $value);
            }
            $rowNo++;
        }

        $tempBase = tempnam(sys_get_temp_dir(), 'product-bulk-create-');
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
        $path = tempnam(sys_get_temp_dir(), 'product-bulk-create-');
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

    // 結果一覧の行を status / 管理番号 / 商品名単位で確認する
    private function assertResultRows(Response $response, array $expectedRows): void
    {
        $xpath = new DOMXPath($response->dom());
        $rowNodes = $xpath->query("//div[@id='table-wrapper']//tbody/tr");
        $this->assertNotFalse($rowNodes);
        $this->assertSame(count($expectedRows), $rowNodes->length, 'Unexpected result row count.');

        foreach ($expectedRows as $index => $expectedRow) {
            $cellNodes = $xpath->query('./td', $rowNodes->item($index));
            $this->assertNotFalse($cellNodes);
            $this->assertSame(3, $cellNodes->length);

            $this->assertSame((string) $expectedRow['status'], $this->normalizeText($cellNodes->item(0)?->textContent ?? ''));
            $this->assertSame((string) $expectedRow['management_no'], $this->normalizeText($cellNodes->item(1)?->textContent ?? ''));
            $this->assertSame((string) $expectedRow['product_name'], $this->normalizeText($cellNodes->item(2)?->textContent ?? ''));
        }
    }

    private function selectProductRow(string $managementNo): array
    {
        $pdo = $this->createTestPdo();
        $stmt = $pdo->prepare(
            'SELECT management_no, category_id, maker_id, product_name, unit_id, storage_place, remarks, remarks2
             FROM products WHERE management_no = :management_no'
        );
        $stmt->execute([
            'management_no' => $managementNo,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row, "Product {$managementNo} was not found.");

        return $row;
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
    public function testProductBulkCreateClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('product_bulk_create.php');
    }
}
