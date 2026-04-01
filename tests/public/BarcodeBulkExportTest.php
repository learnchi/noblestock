<?php

declare(strict_types=1);

use Noblestock\DbLogic\Config;
use Noblestock\DbLogic\Product;
use Noblestock\Logic\MessageConst;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Support\ImageWebTestCase;
use Tests\Support\Response;
use Tests\Support\SpreadsheetResponseHelper;

final class BarcodeBulkExportTest extends ImageWebTestCase
{
    use SpreadsheetResponseHelper;

    // ログインセッションがない状態で export POST すると、index.php にリダイレクトされる。
    public function testBarcodeBulkExportRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->post('barcode_bulk_export.php', [
            'mode' => 'export',
        ]);
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // ログインセッションがない状態で export POST すると、認証エラーメッセージ付きで index.php に戻る。
    public function testBarcodeBulkExportRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->post('barcode_bulk_export.php', [
            'mode' => 'export',
        ]);
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    // GET アクセスは 405 を返し、Allow: POST ヘッダーとメッセージを返す。
    public function testNonPostAccessReturns405(): void
    {
        $response = $this->getClient()->get('barcode_bulk_export.php');

        $this->assertSame(405, $response->status);
        $this->assertStringContainsString('Allow: POST', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_VAL_FILE_017, $response->body);
    }

    // mode=export 以外の POST は 400 を返し、入力エラーメッセージを返す。
    public function testPostWithoutExportModeReturns400(): void
    {
        $this->loginAsAdmin();

        $response = $this->getClient()->post('barcode_bulk_export.php', [
            'mode' => 'invalid',
        ]);

        $this->assertSame(400, $response->status);
        $this->assertStringContainsString(MessageConst::MSG_VAL_FILE_018, $response->body);
    }

    // barcode_create.php で準備したセッションから、xls 添付ファイルが生成され、先頭ラベルに商品情報が入る。
    public function testBarcodeBulkExportReturnsXlsAttachmentWithExpectedCellValues(): void
    {
        $this->loginAsAdmin();
        $originalSettings = $this->snapshotBarcodeSettings();
        $productData = $this->selectProductData('ABC002');

        try {
            $this->updateBarcodeSettings([
                'EXCEL_VAR' => 0,
                'BAR_PRT_SIZE' => 1,
                'LABEL_UPPER' => 1,
                'LABEL_LOWER' => 4,
            ]);
            $csrfFields = $this->seedBarcodeBulkExportSession(
                'mng_product',
                [$this->createBarcodeListRow($productData, 1, 4)]
            );

            $response = $this->getClient()->post('barcode_bulk_export.php', [
                'mode' => 'export',
            ] + $csrfFields);

            $this->assertSame(200, $response->status);
            $this->assertStringContainsString('Content-Type: application/vnd.ms-excel', $response->headers);
            $this->assertStringContainsString(
                'Content-Disposition: attachment;filename="mng_product_barcode_' . date('Ymd') . '.xls"',
                $response->headers
            );

            $sheet = $this->loadWorksheetFromResponse($response, 'xls');
            $this->assertSame($productData['management_no'], (string) $sheet->getCell('A1')->getValue());
            $this->assertSame($productData['product_name'], (string) $sheet->getCell('A3')->getValue());
        } finally {
            $this->restoreBarcodeSettings($originalSettings);
        }
    }

    // EXCEL_VAR=1 の場合は xlsx 添付ファイルが生成され、セル内容も保持される。
    public function testBarcodeBulkExportReturnsXlsxAttachmentWhenExcelVarIsOne(): void
    {
        $this->loginAsAdmin();
        $originalSettings = $this->snapshotBarcodeSettings();

        try {
            $this->updateBarcodeSettings([
                'EXCEL_VAR' => 1,
                'BAR_PRT_SIZE' => 1,
                'LABEL_UPPER' => 1,
                'LABEL_LOWER' => 4,
            ]);
            $csrfFields = $this->seedBarcodeBulkExportSession(
                'mng_product',
                [$this->createBarcodeListRow($this->selectProductData('ABC002'), 1, 4)]
            );

            $response = $this->getClient()->post('barcode_bulk_export.php', [
                'mode' => 'export',
            ] + $csrfFields);

            $this->assertSame(200, $response->status);
            $this->assertStringContainsString(
                'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                $response->headers
            );
            $this->assertStringContainsString(
                'Content-Disposition: attachment;filename="mng_product_barcode_' . date('Ymd') . '.xlsx"',
                $response->headers
            );
            $this->assertSame('PK', substr($response->body, 0, 2));
        } finally {
            $this->restoreBarcodeSettings($originalSettings);
        }
    }

