<?php

declare(strict_types=1);

use Noblestock\Logic\LogicConst;
use Noblestock\Logic\MessageConst;
use Tests\Support\WebTestCase;

final class ProductShowPageTest extends WebTestCase
{
    // ログインセッションをクリア後、この画面にアクセスすると、indexに遷移する
    public function testProductShowRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('product_show.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // ログインセッションをクリア後、この画面にアクセスすると、認証エラーメッセージ付きでindexに遷移する
    public function testProductShowRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('product_show.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    public function testProductShowDisplays(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->get('product_show.php');
        $this->assertOk($response);
    }

    // POST値barcode=ABC001を送信し、product_infoのth/tdペアとして商品情報が表示されることを確認する
    public function testProductShowDisplaysProductDataWhenBarcodePosted(): void
    {
        $this->loginAsAdmin();

        $response = $this->getClient()->post('product_show.php', [
            'barcode' => 'ABC001',
        ]);

        $this->assertOk($response);
        $this->assertProductInfoFieldValue($response->body, '管理番号', 'ABC001');
        $this->assertProductInfoFieldValue($response->body, '商品名', '商品サンプルABC001');
        $this->assertProductInfoFieldValue($response->body, '卸価格', '1');
        $this->assertProductInfoFieldValue($response->body, '小売価格', '1');
        $this->assertProductInfoFieldValue($response->body, '仕入原価', '1');
        $this->assertProductInfoFieldValue($response->body, '在庫数', '70');
        $this->assertProductInfoFieldValue($response->body, '保管場所', '保管場所01');
    }

    // POST値barcode=ABC000を送信し、「対象商品が存在しません」エラーメッセージが表示されることを確認する
    public function testProductShowDisplaysErrorWhenUnknownBarcodePosted(): void
    {
        $this->loginAsAdmin();

        $response = $this->getClient()->post('product_show.php', [
            'barcode' => 'ABC000',
        ]);

        $this->assertOk($response);
        $this->assertStringContainsString(MessageConst::MSG_VAL_BARCODE_002, $response->body);
    }

    // POST値barcode=LogicConst::CMD_MENUを送信すると、menu.php（メニュー）画面にリダイレクトする
    public function testProductShowRedirectsToMenuWhenCmdMenuPosted(): void
    {
        $this->assertPostRedirect(
            'product_show.php',
            ['barcode' => LogicConst::CMD_MENU],
            'menu.php',
            true
        );
    }

    // POST値barcode=LogicConst::CMD_VIEWを送信すると、product_show.php（商品表示）画面にリダイレクトする
    public function testProductShowRedirectsToProductShowWhenCmdViewPosted(): void
    {
        $this->assertPostRedirect(
            'product_show.php',
            ['barcode' => LogicConst::CMD_VIEW],
            'product_show.php',
            true
        );
    }

    // POST値barcode=LogicConst::CMD_STOCKを送信すると、stock_in.php（入庫）画面にリダイレクトする
    public function testProductShowRedirectsToStockInWhenCmdStockPosted(): void
    {
        $this->assertPostRedirect(
            'product_show.php',
            ['barcode' => LogicConst::CMD_STOCK],
            'stock_in.php',
            true
        );
    }

    // POST値barcode=LogicConst::CMD_SHIPPINGを送信すると、stock_out.php（出庫）画面にリダイレクトする
    public function testProductShowRedirectsToStockOutWhenCmdShippingPosted(): void
    {
        $this->assertPostRedirect(
            'product_show.php',
            ['barcode' => LogicConst::CMD_SHIPPING],
            'stock_out.php',
            true
        );
    }

    // POST値barcode=LogicConst::CMD_LOCCHGを送信すると、stock_move.php（移動）画面にリダイレクトする
    public function testProductShowRedirectsToStockMoveWhenCmdLocchgPosted(): void
    {
        $this->assertPostRedirect(
            'product_show.php',
            ['barcode' => LogicConst::CMD_LOCCHG],
            'stock_move.php',
            true
        );
    }

    // POST値barcode=LogicConst::CMD_CREATEを送信すると、barcode_create.php（バーコード生成）画面にリダイレクトする
    public function testProductShowRedirectsToBarcodeCreateWhenCmdCreatePosted(): void
    {
        $this->assertPostRedirect(
            'product_show.php',
            ['barcode' => LogicConst::CMD_CREATE],
            'barcode_create.php',
            true
        );
    }

    public function testProductShowDisplaysForPerm00(): void
    {
        $this->loginAs('perm00', 'perm0000');

        $response = $this->getClient()->get('product_show.php');
        $this->assertOk($response);
    }

    public function testProductShowReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->get('product_show.php');
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }

    private function assertProductInfoFieldValue(string $html, string $label, string $expectedValue): void
    {
        $labelEscaped = preg_quote($label, '/');
        $valueEscaped = preg_quote($expectedValue, '/');
        $pattern = "/<th[^>]*>\\s*{$labelEscaped}\\s*<\\/th>\\s*<td>\\s*{$valueEscaped}\\s*<\\/td>/u";

        $this->assertMatchesRegularExpression($pattern, $html);
    }
    // セッション期限切れ時に認証情報と画面用セッションが破棄されることを確認する
    public function testProductShowClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('product_show.php');
    }
}
