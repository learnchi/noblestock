<?php

declare(strict_types=1);

use Noblestock\DbLogic\Config;
use Noblestock\Logic\MessageConst;
use Tests\Support\Response;
use Tests\Support\SpreadsheetResponseHelper;
use Tests\Support\WebTestCase;

final class BarcodeExportTest extends WebTestCase
{
    use SpreadsheetResponseHelper;

    // barcode_export.php を直接開いた場合は 404 を返す
    public function testDirectAccessReturns404(): void
    {
        $response = $this->getClient()->get('barcode_export.php');
        $this->assertSame(404, $response->status);
    }

    // 権限ありユーザーでも直接アクセスは禁止されている
    public function testDirectAccessReturns404ForPerm01(): void
    {
        $this->loginAs('perm01', 'perm0100');

        $response = $this->getClient()->get('barcode_export.php');
        $this->assertSame(404, $response->status);
    }

    // 権限なしユーザーでも直接アクセスは禁止されている
    public function testDirectAccessReturns404ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->get('barcode_export.php');
        $this->assertSame(404, $response->status);
    }

    // 画面で選択した商品情報を使って xls を出力し、連番と管理番号が書き込まれる
    public function testBarcodeExportReturnsXlsAttachmentWithExpectedRows(): void
    {
        $this->loginAsAdmin();
        $originalSettings = $this->snapshotBarcodeSettings();

        try {
            $this->updateBarcodeSettings([
                'EXCEL_VAR' => 0,
            ]);
            $csrfFields = $this->prepareBarcodeExportSession(['ABC005', 'ABC002']);

            $response = $this->postViaBarcodeExportBridge([
                'OUT_NUM_0' => '2',
                'OUT_NUM_1' => '1',
            ] + $csrfFields);

            $this->assertSame(200, $response->status);
            $this->assertStringContainsString('Content-Type: application/vnd.ms-excel', $response->headers);
            $this->assertStringContainsString(
                'Content-Disposition: attachment;filename="barcodeList_' . date('Ymd') . '.xls"',
                $response->headers
            );

            $sheet = $this->loadWorksheetFromResponse($response, 'xls');
            $this->assertSame(1, (int) $sheet->getCell('A2')->getValue());
            $this->assertSame('ABC005', (string) $sheet->getCell('B2')->getValue());
            $this->assertSame(2, (int) $sheet->getCell('A3')->getValue());
            $this->assertSame('ABC005', (string) $sheet->getCell('B3')->getValue());
            $this->assertSame(3, (int) $sheet->getCell('A4')->getValue());
            $this->assertSame('ABC002', (string) $sheet->getCell('B4')->getValue());
        } finally {
            $this->restoreBarcodeSettings($originalSettings);
        }
    }

    // 非数値は 1、0 以下は 0 として扱われ、0 件の商品は出力されない
    public function testBarcodeExportNormalizesOutNumValues(): void
    {
        $this->loginAsAdmin();
        $originalSettings = $this->snapshotBarcodeSettings();

        try {
            $this->updateBarcodeSettings([
                'EXCEL_VAR' => 0,
            ]);
            $csrfFields = $this->prepareBarcodeExportSession(['ABC005', 'ABC002', 'ABC001']);

            $response = $this->postViaBarcodeExportBridge([
                'OUT_NUM_0' => 'abc',
                'OUT_NUM_1' => '0',
                'OUT_NUM_2' => '-3',
            ] + $csrfFields);

            $this->assertSame(200, $response->status);

            $sheet = $this->loadWorksheetFromResponse($response, 'xls');
            $this->assertSame(1, $this->countCellValueOccurrences($sheet, 'ABC005'));
            $this->assertSame(0, $this->countCellValueOccurrences($sheet, 'ABC002'));
            $this->assertSame(0, $this->countCellValueOccurrences($sheet, 'ABC001'));
            $this->assertSame(1, (int) $sheet->getCell('A2')->getValue());
            $this->assertSame('ABC005', (string) $sheet->getCell('B2')->getValue());
            $this->assertSame('', (string) $sheet->getCell('B3')->getValue());
        } finally {
            $this->restoreBarcodeSettings($originalSettings);
        }
    }

    // EXCEL_VAR=1 のときは xlsx 添付として出力される
    public function testBarcodeExportReturnsXlsxAttachmentWhenExcelVarIsOne(): void
    {
        $this->loginAsAdmin();
        $originalSettings = $this->snapshotBarcodeSettings();

        try {
            $this->updateBarcodeSettings([
                'EXCEL_VAR' => 1,
            ]);
            $csrfFields = $this->prepareBarcodeExportSession(['ABC005']);

            $response = $this->postViaBarcodeExportBridge([
                'OUT_NUM_0' => '1',
            ] + $csrfFields);

            $this->assertSame(200, $response->status);
            $this->assertStringContainsString(
                'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                $response->headers
            );
            $this->assertStringContainsString(
                'Content-Disposition: attachment;filename="barcodeList_' . date('Ymd') . '.xlsx"',
                $response->headers
            );
            $this->assertSame('PK', substr($response->body, 0, 2));

            $sheet = $this->loadWorksheetFromResponse($response, 'xlsx');
            $this->assertSame(1, (int) $sheet->getCell('A2')->getValue());
            $this->assertSame('ABC005', (string) $sheet->getCell('B2')->getValue());
        } finally {
            $this->restoreBarcodeSettings($originalSettings);
        }
    }

    // 未ログインのまま include 経由で実行すると index.php へ戻される
    public function testBarcodeExportRedirectsToIndexWhenSessionIsMissing(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->postViaBarcodeExportBridge([
            'OUT_NUM_0' => '1',
            '_skip_auto_csrf' => true,
        ]);

        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    // 権限なしユーザーで include 経由実行すると 403 を返す
    public function testBarcodeExportReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->postViaBarcodeExportBridge([
            'OUT_NUM_0' => '1',
            '_skip_auto_csrf' => true,
        ]);

        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_003, $response->body);
    }

    // CSRF トークンなしのバーコードリスト出力要求は 400 で拒否する
    public function testBarcodeExportReturns400WithoutCsrfToken(): void
    {
        $this->loginAsAdmin();
        $this->prepareBarcodeExportSession(['ABC005']);

        $response = $this->postViaBarcodeExportBridge([
            'OUT_NUM_0' => '1',
            '_skip_auto_csrf' => true,
        ]);

        $this->assertSame(400, $response->status, "Expected 400 from {$response->url}");
        $this->assertStringContainsString(MessageConst::MSG_VAL_FILE_018, $response->body);
    }

    // product_barcode_list.php を一度表示して barcode_export 用セッションを組み立てる
    private function prepareBarcodeExportSession(array $managementNos): array
    {
        $this->seedSelectedMngNos($managementNos);

        $response = $this->getClient()->get('product_barcode_list.php');
        $this->assertOk($response);

        return $this->extractCsrfPostData($response);
    }

    // 一時ブリッジ経由で barcode_export.php を include 実行する
    private function postViaBarcodeExportBridge(array $postData): Response
    {
        $bridgeName = '__barcode_export_bridge_' . bin2hex(random_bytes(8)) . '.php';
        $bridgePath = dirname(__DIR__, 2) . '/public/' . $bridgeName;
        $bridgeCode = <<<'PHP'
<?php
require __DIR__ . '/barcode_export.php';
PHP;

        file_put_contents($bridgePath, $bridgeCode);

        try {
            return $this->getClient()->post($bridgeName, $postData);
        } finally {
            @unlink($bridgePath);
        }
    }

    // 現在のバーコード出力設定を退避する
    private function snapshotBarcodeSettings(): array
    {
        $settings = [];
        foreach ((new Config())->list() as $row) {
            $key = (string) ($row['config_key'] ?? '');
            if ($key === 'EXCEL_VAR') {
                $settings[$key] = (string) ($row['value_int'] ?? '');
            }
        }

        return $settings;
    }

    // settings.php 経由でバーコード出力設定を更新する
    private function updateBarcodeSettings(array $settings): void
    {
        $response = $this->getClient()->post('settings.php', array_merge($settings, [
            'mode' => 'update',
        ]));

        $this->assertOk($response);
        $this->assertStringContainsString('settings.php', $response->headers . $response->body);
    }

    // 退避したバーコード出力設定に戻す
    private function restoreBarcodeSettings(array $settings): void
    {
        if ($settings === []) {
            return;
        }

        $this->updateBarcodeSettings($settings);
    }

    // 現在の WebClient と同じ cookie jar を使って selectedMngNos を投入する
    private function seedSelectedMngNos(array $mngNos): void
    {
        $this->clearSelectedMngNos();

        $response = $this->postJsonWithCurrentClient('api/BarcodeOutput.php', [
            'mode' => 'selectAll',
            'mngNos' => array_values($mngNos),
        ]);

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('"ok":true', $response->body);
    }

    // 選択中の管理番号一覧を空にする
    private function clearSelectedMngNos(): void
    {
        $response = $this->postJsonWithCurrentClient('api/BarcodeOutput.php', [
            'mode' => 'clearSelect',
        ]);

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('"ok":true', $response->body);
    }

    // 現在の WebClient と同じ cookie jar を使って JSON POST を行う
    private function postJsonWithCurrentClient(string $path, array $payload): Response
    {
        $payload += $this->extractCsrfPostData($this->getClient()->get('menu.php'));

        $clientRef = new \ReflectionClass($this->getClient());

        $baseUrlProperty = $clientRef->getProperty('baseUrl');
        $baseUrlProperty->setAccessible(true);
        $baseUrl = rtrim((string) $baseUrlProperty->getValue($this->getClient()), '/') . '/';

        $cookieFileProperty = $clientRef->getProperty('cookieFile');
        $cookieFileProperty->setAccessible(true);
        $cookieFile = (string) $cookieFileProperty->getValue($this->getClient());

        $url = str_starts_with($path, 'http') ? $path : $baseUrl . ltrim($path, '/');

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $cookieFile,
            CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->fail("cURL error: {$error}");
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        return new Response(
            $status,
            substr((string) $raw, 0, $headerSize),
            substr((string) $raw, $headerSize),
            $url
        );
    }
    // セッション期限切れ時に認証情報と画面用セッションが破棄されることを確認する
    public function testBarcodeExportClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('barcode_export.php');
    }
}
