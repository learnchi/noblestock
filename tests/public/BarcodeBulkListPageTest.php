<?php

declare(strict_types=1);

use Noblestock\Logic\MessageConst;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Support\ExcelSettingsHelper;
use Tests\Support\Response;
use Tests\Support\SpreadsheetResponseHelper;
use Tests\Support\WebTestCase;

final class BarcodeBulkListPageTest extends WebTestCase
{
    use ExcelSettingsHelper;
    use SpreadsheetResponseHelper;

    // ログインしていない状態ではこの画面にアクセスできず、index.php に戻される
    public function testBarcodeBulkListRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('barcode_bulk_list.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // セッション切れメッセージを出して index.php に戻されることも確認する
    public function testBarcodeBulkListRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('barcode_bulk_list.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    public function testBarcodeBulkListDisplays(): void
    {
        $this->loginAsAdmin();

        $response = $this->getClient()->get('barcode_bulk_list.php');
        $this->assertOk($response);
    }

    // 権限を持つ perm07 でも表示できる
    public function testBarcodeBulkListDisplaysForPerm07(): void
    {
        $this->loginAs('perm07', 'perm0700');

        $response = $this->getClient()->get('barcode_bulk_list.php');
        $this->assertOk($response);
    }

    // 権限のない noauth では 403 になる
    public function testBarcodeBulkListReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->get('barcode_bulk_list.php');
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }

    // 初期表示ではファイル読込の案内が出ていて、バーコード出力ボタンはまだ押せない
    public function testBarcodeBulkListShowsInitialGuideMessageAndDisabledExportButton(): void
    {
        $this->prepareBarcodeBulkListAsAdmin();

        $response = $this->getClient()->get('barcode_bulk_list.php');
        $this->assertOk($response);

        $this->assertGuideMessage($response, MessageConst::MSG_INF_FILE_002);
        $this->assertBarcodeExportButtonDisabled($response);
    }

    // Excel 以外の拡張子を読ませたときはエラーになり、出力ボタンも無効のまま
    public function testBarcodeBulkListUploadWithInvalidExtensionShowsValidationError(): void
    {
        $this->prepareBarcodeBulkListAsAdmin();
        $tempFile = $this->createTempFile('not excel');

        try {
            $response = $this->getClient()->post('barcode_bulk_list.php', [
                'mode' => 'formexcel',
                'nofrom' => '',
                'noto' => '',
                'upfile' => curl_file_create($tempFile, 'text/plain', 'barcode-list.txt'),
            ]);
            $this->assertOk($response);

            $this->assertFlushErrorMessage($response, MessageConst::MSG_VAL_FILE_005);
            $this->assertGuideMessage($response, MessageConst::MSG_INF_FILE_002);
            $this->assertBarcodeExportButtonDisabled($response);
        } finally {
            @unlink($tempFile);
        }
    }

    // 正しい Excel を読ませると一覧が表示され、案内メッセージと出力ボタンの状態が切り替わる
    public function testBarcodeBulkListUploadValidExcelDisplaysImportedRowsAndEnablesExport(): void
    {
        $this->prepareBarcodeBulkListAsAdmin();
        $fixturePath = $this->createBarcodeExcelFixture([
            ['SEQ' => '10', 'management_no' => 'ABC001'],
            ['SEQ' => '20', 'management_no' => 'ABC002'],
            ['SEQ' => '30', 'management_no' => 'ABC003'],
        ]);

        try {
            $response = $this->uploadBarcodeExcelFixture($fixturePath, 'barcode-import.xlsx');

            $this->assertGuideMessage($response, MessageConst::MSG_INF_FILE_003);
            $this->assertImportedRows($response, [
                ['SEQ' => '10', 'management_no' => 'ABC001'],
                ['SEQ' => '20', 'management_no' => 'ABC002'],
                ['SEQ' => '30', 'management_no' => 'ABC003'],
            ]);
            $this->assertStringContainsString('barcode-import.xlsx', $response->body);
            $this->assertBarcodeExportButtonEnabled($response);
        } finally {
            @unlink($fixturePath);
        }
    }

    // 読み込んだ一覧に対して SEQ の From/To を指定すると、その範囲だけに絞り込まれる
    public function testBarcodeBulkListFilterNarrowsImportedRowsBySeqRange(): void
    {
        $this->prepareBarcodeBulkListAsAdmin();
        $fixturePath = $this->createBarcodeExcelFixture([
            ['SEQ' => '10', 'management_no' => 'ABC001'],
            ['SEQ' => '20', 'management_no' => 'ABC002'],
            ['SEQ' => '30', 'management_no' => 'ABC003'],
            ['SEQ' => '40', 'management_no' => 'ABC004'],
        ]);

        try {
            $uploadResponse = $this->uploadBarcodeExcelFixture($fixturePath, 'barcode-filter.xlsx');
            $this->assertGuideMessage($uploadResponse, MessageConst::MSG_INF_FILE_003);

            $response = $this->getClient()->post('barcode_bulk_list.php', [
                'mode' => 'filter',
                'nofrom' => '20',
                'noto' => '30',
            ]);
            $this->assertOk($response);

            $this->assertGuideMessage($response, MessageConst::MSG_INF_FILE_003);
            $this->assertImportedRows($response, [
                ['SEQ' => '20', 'management_no' => 'ABC002'],
                ['SEQ' => '30', 'management_no' => 'ABC003'],
            ]);
            $this->assertStringContainsString('name="nofrom" value="20"', $response->body);
            $this->assertStringContainsString('name="noto"   value="30"', $response->body);
            $this->assertBarcodeExportButtonEnabled($response);
        } finally {
            @unlink($fixturePath);
        }
    }

    // 1000件以下の一覧は、この画面からそのまま Excel を返してダウンロードさせる
    public function testBarcodeBulkListExcelModeReturnsAttachmentForImportedRows(): void
    {
        $this->prepareBarcodeBulkListAsAdmin();
        $originalSettings = $this->snapshotExcelSettings();
        $fixturePath = $this->createBarcodeExcelFixture([
            ['SEQ' => '10', 'management_no' => 'ABC001'],
            ['SEQ' => '20', 'management_no' => 'ABC002'],
        ]);

        try {
            $this->updateExcelSettings([
                'EXCEL_VAR' => 0,
            ]);
            $this->uploadBarcodeExcelFixture($fixturePath, 'barcode-export.xlsx');

            $response = $this->getClient()->post('barcode_bulk_list.php', [
                'mode' => 'excel',
            ]);

            $this->assertSame(200, $response->status);
            $this->assertStringContainsString('Content-Type: application/vnd.ms-excel', $response->headers);
            $this->assertStringContainsString(
                'Content-Disposition: attachment;filename="product_barcode_' . date('Ymd') . '.xls"',
                $response->headers
            );

            $sheet = $this->loadWorksheetFromResponse($response, 'xls');
            $this->assertNotNull($sheet);
        } finally {
            @unlink($fixturePath);
            $this->restoreExcelSettings($originalSettings);
        }
    }

    // 1001件以上の一覧は直接ダウンロードせず、分割出力画面へ遷移させる
    public function testBarcodeBulkListExcelModeRedirectsToSplitPageWhenRowCountExceedsLimit(): void
    {
        $this->prepareBarcodeBulkListAsAdmin();
        $this->seedBarcodeBulkListSessionDataViaBridge($this->buildBarcodeList(1001));

        $response = $this->getClient()->post('barcode_bulk_list.php', [
            'mode' => 'excel',
        ]);

        $this->assertOk($response);
        $this->assertStringContainsString('Location: barcode_list_export_split.php', $response->headers);
        $this->assertStringContainsString('name="listPage" value="1"', $response->body);
        $this->assertStringContainsString('name="listPage" value="2"', $response->body);
        $this->assertStringContainsString('href="barcode_bulk_list.php"', $response->body);
    }

    private function prepareBarcodeBulkListAsAdmin(): void
    {
        \Tests\Support\TestDatabase::seed();
        $this->loginAsAdmin();
        $this->getClient()->get('menu.php');
        $this->delSessionStructData('biz402', null, 'barcode_bulk_list.php');
        $this->delSessionStructData('com901', null, 'barcode_bulk_list.php');
    }

    // 実際の画面と同じ multipart/form-data で Excel を投げ、読込完了後の画面を返す
    private function uploadBarcodeExcelFixture(string $fixturePath, string $uploadName): Response
    {
        $response = $this->getClient()->post('barcode_bulk_list.php', [
            'mode' => 'formexcel',
            'nofrom' => '',
            'noto' => '',
            'upfile' => curl_file_create(
                $fixturePath,
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                $uploadName
            ),
        ]);
        $this->assertOk($response);

        return $response;
    }

    // getImportBarcode() が読める最小構成の Excel を一時生成する
    private function createBarcodeExcelFixture(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'SEQ');
        $sheet->setCellValue('B1', '管理番号');

        $rowNo = 2;
        foreach ($rows as $row) {
            $sheet->setCellValue('A' . $rowNo, (string) ($row['SEQ'] ?? ''));
            $sheet->setCellValue('B' . $rowNo, (string) ($row['management_no'] ?? ''));
            $rowNo++;
        }

        $tempBase = tempnam(sys_get_temp_dir(), 'barcode-bulk-list-');
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

    // 不正拡張子テスト用の一時ファイルを作る
    private function createTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'barcode-bulk-list-');
        $this->assertNotFalse($path, 'Failed to create temporary upload file.');
        file_put_contents($path, $contents);

