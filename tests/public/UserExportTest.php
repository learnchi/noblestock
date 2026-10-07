<?php

declare(strict_types=1);

use Noblestock\Logic\MessageConst;
use Tests\Support\ExcelSettingsHelper;
use Tests\Support\SpreadsheetResponseHelper;
use Tests\Support\WebClient;
use Tests\Support\WebTestCase;

final class UserExportTest extends WebTestCase
{
    use ExcelSettingsHelper;
    use SpreadsheetResponseHelper;

    // ログインセッションをクリア後、この画面にアクセスすると、indexに遷移する
    public function testUserExportRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->post('user_export.php', [
            'mode' => 'export',
        ]);
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertInitialStatus($response, 303);
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // ログインセッションをクリア後、この画面にアクセスすると、認証エラーメッセージ付きでindexに遷移する
    public function testUserExportRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->post('user_export.php', [
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
        $response = $this->getClient()->get('user_export.php');
        $this->assertSame(405, $response->status);
        $this->assertStringContainsString('Allow: POST', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_VAL_FILE_017, $response->body);
    }

    // mode=export 以外の POST は 400 になる
    public function testPostWithoutExportModeReturns400(): void
    {
        $this->loginAsAdmin();

        $response = $this->getClient()->post('user_export.php', [
            'mode' => 'invalid',
        ]);
        $this->assertSame(400, $response->status);
        $this->assertStringContainsString(MessageConst::MSG_VAL_FILE_018, $response->body);
    }

    // EXCEL_VAR=0 では xls 添付として出力され、ユーザー一覧のセル値が入る
    public function testUserExportReturnsXlsAttachmentWithExpectedCellValues(): void
    {
        $this->prepareUserExportAsAdmin();
        $originalSettings = $this->snapshotExcelSettings();

        try {
            $this->updateExcelSettings([
                'EXCEL_VAR' => 0,
            ]);

            $response = $this->getClient()->post('user_export.php', [
                'mode' => 'export',
            ]);

            $this->assertSame(200, $response->status);
            $this->assertStringContainsString('Content-Type: application/vnd.ms-excel', $response->headers);
            $this->assertStringContainsString(
                'Content-Disposition: attachment;filename="user_' . date('Ymd') . '.xls"',
                $response->headers
            );

            $sheet = $this->loadWorksheetFromResponse($response, 'xls');
            $this->assertSame('ユーザーID', (string) $sheet->getCell('A1')->getValue());
            $this->assertSame('パスワード', (string) $sheet->getCell('B1')->getValue());
            $this->assertSame('名前', (string) $sheet->getCell('C1')->getValue());
            $this->assertSame('シーケンシャルNo', (string) $sheet->getCell('D1')->getValue());
            $this->assertSame('フリガナ', (string) $sheet->getCell('E1')->getValue());
            $this->assertSame('メールアドレス', (string) $sheet->getCell('F1')->getValue());
            $this->assertSame('権限', (string) $sheet->getCell('G1')->getValue());

            $this->assertSame('admin', (string) $sheet->getCell('A2')->getValue());
            $this->assertSame('', (string) $sheet->getCell('B2')->getValue());
            $this->assertSame('管理者がう', $this->normalizeCellString($sheet->getCell('C2')->getValue()));
            $this->assertSame('1', (string) $sheet->getCell('D2')->getValue());
            $this->assertSame('カンリシャガウ', $this->normalizeCellString($sheet->getCell('E2')->getValue()));
            $this->assertSame('admingau@example.com', $this->normalizeCellString($sheet->getCell('F2')->getValue()));
            $this->assertSame('11111111111111111111', $this->normalizeCellString($sheet->getCell('G2')->getValue()));

            $this->assertSame('user1', (string) $sheet->getCell('A3')->getValue());
            $this->assertSame('2', (string) $sheet->getCell('D3')->getValue());
            $this->assertSame('11111111111111111100', $this->normalizeCellString($sheet->getCell('G3')->getValue()));
        } finally {
            $this->restoreExcelSettings($originalSettings);
        }
    }

    // EXCEL_VAR=1 のときは xlsx 添付として出力され、権限の長い文字列もそのまま保持される
    public function testUserExportReturnsXlsxAttachmentWhenExcelVarIsOne(): void
    {
        $this->prepareUserExportAsAdmin();
        $originalSettings = $this->snapshotExcelSettings();

        try {
            $this->updateExcelSettings([
                'EXCEL_VAR' => 1,
            ]);

            $response = $this->getClient()->post('user_export.php', [
                'mode' => 'export',
            ]);

            $this->assertSame(200, $response->status);
            $this->assertStringContainsString(
                'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                $response->headers
            );
            $this->assertStringContainsString(
                'Content-Disposition: attachment;filename="user_' . date('Ymd') . '.xlsx"',
                $response->headers
            );
            $this->assertSame('PK', substr($response->body, 0, 2));

            $sheet = $this->loadWorksheetFromResponse($response, 'xlsx');
            $this->assertSame('admin', (string) $sheet->getCell('A2')->getValue());
            $this->assertSame('1', (string) $sheet->getCell('D2')->getValue());
            $this->assertSame('11111111111111111111', $this->normalizeCellString($sheet->getCell('G2')->getValue()));
            $this->assertSame('user1', (string) $sheet->getCell('A3')->getValue());
            $this->assertSame('11111111111111111100', $this->normalizeCellString($sheet->getCell('G3')->getValue()));
        } finally {
            $this->restoreExcelSettings($originalSettings);
        }
    }

    // この画面に権限があるユーザー[perm18]で200で表示される
    public function testPostWithExportModeReturns200ForPerm18(): void
    {
        $this->prepareUserExportAsPerm18();

        $response = $this->getClient()->post('user_export.php', [
            'mode' => 'export',
        ]);
        $this->assertSame(200, $response->status);
    }

    // この画面に権限がないユーザーnoauthで403エラーとなる
    public function testPostWithExportModeReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->post('user_export.php', [
            'mode' => 'export',
        ]);
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_003, $response->body);
    }

    private function prepareUserExportAsAdmin(): void
    {
        \Tests\Support\TestDatabase::seed();
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->loginAsAdmin();
        $this->getClient()->get('menu.php');
    }

    private function prepareUserExportAsPerm18(): void
    {
        \Tests\Support\TestDatabase::seed();
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->loginAs('perm18', 'perm1800');
        $this->getClient()->get('menu.php');
    }

    private function normalizeCellString(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }
    // セッション期限切れ時に認証情報と画面用セッションが破棄されることを確認する
    public function testUserExportClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage(
            'user_export.php',
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
