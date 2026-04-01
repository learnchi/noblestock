<?php

declare(strict_types=1);

use Noblestock\Logic\LogicConst;
use Noblestock\Logic\MessageConst;
use Studiogau\Chandra\Support\SessionHelper;
use Tests\Support\ImageWebTestCase;
use Tests\Support\Response;

final class ProductCreatePageTest extends ImageWebTestCase
{
    // ログインセッションがない場合は index.php へリダイレクトされることを確認する。
    public function testProductCreateRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('product_create.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // ログインセッションがない場合はセッション切れメッセージ付きで index.php へ戻ることを確認する。
    public function testProductCreateRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('product_create.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    // 管理者でアクセスした場合に商品登録画面が表示されることを確認する。
    public function testProductCreateDisplays(): void
    {
        $this->arrangeProductCreateSession();

        $response = $this->getClient()->get('product_create.php');
        $this->assertOk($response);
    }

    // 権限を持つ perm01 ユーザーでも商品登録画面が表示されることを確認する。
    public function testProductCreateDisplaysForPerm01(): void
    {
        $this->loginAs('perm01', 'perm0100');
        $this->seedProductCreateSessionState();

        $response = $this->getClient()->get('product_create.php');
        $this->assertOk($response);
    }

    // 権限を持たないユーザーでは 403 が返ることを確認する。
    public function testProductCreateReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->get('product_create.php');
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }

    // セッションに商品入力値がない場合は初期値でフォームが表示されることを確認する。
    public function testProductCreateDisplaysDefaultValuesWhenSessionDataIsMissing(): void
    {
        $this->arrangeProductCreateSession();

        $response = $this->getClient()->get('product_create.php');

        $this->assertOk($response);
        $this->assertInputValue($response, 'management_no', '');
        $this->assertInputValue($response, 'product_name', '');
        $this->assertInputValue($response, 'wholesale_amount', '');
        $this->assertInputValue($response, 'retail_amount', '');
        $this->assertInputValue($response, 'sell_amount', '');
        $this->assertInputValue($response, 'storage_place', '');
        $this->assertInputValue($response, 'image_file', '');
    }

    // 初期表示時に maker と category と unit の既定値が選択されることを確認する。
    public function testProductCreateSelectsDefaultMasterValuesOnInitialDisplay(): void
    {
        $this->arrangeProductCreateSession();

        $response = $this->getClient()->get('product_create.php');

        $this->assertSelectedValue($response, 'maker_id', '1');
        $this->assertSelectedValue($response, 'category_id', '1');
        $this->assertSelectedValue($response, 'unit_id', '1');
    }

    // セッションに保持された入力値が各入力欄へ復元表示されることを確認する。
    public function testProductCreateRestoresInputValuesFromSession(): void
    {
        $this->arrangeProductCreateSession([
            'productData' => $this->buildProductData([
                'management_no' => 'TEST-001',
                'product_name' => 'Sample Product',
                'wholesale_amount' => '1200',
                'retail_amount' => '1800',
                'sell_amount' => '1500',
                'storage_place' => 'A-01',
                'image_file' => 'sample001.jpg',
            ]),
        ]);

        $response = $this->getClient()->get('product_create.php');

        $this->assertInputValue($response, 'management_no', 'TEST-001');
        $this->assertInputValue($response, 'product_name', 'Sample Product');
        $this->assertInputValue($response, 'wholesale_amount', '1200');
        $this->assertInputValue($response, 'retail_amount', '1800');
        $this->assertInputValue($response, 'sell_amount', '1500');
        $this->assertInputValue($response, 'storage_place', 'A-01');
        $this->assertInputValue($response, 'image_file', 'sample001.jpg');
    }

    // セッションに保持された maker と category と unit の選択値が復元されることを確認する。
    public function testProductCreateRestoresSelectedMasterValuesFromSession(): void
    {
        $this->arrangeProductCreateSession([
            'productData' => $this->buildProductData([
                'category_id' => 2,
                'maker_id' => 2,
                'unit_id' => 2,
            ]),
        ]);

        $response = $this->getClient()->get('product_create.php');

        $this->assertSelectedValue($response, 'maker_id', '2');
        $this->assertSelectedValue($response, 'category_id', '2');
        $this->assertSelectedValue($response, 'unit_id', '2');
    }

    // セッションに保持された remarks と remarks2 が textarea へ復元されることを確認する。
    public function testProductCreateRestoresTextareaValuesFromSession(): void
    {
        $this->arrangeProductCreateSession([
            'productData' => $this->buildProductData([
                'remarks' => '備考1',
                'remarks2' => '備考2',
            ]),
        ]);

        $response = $this->getClient()->get('product_create.php');

        $this->assertTextareaValue($response, 'remarks', '備考1');
        $this->assertTextareaValue($response, 'remarks2', '備考2');
    }

    // バリデーションエラーがある項目に is-invalid クラスが付与されることを確認する。
    public function testProductCreateMarksFieldsInvalidWhenValidationErrorsExist(): void
    {
        $this->arrangeProductCreateSession([
            'errors' => [
                'management_no' => '管理番号エラー',
                'product_name' => '商品名エラー',
                'wholesale_amount' => '仕入価格エラー',
            ],
        ]);

        $response = $this->getClient()->get('product_create.php');

        $this->assertElementExists($response, "//input[@id='management_no' and contains(@class, 'is-invalid')]");
        $this->assertElementExists($response, "//input[@id='product_name' and contains(@class, 'is-invalid')]");
        $this->assertElementExists($response, "//input[@id='wholesale_amount' and contains(@class, 'is-invalid')]");
    }

    // バリデーションエラーがある場合に対応するエラーメッセージが表示されることを確認する。
    public function testProductCreateDisplaysValidationErrorMessages(): void
    {
        $this->arrangeProductCreateSession([
            'errors' => [
                'management_no' => '管理番号エラー',
                'product_name' => '商品名エラー',
                'wholesale_amount' => '仕入価格エラー',
                'retail_amount' => '定価エラー',
                'sell_amount' => '売価エラー',
            ],
        ]);

        $response = $this->getClient()->get('product_create.php');

        $this->assertStringContainsString('管理番号エラー', $response->body);
        $this->assertStringContainsString('商品名エラー', $response->body);
        $this->assertStringContainsString('仕入価格エラー', $response->body);
        $this->assertStringContainsString('定価エラー', $response->body);
        $this->assertStringContainsString('売価エラー', $response->body);
    }

    // フラッシュエラーがある場合にエラーアラートが表示されることを確認する。
    public function testProductCreateDisplaysFlushErrorMessage(): void
    {
        $this->arrangeProductCreateSession([
            'flushError' => 'テスト用エラーメッセージ',
        ]);

        $response = $this->getClient()->get('product_create.php');

        $this->assertElementExists($response, "//div[contains(@class, 'alert-danger')]");
        $this->assertStringContainsString('テスト用エラーメッセージ', $response->body);
    }

    // フラッシュ成功がある場合に成功アラートが表示されることを確認する。
    public function testProductCreateDisplaysFlushSuccessMessage(): void
    {
        $this->arrangeProductCreateSession([
            'flushSuccess' => 'テスト用成功メッセージ',
        ]);

        $response = $this->getClient()->get('product_create.php');

        $this->assertElementExists($response, "//div[contains(@class, 'alert-success')]");
        $this->assertStringContainsString('テスト用成功メッセージ', $response->body);
    }

    // BAR_PRT_SIZE 設定値に応じたバーコード案内文が表示されることを確認する。
    public function testProductCreateDisplaysBarcodeGuideForConfiguredPrintSize(): void
    {
        $this->arrangeProductCreateSession([
            'pref' => ['BAR_PRT_SIZE' => 4],
        ]);

        $response = $this->getClient()->get('product_create.php');

        $this->assertStringContainsString(LogicConst::BAR_PRT_SIZE_DEFS[4], $response->body);
    }

    // BAR_PRT_SIZE が想定外の値でも既定のバーコード案内文が表示されることを確認する。
    public function testProductCreateDisplaysDefaultBarcodeGuideForUnexpectedPrintSize(): void
    {
        $this->arrangeProductCreateSession([
            'pref' => ['BAR_PRT_SIZE' => 999],
        ]);

        $response = $this->getClient()->get('product_create.php');

        $this->assertStringContainsString(LogicConst::BAR_PRT_SIZE_DEFS[1], $response->body);
    }

    // image_file が空の場合はサムネイルリンクが表示されないことを確認する。
    public function testProductCreateDoesNotDisplayImageThumbnailWhenImageFileIsEmpty(): void
    {
        $this->arrangeProductCreateSession([
            'productData' => $this->buildProductData([
                'image_file' => '',
            ]),
        ]);

        $response = $this->getClient()->get('product_create.php');

        $this->assertXPathCount(
            $response,
            "//a[@data-bs-target='#imageViewer' and contains(@href, 'uploads/')]",
            0
        );
    }

    // image_file があり画像実体も存在する場合はサムネイルリンクが表示されることを確認する。
    public function testProductCreateDisplaysImageThumbnailWhenImageExists(): void
    {
        $this->arrangeProductCreateSession([
            'productData' => $this->buildProductData([
                'image_file' => 'sample001.jpg',
            ]),
        ]);

        $response = $this->getClient()->get('product_create.php');

        $this->assertElementExists(
            $response,
            "//a[@href='uploads/sample001.jpg' and @data-bs-target='#imageViewer']/img[@src='uploads/s_sample001.jpg']"
        );
    }

    // mode=reset で POST した場合に biz003.productData が削除されることを確認する。
    public function testProductCreateResetRemovesProductData(): void
    {
        $this->arrangeProductCreateSession([
            'productData' => $this->buildProductData([
                'management_no' => 'RESET-001',
            ]),
        ]);

        $response = $this->getClient()->post('product_create.php', [
            'mode' => 'reset',
        ]);

        $this->assertOk($response);
        $this->assertNull($this->readSessionStructData('biz003', 'productData'));
    }

    // mode=reset で POST した場合に biz003.validation-errors が削除されることを確認する。
    public function testProductCreateResetRemovesValidationErrors(): void
    {
        $this->arrangeProductCreateSession([
            'errors' => [
                'management_no' => '管理番号エラー',
            ],
        ]);

        $response = $this->getClient()->post('product_create.php', [
            'mode' => 'reset',
        ]);

        $this->assertOk($response);
        $this->assertNull($this->readSessionStructData('biz003', 'validation-errors'));
    }

    // mode=reset で POST した場合に 303 で自画面へリダイレクトすることを確認する。
    public function testProductCreateResetRedirectsWith303ToSelf(): void
    {
        $this->arrangeProductCreateSession();

        $response = $this->getClient()->post('product_create.php', [
            'mode' => 'reset',
        ]);

        $this->assertMatchesRegularExpression('/HTTP\/\d(?:\.\d)? 303/', $response->headers);
        $this->assertStringContainsString('product_create.php', $response->headers);
    }

    // POST で pos を送った場合に biz002.scrollPos へ保存されることを確認する。
    public function testProductCreateStoresScrollPositionWhenPosPosted(): void
    {
        $this->arrangeProductCreateSession();

        $response = $this->getClient()->post('product_create.php', [
            'mode' => 'confirm',
            'pos' => '1234',
        ]);

        $this->assertOk($response);
        $this->assertSame('1234', $this->readSessionStructData('biz002', 'scrollPos', 'product_list.php'));
    }

    // POST で pos を送った場合も 303 で自画面へリダイレクトすることを確認する。
    public function testProductCreatePostWithPosRedirectsWith303ToSelf(): void
    {
        $this->arrangeProductCreateSession();

        $response = $this->getClient()->post('product_create.php', [
            'mode' => 'confirm',
            'pos' => '5678',
        ]);

        $this->assertMatchesRegularExpression('/HTTP\/\d(?:\.\d)? 303/', $response->headers);
        $this->assertStringContainsString('product_create.php', $response->headers);
    }

    // フォームの送信先が product_confirm.php になっていることを確認する。
    public function testProductCreateFormPostsToProductConfirm(): void
    {
        $this->arrangeProductCreateSession();

        $response = $this->getClient()->get('product_create.php');

        $this->assertElementExists($response, "//form[@method='post' and @action='product_confirm.php']");
    }

    // 画像選択ボタンの formaction が image_create.php になっていることを確認する。
    public function testProductCreateImageButtonPostsToImageCreate(): void
    {
        $this->arrangeProductCreateSession();

        $response = $this->getClient()->get('product_create.php');

        $this->assertElementExists(
            $response,
            "//button[@formaction='image_create.php' and @name='filename' and @value='product_create']"
        );
    }

    // リセットボタンの formaction が product_create.php になっていることを確認する。
    public function testProductCreateResetButtonPostsToSelf(): void
    {
        $this->arrangeProductCreateSession();

        $response = $this->getClient()->get('product_create.php');

        $this->assertElementExists(
            $response,
            "//button[@formaction='product_create.php' and @name='mode' and @value='reset']"
        );
    }

    // hidden の filename が product_create になっていることを確認する。
    public function testProductCreateIncludesHiddenFilename(): void
    {
        $this->arrangeProductCreateSession();

        $response = $this->getClient()->get('product_create.php');

        $this->assertElementExists(
            $response,
            "//input[@type='hidden' and @name='filename' and @value='product_create']"
        );
    }

    // hidden の quantity が 0 になっていることを確認する。
    public function testProductCreateIncludesHiddenQuantity(): void
    {
        $this->arrangeProductCreateSession();

        $response = $this->getClient()->get('product_create.php');

        $this->assertElementExists(
            $response,
            "//input[@type='hidden' and @name='quantity' and @value='0']"
        );
    }

    // 商品登録画面テストで使うセッション状態をまとめて準備する。
    private function arrangeProductCreateSession(array $options = []): void
    {
        $this->loginAsAdmin();
        $this->seedProductCreateSessionState($options);
    }

    // 商品登録画面テスト用のマスタやセッション値を現在の Web セッションへ投入する。
    private function seedProductCreateSessionState(array $options = []): void
    {
        $productData = $options['productData'] ?? null;
        $errors = $options['errors'] ?? null;
        $pref = $options['pref'] ?? [];
        $flushError = $options['flushError'] ?? null;
        $flushSuccess = $options['flushSuccess'] ?? null;
        $master = $options['master'] ?? $this->defaultMasterData();
        $result = $this->runProductCreateSessionBridge([
            'mode' => 'seed',
            'scriptName' => 'product_create.php',
            'master' => $master,
            'productData' => $productData,
            'errors' => $errors,
            'pref' => $pref,
            'flushError' => $flushError,
            'flushSuccess' => $flushSuccess,
        ]);

        $this->assertTrue((bool) ($result['ok'] ?? false));
    }

    // 商品登録画面の既定マスタ一覧をテスト用に返す。
    private function defaultMasterData(): array
    {
        return [
            'makerList' => [
                ['id' => 1, 'maker_name' => 'Maker One'],
                ['id' => 2, 'maker_name' => 'Maker Two'],
            ],
            'categoryList' => [
                ['id' => 1, 'category_name' => 'Category One'],
                ['id' => 2, 'category_name' => 'Category Two'],
            ],
            'unitList' => [
                ['id' => 1, 'unit_name' => 'Unit One'],
                ['id' => 2, 'unit_name' => 'Unit Two'],
            ],
        ];
    }

    // 商品登録画面の productData 既定値へ指定した差分を上書きした配列を返す。
    private function buildProductData(array $overrides = []): array
    {
        return array_merge([
            'management_no' => '',
            'category_id' => 1,
            'maker_id' => 1,
            'product_name' => '',
            'wholesale_amount' => '',
            'retail_amount' => '',
            'sell_amount' => '',
            'quantity' => '',
            'unit_id' => 1,
            'storage_place' => '',
            'image_file' => '',
            'remarks' => '',
            'remarks2' => '',
        ], $overrides);
    }

    // 現在の Web セッションに保存された構造化セッション値を読み出す。
    private function readSessionStructData(string $func, string $key, string $scriptName = 'product_create.php'): mixed
    {
        $result = $this->runProductCreateSessionBridge([
            'mode' => 'read',
            'scriptName' => $scriptName,
            'func' => $func,
            'key' => $key,
        ]);

        return $result['value'] ?? null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function runProductCreateSessionBridge(array $payload): array
    {
        $bridgeName = '__product_create_session_' . bin2hex(random_bytes(8)) . '.php';
        $bridgePath = dirname(__DIR__, 2) . '/public/' . $bridgeName;
        $bridgeCode = <<<'PHP'
<?php
session_cache_limiter("none");
@session_start();
date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../vendor/autoload.php';

use Studiogau\Chandra\Support\SessionHelper;

$payload = json_decode(file_get_contents('php://input'), true);
$scriptName = (string) ($payload['scriptName'] ?? 'product_create.php');
$_SERVER['SCRIPT_NAME'] = '/noblestock/public/' . ltrim($scriptName, '/');

if (($payload['mode'] ?? 'seed') === 'read') {
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'value' => SessionHelper::getData((string) ($payload['func'] ?? ''), (string) ($payload['key'] ?? '')),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

SessionHelper::setMaster(is_array($payload['master'] ?? null) ? $payload['master'] : []);
SessionHelper::delData('biz003', null);
SessionHelper::delData('biz002', 'scrollPos');
SessionHelper::clearPref();
SessionHelper::getFlushError();
SessionHelper::getFlushSuccess();

if (array_key_exists('productData', $payload) && is_array($payload['productData'])) {
    SessionHelper::setData('biz003', 'productData', $payload['productData']);
}

if (array_key_exists('errors', $payload) && is_array($payload['errors'])) {
    SessionHelper::setData('biz003', 'validation-errors', $payload['errors']);
}

foreach ((array) ($payload['pref'] ?? []) as $key => $value) {
    SessionHelper::setPref((string) $key, $value);
}

if (is_string($payload['flushError'] ?? null) && $payload['flushError'] !== '') {
    SessionHelper::flushError($payload['flushError']);
}

if (is_string($payload['flushSuccess'] ?? null) && $payload['flushSuccess'] !== '') {
    SessionHelper::flushSuccess($payload['flushSuccess']);
}

header('Content-Type: application/json');
echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
PHP;

        file_put_contents($bridgePath, $bridgeCode);

        try {
            $response = $this->postJsonWithCurrentClient($bridgeName, $payload);
            $this->assertSame(200, $response->status);

            $decoded = json_decode($response->body, true);
            $this->assertIsArray($decoded);

            return $decoded;
        } finally {
            @unlink($bridgePath);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postJsonWithCurrentClient(string $path, array $payload): Response
    {
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

    // 指定した input 要素の value 属性が期待どおりであることを確認する。
    private function assertInputValue(Response $response, string $id, string $expectedValue): void
    {
        $node = $this->getSingleElement($response, "//input[@id='{$id}']");
        $this->assertSame($expectedValue, $node->getAttribute('value'));
    }

    // 指定した textarea 要素の値が期待どおりであることを確認する。
    private function assertTextareaValue(Response $response, string $id, string $expectedValue): void
    {
        $node = $this->getSingleElement($response, "//textarea[@id='{$id}']");
        $this->assertSame($expectedValue, $node->textContent);
    }

    // 指定した select 要素で期待する value の option が選択されていることを確認する。
    private function assertSelectedValue(Response $response, string $id, string $expectedValue): void
    {
        $node = $this->getSingleElement(
            $response,
            "//select[@id='{$id}']/option[@value='{$expectedValue}' and @selected]"
        );
        $this->assertSame($expectedValue, $node->getAttribute('value'));
    }

    // 指定した XPath で単一要素を取得し、DOMElement として返す。
    private function getSingleElement(Response $response, string $xpath): \DOMElement
    {
        $nodes = $this->createXPath($response)->query($xpath);
        $this->assertNotFalse($nodes, "Invalid XPath: {$xpath}");
        $this->assertSame(1, $nodes->length, "Expected exactly one element for XPath: {$xpath}");
        $node = $nodes->item(0);
        $this->assertInstanceOf(\DOMElement::class, $node);

        return $node;
    }

    // セッション期限切れ時に認証情報と画面用セッションが破棄されることを確認する
    public function testProductCreateClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('product_create.php');
    }
}
