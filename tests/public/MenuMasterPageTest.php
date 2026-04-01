<?php

declare(strict_types=1);

use Noblestock\Logic\MessageConst;
use Tests\Support\Response;
use Tests\Support\WebTestCase;

final class MenuMasterPageTest extends WebTestCase
{
    // ログインしていない状態ではこの画面にアクセスできず、index.php に戻される
    public function testMenuMasterRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('menu_master.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // セッション切れメッセージを出して index.php に戻されることも確認する
    public function testMenuMasterRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('menu_master.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    public function testMenuMasterDisplays(): void
    {
        $this->loginAsAdmin();

        $response = $this->getClient()->get('menu_master.php');
        $this->assertOk($response);
    }

    // 権限を持つ perm11 でも表示できる
    public function testMenuMasterDisplaysForPerm11(): void
    {
        $this->loginAs('perm11', 'perm1100');

        $response = $this->getClient()->get('menu_master.php');
        $this->assertOk($response);
    }

    // 権限のない noauth では 403 になる
    public function testMenuMasterReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->get('menu_master.php');
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }

    // 初期表示ではタイトルと案内メッセージが出ている
    public function testMenuMasterShowsTitleAndGuideMessage(): void
    {
        $response = $this->openMenuMasterAsAdmin();

        $this->assertStringContainsString('<title>マスタメンテナンス | NobleStock</title>', $response->body);
        $this->assertGuideMessage($response, MessageConst::MSG_INF_MENU_001);
    }

    // 管理者ではマスタメンテナンス配下の各メニューがすべて表示される
    public function testMenuMasterDisplaysAllMaintenanceLinksForAdmin(): void
    {
        $response = $this->openMenuMasterAsAdmin();

        $this->assertMenuLink($response, 'product_bulk_create.php', '商品一括登録');
        $this->assertMenuLink($response, 'stock_bulk_create.php', '在庫一括登録');
        $this->assertMenuLink($response, 'image_bulk_create.php', '画像一括登録');
        $this->assertMenuLink($response, 'master_bulk_edit.php', 'マスタ登録');
        $this->assertMenuLink($response, 'settings.php', '設定');
        $this->assertMenuLink($response, 'user_list.php', 'ユーザー管理');
        $this->assertMenuLink($response, 'config.php', '機能設定');
        $this->assertMenuLink($response, 'menu.php', 'トップメニュー');
        $this->assertSame(
            [
                'product_bulk_create.php',
                'stock_bulk_create.php',
                'image_bulk_create.php',
                'master_bulk_edit.php',
                'settings.php',
                'user_list.php',
                'config.php',
                'menu.php',
            ],
            $this->extractMenuHrefs($response)
        );
    }

    // perm11 は menu_master 自体には入れるが、配下のメンテナンス権限がないので戻るリンクだけになる
    public function testMenuMasterDisplaysOnlyTopMenuLinkForPerm11(): void
    {
        $this->loginAs('perm11', 'perm1100');

        $response = $this->getClient()->get('menu_master.php');
        $this->assertOk($response);

        $this->assertSame(['menu.php'], $this->extractMenuHrefs($response));
        $this->assertMenuLink($response, 'menu.php', 'トップメニュー');
        $this->assertMenuLinkMissing($response, 'product_bulk_create.php');
        $this->assertMenuLinkMissing($response, 'stock_bulk_create.php');
        $this->assertMenuLinkMissing($response, 'image_bulk_create.php');
        $this->assertMenuLinkMissing($response, 'master_bulk_edit.php');
        $this->assertMenuLinkMissing($response, 'settings.php');
        $this->assertMenuLinkMissing($response, 'user_list.php');
        $this->assertMenuLinkMissing($response, 'config.php');
    }

    private function openMenuMasterAsAdmin(): Response
    {
        $this->loginAsAdmin();

        $response = $this->getClient()->get('menu_master.php');
        $this->assertOk($response);

        return $response;
    }

    // info アラートの案内文を確認する
    private function assertGuideMessage(Response $response, string $expectedMessage): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//div[contains(@class,'alert-info')]/div");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Guide message alert was not found.');
        $this->assertSame($expectedMessage, $this->normalizeText($nodes->item(0)?->textContent ?? ''));
    }

    // 指定した href と見出しを持つメニューカードがあることを確認する
    private function assertMenuLink(Response $response, string $href, string $title): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//div[@id='menu-container']/a[@href='{$href}'][.//h4[normalize-space()='{$title}']]");
        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length, "Expected menu link {$href} with title {$title}.");
    }

    // 指定した href のメニューカードが出ていないことを確認する
    private function assertMenuLinkMissing(Response $response, string $href): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//div[@id='menu-container']/a[@href='{$href}']");
        $this->assertNotFalse($nodes);
        $this->assertSame(0, $nodes->length, "Did not expect menu link {$href}.");
    }

    // メニューコンテナ直下のリンク順を配列で取り出す
    private function extractMenuHrefs(Response $response): array
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//div[@id='menu-container']/a[@href]");
        $this->assertNotFalse($nodes);

        $hrefs = [];
        foreach ($nodes as $node) {
            $hrefs[] = (string) ($node->attributes?->getNamedItem('href')?->nodeValue ?? '');
        }

        return $hrefs;
    }

    private function normalizeText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
    // セッション期限切れ時に認証情報と画面用セッションが破棄されることを確認する
    public function testMenuMasterClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('menu_master.php');
    }
}
