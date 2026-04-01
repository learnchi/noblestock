<?php

declare(strict_types=1);

use Noblestock\Logic\MessageConst;
use Tests\Support\ExcelSettingsHelper;
use Tests\Support\Response;
use Tests\Support\SpreadsheetResponseHelper;
use Tests\Support\WebTestCase;

final class ProductCheckExportTest extends WebTestCase
{
    use ExcelSettingsHelper;
    use SpreadsheetResponseHelper;

    // ログインしていない状態で export POST すると index.php に戻される
    public function testProductCheckExportRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->post('product_check_export.php', [
            'mode' => 'export',
        ]);
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // セッション切れメッセージを出して index.php に戻されることも確認する
    public function testProductCheckExportRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->post('product_check_export.php', [
            'mode' => 'export',
        ]);
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    // GET アクセスは 405 で、POST だけを受け付ける
    public function testNonPostAccessReturns405(): void
    {
        $response = $this->getClient()->get('product_check_export.php');
        $this->assertSame(405, $response->status);
        $this->assertStringContainsString('Allow: POST', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_VAL_FILE_017, $response->body);
    }

    // mode=export 以外の POST は 400 になる
    public function testPostWithoutExportModeReturns400(): void
    {
        $this->loginAsAdmin();

        $response = $this->getClient()->post('product_check_export.php', [
            'mode' => 'invalid',
        ]);
        $this->assertSame(400, $response->status);
        $this->assertStringContainsString(MessageConst::MSG_VAL_FILE_018, $response->body);
    }

    // 在庫チェック画面で選ばれている店舗とチェック数が、そのまま xls に書き出される
    public function testProductCheckExportReturnsXlsAttachmentWithExpectedCellValues(): void
    {
        $this->prepareProductCheckExportAsAdmin();
        $originalSettings = $this->snapshotExcelSettings();
        $expectedRow = $this->selectCheckExportRow('admin', 10, 'ABC001');

        try {
            $this->updateExcelSettings([
                'EXCEL_VAR' => 0,
            ]);
            $this->insertCheckRow('admin', 10, 'ABC001', 70, 1);
            $this->openProductCheckAsAdmin();

            $response = $this->getClient()->post('product_check_export.php', [
                'mode' => 'export',
            ]);

            $this->assertSame(200, $response->status);
            $this->assertStringContainsString('Content-Type: application/vnd.ms-excel', $response->headers);
            $this->assertStringContainsString(
                'Content-Disposition: attachment;filename="product_check_' . date('Ymd') . '.xls"',
                $response->headers
            );

            $sheet = $this->loadWorksheetFromResponse($response, 'xls');
            $this->assertSame('ABC001', (string) $sheet->getCell('A2')->getValue());
            $this->assertSame($this->normalizeCellString($expectedRow['category_name']), $this->normalizeCellString($sheet->getCell('B2')->getValue()));
            $this->assertSame($this->normalizeCellString($expectedRow['maker_name']), $this->normalizeCellString($sheet->getCell('C2')->getValue()));
            $this->assertSame($this->normalizeCellString($expectedRow['product_name']), $this->normalizeCellString($sheet->getCell('D2')->getValue()));
            $this->assertSame('70', (string) $sheet->getCell('E2')->getValue());
            $this->assertSame('70', (string) $sheet->getCell('F2')->getValue());
            $this->assertSame('○', $this->normalizeCellString($sheet->getCell('G2')->getValue()));
        } finally {
            $this->restoreExcelSettings($originalSettings);
        }
    }

    // EXCEL_VAR=1 のときは xlsx 添付として出力される
    public function testProductCheckExportReturnsXlsxAttachmentWhenExcelVarIsOne(): void
    {
        $this->prepareProductCheckExportAsAdmin();
        $originalSettings = $this->snapshotExcelSettings();

        try {
            $this->updateExcelSettings([
                'EXCEL_VAR' => 1,
            ]);
            $this->insertCheckRow('admin', 10, 'ABC001', 1, 0);
            $this->openProductCheckAsAdmin();

            $response = $this->getClient()->post('product_check_export.php', [
                'mode' => 'export',
            ]);

            $this->assertSame(200, $response->status);
            $this->assertStringContainsString(
                'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                $response->headers
            );
            $this->assertStringContainsString(
                'Content-Disposition: attachment;filename="product_check_' . date('Ymd') . '.xlsx"',
                $response->headers
            );
            $this->assertSame('PK', substr($response->body, 0, 2));

            $sheet = $this->loadWorksheetFromResponse($response, 'xlsx');
            $this->assertSame('ABC001', (string) $sheet->getCell('A2')->getValue());
        } finally {
            $this->restoreExcelSettings($originalSettings);
        }
    }

    // listPage=2 のときは 2 ページ目ぶんだけを切り出したファイル名と内容で出力する
    public function testProductCheckExportUsesRequestedSecondPageSlice(): void
    {
        $originalSettings = $this->snapshotExcelSettings();
        $this->prepareLargeCheckDataset(2005);

        try {
            $this->loginAsAdmin();
            $this->getClient()->get('menu.php');
            $this->updateExcelSettings([
                'EXCEL_VAR' => 0,
            ]);
            $this->openProductCheckAsAdmin();

            $response = $this->getClient()->post('product_check_export.php', [
                'mode' => 'export',
                'listPage' => '2',
            ]);

            $this->assertSame(200, $response->status);
            $this->assertStringContainsString(
                'Content-Disposition: attachment;filename="product_check_' . date('Ymd') . '-2.xls"',
                $response->headers
            );

            $sheet = $this->loadWorksheetFromResponse($response, 'xls');
            $this->assertSame('PCK02001', (string) $sheet->getCell('A2')->getValue());
            $this->assertSame('チェック商品2001', $this->normalizeCellString($sheet->getCell('D2')->getValue()));
            $this->assertSame('1', (string) $sheet->getCell('E2')->getValue());
            $this->assertSame('1', (string) $sheet->getCell('F2')->getValue());
            $this->assertSame('○', $this->normalizeCellString($sheet->getCell('G2')->getValue()));

            $this->assertSame('PCK02005', (string) $sheet->getCell('A6')->getValue());
            $this->assertSame('チェック商品2005', $this->normalizeCellString($sheet->getCell('D6')->getValue()));
        } finally {
            $this->restoreExcelSettings($originalSettings);
            \Tests\Support\TestDatabase::seed();
        }
    }

