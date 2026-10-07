<?php

declare(strict_types=1);

use Noblestock\Logic\MessageConst;
use Tests\Support\BarcodeOutputSelectionHelper;
use Tests\Support\WebTestCase;

final class ProductBarcodeListPageTest extends WebTestCase
{
    use BarcodeOutputSelectionHelper;

    // ログインセッションがない状態でアクセスすると、index.php にリダイレクトされる
    public function testProductBarcodeListRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('product_barcode_list.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // ログインセッション切れでは案内メッセージ付きで index.php に戻される
    public function testProductBarcodeListRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('product_barcode_list.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    // selectedMngNos をセッションに積んだ状態なら 200 で表示される
    public function testProductBarcodeListDisplays(): void
    {
        $this->loginAsAdmin();
        $this->seedSelectedMngNos(['ABC005', 'ABC002']);

        $response = $this->getClient()->get('product_barcode_list.php');
        $this->assertOk($response);
    }

    // 表示権限ユーザー perm01 でも 200 で表示される
    public function testProductBarcodeListDisplaysForPerm01(): void
    {
        $this->loginAs('perm01', 'perm0100');
        $this->seedSelectedMngNos(['ABC005', 'ABC002']);

        $response = $this->getClient()->get('product_barcode_list.php');
        $this->assertOk($response);
    }

    // mode=show の POST では scrollPos を保存し、自画面へ 303 リダイレクトする
    public function testProductBarcodeListShowStoresScrollPositionAndRedirectsToSelf(): void
    {
        $this->loginAsAdmin();
        $this->seedSelectedMngNos(['ABC005', 'ABC002']);

        $response = $this->getClient()->post('product_barcode_list.php', [
            'mode' => 'show',
            'pos' => '4321',
        ]);

        $this->assertOk($response);
        $this->assertMatchesRegularExpression('/HTTP\/\d(?:\.\d)? 303/', $response->headers);
        $this->assertStringContainsString('Location: product_barcode_list.php', $response->headers);

        $productListResponse = $this->getClient()->get('product_list.php');
        $this->assertOk($productListResponse);
        $this->assertMatchesRegularExpression('/const\s+scrollPos\s*=\s*4321\s*;/', $productListResponse->body);
    }

    // 画面のトークンで1枚出力すると、内部呼び出し先のCSRF検証も通過してExcelを返す
    public function testProductBarcodeListRegistReturnsExcelAttachment(): void
    {
        $this->loginAsAdmin();
        $this->seedSelectedMngNos(['ABC002']);
        $displayResponse = $this->getClient()->get('product_barcode_list.php');
        $this->assertOk($displayResponse);

        $response = $this->getClient()->post('product_barcode_list.php', [
            'mode' => 'regist',
            'OUT_NUM_0' => '1',
        ] + $this->extractCsrfPostData($displayResponse));

        $this->assertInitialStatus($response, 200);
        $this->assertOk($response);
        $this->assertMatchesRegularExpression(
            '/Content-Disposition: attachment;filename="product_barcode_\d{8}\.xlsx?"/i',
            $response->headers
        );
        $this->assertNotEmpty($response->body);
    }

    // mode=regist の POST ではバーコード出力情報を作り、分割出力画面へ遷移する
    public function testProductBarcodeListRegistStoresBarcodeDataAndRedirectsToSplitPage(): void
    {
        $this->loginAsAdmin();
        $this->seedSelectedMngNos(['ABC002']);
        $displayResponse = $this->getClient()->get('product_barcode_list.php');
        $this->assertOk($displayResponse);

        $response = $this->getClient()->post('product_barcode_list.php', [
            'mode' => 'regist',
            'OUT_NUM_0' => '1001',
            'pos' => '987',
        ]);

        $this->assertOk($response);
        $this->assertStringContainsString('Location: barcode_list_export_split.php', $response->headers);
        $this->assertStringContainsString('name="listPage" value="1"', $response->body);
        $this->assertStringContainsString('name="listPage" value="2"', $response->body);
        $this->assertStringContainsString('href="product_barcode_list.php"', $response->body);
    }

    // セッションデータがない状態でアクセスすると 400 エラーになる
    public function testNonDataPostAccessReturns400(): void
    {
        $this->loginAsAdmin();
        $this->clearSelectedMngNos();

        $response = $this->getClient()->get('product_barcode_list.php');
        $this->assertSame(400, $response->status);
    }

    // 未準備セッションで GET アクセスすると 400 エラーになる
    public function testNonDataGetAccessReturns400(): void
    {
        $this->loginAsAdmin();

        $response = $this->getClient()->get('product_barcode_list.php');
        $this->assertSame(400, $response->status);
    }

    // 権限のないユーザー noauth では 403 エラーになる
    public function testProductBarcodeListReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->get('product_barcode_list.php');
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }
    // セッション期限切れ時に認証情報と画面用セッションが破棄されることを確認する
    public function testProductBarcodeListClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('product_barcode_list.php');
    }
}