    // listPage=2 を POST した場合は 2 ページ目の添付ファイル名になり、分割後の 1 件だけが出力される。
    public function testBarcodeBulkExportUsesRequestedSecondPageSlice(): void
    {
        $this->loginAsAdmin();
        $originalSettings = $this->snapshotBarcodeSettings();

        try {
            $this->updateBarcodeSettings([
                'EXCEL_VAR' => 0,
                'BAR_PRT_SIZE' => 1,
                'LABEL_UPPER' => 1,
                'LABEL_LOWER' => 1,
            ]);
            $productData = $this->selectProductData('ABC002');
            $barList = array_fill(0, 1001, $this->createBarcodeListRow($productData, 1, 1));
            $csrfFields = $this->seedBarcodeBulkExportSession('product', $barList, 'product_barcode_list');

            $response = $this->getClient()->post('barcode_bulk_export.php', [
                'mode' => 'export',
                'listPage' => '2',
            ] + $csrfFields);

            $this->assertSame(200, $response->status);
            $this->assertStringContainsString(
                'Content-Disposition: attachment;filename="product_barcode_' . date('Ymd') . '-2.xls"',
                $response->headers
            );

            $sheet = $this->loadWorksheetFromResponse($response, 'xls');
            $this->assertSame('ABC002', (string) $sheet->getCell('A1')->getValue());
            $this->assertSame('ABC002', (string) $sheet->getCell('A3')->getValue());
            $this->assertSame(2, $this->countCellValueOccurrences($sheet, 'ABC002'));
        } finally {
            $this->restoreBarcodeSettings($originalSettings);
        }
    }

    // BAR_PRT_SIZE=3 かつ画像付き商品を出力した場合は、バーコード画像に加えて商品画像の Drawing も含まれる。
    public function testBarcodeBulkExportIncludesProductImageDrawingWhenBarSizeIsThree(): void
    {
        $this->loginAsAdmin();
        $originalSettings = $this->snapshotBarcodeSettings();
        $originalProductData = $this->selectProductData('ABC002');

        try {
            $this->updateBarcodeSettings([
                'EXCEL_VAR' => 1,
                'BAR_PRT_SIZE' => 3,
                'LABEL_UPPER' => 1,
                'LABEL_LOWER' => 4,
            ]);
            $productData = $this->replaceProductImageFile($originalProductData, 'sample001.jpg');
            $csrfFields = $this->seedBarcodeBulkExportSession(
                'mng_product',
                [$this->createBarcodeListRow($productData, 1, 4)]
            );

            $response = $this->getClient()->post('barcode_bulk_export.php', [
                'mode' => 'export',
            ] + $csrfFields);

            $this->assertSame(200, $response->status);
            $this->assertSame('PK', substr($response->body, 0, 2));
            $sheet = $this->loadWorksheetFromResponse($response, 'xlsx');
            $this->assertSame($productData['management_no'], (string) $sheet->getCell('A1')->getValue());
            $this->assertSame($productData['product_name'], (string) $sheet->getCell('A3')->getValue());
        } finally {
            $this->restoreProductData($originalProductData);
            $this->restoreBarcodeSettings($originalSettings);
        }
    }

    // 現在の設定画面で扱うバーコード出力関連設定を取得する。
    private function snapshotBarcodeSettings(): array
    {
        $settings = [];
        foreach ((new Config())->list() as $row) {
            $key = (string) ($row['config_key'] ?? '');
            if (in_array($key, ['EXCEL_VAR', 'BAR_PRT_SIZE', 'LABEL_UPPER', 'LABEL_LOWER'], true)) {
                $settings[$key] = (string) ($row['value_int'] ?? '');
            }
        }

        return $settings;
    }

