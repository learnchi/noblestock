<?php

declare(strict_types=1);

use Noblestock\Logic\MessageConst;
use Tests\Support\ExcelSettingsHelper;
use Tests\Support\Response;
use Tests\Support\SpreadsheetResponseHelper;
use Tests\Support\TestDatabase;
use Tests\Support\WebClient;
use Tests\Support\WebTestCase;

final class SalesListExportTest extends WebTestCase
{
    use ExcelSettingsHelper;
    use SpreadsheetResponseHelper;

    // ログインしていない状態で export POST すると index.php に戻される
    public function testSalesListExportRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->post('sales_list_export.php', [
            'mode' => 'export',
        ]);
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // セッション切れ時も index.php に戻り、期限切れメッセージが表示される
    public function testSalesListExportRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->post('sales_list_export.php', [
            'mode' => 'export',
        ]);
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    // GET アクセスは 405 で、POST だけを受け付ける
    public function testNonPostAccessReturns405(): void
    {
        $response = $this->getClient()->get('sales_list_export.php');
        $this->assertSame(405, $response->status);
        $this->assertStringContainsString('Allow: POST', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_VAL_FILE_017, $response->body);
    }

    // mode=export 以外の POST は 400 にする
    public function testPostWithoutExportModeReturns400(): void
    {
        $this->loginAsAdmin();

        $response = $this->getClient()->post('sales_list_export.php', [
            'mode' => 'invalid',
        ]);
        $this->assertSame(400, $response->status);
        $this->assertStringContainsString(MessageConst::MSG_VAL_FILE_018, $response->body);
    }

    // sales_list.php で見ている一覧が、そのまま xls 添付ファイルとして出力される
    public function testSalesListExportReturnsXlsAttachmentWithExpectedCellValues(): void
    {
        $this->prepareSalesListExportAsAdmin();
        $originalSettings = $this->snapshotExcelSettings();

        try {
            $this->updateExcelSettings([
                'EXCEL_VAR' => 0,
            ]);
            $this->openSalesListForSeedData();

            $response = $this->getClient()->post('sales_list_export.php', [
                'mode' => 'export',
            ]);

            $this->assertSame(200, $response->status);
            $this->assertStringContainsString('Content-Type: application/vnd.ms-excel', $response->headers);
            $this->assertStringContainsString(
                'Content-Disposition: attachment;filename="sales_list_' . date('Ymd') . '.xls"',
                $response->headers
            );

            $sheet = $this->loadWorksheetFromResponse($response, 'xls');
            $this->assertStringContainsString('2026-01-01', (string) $sheet->getCell('A1')->getValue());
            $this->assertStringContainsString('2026-01-31', (string) $sheet->getCell('A1')->getValue());
            $this->assertSame('ABC001', (string) $sheet->getCell('A4')->getValue());
            $this->assertSame('', $this->normalizeCellString($sheet->getCell('B4')->getValue()));
            $this->assertSame('', $this->normalizeCellString($sheet->getCell('C4')->getValue()));
            $this->assertSame('商品サンプルABC001', $this->normalizeCellString($sheet->getCell('D4')->getValue()));
            $this->assertSame('120', (string) $sheet->getCell('E4')->getValue());
            $this->assertSame('50', (string) $sheet->getCell('F4')->getValue());
            $this->assertSame('0', (string) $sheet->getCell('G4')->getValue());
            $this->assertSame('70', (string) $sheet->getCell('H4')->getValue());
        } finally {
            $this->restoreExcelSettings($originalSettings);
        }
    }

    // EXCEL_VAR=1 のときは xlsx 形式で返る
    public function testSalesListExportReturnsXlsxAttachmentWhenExcelVarIsOne(): void
    {
        $this->prepareSalesListExportAsAdmin();
        $originalSettings = $this->snapshotExcelSettings();

        try {
            $this->updateExcelSettings([
                'EXCEL_VAR' => 1,
            ]);
            $this->openSalesListForSeedData();

            $response = $this->getClient()->post('sales_list_export.php', [
                'mode' => 'export',
            ]);

            $this->assertSame(200, $response->status);
            $this->assertStringContainsString(
                'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                $response->headers
            );
            $this->assertStringContainsString(
                'Content-Disposition: attachment;filename="sales_list_' . date('Ymd') . '.xlsx"',
                $response->headers
            );
            $this->assertSame('PK', substr($response->body, 0, 2));

            $sheet = $this->loadWorksheetFromResponse($response, 'xlsx');
            $this->assertSame('ABC001', (string) $sheet->getCell('A4')->getValue());
        } finally {
            $this->restoreExcelSettings($originalSettings);
        }
    }