    // 権限ありユーザー perm08 でも export POST は 200 になる
    public function testPostWithExportModeReturns200ForPerm08(): void
    {
        $this->prepareProductCheckExportAsPerm08();
        $this->openProductCheckAsPerm08();

        $response = $this->getClient()->post('product_check_export.php', [
            'mode' => 'export',
        ]);
        $this->assertSame(200, $response->status);
    }

    // 権限なしユーザー noauth では 403 になる
    public function testPostWithExportModeReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->post('product_check_export.php', [
            'mode' => 'export',
        ]);
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_003, $response->body);
    }

    // product_check.php を開いて export 用の searchKey / listCnt / pgsort を現在セッションへ保存させる
    private function openProductCheckAsAdmin(): Response
    {
        $response = $this->getClient()->get('product_check.php');
        $this->assertOk($response);

        return $response;
    }

    private function openProductCheckAsPerm08(): Response
    {
        $response = $this->getClient()->get('product_check.php');
        $this->assertOk($response);

        return $response;
    }

    private function prepareProductCheckExportAsAdmin(): void
    {
        \Tests\Support\TestDatabase::seed();
        $this->loginAsAdmin();
        $this->getClient()->get('menu.php');
    }

    private function prepareProductCheckExportAsPerm08(): void
    {
        \Tests\Support\TestDatabase::seed();
        $this->loginAs('perm08', 'perm0800');
        $this->getClient()->get('menu.php');
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

    // export の 1 行目比較に使うため、一覧 SQL と同等の内容を DB から取得する
    private function selectCheckExportRow(string $loginId, int $locationId, string $managementNo): array
    {
        $pdo = $this->createTestPdo();
        $stmt = $pdo->prepare(
            'SELECT
                st.management_no,
                ca.category_name,
                ma.maker_name,
                pr.product_name,
                st.quantity,
                IFNULL(ck.check_count, 0) AS check_count
             FROM stocks st
             LEFT JOIN products pr ON st.management_no = pr.management_no
             LEFT JOIN categories ca ON pr.category_id = ca.id
             LEFT JOIN makers ma ON pr.maker_id = ma.id
             LEFT JOIN checks ck
                ON st.management_no = ck.management_no
                AND st.location_id = ck.location_id
                AND ck.login_id = :login_id
             WHERE st.location_id = :location_id
               AND st.management_no = :management_no'
        );
        $stmt->execute([
            'login_id' => $loginId,
            'location_id' => $locationId,
            'management_no' => $managementNo,
        ]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($row, 'Expected check export row was not found.');

        return $row;
    }

    // 2 ページ目の切り出しを確認するため、店舗10へ 2005 件ぶんの在庫チェックデータを用意する
    private function prepareLargeCheckDataset(int $count): void
    {
        \Tests\Support\TestDatabase::seed();

        $pdo = $this->createTestPdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            $pdo->exec("DELETE FROM checks WHERE login_id = 'admin'");
            $pdo->exec('DELETE FROM stocks');
            $pdo->exec('DELETE FROM products');
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }

        $productStmt = $pdo->prepare(
            'INSERT INTO products (
                management_no, category_id, maker_id, product_name, wholesale_amount, retail_amount, sell_amount,
                quantity, unit_id, storage_place, image_file, remarks, remarks2, created_at, created_by, updated_at, updated_by
            ) VALUES (
                :management_no, 10, 10, :product_name, 1, 1, 1,
                0, NULL, :storage_place, \'\', \'\', \'\', NOW(), \'admin\', NOW(), \'admin\'
            )'
        );
        $stockStmt = $pdo->prepare(
            'INSERT INTO stocks (management_no, location_id, quantity, remarks, created_at, created_by, updated_at, updated_by)
             VALUES (:management_no, 10, 1, NULL, NOW(), \'admin\', NOW(), \'admin\')'
        );
        $checkStmt = $pdo->prepare(
            'INSERT INTO checks (login_id, location_id, management_no, check_count, result_flg, created_at, created_by, updated_at, updated_by)
             VALUES (\'admin\', 10, :management_no, 1, 1, NOW(), \'admin\', NOW(), \'admin\')'
        );

        $pdo->beginTransaction();
        try {
            for ($i = 1; $i <= $count; $i++) {
                $managementNo = sprintf('PCK%05d', $i);
                $productStmt->execute([
                    'management_no' => $managementNo,
                    'product_name' => 'チェック商品' . $i,
                    'storage_place' => '棚-' . sprintf('%02d', (($i - 1) % 20) + 1),
                ]);
                $stockStmt->execute([
                    'management_no' => $managementNo,
                ]);
                $checkStmt->execute([
                    'management_no' => $managementNo,
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

    private function normalizeCellString(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }
    // セッション期限切れ時に認証情報と画面用セッションが破棄されることを確認する
    public function testProductCheckExportClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage(
            'product_check_export.php',
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