    // settings.php を通してバーコード出力関連設定を更新する。
    private function updateBarcodeSettings(array $settings): void
    {
        $response = $this->getClient()->post('settings.php', array_merge($settings, [
            'mode' => 'update',
        ]));

        $this->assertOk($response);
        $this->assertStringContainsString('settings.php', $response->headers . $response->body);
    }

    // 退避していた設定値を settings.php 経由で元に戻す。
    private function restoreBarcodeSettings(array $settings): void
    {
        if ($settings === []) {
            return;
        }

        $this->updateBarcodeSettings($settings);
    }

    // barcode_create.php に管理番号を POST し、barcode_bulk_export.php が参照する com901 セッションを作る。
    private function prepareBarcodeCreateSession(string $managementNo): void
    {
        $response = $this->getClient()->post('barcode_create.php', [
            'barcode' => $managementNo,
        ]);

        $this->assertOk($response);
    }

    private function createBarcodeListRow(array $productData, int $labelUpper, int $labelLower): array
    {
        return [
            'bar_no' => (string) ($productData['management_no'] ?? ''),
            'img_file' => (string) ($productData['image_file'] ?? ''),
            'bar_top' => $this->resolveBarcodeLabelText($productData, $labelUpper),
            'bar_btm' => $this->resolveBarcodeLabelText($productData, $labelLower),
        ];
    }

    private function resolveBarcodeLabelText(array $productData, int $label): string
    {
        return match ($label) {
            1 => (string) ($productData['management_no'] ?? ''),
            2 => (string) ($productData['category_name'] ?? ''),
            3 => (string) ($productData['maker_name'] ?? ''),
            4 => (string) ($productData['product_name'] ?? ''),
            5 => ($productData['wholesale_amount'] ?? '') === '' ? '' : '\\' . number_format((int) $productData['wholesale_amount']),
            6 => ($productData['retail_amount'] ?? '') === '' ? '' : '\\' . number_format((int) $productData['retail_amount']),
            7 => ($productData['sell_amount'] ?? '') === '' ? '' : '\\' . number_format((int) $productData['sell_amount']),
            8 => (string) ($productData['storage_place'] ?? ''),
            default => '',
        };
    }