        return $path;
    }

    // excel モードの大件数分岐だけは、現在のログインセッションに HTTP 経由で読込結果を投入して確認する
    private function seedBarcodeBulkListSessionDataViaBridge(array $list): void
    {
        $bridgeName = '__barcode_bulk_list_session_' . bin2hex(random_bytes(8)) . '.php';
        $bridgePath = dirname(__DIR__, 2) . '/public/' . $bridgeName;
        $bridgeCode = <<<'PHP'
<?php
session_cache_limiter("none");
session_start();
date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../vendor/autoload.php';

use Studiogau\Chandra\Support\SessionHelper;

$payload = json_decode(file_get_contents('php://input'), true);
$_SERVER['SCRIPT_NAME'] = '/noblestock/public/barcode_bulk_list.php';

SessionHelper::delData('biz402', null);
SessionHelper::setData('biz402', 'barcodeList', [
    'status' => '00000',
    'errMsg' => '',
    'lists' => $payload['list'] ?? [],
]);
SessionHelper::setData('biz402', 'barlist', $payload['list'] ?? []);
SessionHelper::setData('biz402', 'nofrom', '1');
SessionHelper::setData('biz402', 'noto', (string) count($payload['list'] ?? []));
SessionHelper::setData('biz402', 'strl', 'seeded.xlsx');

header('Content-Type: application/json');
echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
PHP;

        file_put_contents($bridgePath, $bridgeCode);

        try {
            $response = $this->postJsonWithCurrentClient($bridgeName, [
                'list' => $list,
            ]);

            $this->assertSame(200, $response->status);
            $this->assertStringContainsString('"ok":true', $response->body);
        } finally {
            @unlink($bridgePath);
        }
    }

    // 分割出力の閾値をまたぐ件数を手早く作るためのダミー一覧
    private function buildBarcodeList(int $count): array
    {
        $list = [];
        for ($i = 1; $i <= $count; $i++) {
            $list[] = [
                'SEQ' => (string) $i,
                'management_no' => 'ABC001',
            ];
        }

        return $list;
    }

    // info アラートの文言が期待通りかを確認する
    private function assertGuideMessage(Response $response, string $expectedMessage): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//div[contains(@class,'alert-info')]/div");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Guide message alert was not found.');

        $actual = trim((string) ($nodes->item(0)?->textContent ?? ''));
        $this->assertSame($expectedMessage, $actual);
    }

    // danger アラートに出るファイル関連エラーを確認する
    private function assertFlushErrorMessage(Response $response, string $expectedMessage): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//div[contains(@class,'alert-danger')]/div");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Flush error alert was not found.');

        $actual = trim((string) ($nodes->item(0)?->textContent ?? ''));
        $this->assertSame($expectedMessage, $actual);
    }

    // 読み込んだ SEQ と管理番号が表に並んでいることを行単位で確認する
    private function assertImportedRows(Response $response, array $expectedRows): void
    {
        $xpath = new DOMXPath($response->dom());
        $rowNodes = $xpath->query("//table[contains(@class,'table')]//tbody/tr");
        $this->assertNotFalse($rowNodes);
        $this->assertSame(count($expectedRows), $rowNodes->length, 'Unexpected imported row count.');

        foreach ($expectedRows as $index => $expectedRow) {
            $cellNodes = $xpath->query('./td', $rowNodes->item($index));
            $this->assertNotFalse($cellNodes);
            $this->assertSame(2, $cellNodes->length);

            $actualSeq = $this->normalizeText($cellNodes->item(0)?->textContent ?? '');
            $actualManagementNo = $this->normalizeText($cellNodes->item(1)?->textContent ?? '');

            $this->assertSame((string) $expectedRow['SEQ'], $actualSeq);
            $this->assertSame((string) $expectedRow['management_no'], $actualManagementNo);
        }
    }

    // 読込後だけ表示される出力フォームがあり、ボタンが押せる状態になっていることを確認する
    private function assertBarcodeExportButtonEnabled(Response $response): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//form[@action='barcode_bulk_list.php']//input[@name='mode' and @value='excel']/following-sibling::button");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Enabled barcode export button was not found.');
        $this->assertNull($nodes->item(0)?->attributes?->getNamedItem('disabled'));
    }

    // 読込前は disabled 付きの単独ボタンしか出ていないことを確認する
    private function assertBarcodeExportButtonDisabled(Response $response): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//button[@disabled]");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Disabled barcode export button was not found.');
    }

    private function normalizeText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    // 共有中の WebClient の cookie jar を使って JSON POST を送る
    private function postJsonWithCurrentClient(string $path, array $payload): Response
    {
        $clientRef = new ReflectionClass($this->getClient());

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
    public function testBarcodeBulkListClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('barcode_bulk_list.php');
    }
}
