<?php

declare(strict_types=1);

use Noblestock\Logic\MessageConst;
use Tests\Support\ExcelSettingsHelper;
use Tests\Support\Response;
use Tests\Support\WebTestCase;

final class ListExportSplitPageTest extends WebTestCase
{
    use ExcelSettingsHelper;

    // ログインセッションがない状態でアクセスすると index.php にリダイレクトされる
    public function testListExportSplitRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('list_export_split.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // ログイン切れではセッション期限切れメッセージ付きで index.php に戻される
    public function testListExportSplitRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('list_export_split.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    // GET 直アクセス時は既定値で 1 行分の分割出力画面が表示される
    public function testListExportSplitReturns400WithoutFn(): void
    {
        $this->loginAsAdmin();

        $response = $this->getClient()->get('list_export_split.php');

        $this->assertSame(400, $response->status, "Expected 400 from {$response->url}");
        $this->assertStringContainsString(MessageConst::MSG_VAL_FILE_018, $response->body);
    }

    public function testListExportSplitReturns400ForInvalidFn(): void
    {
        $this->loginAsAdmin();

        $response = $this->getClient()->get('list_export_split.php?fn=invalid_page');

        $this->assertSame(400, $response->status, "Expected 400 from {$response->url}");
        $this->assertStringContainsString(MessageConst::MSG_VAL_FILE_018, $response->body);
    }

    // GET された fn とセッション上の listCnt に応じて export 先と分割範囲が切り替わる
    public function testListExportSplitUsesGetFilenameAndSessionListCount(): void
    {
        $this->loginAsAdmin();
        $originalSettings = $this->snapshotExcelSettings();

        try {
            $this->updateExcelSettings([
                'EXCEL_VAR' => 0,
            ]);
            $this->seedListCntSession('biz302', 4001, 'sales_show.php');

            $response = $this->getClient()->get('list_export_split.php?fn=sales_show');

            $this->assertOk($response);
            $this->assertSplitRow($response, 1, 'sales_show_' . date('Ymd') . '-1.xls', 1, 2000, 'sales_show_export.php', '1');
            $this->assertSplitRow($response, 2, 'sales_show_' . date('Ymd') . '-2.xls', 2001, 4000, 'sales_show_export.php', '2');
            $this->assertSplitRow($response, 3, 'sales_show_' . date('Ymd') . '-3.xls', 4001, 4001, 'sales_show_export.php', '3');
            $this->assertBackLink($response, 'sales_show.php');
        } finally {
            $this->restoreExcelSettings($originalSettings);
        }
    }

    // EXCEL_VAR=1 のときは分割ファイル名の拡張子が xlsx になる
    public function testListExportSplitUsesXlsxExtensionWhenExcelVarIsOne(): void
    {
        $this->loginAsAdmin();
        $originalSettings = $this->snapshotExcelSettings();

        try {
            $this->updateExcelSettings([
                'EXCEL_VAR' => 1,
            ]);
            $this->seedListCntSession('biz601', 2001, 'product_check.php');

            $response = $this->getClient()->get('list_export_split.php?fn=product_check');

            $this->assertOk($response);
            $this->assertSplitRow($response, 1, 'product_check_' . date('Ymd') . '-1.xlsx', 1, 2000, 'product_check_export.php', '1');
            $this->assertSplitRow($response, 2, 'product_check_' . date('Ymd') . '-2.xlsx', 2001, 2001, 'product_check_export.php', '2');
            $this->assertBackLink($response, 'product_check.php');
        } finally {
            $this->restoreExcelSettings($originalSettings);
        }
    }

    // 表示権限ユーザー perm01 でも商品一覧からの分割画面を表示できる
    public function testListExportSplitDisplaysForPerm01(): void
    {
        $this->loginAsAdmin();
        $originalSettings = $this->snapshotExcelSettings();

        try {
            $this->updateExcelSettings([
                'EXCEL_VAR' => 0,
            ]);
            $this->loginAs('perm01', 'perm0100');
            $this->seedListCntSession('biz002', 120, 'product_list.php');

            $response = $this->getClient()->get('list_export_split.php?fn=product_list');

            $this->assertOk($response);
            $this->assertSplitRow($response, 1, 'product_list_' . date('Ymd') . '-1.xls', 1, 120, 'product_list_export.php', '1');
        } finally {
            $this->loginAsAdmin();
            $this->restoreExcelSettings($originalSettings);
        }
    }

    // 権限なしユーザー noauth では 403 を返すのが理想
    public function testListExportSplitReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->get('list_export_split.php?fn=product_list');

        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_003, $response->body);
    }

