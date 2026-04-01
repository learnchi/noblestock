<?php

declare(strict_types=1);

use Noblestock\Logic\MessageConst;
use Tests\Support\BarcodeOutputSelectionHelper;
use Tests\Support\ExcelSettingsHelper;
use Tests\Support\Response;
use Tests\Support\WebTestCase;

final class BarcodeListExportSplitPageTest extends WebTestCase
{
    use BarcodeOutputSelectionHelper;
    use ExcelSettingsHelper;

    // ログインセッションがない状態でアクセスすると index.php にリダイレクトされる
    public function testBarcodeListExportSplitRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('barcode_list_export_split.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // ログイン切れ時はメッセージ付きで index.php に戻される
    public function testBarcodeListExportSplitRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('barcode_list_export_split.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    // product_barcode_list.php の regist フロー経由で作られた分割情報を画面に表示する
    public function testBarcodeListExportSplitDisplaysRowsCreatedByRegistFlow(): void
    {
        $this->loginAsAdmin();
        $originalSettings = $this->snapshotExcelSettings();

        try {
            $this->updateExcelSettings([
                'EXCEL_VAR' => 0,
            ]);

            $response = $this->prepareSplitPageByRegistFlow(1001);

            $this->assertOk($response);
            $this->assertSplitRow($response, 1, 'product_barcode_' . date('Ymd') . '-1.xls', 1, 1000, '1');
            $this->assertSplitRow($response, 2, 'product_barcode_' . date('Ymd') . '-2.xls', 1001, 1001, '2');
            $this->assertBackLink($response, 'product_barcode_list.php');
        } finally {
            $this->restoreExcelSettings($originalSettings);
        }
    }

    // 表示権限ユーザー perm07 でも分割画面を表示できる
    public function testBarcodeListExportSplitDisplaysForPerm07(): void
    {
        $this->loginAs('perm07', 'perm0700');
        $this->setBarcodeSplitSessionDataViaBridge(
            'product',
            'product_barcode_list',
            [
                ['bar_no' => 'ABC001'],
                ['bar_no' => 'ABC002'],
            ],
            [
                'EXCEL_VAR' => 0,
            ]
        );

        $response = $this->getClient()->get('barcode_list_export_split.php');
        $this->assertOk($response);
        $this->assertSplitRow($response, 1, 'product_barcode_' . date('Ymd') . '-1.xls', 1, 2, '1');
    }

    // EXCEL_VAR=1 のときは分割ファイル名の拡張子が xlsx になる
    public function testBarcodeListExportSplitUsesXlsxExtensionWhenExcelVarIsOne(): void
    {
        $this->loginAsAdmin();
        $originalSettings = $this->snapshotExcelSettings();

        try {
            $this->updateExcelSettings([
                'EXCEL_VAR' => 1,
            ]);
            $this->setBarcodeSplitSessionDataViaBridge(
                'product',
                'product_barcode_list',
                array_fill(0, 1001, ['bar_no' => 'ABC001'])
            );

            $response = $this->getClient()->get('barcode_list_export_split.php');
            $this->assertOk($response);
            $this->assertSplitRow($response, 1, 'product_barcode_' . date('Ymd') . '-1.xlsx', 1, 1000, '1');
            $this->assertSplitRow($response, 2, 'product_barcode_' . date('Ymd') . '-2.xlsx', 1001, 1001, '2');
        } finally {
            $this->restoreExcelSettings($originalSettings);
        }
    }

    // 各行の出力ボタンは barcode_bulk_export.php へ export POST する
    public function testBarcodeListExportSplitPostsToBarcodeBulkExportWithExportMode(): void
    {
        $this->loginAsAdmin();
        $this->setBarcodeSplitSessionDataViaBridge(
            'product',
            'product_barcode_list',
            array_fill(0, 1001, ['bar_no' => 'ABC001'])
        );

        $response = $this->getClient()->get('barcode_list_export_split.php');
        $this->assertOk($response);

        $xpath = new DOMXPath($response->dom());
        $formNodes = $xpath->query("//form[@method='POST' and @action='barcode_bulk_export.php']");
        $this->assertNotFalse($formNodes);
        $this->assertSame(1, $formNodes->length, 'Expected one export form inside the table.');

        $hiddenModeNodes = $xpath->query("//form[@action='barcode_bulk_export.php']//input[@type='hidden' and @name='mode' and @value='export']");
        $this->assertNotFalse($hiddenModeNodes);
        $this->assertSame(1, $hiddenModeNodes->length, 'Expected hidden export mode input.');

        $buttonNodes = $xpath->query("//form[@action='barcode_bulk_export.php']//button[@name='listPage']");
        $this->assertNotFalse($buttonNodes);
        $this->assertSame(2, $buttonNodes->length, 'Expected two listPage buttons.');
    }

    // 権限なしユーザー noauth では 403 にしたい
    public function testBarcodeListExportSplitReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->get('barcode_list_export_split.php');
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }

    // セッションがなくても画面自体は 200 で表示される
    // 分割画面用のセッションが無いまま開いた場合は 400 を返す
    public function testGetAccessWithoutDataReturns400(): void
    {
        $this->loginAsAdmin();

        $response = $this->getClient()->get('barcode_list_export_split.php');
        $this->assertSame(400, $response->status);
        $this->assertStringContainsString(MessageConst::MSG_VAL_FILE_018, $response->body);
    }

    // 許可されていない戻り先画面がセッションに入っている場合は 400 を返す
    public function testBarcodeListExportSplitReturns400ForInvalidBackTo(): void
    {
        $this->loginAsAdmin();
        $this->setBarcodeSplitSessionDataViaBridge(
            'product',
            'evil',
            [
                ['bar_no' => 'ABC001'],
            ]
        );

        $response = $this->getClient()->get('barcode_list_export_split.php');
        $this->assertSame(400, $response->status);
        $this->assertStringContainsString(MessageConst::MSG_VAL_FILE_018, $response->body);
    }

    // regist フローを通して split 画面用の com901 セッションを作る
    private function prepareSplitPageByRegistFlow(int $outNum): Response
    {
        $this->seedSelectedMngNos(['ABC002']);

        $displayResponse = $this->getClient()->get('product_barcode_list.php');
        $this->assertOk($displayResponse);

        $response = $this->getClient()->post('product_barcode_list.php', [
            'mode' => 'regist',
            'OUT_NUM_0' => (string) $outNum,
        ]);

        $this->assertOk($response);
        $this->assertStringContainsString('Location: barcode_list_export_split.php', $response->headers);

        return $response;
    }

    // HTTP ブリッジ経由で barcode_list_export_split 用のセッションを現在のログインセッションに投入する
    private function setBarcodeSplitSessionDataViaBridge(string $barFile, string $backTo, array $barList, array $prefs = []): void
    {
        $bridgeName = '__barcode_split_session_' . bin2hex(random_bytes(8)) . '.php';
        $bridgePath = dirname(__DIR__, 2) . '/public/' . $bridgeName;
        $bridgeCode = <<<'PHP'
<?php
session_cache_limiter("none");
@session_start();
date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../vendor/autoload.php';

use Studiogau\Chandra\Support\SessionHelper;

$payload = json_decode(file_get_contents('php://input'), true);
$_SERVER['SCRIPT_NAME'] = '/noblestock/public/barcode_list_export_split.php';

SessionHelper::delData('com901', null);
SessionHelper::setData('com901', 'barFile', $payload['barFile'] ?? 'product');
SessionHelper::setData('com901', 'backTo', $payload['backTo'] ?? 'product_barcode_list');
SessionHelper::setData('com901', 'barlist', $payload['barList'] ?? []);
foreach (($payload['prefs'] ?? []) as $key => $value) {
    SessionHelper::setPref((string) $key, $value);
}

header('Content-Type: application/json');
echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
PHP;

        file_put_contents($bridgePath, $bridgeCode);

        try {
            $response = $this->postJsonWithCurrentClient($bridgeName, [
                'barFile' => $barFile,
                'backTo' => $backTo,
                'barList' => $barList,
                'prefs' => $prefs,
            ]);

            $this->assertSame(200, $response->status);
            $this->assertStringContainsString('"ok":true', $response->body);
        } finally {
            @unlink($bridgePath);
        }
    }

    // 現在の Excel 出力設定を退避する
    // 指定行のファイル名・範囲・ボタン値が期待どおりか確認する
    private function assertSplitRow(Response $response, int $rowNo, string $expectedFileName, int $fromNo, int $toNo, string $buttonValue): void
    {
        $xpath = new DOMXPath($response->dom());
        $rowNodes = $xpath->query("//table[contains(@class,'table')]//tr[td]");
        $this->assertNotFalse($rowNodes);
        $this->assertGreaterThanOrEqual($rowNo, $rowNodes->length, "Expected row {$rowNo}.");

        $cellNodes = $xpath->query('./td', $rowNodes->item($rowNo - 1));
        $this->assertNotFalse($cellNodes);
        $this->assertSame(3, $cellNodes->length);

        $fileName = $this->normalizeText($cellNodes->item(0)?->textContent ?? '');
        $rangeText = $this->normalizeText($cellNodes->item(1)?->textContent ?? '');
        $button = $xpath->query('.//button[@name="listPage"]', $cellNodes->item(2));
        $this->assertNotFalse($button);
        $this->assertSame(1, $button->length);

        $this->assertSame($expectedFileName, $fileName);
        $this->assertMatchesRegularExpression('/' . $fromNo . '\D+' . $toNo . '/', $rangeText);
        $this->assertSame($buttonValue, $button->item(0)?->attributes?->getNamedItem('value')?->nodeValue);
    }

    // 戻るリンクの遷移先を確認する
    private function assertBackLink(Response $response, string $expectedHref): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//a[@href='{$expectedHref}']");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Back link was not found.');
    }

    // 比較用に空白を正規化する
    private function normalizeText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    // セッション期限切れ時に認証情報と画面用セッションが破棄されることを確認する
    public function testBarcodeListExportSplitClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('barcode_list_export_split.php');
    }
}