    // listPage=2 のときは 2 ページ目の 2001 件目以降だけを出力する
    public function testSalesListExportUsesRequestedSecondPageSlice(): void
    {
        $originalSettings = $this->snapshotExcelSettings();
        $this->prepareLargeSalesListDataset(2005);

        try {
            $this->loginAsAdmin();
            $this->getClient()->get('menu.php');
            $this->updateExcelSettings([
                'EXCEL_VAR' => 0,
            ]);
            $this->openSalesListForLargeDataset();
            $splitResponse = $this->getClient()->get('list_export_split.php?fn=sales_list');
            $this->assertOk($splitResponse);

            $response = $this->getClient()->post('sales_list_export.php', [
                'mode' => 'export',
                'listPage' => '2',
            ]);

            $this->assertSame(200, $response->status);
            $this->assertStringContainsString(
                'Content-Disposition: attachment;filename="sales_list_' . date('Ymd') . '-2.xls"',
                $response->headers
            );

            $sheet = $this->loadWorksheetFromResponse($response, 'xls');
            $this->assertSame('SLX02001', (string) $sheet->getCell('A4')->getValue());
            $this->assertSame('カテゴリ10', $this->normalizeCellString($sheet->getCell('B4')->getValue()));
            $this->assertSame('メーカー10', $this->normalizeCellString($sheet->getCell('C4')->getValue()));
            $this->assertSame('一覧出力テスト商品2001', $this->normalizeCellString($sheet->getCell('D4')->getValue()));
            $this->assertSame('2001', (string) $sheet->getCell('E4')->getValue());
            $this->assertSame('0', (string) $sheet->getCell('F4')->getValue());
            $this->assertSame('0', (string) $sheet->getCell('G4')->getValue());
            $this->assertSame('2001', (string) $sheet->getCell('H4')->getValue());

            $this->assertSame('SLX02005', (string) $sheet->getCell('A8')->getValue());
            $this->assertSame('2005', (string) $sheet->getCell('E8')->getValue());
        } finally {
            $this->restoreExcelSettings($originalSettings);
            TestDatabase::seed();
        }
    }

    // 権限があれば perm09 でも export POST は通る
    public function testPostWithExportModeReturns200ForPerm09(): void
    {
        $this->prepareSalesListExportAsPerm09();
        $this->openSalesListForSeedData();

        $response = $this->getClient()->post('sales_list_export.php', [
            'mode' => 'export',
        ]);
        $this->assertSame(200, $response->status);
    }

    // 権限のない noauth では 403 にする
    public function testPostWithExportModeReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->post('sales_list_export.php', [
            'mode' => 'export',
        ]);
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_003, $response->body);
    }

    private function prepareSalesListExportAsAdmin(): void
    {
        TestDatabase::seed();
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->loginAsAdmin();
        $this->getClient()->get('menu.php');
    }

    private function prepareSalesListExportAsPerm09(): void
    {
        TestDatabase::seed();
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->loginAs('perm09', 'perm0900');
        $this->getClient()->get('menu.php');
    }

    // export 用の searchCondition / listCnt / pgsort は sales_list.php を開いたタイミングでセッションに入る
    private function openSalesListForSeedData(): Response
    {
        $response = $this->getClient()->get(
            'sales_list.php?df=2026-01-01&dt=2026-01-31&s=1'
        );
        $this->assertOk($response);

        return $response;
    }

    private function openSalesListForLargeDataset(): Response
    {
        $response = $this->getClient()->get(
            'sales_list.php?df=2026-01-01&dt=2026-01-31&s=1'
        );
        $this->assertOk($response);

        return $response;
    }

    // 2ページ目の分割出力を確認するため、集計結果が 2005 件になるよう履歴を作る
    private function prepareLargeSalesListDataset(int $count): void
    {
        TestDatabase::seed();

        $pdo = $this->createTestPdo();
        $pdo->exec('DELETE FROM histories');
        $stmt = $pdo->prepare(
            'INSERT INTO histories (
                history_yy, history_mm, history_dd, history_kbn, management_no, branch_no,
                category_name, maker_name, product_name, location_name,
                quantity, stock_in, move_stock, location_stock, stock, del_flg,
                created_at, created_by, updated_at, updated_by
            ) VALUES (
                2026, 1, 27, 0, :management_no, 1,
                :category_name, :maker_name, :product_name, :location_name,
                0, :stock_in, 0, :location_stock, :stock, 0,
                :created_at, :created_by, :updated_at, :updated_by
            )'
        );

        $pdo->beginTransaction();
        try {
            for ($i = 1; $i <= $count; $i++) {
                $stmt->execute([
                    'management_no' => sprintf('SLX%05d', $i),
                    'category_name' => 'カテゴリ10',
                    'maker_name' => 'メーカー10',
                    'product_name' => '一覧出力テスト商品' . $i,
                    'location_name' => '店舗10',
                    'stock_in' => $i,
                    'location_stock' => $i,
                    'stock' => $i,
                    'created_at' => '2026-01-27 10:30:00',
                    'created_by' => 'admin',
                    'updated_at' => '2026-01-27 10:30:00',
                    'updated_by' => 'admin',
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
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

    private function normalizeCellString(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }
    // セッション期限切れ時に認証情報と画面用セッションが破棄されることを確認する
    public function testSalesListExportClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage(
            'sales_list_export.php',
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
