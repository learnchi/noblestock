<?php

declare(strict_types=1);

use Tests\Support\WebTestCase;
use Noblestock\Logic\MessageConst;

final class ProductPerLocationExportTest extends WebTestCase
{
    // ログインセッションをクリア後、この画面にアクセスすると、indexに遷移する
    public function testProductPerLocationExportRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->post('product_per_location_export.php', [
            'mode' => 'export',
        ]);
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }
    // ログインセッションをクリア後、この画面にアクセスすると、認証エラーメッセージ付きでindexに遷移する
    public function testProductPerLocationExportRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->post('product_per_location_export.php', [
            'mode' => 'export',
        ]);
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }
    public function testNonPostAccessReturns405(): void
    {
        $response = $this->getClient()->get('product_per_location_export.php');
        $this->assertSame(405, $response->status);
    }

    public function testPostWithExportModeReturns200(): void
    {
        $this->loginAsAdmin();

        $response = $this->getClient()->post('product_per_location_export.php', [
            'mode' => 'export',
        ]);
        $this->assertSame(200, $response->status);
    }

    public function testPostWithoutExportModeReturns400(): void
    {
        $this->loginAsAdmin();

        $response = $this->getClient()->post('product_per_location_export.php', [
            'mode' => 'invalid',
        ]);
        $this->assertSame(400, $response->status);
    }

    // この画面に権限があるユーザー[perm02]で200で表示される
    public function testPostWithExportModeReturns200ForPerm02(): void
    {
        $this->loginAs('perm02', 'perm0200');

        $response = $this->getClient()->post('product_per_location_export.php', [
            'mode' => 'export',
        ]);
        $this->assertSame(200, $response->status);
    }

    // この画面に権限がないユーザーnoauthで403エラーとなる
    public function testPostWithExportModeReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->post('product_per_location_export.php', [
            'mode' => 'export',
        ]);
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }
    // セッション期限切れ時に認証情報と画面用セッションが破棄されることを確認する
    public function testProductPerLocationExportClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage(
            'product_per_location_export.php',
            [
                'method' => 'POST',
                'payload' => [
                    'mode' => 'export',
                    '_skip_auto_csrf' => true,
                ],
            ]
        );
    }
}
