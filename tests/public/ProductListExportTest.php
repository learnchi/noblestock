<?php

declare(strict_types=1);

use Noblestock\DbLogic\Product;
use Noblestock\DbLogic\Stock;
use Noblestock\Logic\MessageConst;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Tests\Support\ExcelSettingsHelper;
use Tests\Support\Response;
use Tests\Support\SpreadsheetResponseHelper;
use Tests\Support\WebTestCase;

final class ProductListExportTest extends WebTestCase
{
    use ExcelSettingsHelper;
    use SpreadsheetResponseHelper;

    // ログインしていない状態で export POST すると index.php に戻される
    public function testProductListExportRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->post('product_list_export.php', [
            'mode' => 'export',
        ]);
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertInitialStatus($response, 303);
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // ログイン切れ時はセッション期限切れメッセージ付きで index.php に戻される
    public function testProductListExportRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->post('product_list_export.php', [
            'mode' => 'export',
        ]);
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertInitialStatus($response, 303);
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    // GET アクセスは 405 と Allow: POST を返す
    public function testNonPostAccessReturns405(): void
    {
        $response = $this->getClient()->get('product_list_export.php');
        $this->assertSame(405, $response->status);
        $this->assertStringContainsString('Allow: POST', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_VAL_FILE_017, $response->body);
    }

    // mode=export 以外の POST は 400 を返す
    public function testPostWithoutExportModeReturns400(): void
    {
        $this->loginAsAdmin();

        $response = $this->getClient()->post('product_list_export.php', [
            'mode' => 'invalid',
        ]);
        $this->assertSame(400, $response->status);
        $this->assertStringContainsString(MessageConst::MSG_VAL_FILE_018, $response->body);
    }

    // 管理者で通常の xls を出力すると検索結果の内容が Excel に書き込まれる
    public function testProductListExportReturnsXlsAttachmentWithExpectedCellValues(): void
    {
        $this->loginAsAdmin();
        $originalSettings = $this->snapshotExcelSettings();
        $expectedProduct = $this->selectProductData('ABC001');
        $expectedLocationStock = $this->selectLocationStock('ABC001');

        try {
            $this->updateExcelSettings([
                'EXCEL_VAR' => 0,
            ]);
            $displayResponse = $this->openProductListAsAdmin(['mn' => 'ABC001']);
            $this->assertOk($displayResponse);

            $response = $this->getClient()->post('product_list_export.php', [
                'mode' => 'export',
            ]);

            $this->assertSame(200, $response->status);
            $this->assertStringContainsString('Content-Type: application/vnd.ms-excel', $response->headers);
            $this->assertStringContainsString(
                'Content-Disposition: attachment;filename="product_list_' . date('Ymd') . '.xls"',
                $response->headers
            );

            $sheet = $this->loadWorksheetFromResponse($response, 'xls');
            $this->assertSame($expectedProduct['management_no'], (string) $sheet->getCell('A2')->getValue());
            $this->assertSame((string) $expectedProduct['category_id'], (string) $sheet->getCell('B2')->getValue());
            $this->assertSame(
                $this->normalizeCellString($expectedProduct['category_name'] ?? ''),
                $this->normalizeCellString($sheet->getCell('C2')->getValue())
            );
            $this->assertSame(
                $this->normalizeCellString($expectedProduct['maker_name'] ?? ''),
                $this->normalizeCellString($sheet->getCell('E2')->getValue())
            );
            $this->assertSame(
                $this->normalizeCellString($expectedProduct['product_name'] ?? ''),
                $this->normalizeCellString($sheet->getCell('F2')->getValue())
            );
            $this->assertSame((string) $expectedProduct['quantity'], (string) $sheet->getCell('J2')->getValue());
            $this->assertSame(
                $this->normalizeCellString($expectedProduct['unit_name'] ?? ''),
                $this->normalizeCellString($sheet->getCell('L2')->getValue())
            );
            $this->assertSame($expectedLocationStock, (string) $sheet->getCell('N2')->getValue());
            $this->assertSame(
                (string) ($expectedProduct['remarks'] ?? ''),
                $this->normalizeCellString($sheet->getCell('P2')->getValue())
            );
            $this->assertSame(
                (string) ($expectedProduct['remarks2'] ?? ''),
                $this->normalizeCellString($sheet->getCell('Q2')->getValue())
            );
        } finally {
            $this->restoreExcelSettings($originalSettings);
        }
    }

