<?php

declare(strict_types=1);

use Noblestock\DbLogic\Product;
use Noblestock\Logic\LogicConst;
use Noblestock\Logic\MessageConst;
use Studiogau\Chandra\Support\Utility;
use Tests\Support\ImageWebTestCase;
use Tests\Support\Response;

final class ProductConfirmPageTest extends ImageWebTestCase
{
    // ログインセッションをクリア後、この画面にアクセスすると index に戻ることを確認する。
    public function testProductConfirmRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('product_confirm.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // ログインセッションをクリア後、この画面にアクセスするとセッション切れメッセージ付きで index に戻ることを確認する。
    public function testProductConfirmRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('product_confirm.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    // confirm POST を送ると商品確認画面が表示されることを確認する。
    public function testProductConfirmDisplays(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->post('product_confirm.php', [
            'mode' => 'confirm',
        ]);
        $this->assertOk($response);
    }

    // 権限 perm01 のユーザーでも商品確認画面が表示されることを確認する。
    public function testProductConfirmDisplaysForPerm01(): void
    {
        $this->loginAs('perm01', 'perm0100');
        $response = $this->getClient()->post('product_confirm.php', [
            'mode' => 'confirm',
        ]);
        $this->assertOk($response);
    }

    // management_no が空欄の場合、必須チェックエラーで product_create.php に戻ることを確認する。
    public function testProductConfirmRedirectsToProductCreateWhenManagementNoIsBlank(): void
    {
        $this->assertProductConfirmValidationError(
            ['management_no' => ''],
            'management_no',
            MessageConst::MSG_VAL_PRODUCT_003
        );
    }

    // product_name が空欄の場合、必須チェックエラーで product_create.php に戻ることを確認する。
    public function testProductConfirmRedirectsToProductCreateWhenProductNameIsBlank(): void
    {
        $this->assertProductConfirmValidationError(
            ['product_name' => ''],
            'product_name',
            MessageConst::MSG_VAL_PRODUCT_003
        );
    }

    // category_id が不正な場合は product_create.php に戻してエラーを表示する
    public function testProductConfirmRedirectsToProductCreateWhenCategoryIdIsInvalid(): void
    {
        $this->assertProductConfirmValidationError(
            ['category_id' => '999999'],
            'category_id',
            MessageConst::MSG_VAL_PRODUCT_003
        );
    }

    // maker_id が不正な場合は product_create.php に戻してエラーを表示する
    public function testProductConfirmRedirectsToProductCreateWhenMakerIdIsInvalid(): void
    {
        $this->assertProductConfirmValidationError(
            ['maker_id' => '999999'],
            'maker_id',
            MessageConst::MSG_VAL_PRODUCT_003
        );
    }

    // unit_id が不正な場合は product_create.php に戻してエラーを表示する
    public function testProductConfirmRedirectsToProductCreateWhenUnitIdIsInvalid(): void
    {
        $this->assertProductConfirmValidationError(
            ['unit_id' => '999999'],
            'unit_id',
            MessageConst::MSG_VAL_PRODUCT_003
        );
    }

    // wholesale_amount が数値以外の場合、0 がセットされて確認画面に表示されることを確認する。
    public function testProductConfirmRedirectsToProductCreateWhenWholesaleAmountIsNotNumeric(): void
    {
        $this->assertProductConfirmValidationError(
            ['wholesale_amount' => 'invalid'],
            'wholesale_amount',
            MessageConst::MSG_VAL_PRODUCT_004
        );
    }

    // retail_amount が数値以外の場合、0 がセットされて確認画面に表示されることを確認する。
    public function testProductConfirmRedirectsToProductCreateWhenRetailAmountIsNotNumeric(): void
    {
        $this->assertProductConfirmValidationError(
            ['retail_amount' => 'invalid'],
            'retail_amount',
            MessageConst::MSG_VAL_PRODUCT_004
        );
    }

    // sell_amount が数値以外の場合、0 がセットされて確認画面に表示されることを確認する。
    public function testProductConfirmRedirectsToProductCreateWhenSellAmountIsNotNumeric(): void
    {
        $this->assertProductConfirmValidationError(
            ['sell_amount' => 'invalid'],
            'sell_amount',
            MessageConst::MSG_VAL_PRODUCT_004
        );
    }

    // management_no が products テーブルに既に存在する場合、存在チェックエラーで product_create.php に戻ることを確認する。
    public function testProductConfirmRedirectsToProductCreateWhenManagementNoAlreadyExists(): void
    {
        $this->assertProductConfirmValidationError(
            ['management_no' => 'ABC001'],
            'management_no',
            MessageConst::MSG_VAL_PRODUCT_005
        );
    }

    // セッションに保存済みの商品データをもとに、GET 表示で確認画面へ各項目が反映されることを確認する。
    public function testProductConfirmDisplaysSessionProductDataAsGet(): void
    {
        $imageName = $this->existingUploadImageName();
        $productData = $this->arrangeProductConfirmSession([
            'management_no' => 'PCF001',
            'category_id' => 20,
            'maker_id' => 30,
            'product_name' => 'Product Confirm Test',
            'wholesale_amount' => 1234,
            'retail_amount' => 2345,
            'sell_amount' => 3456,
            'quantity' => 7,
            'unit_id' => 10,
            'storage_place' => 'Confirm Shelf A-01',
            'image_file' => $imageName,
            'remarks' => "Remark 1\nRemark 2",
            'remarks2' => "Note 1\nNote 2",
        ]);

        try {
            $response = $this->getClient()->get('product_confirm.php');
            $response->body = str_replace("\r\n", "\n", $response->body);

            $this->assertOk($response);
            $this->assertStringContainsString($productData['management_no'], $response->body);
            $this->assertStringContainsString('カテゴリ20', $response->body);
            $this->assertStringContainsString('メーカー30', $response->body);
            $this->assertStringContainsString($productData['product_name'], $response->body);
            $this->assertStringContainsString(number_format((int) $productData['wholesale_amount']), $response->body);
            $this->assertStringContainsString(number_format((int) $productData['retail_amount']), $response->body);
            $this->assertStringContainsString(number_format((int) $productData['sell_amount']), $response->body);
            $this->assertStringContainsString(number_format((int) $productData['quantity']) . '個', $response->body);
            $this->assertStringContainsString($productData['storage_place'], $response->body);
            $this->assertStringContainsString('Remark 1<br />' . "\n" . 'Remark 2', $response->body);
            $this->assertStringContainsString('Note 1<br />' . "\n" . 'Note 2', $response->body);
            $this->assertElementExists(
                $response,
                "//a[@href='uploads/{$imageName}' and @data-bs-image='uploads/{$imageName}']/img[@src='uploads/s_{$imageName}']"
            );
        } finally {
            $this->clearProductConfirmSession();
        }
    }

    // mode=insert を POST した場合に商品が登録され、product_list.php へ遷移することを確認する。
    public function testProductConfirmInsertsProductAndRedirectsToProductList(): void
    {
        $managementNo = 'INS' . strtoupper(substr(str_replace('.', '', uniqid('', true)), -10));
        $productData = $this->arrangeProductConfirmSession([
            'management_no' => $managementNo,
            'category_id' => 20,
            'maker_id' => 30,
            'product_name' => 'Inserted Product Test',
            'wholesale_amount' => 4321,
            'retail_amount' => 5432,
            'sell_amount' => 6543,
            'quantity' => 0,
            'unit_id' => 10,
            'storage_place' => 'Insert Shelf A-01',
            'image_file' => '',
            'remarks' => 'insert remarks',
            'remarks2' => 'insert remarks2',
        ]);

        $this->assertFalse($this->productExists($managementNo), "Product should not exist before insert: {$managementNo}");

        $response = $this->getClient()->post('product_confirm.php', [
            'mode' => 'insert',
        ]);

        $this->assertOk($response);
        $this->assertStringContainsString('Location: product_list.php', $response->headers);
        $this->assertTrue($this->productExists($managementNo), "Product should exist after insert: {$managementNo}");

        $inserted = $this->selectProductData($managementNo);
        $this->assertSame($productData['management_no'], $inserted['management_no']);
        $this->assertSame($productData['category_id'], $inserted['category_id']);
        $this->assertSame($productData['maker_id'], $inserted['maker_id']);
        $this->assertSame($productData['product_name'], $inserted['product_name']);
        $this->assertSame($productData['wholesale_amount'], $inserted['wholesale_amount']);
        $this->assertSame($productData['retail_amount'], $inserted['retail_amount']);
        $this->assertSame($productData['sell_amount'], $inserted['sell_amount']);
        $this->assertSame($productData['unit_id'], $inserted['unit_id']);
        $this->assertSame($productData['storage_place'], $inserted['storage_place']);
        $this->assertSame($productData['remarks'], $inserted['remarks']);
        $this->assertSame($productData['remarks2'], $inserted['remarks2']);
    }

    // 想定外の POST 値でアクセスした場合は 400 を返すことを確認する。
    public function testNonDataPostAccessReturns400(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->post('product_confirm.php', [
            'mode' => 'invalid',
        ]);
        $this->assertSame(400, $response->status);
    }

    // セッションがない GET アクセスは 400 を返すことを確認する。
    public function testNonDataGetAccessReturns400(): void
    {
        $this->loginAsAdmin();
        $this->clearProductConfirmSession();
        $response = $this->getClient()->get('product_confirm.php');
        $this->assertSame(400, $response->status);
    }

    // 権限のないユーザーでは 403 を返すことを確認する。
    public function testProductConfirmReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');
        $response = $this->getClient()->post('product_confirm.php', [
            'mode' => 'confirm',
        ]);
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }

    // product_confirm.php の confirm POST を通して、確認画面表示用の productData をセッションへ作成する。
    private function arrangeProductConfirmSession(array $productDataOverrides = []): array
    {
        $this->loginAsAdmin();

        $productData = $this->buildProductConfirmProductData($productDataOverrides);
        $response = $this->getClient()->post('product_confirm.php', array_merge(
            ['mode' => 'confirm'],
            $productData
        ));

        $this->assertOk($response);
        $this->assertStringContainsString('Location: product_confirm.php', $response->headers);

        return $productData;
    }

    // 商品登録確認の confirm POST を行い、バリデーションエラー時のリダイレクトとエラー表示を確認する。
    private function assertProductConfirmValidationError(
        array $productDataOverrides,
        string $fieldId,
        string $expectedMessage
    ): void {
        $this->loginAsAdmin();

        $postData = array_merge(
            ['mode' => 'confirm'],
            $this->buildProductConfirmProductData($productDataOverrides)
        );

        try {
            $response = $this->getClient()->post('product_confirm.php', $postData);

            $this->assertOk($response);
            $this->assertStringContainsString('Location: product_create.php', $response->headers);
            $this->assertStringContainsString($expectedMessage, $response->body);
            $this->assertElementExists(
                $response,
                "//*[@id='{$fieldId}' and contains(@class, 'is-invalid')]"
            );
            $this->assertInputValue($response, 'storage_place', (string) $postData['storage_place']);
            $this->assertInputValue($response, 'image_file', (string) $postData['image_file']);
        } finally {
            $this->clearProductConfirmSession();
        }
    }

    // 商品登録確認の confirm POST を行い、不正な数値入力が 0 に変換されて確認画面へ表示されることを確認する。
    // ProductConfirm で使う productData の既定値へ、指定した差分を上書きした配列を返す。
    private function buildProductConfirmProductData(array $overrides = []): array
    {
        return array_merge([
            'management_no' => 'VALPCF001',
            'category_id' => 10,
            'maker_id' => 10,
            'product_name' => 'Validation Product',
            'wholesale_amount' => 1,
            'retail_amount' => 1,
            'sell_amount' => 1,
            'quantity' => 0,
            'unit_id' => 10,
            'storage_place' => 'Validation Shelf 01',
            'image_file' => '',
            'remarks' => '',
            'remarks2' => '',
        ], $overrides);
    }

    // products テーブルから指定管理番号の商品情報を取得する。
    private function selectProductData(string $managementNo): array
    {
        return (new Product())->select($managementNo);
    }

    // products テーブルに指定管理番号の商品が存在するかを返す。
    private function productExists(string $managementNo): bool
    {
        return (new Product())->isExistsByManagementNo($managementNo);
    }

    // product_create.php の reset POST を使って、biz003 の商品入力セッションをクリアする。
    private function clearProductConfirmSession(): void
    {
        $response = $this->getClient()->post('product_create.php', [
            'mode' => 'reset',
        ]);

        $this->assertOk($response);
        $this->assertStringContainsString('Location: product_create.php', $response->headers);
    }

    // public/uploads にある表示可能な画像名を 1 件取得する。
    private function existingUploadImageName(): string
    {
        $uploadsDir = dirname(__DIR__, 2) . '/public/' . LogicConst::DIR_IMAGES;
        $entries = @scandir($uploadsDir);

        if ($entries === false) {
            $this->fail("Failed to read uploads directory: {$uploadsDir}");
        }

        $imageNames = [];
        foreach ($entries as $entry) {
            if (str_starts_with($entry, 's_')) {
                continue;
            }

            if (Utility::checkImageName($entry)) {
                $imageNames[] = $entry;
            }
        }

        sort($imageNames);
        $this->assertNotEmpty($imageNames, 'Expected at least one displayable image in public/uploads.');

        return $imageNames[0];
    }

    // レスポンス HTML から XPath を使える状態にして返す。
    private function createXPath(Response $response): \DOMXPath
    {
        return new \DOMXPath($response->dom());
    }

    // 指定した XPath に一致する要素数が期待値どおりであることを確認する。
    private function assertXPathCount(Response $response, string $xpath, int $expectedCount): void
    {
        $nodes = $this->createXPath($response)->query($xpath);
        $this->assertNotFalse($nodes, "Invalid XPath: {$xpath}");
        $this->assertSame($expectedCount, $nodes->length, "Unexpected node count for XPath: {$xpath}");
    }

    // 指定した XPath に一致する要素が存在することを確認する。
    private function assertElementExists(Response $response, string $xpath): void
    {
        $this->assertXPathCount($response, $xpath, 1);
    }

    // 指定した input 要素の value 属性が期待値どおりであることを確認する。
    private function assertInputValue(Response $response, string $id, string $expectedValue): void
    {
        $nodes = $this->createXPath($response)->query("//input[@id='{$id}']");
        $this->assertNotFalse($nodes, "Invalid XPath for input id: {$id}");
        $this->assertSame(1, $nodes->length, "Expected exactly one input element for id: {$id}");

        $node = $nodes->item(0);
        $this->assertInstanceOf(\DOMElement::class, $node);
        $this->assertSame($expectedValue, $node->getAttribute('value'));
    }
    // セッション期限切れ時に認証情報と画面用セッションが破棄されることを確認する
    public function testProductConfirmClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('product_confirm.php');
    }
}
