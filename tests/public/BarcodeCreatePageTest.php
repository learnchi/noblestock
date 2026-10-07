<?php

declare(strict_types=1);

use Noblestock\DbLogic\User;
use Noblestock\Logic\LogicConst;
use Noblestock\Logic\MessageConst;
use Tests\Support\Response;
use Tests\Support\WebTestCase;

final class BarcodeCreatePageTest extends WebTestCase
{
    // ログインセッションをクリア後、この画面にアクセスすると、indexに遷移する
    public function testBarcodeCreateRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('barcode_create.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }
    // ログインセッションをクリア後、この画面にアクセスすると、認証エラーメッセージ付きでindexに遷移する
    public function testBarcodeCreateRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('barcode_create.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }
    public function testBarcodeCreateDisplays(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->get('barcode_create.php');
        $this->assertOk($response);
    }

    // セッション上のユーザーがDBから削除済みなら、セッションを破棄してindexへ戻る
    public function testBarcodeCreateRedirectsWhenCurrentUserNoLongerExists(): void
    {
        \Tests\Support\TestDatabase::seed();
        $this->loginAsAdmin();
        (new User())->delete(null, 'admin');

        try {
            $response = $this->getClient()->get('barcode_create.php');
        } finally {
            \Tests\Support\TestDatabase::seed();
        }

        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    // この画面に権限があるユーザー[perm06]で200で表示される
    public function testBarcodeCreateDisplaysForPerm06(): void
    {
        $this->loginAs('perm06', 'perm0600');

        $response = $this->getClient()->get('barcode_create.php');
        $this->assertOk($response);
    }

    // この画面に権限がないユーザーnoauthで403エラーとなる
    public function testBarcodeCreateReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->get('barcode_create.php');
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }

    // 初期表示では、現在のラベル設定に応じたガイド文が出ていることを確認する。
    public function testBarcodeCreateShowsGuideMessageOnInitialDisplay(): void
    {
        $this->prepareBarcodeCreateAsAdmin();

        $response = $this->getClient()->get('barcode_create.php');
        $this->assertOk($response);

        $this->assertGuideMessageStartsWith($response, MessageConst::MSG_INF_BARCODE_001);
    }

    // 有効な管理番号を POST すると、バーコード画像と商品情報が表示され、出力ボタンも有効になる。
    public function testBarcodeCreatePostKnownManagementNoShowsBarcodeAndProductInfo(): void
    {
        $this->prepareBarcodeCreateAsAdmin();

        $response = $this->postBarcode('ABC001');

        $this->assertStringContainsString('ABC001', $response->body);
        $this->assertStringContainsString('商品サンプルABC001', $response->body);
        $this->assertStringContainsString('../tmp/u1_barcode.png', $response->body);
        $this->assertStringContainsString('バーコードイメージ', $response->body);
        $this->assertBarcodeExportButtonEnabled($response);
        $this->assertBarcodeImageFileExists('u1_barcode.png');
    }

    // 商品が存在しない管理番号でも、Code39 として扱える値ならバーコード画像自体は生成される。
    // その場合は対象商品なしエラーを出しつつ、管理番号だけ表示される。
    public function testBarcodeCreatePostUnknownManagementNoShowsErrorAndManagementNo(): void
    {
        $this->prepareBarcodeCreateAsAdmin();

        $response = $this->postBarcode('ZZZ001');

        $this->assertFlushErrorMessage($response, MessageConst::MSG_VAL_BARCODE_002);
        $this->assertStringContainsString('ZZZ001', $response->body);
        $this->assertStringContainsString('../tmp/u1_barcode.png', $response->body);
        $this->assertBarcodeExportButtonEnabled($response);
        $this->assertBarcodeImageFileExists('u1_barcode.png');
    }

    // バーコードとして扱えない文字列を POST すると、生成失敗エラーが出て出力ボタンは無効のままになる。
    public function testBarcodeCreatePostInvalidBarcodeShowsSystemErrorAndKeepsExportDisabled(): void
    {
        $this->prepareBarcodeCreateAsAdmin();

        $response = $this->postBarcode('あいう');

        $this->assertFlushErrorMessage($response, MessageConst::MSG_SYS_BARCODE_018);
        $this->assertBarcodeExportButtonDisabled($response);
    }

    // メニューコマンドはバーコード生成ではなく画面遷移として処理されることを確認する。
    public function testBarcodeCreatePostMenuCommandRedirectsToMenu(): void
    {
        $this->prepareBarcodeCreateAsAdmin();

        $response = $this->postBarcode(LogicConst::CMD_MENU);
        $this->assertStringContainsString('Location: menu.php', $response->headers);
    }

    private function prepareBarcodeCreateAsAdmin(): void
    {
        \Tests\Support\TestDatabase::seed();
        $this->loginAsAdmin();
        $this->getClient()->get('menu.php');
        $this->delSessionStructData('biz401', null, 'barcode_create.php');
        $this->delSessionStructData('com901', null, 'barcode_create.php');
        $this->deleteBarcodeImageFileIfExists('u1_barcode.png');
    }

    private function postBarcode(string $barcode): Response
    {
        $response = $this->getClient()->post('barcode_create.php', [
            'barcode' => $barcode,
        ]);
        $this->assertOk($response);

        return $response;
    }

    // info アラートの先頭が基本ガイド文になっていることを確認する。
    private function assertGuideMessageStartsWith(Response $response, string $expectedPrefix): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//div[contains(@class,'alert-info')]/div");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Guide message alert was not found.');

        $actual = trim((string) ($nodes->item(0)?->textContent ?? ''));
        $this->assertStringStartsWith($expectedPrefix, $actual);
    }

    // danger アラートに想定どおりのエラーメッセージが出ていることを確認する。
    private function assertFlushErrorMessage(Response $response, string $expectedMessage): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//div[contains(@class,'alert-danger')]/div");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Flush error alert was not found.');

        $actual = trim((string) ($nodes->item(0)?->textContent ?? ''));
        $this->assertSame($expectedMessage, $actual);
    }

    // バーコード出力ボタンが有効な状態になっていることを確認する。
    private function assertBarcodeExportButtonEnabled(Response $response): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//form[@action='barcode_bulk_export.php']//button");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Barcode export button was not found.');
        $this->assertFalse($nodes->item(0)?->attributes?->getNamedItem('disabled') !== null);
    }

    // バーコード出力ボタンが無効な状態のままであることを確認する。
    private function assertBarcodeExportButtonDisabled(Response $response): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//form[@action='barcode_bulk_export.php']//button");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Barcode export button was not found.');
        $this->assertTrue($nodes->item(0)?->attributes?->getNamedItem('disabled') !== null);
    }

    // tmp に出力された PNG が実際に存在することを確認する。
    private function assertBarcodeImageFileExists(string $fileName): void
    {
        $path = dirname(__DIR__, 2) . '/tmp/' . $fileName;
        $this->assertFileExists($path, "Expected barcode image file was not created: {$path}");
    }

    private function deleteBarcodeImageFileIfExists(string $fileName): void
    {
        $path = dirname(__DIR__, 2) . '/tmp/' . $fileName;
        if (is_file($path)) {
            @unlink($path);
        }
    }
    // セッション期限切れ時に認証情報と画面用セッションが破棄されることを確認する
    public function testBarcodeCreateClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('barcode_create.php');
    }
}
