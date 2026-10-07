<?php

declare(strict_types=1);

use Noblestock\DbLogic\Product;
use Noblestock\Logic\MessageConst;
use Tests\Support\Response;
use Tests\Support\WebClient;
use Tests\Support\WebTestCase;

final class ProductEditPageTest extends WebTestCase
{
    // ログインセッションをクリア後、この画面にアクセスすると、indexに遷移する
    public function testProductEditRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('product_edit.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertInitialStatus($response, 302);
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // ログインセッションをクリア後、この画面にアクセスすると、認証エラーメッセージ付きでindexに遷移する
    public function testProductEditRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('product_edit.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertInitialStatus($response, 302);
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    // mngNo と filename を POST した場合に、商品編集画面が表示されることを確認する。
    public function testProductEditDisplays(): void
    {
        $response = $this->openProductEditAsAdmin('ABC001');
        $this->assertOk($response);
    }

    // 一度正常表示した後、mn クエリパラメータ付き GET でも商品編集画面が表示されることを確認する。
    public function testProductEditDisplaysAsGet(): void
    {
        $this->resetWebClient();
        $this->openProductEditAsAdmin('ABC001');

        $response = $this->getClient()->get('product_edit.php?mn=ABC001');
        $this->assertOk($response);
    }

    // mn クエリパラメータを受け取った場合に mngNo をセッションへ保存し、検索結果を productData として保存することを確認する。
    public function testProductEditStoresMngNoFromQueryParameterAndLoadsProductDataIntoSession(): void
    {
        $this->resetWebClient();
        $this->openProductEditAsAdmin('ABC001');

        $expected = $this->selectProductData('ABC002');
        $response = $this->getClient()->get('product_edit.php?mn=ABC002');
        $followUpResponse = $this->getClient()->get('product_edit.php');

        $this->assertOk($response);
        $this->assertInputValue($response, 'management_no', 'ABC002');
        $this->assertInputValue($response, 'product_name', $expected['product_name']);
        $this->assertInputValue($followUpResponse, 'management_no', 'ABC002');
        $this->assertInputValue($followUpResponse, 'product_name', $expected['product_name']);
        $this->assertInputValue($followUpResponse, 'storage_place', $expected['storage_place']);
    }

    // 既に別商品の productData があっても、mn クエリパラメータで指定した商品の情報へ上書きされることを確認する。
    public function testProductEditOverwritesExistingProductDataWhenQueryParameterTargetsAnotherProduct(): void
    {
        $this->resetWebClient();
        $this->openProductEditAsAdmin('ABC003');
        $staleProductData = $this->selectProductData('ABC003');

        $expected = $this->selectProductData('ABC001');
        $response = $this->getClient()->get('product_edit.php?mn=ABC001');
        $followUpResponse = $this->getClient()->get('product_edit.php');

        $this->assertOk($response);
        $this->assertInputValue($response, 'management_no', 'ABC001');
        $this->assertInputValue($response, 'product_name', $expected['product_name']);
        $this->assertInputValue($followUpResponse, 'management_no', 'ABC001');
        $this->assertInputValue($followUpResponse, 'product_name', $expected['product_name']);
        $this->assertStringNotContainsString((string) $staleProductData['product_name'], $followUpResponse->body);
    }

    // mngNo を POST した場合に mngNo をセッションへ保存し、検索結果を productData として保存することを確認する。
    public function testProductEditStoresMngNoFromPostAndLoadsProductDataIntoSession(): void
    {
        $this->resetWebClient();
        $expected = $this->selectProductData('ABC002');
        $response = $this->openProductEditAsAdmin('ABC002');
        $followUpResponse = $this->getClient()->get('product_edit.php');

        $this->assertOk($response);
        $this->assertInputValue($response, 'management_no', 'ABC002');
        $this->assertInputValue($response, 'product_name', $expected['product_name']);
        $this->assertInputValue($followUpResponse, 'management_no', 'ABC002');
        $this->assertInputValue($followUpResponse, 'product_name', $expected['product_name']);
    }

    // 既に別商品の productData があっても、POST された mngNo の商品情報へ上書きされることを確認する。
    public function testProductEditOverwritesExistingProductDataWhenPostedMngNoTargetsAnotherProduct(): void
    {
        $this->resetWebClient();
        $this->openProductEditAsAdmin('ABC003');
        $staleProductData = $this->selectProductData('ABC003');

        $expected = $this->selectProductData('ABC001');
        $response = $this->openProductEditAsAdmin('ABC001');
        $followUpResponse = $this->getClient()->get('product_edit.php');

        $this->assertOk($response);
        $this->assertInputValue($response, 'management_no', 'ABC001');
        $this->assertInputValue($response, 'product_name', $expected['product_name']);
        $this->assertInputValue($followUpResponse, 'management_no', 'ABC001');
        $this->assertInputValue($followUpResponse, 'product_name', $expected['product_name']);
        $this->assertStringNotContainsString((string) $staleProductData['product_name'], $followUpResponse->body);
    }

    // mode=reset を POST した場合に、mngNo セッション値から再取得した商品情報へ画面表示が戻ることを確認する。
    public function testProductEditResetRestoresProductDataFromSessionMngNo(): void
    {
        $this->resetWebClient();
        $productEditResponse = $this->openProductEditAsAdmin('ABC001');

        $imageCreateResponse = $this->getClient()->post('image_create.php', [
            'filename' => 'product_edit',
            'management_no' => 'FAKE999',
            'category_id' => 20,
            'maker_id' => 20,
            'product_name' => 'Fake Product For Reset',
            'wholesale_amount' => 999,
            'retail_amount' => 999,
            'sell_amount' => 999,
            'quantity' => 9,
            'unit_id' => 20,
            'storage_place' => 'FAKE-SHELF',
            'image_file' => '',
            'remarks' => 'fake remarks',
            'remarks2' => 'fake remarks2',
        ] + $this->extractCsrfPostData($productEditResponse));

        $expected = $this->selectProductData('ABC001');
        $response = $this->getClient()->post('product_edit.php', [
            'mode' => 'reset',
        ] + $this->extractCsrfPostData($imageCreateResponse));
        $followUpResponse = $this->getClient()->get('product_edit.php');

        $this->assertOk($response);
        $this->assertInitialStatus($response, 303);
        $this->assertStringContainsString('Location: product_edit.php?mn=ABC001', $response->headers);
        $this->assertInputValue($response, 'management_no', 'ABC001');
        $this->assertInputValue($response, 'product_name', $expected['product_name']);
        $this->assertInputValue($response, 'storage_place', $expected['storage_place']);
        $this->assertInputValue($followUpResponse, 'management_no', 'ABC001');
        $this->assertInputValue($followUpResponse, 'product_name', $expected['product_name']);
        $this->assertInputValue($followUpResponse, 'storage_place', $expected['storage_place']);
    }

    // 正常表示時に戻るボタンの href が filename.php になっていることを確認する。
    public function testProductEditBackLinkTargetsFilenamePhpWhenDisplayedNormally(): void
    {
        $this->resetWebClient();
        $response = $this->openProductEditAsAdmin('ABC001');

        $this->assertOk($response);
        $this->assertElementExists($response, "//a[@href='product_list.php']");
    }

    // mode=del を POST した場合に対象商品が削除され、product_list.php へ戻って成功メッセージが表示されることを確認する。
    public function testProductEditDeletesProductAndRedirectsToProductListWithSuccessMessage(): void
    {
        $this->resetWebClient();
        $targetMngNo = 'ABC020';
        $this->assertTrue($this->productExists($targetMngNo), "Expected seeded product to exist: {$targetMngNo}");

        $productEditResponse = $this->openProductEditAsAdmin($targetMngNo);
        $response = $this->getClient()->post('product_edit.php', [
            'mode' => 'del',
            'mngNo' => $targetMngNo,
        ] + $this->extractCsrfPostData($productEditResponse));

        $this->assertOk($response);
        $this->assertStringContainsString('Location: product_list.php', $response->headers);
        $this->assertStringContainsString(
            $this->formatMessage(MessageConst::MSG_OK_PRODUCT_012, $targetMngNo),
            $response->body
        );
        $this->assertFalse($this->productExists($targetMngNo), "Expected product to be deleted: {$targetMngNo}");
    }

    // この画面に必須の情報が無い場合400エラー
    public function testNonDataPostAccessReturns400(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->post('product_edit.php', [
        ]);
        $this->assertSame(400, $response->status);
    }

    // 許可されていない遷移元画面名が送られた場合は 400 を返す
    public function testProductEditReturns400ForInvalidFilename(): void
    {
        $this->loginAsAdmin();
        $sourceResponse = $this->getClient()->get('product_list.php');
        $this->assertOk($sourceResponse);

        $response = $this->getClient()->post('product_edit.php', [
            'mngNo' => 'ABC001',
            'filename' => 'evil',
        ] + $this->extractCsrfPostData($sourceResponse));

        $this->assertSame(400, $response->status);
        $this->assertStringContainsString(MessageConst::MSG_VAL_FILE_018, $response->body);
    }
    // この画面に必須の情報が無い場合400エラー
    public function testNonDataGetAccessReturns400(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->get('product_edit.php');
        $this->assertSame(400, $response->status);
    }
    // この画面に権限があるユーザー[perm01]で200で表示される
    public function testProductEditDisplaysForPerm01(): void
    {
        $this->loginAs('perm01', 'perm0100');
        $response = $this->openProductEdit('ABC001');
        $this->assertOk($response);
    }

    // この画面に権限がないユーザーnoauthで403エラーとなる
    public function testProductEditReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->post('product_edit.php', [
            'mngNo' => 'ABC001',
            'filename' => 'product_list',
        ]);
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }

    // 商品編集画面を管理者で開き、表示レスポンスを返す。
    private function openProductEditAsAdmin(string $managementNo, string $filename = 'product_list'): Response
    {
        $this->loginAsAdmin();

        return $this->openProductEdit($managementNo, $filename);
    }

    // 商品一覧画面からCSRF付きで商品編集画面を開く。
    private function openProductEdit(string $managementNo, string $filename = 'product_list'): Response
    {
        $sourceResponse = $this->getClient()->get($filename . '.php');
        $this->assertOk($sourceResponse);

        $response = $this->getClient()->post('product_edit.php', [
            'mngNo' => $managementNo,
            'filename' => $filename,
        ] + $this->extractCsrfPostData($sourceResponse));
        $this->assertInitialStatus($response, 303);

        return $response;
    }

    // products テーブルから対象商品の商品情報を取得する。
    private function selectProductData(string $managementNo): array
    {
        return (new Product())->select($managementNo);
    }

    // 対象の商品が products テーブルに存在するかを返す。
    private function productExists(string $managementNo): bool
    {
        return (new Product())->isExistsByManagementNo($managementNo);
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

    // メッセージ定数内の {0} を指定値で置換した文字列を返す。
    private function formatMessage(string $template, string $value): string
    {
        return str_replace('{0}', $value, $template);
    }

    // テストごとに新しい WebClient を使ってセッションを分離する。
    private function resetWebClient(): void
    {
        self::$client = new WebClient('http://localhost/noblestock/public');
    }
    // セッション期限切れ時に認証情報と画面用セッションが破棄されることを確認する
    public function testProductEditClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('product_edit.php');
    }
}