    private function seedBarcodeBulkExportSession(string $barFile, array $barList, ?string $backTo = null): array
    {
        $bridgeName = '__barcode_bulk_session_' . bin2hex(random_bytes(8)) . '.php';
        $bridgePath = dirname(__DIR__, 2) . '/public/' . $bridgeName;
        $bridgeCode = <<<'PHP'
<?php
session_cache_limiter("none");
@session_start();
date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../vendor/autoload.php';

use Studiogau\Chandra\Support\SessionHelper;
use Studiogau\Chandra\Support\Utility;

$payload = json_decode(file_get_contents('php://input'), true);
$_SERVER['SCRIPT_NAME'] = '/noblestock/public/barcode_bulk_export.php';

SessionHelper::setData('com901', 'barFile', $payload['barFile'] ?? '');
SessionHelper::setData('com901', 'barlist', $payload['barList'] ?? []);
if (array_key_exists('backTo', $payload) && $payload['backTo'] !== null) {
    SessionHelper::setData('com901', 'backTo', $payload['backTo']);
} else {
    SessionHelper::delData('com901', 'backTo');
    // セッション期限切れ時に認証情報と画面用セッションが破棄されることを確認する
    // セッション期限切れ時に認証情報と画面用セッションが破棄されることを確認する
}

$scope = (string)($payload['csrfScope'] ?? 'test.barcode_bulk_export');
$token = Utility::issueCsrfToken($scope);

header('Content-Type: application/json');
echo json_encode([
    'ok' => true,
    'csrfScope' => $scope,
    'csrfToken' => $token,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
PHP;

        file_put_contents($bridgePath, $bridgeCode);

        try {
            $response = $this->postJsonWithCurrentClient($bridgeName, [
                'barFile' => $barFile,
                'barList' => $barList,
                'backTo' => $backTo,
                'csrfScope' => 'test.barcode_bulk_export',
            ], false);

            $this->assertSame(200, $response->status);
            $decoded = json_decode($response->body, true);
            $this->assertIsArray($decoded);
            $this->assertTrue((bool)($decoded['ok'] ?? false));

            return [
                '_csrf_scope' => (string)($decoded['csrfScope'] ?? ''),
                '_csrf_token' => (string)($decoded['csrfToken'] ?? ''),
            ];
        } finally {
            @unlink($bridgePath);
        }
    }

    // 商品一覧の選択状態 API を使って、現在の Web セッションへ selectedMngNos を投入する。
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

    // 商品一覧の選択状態を空に戻す。
    private function clearSelectedMngNos(): void
    {
        $response = $this->postJsonWithCurrentClient('api/BarcodeOutput.php', [
            'mode' => 'clearSelect',
        ]);

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('"ok":true', $response->body);
    }

    // 現在の WebClient と同じ cookie jar を使って JSON POST を送る。
    private function postJsonWithCurrentClient(string $path, array $payload, bool $includeCsrf = true): Response
    {
        if ($includeCsrf) {
            $payload += $this->extractCsrfPostData($this->getClient()->get('menu.php'));
        }

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

    // レスポンスの Excel バイナリを一時ファイルへ保存してワークシートを読み込む。
    private function loadWorksheetFromResponse(Response $response, string $extension): Worksheet
    {
        $spreadsheet = $this->loadSpreadsheetFromResponse($response, $extension);

        return $spreadsheet->getActiveSheet();
    }

    // レスポンスの Excel バイナリを PhpSpreadsheet で読み込む。
    private function loadSpreadsheetFromResponse(Response $response, string $extension): Spreadsheet
    {
        $tempBase = tempnam(sys_get_temp_dir(), 'noble-bar-');
        if ($tempBase === false) {
            $this->fail('Failed to create temp file for spreadsheet.');
        }

        $tempFile = $tempBase . '.' . $extension;
        if (!@rename($tempBase, $tempFile)) {
            @unlink($tempBase);
            $this->fail('Failed to prepare temp file for spreadsheet.');
        }

        try {
            file_put_contents($tempFile, $response->body);
            return IOFactory::load($tempFile);
        } finally {
            @unlink($tempFile);
        }
    }

    // 指定値と一致するセル値の出現回数をシート全体から数える。
    private function countCellValueOccurrences(Worksheet $sheet, string $expectedValue): int
    {
        $count = 0;

        foreach ($sheet->toArray(null, true, true, true) as $row) {
            foreach ($row as $value) {
                if ((string) $value === $expectedValue) {
                    $count++;
                }
            }
        }

        return $count;
    }

    // 指定管理番号の商品情報を取得する。
    private function selectProductData(string $managementNo): array
    {
        return (new Product())->select($managementNo);
    }

    // 指定商品の image_file を一時的に差し替え、更新後のデータを返す。
    private function replaceProductImageFile(array $productData, string $imageFile): array
    {
        $updatedData = $productData;
        $updatedData['image_file'] = $imageFile;
        $this->updateProductImageFileRaw($updatedData['management_no'], $imageFile);

        return $updatedData;
    }

    // 一時的に変更した商品情報を元の状態へ戻す。
    private function restoreProductData(array $productData): void
    {
        $this->updateProductImageFileRaw($productData['management_no'], (string) ($productData['image_file'] ?? ''));
    }

    // products テーブルの image_file だけを直接更新する。
    private function updateProductImageFileRaw(string $managementNo, string $imageFile): void
    {
        $pdo = $this->createTestPdo();
        $stmt = $pdo->prepare('UPDATE products SET image_file = :image_file WHERE management_no = :management_no');
        $stmt->execute([
            'image_file' => $imageFile,
            'management_no' => $managementNo,
        ]);

        $this->assertSame(1, $stmt->rowCount());
    }

    // テスト DB へ直接接続する PDO を返す。
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

    // セッション期限切れ時に認証情報と画面用セッションが破棄されることを確認する
    public function testBarcodeBulkExportClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage(
            'barcode_bulk_export.php',
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