    // EXCEL_VAR=1 のときは xlsx 添付で出力される
    public function testProductListExportReturnsXlsxAttachmentWhenExcelVarIsOne(): void
    {
        $this->loginAsAdmin();
        $originalSettings = $this->snapshotExcelSettings();

        try {
            $this->updateExcelSettings([
                'EXCEL_VAR' => 1,
            ]);
            $displayResponse = $this->openProductListAsAdmin(['mn' => 'ABC001']);
            $this->assertOk($displayResponse);

            $response = $this->getClient()->post('product_list_export.php', [
                'mode' => 'export',
            ]);

            $this->assertSame(200, $response->status);
            $this->assertStringContainsString(
                'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                $response->headers
            );
            $this->assertStringContainsString(
                'Content-Disposition: attachment;filename="product_list_' . date('Ymd') . '.xlsx"',
                $response->headers
            );
            $this->assertSame('PK', substr($response->body, 0, 2));

            $sheet = $this->loadWorksheetFromResponse($response, 'xlsx');
            $this->assertSame('ABC001', (string) $sheet->getCell('A2')->getValue());
        } finally {
            $this->restoreExcelSettings($originalSettings);
        }
    }

    // 数式解釈されうる文字列は Excel 上でも文字列型のまま出力される
    public function testProductListExportWritesFormulaLikeStringsAsText(): void
    {
        $this->loginAsAdmin();
        $originalSettings = $this->snapshotExcelSettings();
        $this->updateProductForExcelFormulaTest('ABC001');

        try {
            $this->updateExcelSettings([
                'EXCEL_VAR' => 0,
            ]);
            $displayResponse = $this->openProductListAsAdmin(['mn' => 'ABC001']);
            $this->assertOk($displayResponse);

            $response = $this->getClient()->post('product_list_export.php', [
                'mode' => 'export',
            ]);

            $this->assertSame(200, $response->status);

            $sheet = $this->loadWorksheetFromResponse($response, 'xls');
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('F2')->getDataType());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('M2')->getDataType());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('O2')->getDataType());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('P2')->getDataType());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('Q2')->getDataType());
            $this->assertSame('=SUM(1,1)', (string) $sheet->getCell('F2')->getValue());
            $this->assertSame('-B1', (string) $sheet->getCell('M2')->getValue());
            $this->assertSame('@image.jpg', (string) $sheet->getCell('O2')->getValue());
            $this->assertSame('+cmd', (string) $sheet->getCell('P2')->getValue());
            $this->assertSame(" \t=alert", (string) $sheet->getCell('Q2')->getValue());
        } finally {
            $this->restoreExcelSettings($originalSettings);
        }
    }

    // listPage 指定時は 2 ページ目の 2000 件単位スライスを別名で出力する
    public function testProductListExportUsesRequestedSecondPageSlice(): void
    {
        $originalSettings = $this->snapshotExcelSettings();
        $this->prepareLargeProductDataset(2005);

        try {
            $this->loginAsAdmin();
            $this->updateExcelSettings([
                'EXCEL_VAR' => 0,
            ]);
            $displayResponse = $this->openProductListAsAdmin();
            $this->assertOk($displayResponse);

            $response = $this->getClient()->post('product_list_export.php', [
                'mode' => 'export',
                'listPage' => '2',
            ]);

            $this->assertSame(200, $response->status);
            $this->assertStringContainsString(
                'Content-Disposition: attachment;filename="product_list_' . date('Ymd') . '-2.xls"',
                $response->headers
            );

            $sheet = $this->loadWorksheetFromResponse($response, 'xls');
            $this->assertSame('TST02001', (string) $sheet->getCell('A2')->getValue());
            $this->assertSame('商品2001', (string) $sheet->getCell('F2')->getValue());
            $this->assertSame('TST02005', (string) $sheet->getCell('A6')->getValue());
            $this->assertSame('商品2005', (string) $sheet->getCell('F6')->getValue());
        } finally {
            $this->restoreExcelSettings($originalSettings);
            \Tests\Support\TestDatabase::seed();
        }
    }

    // 検索結果が 0 件でもテンプレート用のサンプル行が 1 行出力される
    public function testProductListExportOutputsTemplateRowWhenSearchResultIsEmpty(): void
    {
        $this->loginAsAdmin();
        $originalSettings = $this->snapshotExcelSettings();

        try {
            $this->updateExcelSettings([
                'EXCEL_VAR' => 0,
            ]);
            $displayResponse = $this->openProductListAsAdmin(['mn' => 'ABC000']);
            $this->assertOk($displayResponse);
            $this->assertStringContainsString(MessageConst::MSG_VAL_LIST_001, $displayResponse->body);

            $response = $this->getClient()->post('product_list_export.php', [
                'mode' => 'export',
            ]);

            $this->assertSame(200, $response->status);

            $sheet = $this->loadWorksheetFromResponse($response, 'xls');
            $this->assertSame('A12345', (string) $sheet->getCell('A2')->getValue());
            $this->assertSame('商品サンプル01', (string) $sheet->getCell('F2')->getValue());
        } finally {
            $this->restoreExcelSettings($originalSettings);
        }
    }

    // 権限ありユーザーでも export POST で 200 を返す
    public function testPostWithExportModeReturns200ForPerm01(): void
    {
        $this->loginAs('perm01', 'perm0100');

        $response = $this->getClient()->post('product_list_export.php', [
            'mode' => 'export',
        ]);
        $this->assertSame(200, $response->status);
    }

    // 権限なしユーザーでは export POST が 403 になる
    public function testPostWithExportModeReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->post('product_list_export.php', [
            'mode' => 'export',
        ]);
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_003, $response->body);
    }

    // product_list.php を開いて export 用の検索条件セッションを作る
    private function openProductListAsAdmin(array $query = []): Response
    {
        $normalizedQuery = array_merge([
            'pos' => '0',
            's' => '5',
            'p' => '1',
            'sf' => '9',
        ], $query);

        $path = 'product_list.php?' . http_build_query($normalizedQuery);

        return $this->getClient()->get($path);
    }

    // 現在の Excel 出力設定を退避する
    // 指定商品の検索結果 1 行分を DB から取得する
    private function selectProductData(string $managementNo): array
    {
        return (new Product())->select($managementNo);
    }

    // 指定商品のロケーション別在庫文字列を取得する
    private function selectLocationStock(string $managementNo): string
    {
        return (string) ((new Stock())->getStockPerLocation($managementNo) ?? '');
    }

    // 2 ページ目分岐を踏むための大量商品データを作り直す
    private function prepareLargeProductDataset(int $count): void
    {
        \Tests\Support\TestDatabase::seed();

        $pdo = $this->createTestPdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            $pdo->exec('DELETE FROM products');
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO products (
                management_no, category_id, maker_id, product_name,
                wholesale_amount, retail_amount, sell_amount, quantity, unit_id,
                storage_place, image_file, remarks, remarks2,
                created_at, created_by, updated_at, updated_by
            ) VALUES (
                :management_no, :category_id, :maker_id, :product_name,
                :wholesale_amount, :retail_amount, :sell_amount, :quantity, :unit_id,
                :storage_place, :image_file, :remarks, :remarks2,
                :created_at, :created_by, :updated_at, :updated_by
            )'
        );

        $createdAt = '2026-03-16 00:00:00';
        $createdBy = 'admin';

        $pdo->beginTransaction();
        try {
            for ($i = 1; $i <= $count; $i++) {
                $managementNo = sprintf('TST%05d', $i);
                $amount = 1000 + $i;

                $stmt->execute([
                    'management_no' => $managementNo,
                    'category_id' => ((($i - 1) % 10) + 1) * 10,
                    'maker_id' => ((($i + 3) % 10) + 1) * 10,
                    'product_name' => '商品' . $i,
                    'wholesale_amount' => $amount,
                    'retail_amount' => $amount + 100,
                    'sell_amount' => $amount + 200,
                    'quantity' => 0,
                    'unit_id' => ((($i - 1) % 8) + 1) * 10,
                    'storage_place' => '棚' . sprintf('%02d', ((($i - 1) % 20) + 1)),
                    'image_file' => '',
                    'remarks' => '備考' . $i,
                    'remarks2' => 'メモ' . $i,
                    'created_at' => $createdAt,
                    'created_by' => $createdBy,
                    'updated_at' => $createdAt,
                    'updated_by' => $createdBy,
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function updateProductForExcelFormulaTest(string $managementNo): void
    {
        $pdo = $this->createTestPdo();
        $stmt = $pdo->prepare(
            'UPDATE products
                SET product_name = :product_name,
                    storage_place = :storage_place,
                    image_file = :image_file,
                    remarks = :remarks,
                    remarks2 = :remarks2,
                    updated_at = NOW(),
                    updated_by = :updated_by
              WHERE management_no = :management_no'
        );
        $stmt->execute([
            'product_name' => '=SUM(1,1)',
            'storage_place' => '-B1',
            'image_file' => '@image.jpg',
            'remarks' => '+cmd',
            'remarks2' => " \t=alert",
            'updated_by' => 'admin',
            'management_no' => $managementNo,
        ]);
    }

    // テスト用 DB に直接接続する PDO を作る
    private function createTestPdo(): \PDO
    {
        $configPath = dirname(__DIR__, 2) . '/config/dbconfig.ini';
        $config = parse_ini_file($configPath);

        $this->assertIsArray($config, 'Failed to read dbconfig.ini.');
        foreach (['dbhost', 'dbuser', 'dbpass', 'dbname'] as $requiredKey) {
            $this->assertArrayHasKey($requiredKey, $config, "Missing {$requiredKey} in dbconfig.ini.");
        }

        return new \PDO(
            sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                (string) $config['dbhost'],
                (string) $config['dbname']
            ),
            (string) $config['dbuser'],
            (string) $config['dbpass'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
    }

    // Excel セル値の null を空文字として扱い、比較しやすい文字列にそろえる
    private function normalizeCellString(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }
    // セッション期限切れ時に認証情報と画面用セッションが破棄されることを確認する
    public function testProductListExportClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage(
            'product_list_export.php',
            [
                'method' => 'POST',
                'payload' => [
                    'mode' => 'export',
                    '_skip_auto_csrf' => true,
                ],
            ]
        );
    }
}
