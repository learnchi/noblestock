<?php

declare(strict_types=1);

use Noblestock\Logic\MessageConst;
use Studiogau\Chandra\Support\SessionHelper;
use Tests\Support\WebTestCase;
use Noblestock\Logic\LogicConst;

final class MenuPageTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 各テスト開始時にログアウト画面を踏んでログイン状態を初期化する。
        $this->getClient()->get('index.php');
    }

    /**
     * adminでログインしてmenuページに200で遷移することを確認する。
     */
    public function testMenuDisplaysAfterLogin(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->get('menu.php');
        $this->assertOk($response);
    }

    /**
     * 存在しないIDをPOST送信すると認証エラーメッセージが表示され、index.phpへリダイレクトされることを確認する。
     */
    public function testPostWithUnknownUserRedirectsToIndexWithAuthError(): void
    {
        $response = $this->getClient()->post('menu.php', [
            'user' => 'unknown_user_for_test',
            'pass' => 'dummy-pass',
        ]);

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_VAL_AUTH_001, $response->body);
    }

    /**
     * adminで誤ったパスワードをPOST送信すると認証エラーメッセージが表示され、index.phpへリダイレクトされることを確認する。
     */
    public function testPostWithWrongAdminPasswordRedirectsToIndexWithAuthError(): void
    {
        $response = $this->getClient()->post('menu.php', [
            'user' => 'admin',
            'pass' => 'wrong-password',
        ]);

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_VAL_AUTH_001, $response->body);
    }

    /**
     * menu.phpが200で表示されたときにSessionHelper::getMasterList("makerList")で内容のあるリストを取得できることを確認する。
     */
    public function testMakerMasterListIsAvailableAfterMenuDisplayed(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->get('menu.php');
        $this->assertOk($response);

        $makerList = $this->readMasterListFromCurrentSession('makerList');
        $this->assertIsArray($makerList);
        $this->assertNotEmpty($makerList);
    }

    /**
     * menu.phpが200で表示されたときにSessionHelper::getMasterList("categoryList")で内容のあるリストを取得できることを確認する。
     */
    public function testCategoryMasterListIsAvailableAfterMenuDisplayed(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->get('menu.php');
        $this->assertOk($response);

        $categoryList = $this->readMasterListFromCurrentSession('categoryList');
        $this->assertIsArray($categoryList);
        $this->assertNotEmpty($categoryList);
    }

    /**
     * menu.phpが200で表示されたときにSessionHelper::getMasterList("locationList")で内容のあるリストを取得できることを確認する。
     */
    public function testLocationMasterListIsAvailableAfterMenuDisplayed(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->get('menu.php');
        $this->assertOk($response);

        $locationList = $this->readMasterListFromCurrentSession('locationList');
        $this->assertIsArray($locationList);
        $this->assertNotEmpty($locationList);
    }







    // POST値barcode=ABC000を送信し、「指定されたメニューが見つかりません」エラーメッセージが表示されることを確認する
    public function testProductShowDisplaysErrorWhenUnknownBarcodePosted(): void
    {
        $this->loginAsAdmin();

        $response = $this->getClient()->post('menu.php', [
            'barcode' => 'ABC000',
        ]);

        $this->assertOk($response);
        $this->assertStringContainsString(MessageConst::MSG_SYS_MENU_002, $response->body);
    }

    // POST値barcode=LogicConst::CMD_MENUを送信すると、menu.php（メニュー）画面にリダイレクトする
    public function testProductShowRedirectsToMenuWhenCmdMenuPosted(): void
    {
        $this->assertPostRedirect(
            'menu.php',
            ['barcode' => LogicConst::CMD_MENU],
            'menu.php',
            true
        );
    }

    // POST値barcode=LogicConst::CMD_VIEWを送信すると、product_show.php（商品表示）画面にリダイレクトする
    public function testProductShowRedirectsToProductShowWhenCmdViewPosted(): void
    {
        $this->assertPostRedirect(
            'menu.php',
            ['barcode' => LogicConst::CMD_VIEW],
            'product_show.php',
            true
        );
    }

    // POST値barcode=LogicConst::CMD_STOCKを送信すると、stock_in.php（入庫）画面にリダイレクトする
    public function testProductShowRedirectsToStockInWhenCmdStockPosted(): void
    {
        $this->assertPostRedirect(
            'menu.php',
            ['barcode' => LogicConst::CMD_STOCK],
            'stock_in.php',
            true
        );
    }

    // POST値barcode=LogicConst::CMD_SHIPPINGを送信すると、stock_out.php（出庫）画面にリダイレクトする
    public function testProductShowRedirectsToStockOutWhenCmdShippingPosted(): void
    {
        $this->assertPostRedirect(
            'menu.php',
            ['barcode' => LogicConst::CMD_SHIPPING],
            'stock_out.php',
            true
        );
    }

    // POST値barcode=LogicConst::CMD_LOCCHGを送信すると、stock_move.php（移動）画面にリダイレクトする
    public function testProductShowRedirectsToStockMoveWhenCmdLocchgPosted(): void
    {
        $this->assertPostRedirect(
            'menu.php',
            ['barcode' => LogicConst::CMD_LOCCHG],
            'stock_move.php',
            true
        );
    }

    // POST値barcode=LogicConst::CMD_CREATEを送信すると、barcode_create.php（バーコード生成）画面にリダイレクトする
    public function testProductShowRedirectsToBarcodeCreateWhenCmdCreatePosted(): void
    {
        $this->assertPostRedirect(
            'menu.php',
            ['barcode' => LogicConst::CMD_CREATE],
            'barcode_create.php',
            true
        );
    }







    private function readMasterListFromCurrentSession(string $key): mixed
    {
        $bridgeName = '__menu_master_read_' . bin2hex(random_bytes(8)) . '.php';
        $bridgePath = dirname(__DIR__, 2) . '/public/' . $bridgeName;
        $bridgeCode = <<<'PHP'
<?php
session_cache_limiter("none");
@session_start();
date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../vendor/autoload.php';

use Studiogau\Chandra\Support\SessionHelper;

$_SERVER['SCRIPT_NAME'] = '/noblestock/public/menu.php';

header('Content-Type: application/json');
echo json_encode([
    'value' => SessionHelper::getMasterList((string) ($_GET['key'] ?? '')),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
PHP;

        file_put_contents($bridgePath, $bridgeCode);

        try {
            $response = $this->getClient()->get($bridgeName . '?key=' . rawurlencode($key));
            $this->assertOk($response);

            $decoded = json_decode($response->body, true);
            $this->assertIsArray($decoded);

            return $decoded['value'] ?? null;
        } finally {
            @unlink($bridgePath);
        }
    }
    // セッション期限切れ時に認証情報と画面用セッションが破棄されることを確認する
    public function testSuccessfulLoginRegeneratesSessionId(): void
    {
        $this->assertSuccessfulLoginRegeneratesSessionId();
    }

    // セッション期限切れ時に認証情報と画面用セッションが破棄されることを確認する
    public function testMenuClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('menu.php');
    }
}