    // 指定行のファイル名と範囲と export 先が期待どおりか確認する
    private function seedListCntSession(string $funcId, int $listCnt, string $scriptName): void
    {
        $bridgeName = '__list_export_split_session_' . bin2hex(random_bytes(8)) . '.php';
        $bridgePath = dirname(__DIR__, 2) . '/public/' . $bridgeName;
        $normalizedScriptName = '/noblestock/public/' . ltrim($scriptName, '/');

        $bridgeCode = <<<PHP
<?php
session_cache_limiter("none");
session_start();
date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../vendor/autoload.php';

use Studiogau\Chandra\Support\SessionHelper;

\$_SERVER['SCRIPT_NAME'] = %s;
SessionHelper::setData(%s, 'listCnt', (int) (\$_POST['listCnt'] ?? 0));

header('Content-Type: application/json');
echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
PHP;

        file_put_contents(
            $bridgePath,
            sprintf($bridgeCode, var_export($normalizedScriptName, true), var_export($funcId, true))
        );

        try {
            $response = $this->getClient()->post($bridgeName, [
                'listCnt' => (string) $listCnt,
            ]);

            $this->assertSame(200, $response->status);
            $this->assertStringContainsString('"ok":true', $response->body);
        } finally {
            @unlink($bridgePath);
        }
    }

    private function assertSplitRow(
        Response $response,
        int $rowNo,
        string $expectedFileName,
        int $fromNo,
        int $toNo,
        string $expectedAction,
        string $expectedListPage
    ): void {
        $xpath = new DOMXPath($response->dom());
        $rowNodes = $xpath->query("//table[contains(@class,'table')]//tr[td]");
        $this->assertNotFalse($rowNodes);
        $this->assertGreaterThanOrEqual($rowNo, $rowNodes->length, "Expected row {$rowNo}.");

        $cellNodes = $xpath->query('./td', $rowNodes->item($rowNo - 1));
        $this->assertNotFalse($cellNodes);
        $this->assertSame(3, $cellNodes->length);

        $fileName = $this->normalizeText($cellNodes->item(0)?->textContent ?? '');
        $rangeText = $this->normalizeText($cellNodes->item(1)?->textContent ?? '');
        $formNodes = $xpath->query(".//form[@method='POST' and @action='{$expectedAction}']", $cellNodes->item(2));
        $this->assertNotFalse($formNodes);
        $this->assertSame(1, $formNodes->length, 'Expected one export form in the row.');

        $pageInputNodes = $xpath->query(".//input[@type='hidden' and @name='listPage' and @value='{$expectedListPage}']", $cellNodes->item(2));
        $this->assertNotFalse($pageInputNodes);
        $this->assertSame(1, $pageInputNodes->length, 'Expected hidden listPage input.');

        $buttonNodes = $xpath->query(".//button[@name='mode' and @value='export']", $cellNodes->item(2));
        $this->assertNotFalse($buttonNodes);
        $this->assertSame(1, $buttonNodes->length, 'Expected export button.');

        $this->assertSame($expectedFileName, $fileName);
        $this->assertMatchesRegularExpression('/' . $fromNo . '\D+' . $toNo . '/', $rangeText);
    }

    // 戻るリンクの遷移先が想定どおりか確認する
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
    public function testListExportSplitClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('list_export_split.php');
    }
}
