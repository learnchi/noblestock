<?php

declare(strict_types=1);

use Noblestock\Logic\MessageConst;
use Tests\Support\ExcelSettingsHelper;
use Tests\Support\SpreadsheetResponseHelper;
use Tests\Support\WebClient;
use Tests\Support\WebTestCase;

final class MasterBulkExportTest extends WebTestCase
{
    use ExcelSettingsHelper;
    use SpreadsheetResponseHelper;

    // ログインセッションをクリア後、この画面にアクセスすると、indexに遷移する
    public function testMasterBulkExportRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->post('master_bulk_export.php', [
            'mode' => 'export',
        ]);
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertInitialStatus($response, 303);
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // ログインセッションをクリア後、この画面にアクセスすると、認証エラーメッセージ付きでindexに遷移する
    public function testMasterBulkExportRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->post('master_bulk_export.php', [
            'mode' => 'export',
        ]);
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertInitialStatus($response, 303);
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    // GET アクセスは 405 で、POST だけを受け付ける
    public function testNonPostAccessReturns405(): void
    {
        $response = $this->getClient()->get('master_bulk_export.php');
        $this->assertSame(405, $response->status);
        $this->assertStringContainsString('Allow: POST', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_VAL_FILE_017, $response->body);
    }

    // mode=export 以外の POST は 400 になる
    public function testPostWithoutExportModeReturns400(): void
    {
        $this->loginAsAdmin();

        $response = $this->getClient()->post('master_bulk_export.php', [
            'mode' => 'invalid',
        ]);
        $this->assertSame(400, $response->status);
        $this->assertStringContainsString(MessageConst::MSG_VAL_FILE_018, $response->body);
    }

    // メーカーを選んだ状態では、メーカー一覧が xls 添付で出力される
    public function testMasterBulkExportReturnsMakerXlsAttachmentWithExpectedCellValues(): void
    {
        $this->prepareMasterBulkExportAsAdmin();
        $originalSettings = $this->snapshotExcelSettings();

        try {
            $this->updateExcelSettings([
                'EXCEL_VAR' => 0,
            ]);
            $this->selectMasterForExport('2');

            $response = $this->getClient()->post('master_bulk_export.php', [
                'mode' => 'export',
            ]);

            $this->assertSame(200, $response->status);
            $this->assertStringContainsString('Content-Type: application/vnd.ms-excel', $response->headers);
            $this->assertStringContainsString(
                'Content-Disposition: attachment;filename="maker_' . date('Ymd') . '.xls"',
                $response->headers
            );

            $sheet = $this->loadWorksheetFromResponse($response, 'xls');
            $this->assertSame('メーカーid', (string) $sheet->getCell('A1')->getValue());
            $this->assertSame('メーカー名', (string) $sheet->getCell('B1')->getValue());
            $this->assertSame('備考', (string) $sheet->getCell('C1')->getValue());
            $this->assertSame('10', (string) $sheet->getCell('A2')->getValue());
            $this->assertSame('メーカー10', $this->normalizeCellString($sheet->getCell('B2')->getValue()));
            $this->assertSame('備考10', $this->normalizeCellString($sheet->getCell('C2')->getValue()));
            $this->assertSame('50', (string) $sheet->getCell('A6')->getValue());
            $this->assertSame('メーカー50', $this->normalizeCellString($sheet->getCell('B6')->getValue()));
        } finally {
            $this->restoreExcelSettings($originalSettings);
        }
    }

    // EXCEL_VAR=1 のときは店舗一覧が xlsx 添付として出力される
    public function testMasterBulkExportReturnsLocationXlsxAttachmentWhenExcelVarIsOne(): void
    {
        $this->prepareMasterBulkExportAsAdmin();
        $originalSettings = $this->snapshotExcelSettings();

        try {
            $this->updateExcelSettings([
                'EXCEL_VAR' => 1,
            ]);
            $this->selectMasterForExport('4');

            $response = $this->getClient()->post('master_bulk_export.php', [
                'mode' => 'export',
            ]);

            $this->assertSame(200, $response->status);
            $this->assertStringContainsString(
                'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                $response->headers
            );
            $this->assertStringContainsString(
                'Content-Disposition: attachment;filename="shop_' . date('Ymd') . '.xlsx"',
                $response->headers
            );
            $this->assertSame('PK', substr($response->body, 0, 2));

            $sheet = $this->loadWorksheetFromResponse($response, 'xlsx');
            $this->assertSame('店舗id', (string) $sheet->getCell('A1')->getValue());
            $this->assertSame('店舗名', (string) $sheet->getCell('B1')->getValue());
            $this->assertSame('備考', (string) $sheet->getCell('C1')->getValue());
            $this->assertSame('10', (string) $sheet->getCell('A2')->getValue());
            $this->assertSame('店舗10', $this->normalizeCellString($sheet->getCell('B2')->getValue()));
            $this->assertSame('備考店舗10', $this->normalizeCellString($sheet->getCell('C2')->getValue()));
            $this->assertSame('50', (string) $sheet->getCell('A6')->getValue());
            $this->assertSame('店舗50', $this->normalizeCellString($sheet->getCell('B6')->getValue()));
        } finally {
            $this->restoreExcelSettings($originalSettings);
        }
    }

    // 権限ありユーザー perm15 でも export POST は 200 になる
    public function testPostWithExportModeReturns200ForPerm15(): void
    {
        $this->prepareMasterBulkExportAsPerm15();
        $this->selectMasterForExport('2');

        $response = $this->getClient()->post('master_bulk_export.php', [
            'mode' => 'export',
        ]);
        $this->assertSame(200, $response->status);
    }

    // 権限なしユーザー noauth では 403 になる
    public function testPostWithExportModeReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->post('master_bulk_export.php', [
            'mode' => 'export',
        ]);
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_003, $response->body);
    }

    private function prepareMasterBulkExportAsAdmin(): void
    {
        \Tests\Support\TestDatabase::seed();
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->loginAsAdmin();
        $this->getClient()->get('menu.php');
    }

    private function prepareMasterBulkExportAsPerm15(): void
    {
        \Tests\Support\TestDatabase::seed();
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->loginAs('perm15', 'perm1500');
        $this->getClient()->get('menu.php');
    }

    // master_bulk_edit と同じ selectMaster POST で、export 対象マスタを現在セッションへ保存する
    private function selectMasterForExport(string $masterNo): void
    {
        $response = $this->getClient()->post('master_bulk_edit.php', [
            'mode' => 'selectMaster',
            'masterNo' => $masterNo,
        ]);
        $this->assertOk($response);
    }

    private function normalizeCellString(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }
    // セッション期限切れ時に認証情報と画面用セッションが破棄されることを確認する
    public function testMasterBulkExportClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage(
            'master_bulk_export.php',
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
