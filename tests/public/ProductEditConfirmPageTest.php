<?php

declare(strict_types=1);

use Noblestock\DbLogic\Product;
use Noblestock\Logic\MessageConst;
use Tests\Support\Response;
use Tests\Support\WebTestCase;

final class ProductEditConfirmPageTest extends WebTestCase
{
    // ログインセッションを失った状態では、この画面にアクセスすると index に戻る
    public function testProductEditConfirmRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('product_edit_confirm.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // ログインセッションを失った状態では、案内メッセージ付きで index に戻る
    public function testProductEditConfirmRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('product_edit_confirm.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    // confirm POST 後は商品更新確認画面が表示される
    public function testProductEditConfirmDisplays(): void
    {
        $result = $this->openProductEditConfirm();
        $this->assertOk($result['response']);
    }

    // セッションに保存済みの商品データを GET 表示で確認できる
    public function testProductEditConfirmDisplaysSessionProductDataAsGet(): void
    {
        $result = $this->openProductEditConfirm([
            'management_no' => 'ABC002',
            'category_id' => 30,
            'maker_id' => 40,
            'product_name' => 'Edited Confirm Product',
            'wholesale_amount' => 4321,
            'retail_amount' => 5432,
            'sell_amount' => 6543,
            'quantity' => 300,
            'unit_id' => 10,
            'storage_place' => 'Edited Shelf A-02',
            'image_file' => '',
            'remarks' => "edit remark 1\nedit remark 2",
            'remarks2' => "edit note 1\nedit note 2",
        ]);
        $productData = $result['productData'];

        $response = $this->getClient()->get('product_edit_confirm.php');
        $response->body = str_replace("\r\n", "\n", $response->body);

        $this->assertOk($response);
        $this->assertStringContainsString($productData['management_no'], $response->body);
        $this->assertStringContainsString('カテゴリ30', $response->body);
        $this->assertStringContainsString('メーカー40', $response->body);
        $this->assertStringContainsString($productData['product_name'], $response->body);
        $this->assertStringContainsString(number_format((int) $productData['wholesale_amount']), $response->body);
        $this->assertStringContainsString(number_format((int) $productData['retail_amount']), $response->body);
        $this->assertStringContainsString(number_format((int) $productData['sell_amount']), $response->body);
        $this->assertStringContainsString(number_format((int) $productData['quantity']) . '個', $response->body);
        $this->assertStringContainsString($productData['storage_place'], $response->body);
        $this->assertStringContainsString('edit remark 1<br />' . "\n" . 'edit remark 2', $response->body);
        $this->assertStringContainsString('edit note 1<br />' . "\n" . 'edit note 2', $response->body);
    }

    // mode=update で商品情報が更新され、product_list.php に戻る
    public function testProductEditConfirmUpdatesProductAndRedirectsToProductList(): void
    {
        $original = $this->selectProductData('ABC002');

        $result = $this->openProductEditConfirm([
            'management_no' => 'ABC002',
            'category_id' => 30,
            'maker_id' => 40,
            'product_name' => 'Updated Product Test',
            'wholesale_amount' => 7654,
            'retail_amount' => 8765,
            'sell_amount' => 9876,
            'quantity' => 300,
            'unit_id' => 20,
            'storage_place' => 'Updated Shelf B-02',
            'image_file' => '',
            'remarks' => 'updated remarks',
            'remarks2' => 'updated remarks2',
        ]);
        $updatedData = $result['productData'];
        $confirmResponse = $result['response'];

        try {
            $response = $this->getClient()->post('product_edit_confirm.php', [
                'mode' => 'update',
            ] + $this->extractCsrfPostData($confirmResponse));

            $this->assertOk($response);
            $this->assertStringContainsString('Location: product_list.php', $response->headers);

            $product = $this->selectProductData('ABC002');
            $this->assertSame($updatedData['management_no'], $product['management_no']);
            $this->assertSame($updatedData['category_id'], $product['category_id']);
            $this->assertSame($updatedData['maker_id'], $product['maker_id']);
            $this->assertSame($updatedData['product_name'], $product['product_name']);
            $this->assertSame((int) $updatedData['wholesale_amount'], $product['wholesale_amount']);
            $this->assertSame((int) $updatedData['retail_amount'], $product['retail_amount']);
            $this->assertSame((int) $updatedData['sell_amount'], $product['sell_amount']);
            $this->assertSame($updatedData['unit_id'], $product['unit_id']);
            $this->assertSame($updatedData['storage_place'], $product['storage_place']);
            $this->assertSame($updatedData['remarks'], $product['remarks']);
            $this->assertSame($updatedData['remarks2'], $product['remarks2']);
        } finally {
            $this->restoreProductData($original);
        }
    }

    // 権限 perm01 のユーザーでも商品更新確認画面を表示できる
    public function testProductEditConfirmDisplaysForPerm01(): void
    {
        $this->loginAs('perm01', 'perm0100');
        $productListResponse = $this->getClient()->get('product_list.php');
        $this->assertOk($productListResponse);

        $productEditResponse = $this->getClient()->post('product_edit.php', [
            'mngNo' => 'ABC002',
            'filename' => 'product_list',
        ] + $this->extractCsrfPostData($productListResponse));
        $this->assertOk($productEditResponse);

        $response = $this->getClient()->post('product_edit_confirm.php', array_merge(
            ['mode' => 'confirm'],
            $this->buildProductEditConfirmData()
        ) + $this->extractCsrfPostData($productEditResponse));
        $this->assertOk($response);
    }

    // wholesale_amount が数値以外の場合は、エラーで product_edit.php に戻る
    public function testProductEditConfirmRedirectsToProductEditWhenWholesaleAmountIsNotNumeric(): void
    {
        $this->assertProductEditConfirmValidationError(
            ['wholesale_amount' => 'invalid'],
            'wholesale_amount',
            MessageConst::MSG_VAL_PRODUCT_004
        );
    }

    // retail_amount が数値以外の場合は、エラーで product_edit.php に戻る
    public function testProductEditConfirmRedirectsToProductEditWhenRetailAmountIsNotNumeric(): void
    {
        $this->assertProductEditConfirmValidationError(
            ['retail_amount' => 'invalid'],
            'retail_amount',
            MessageConst::MSG_VAL_PRODUCT_004
        );
    }

    // sell_amount が数値以外の場合は、エラーで product_edit.php に戻る
    public function testProductEditConfirmRedirectsToProductEditWhenSellAmountIsNotNumeric(): void
    {
        $this->assertProductEditConfirmValidationError(
            ['sell_amount' => 'invalid'],
            'sell_amount',
            MessageConst::MSG_VAL_PRODUCT_004
        );
    }

    // category_id が不正な場合は product_edit.php に戻してエラーを表示する
    public function testProductEditConfirmRedirectsToProductEditWhenCategoryIdIsInvalid(): void
    {
        $this->assertProductEditConfirmValidationError(
            ['category_id' => '999999'],
            'category_id',
            MessageConst::MSG_VAL_PRODUCT_003
        );
    }

    // maker_id が不正な場合は product_edit.php に戻してエラーを表示する
    public function testProductEditConfirmRedirectsToProductEditWhenMakerIdIsInvalid(): void
    {
        $this->assertProductEditConfirmValidationError(
            ['maker_id' => '999999'],
            'maker_id',
            MessageConst::MSG_VAL_PRODUCT_003
        );
    }

    // unit_id が不正な場合は product_edit.php に戻してエラーを表示する
    public function testProductEditConfirmRedirectsToProductEditWhenUnitIdIsInvalid(): void
    {
        $this->assertProductEditConfirmValidationError(
            ['unit_id' => '999999'],
            'unit_id',
            MessageConst::MSG_VAL_PRODUCT_003
        );
    }

    // 必須情報なしの POST は 400 エラー
    public function testNonDataPostAccessReturns400(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->post('product_edit_confirm.php', [
            'mode' => 'invalid',
        ]);
        $this->assertSame(400, $response->status);
    }

    // 必須情報なしの GET は 400 エラー
    public function testNonDataGetAccessReturns400(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->get('product_edit_confirm.php');
        $this->assertSame(400, $response->status);
    }

    // 権限のないユーザーでは 403 エラーになる
    public function testProductEditConfirmReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->post('product_edit_confirm.php', [
            'mode' => 'confirm',
        ]);
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }

    // 商品更新画面を経由して backTo をセットし、確認画面用の productData をセッションに保存する
    private function openProductEditConfirm(array $overrides = []): array
    {
        $this->loginAsAdmin();

        $productListResponse = $this->getClient()->get('product_list.php');
        $this->assertOk($productListResponse);

        $productEditResponse = $this->getClient()->post('product_edit.php', [
            'mngNo' => 'ABC002',
            'filename' => 'product_list',
        ] + $this->extractCsrfPostData($productListResponse));
        $this->assertOk($productEditResponse);

        $productData = $this->buildProductEditConfirmData($overrides);
        $confirmResponse = $this->getClient()->post('product_edit_confirm.php', array_merge(
            ['mode' => 'confirm'],
            $productData
        ) + $this->extractCsrfPostData($productEditResponse));

        $this->assertOk($confirmResponse);

        return [
            'productData' => $productData,
            'response' => $confirmResponse,
        ];
    }

    // ProductEditConfirm の confirm POST を送り、バリデーションエラーで product_edit.php に戻ることを確認する
    private function assertProductEditConfirmValidationError(
        array $productDataOverrides,
        string $fieldId,
        string $expectedMessage
    ): void {
        $this->loginAsAdmin();

        $productListResponse = $this->getClient()->get('product_list.php');
        $this->assertOk($productListResponse);

        $productEditResponse = $this->getClient()->post('product_edit.php', [
            'mngNo' => 'ABC002',
            'filename' => 'product_list',
        ] + $this->extractCsrfPostData($productListResponse));
        $this->assertOk($productEditResponse);

        $postData = array_merge(
            ['mode' => 'confirm'],
            $this->buildProductEditConfirmData($productDataOverrides)
        );

        $response = $this->getClient()->post(
            'product_edit_confirm.php',
            $postData + $this->extractCsrfPostData($productEditResponse)
        );

        $this->assertOk($response);
        $this->assertStringContainsString('Location: product_edit.php', $response->headers);
        $this->assertStringContainsString($expectedMessage, $response->body);
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//*[@id='{$fieldId}' and contains(@class, 'is-invalid')]");
        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length);
        $storageNodes = $xpath->query("//*[@id='storage_place' and @value='" . $postData['storage_place'] . "']");
        $this->assertNotFalse($storageNodes);
        $this->assertSame(1, $storageNodes->length);

        $imageNodes = $xpath->query("//*[@id='image_file' and @value='" . $postData['image_file'] . "']");
        $this->assertNotFalse($imageNodes);
        $this->assertSame(1, $imageNodes->length);
    }

    // ProductEditConfirm で使う POST データの既定値を返す
    private function buildProductEditConfirmData(array $overrides = []): array
    {
        return array_merge([
            'management_no' => 'ABC002',
            'category_id' => 10,
            'maker_id' => 20,
            'product_name' => '商品サンプルABC002',
            'wholesale_amount' => 1,
            'retail_amount' => 1,
            'sell_amount' => 1,
            'unit_id' => 10,
            'image_file' => '',
            'storage_place' => '保管場所02',
            'remarks' => '',
            'remarks2' => '',
            'quantity' => 300,
        ], $overrides);
    }

    // products テーブルから管理番号の商品の情報を取得する
    private function selectProductData(string $managementNo): array
    {
        return (new Product())->select($managementNo);
    }

    // 元の商品情報へ戻す
    private function restoreProductData(array $productData): void
    {
        $this->loginAsAdmin();
        $result = (new Product())->update($productData);
        $this->assertSame(1, $result);
    }
    // セッション期限切れ時に認証情報と画面用セッションが破棄されることを確認する
    public function testProductEditConfirmClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('product_edit_confirm.php');
    }
}
