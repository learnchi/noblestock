<?php

declare(strict_types=1);

use Tests\Support\Response;
use Tests\Support\WebTestCase;

final class IncNavRenderTest extends WebTestCase
{
    // ログイン後の画面では、タイトル・ユーザーリンク・ログアウトリンクを含む共通ナビが描画される
    public function testMenuMasterPageIncludesCommonNavigationLinks(): void
    {
        $response = $this->openMenuMasterAsAdmin();

        $xpath = new DOMXPath($response->dom());

        $brandNodes = $xpath->query("//nav[contains(@class,'navbar')]//div[contains(@class,'navbar-brand')]");
        $this->assertNotFalse($brandNodes);
        $this->assertGreaterThan(0, $brandNodes->length, 'Navbar brand was not found.');
        $this->assertStringContainsString('マスタメンテナンス', $brandNodes->item(0)?->textContent ?? '');

        $logoNodes = $xpath->query("//nav[contains(@class,'navbar')]//img[@alt='Noble Stock']");
        $this->assertNotFalse($logoNodes);
        $this->assertSame(1, $logoNodes->length, 'Navbar logo was not found.');

        $passwordLinkNodes = $xpath->query("//nav[contains(@class,'navbar')]//a[@href='password_edit.php']");
        $this->assertNotFalse($passwordLinkNodes);
        $this->assertSame(1, $passwordLinkNodes->length, 'Password edit link was not found.');
        $this->assertStringContainsString('管理者がう', $passwordLinkNodes->item(0)?->textContent ?? '');
        $this->assertStringContainsString('bi-person-circle', $response->body);

        $logoutLinkNodes = $xpath->query("//nav[contains(@class,'navbar')]//a[@href='index.php']");
        $this->assertNotFalse($logoutLinkNodes);
        $this->assertSame(1, $logoutLinkNodes->length, 'Logout link was not found.');
        $this->assertStringContainsString('ログアウト', $logoutLinkNodes->item(0)?->textContent ?? '');
        $this->assertStringContainsString('bi-door-open', $response->body);
    }

    // 表示ユーザー名はログインユーザーに応じて切り替わる
    public function testPasswordEditPageNavigationShowsCurrentUserName(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->get('password_edit.php');
        $this->assertOk($response);

        $this->assertStringContainsString('password_edit.php', $response->body);
        $this->assertStringContainsString('No Auth', $response->body);
        $this->assertStringContainsString('href="index.php"', $response->body);
    }

    private function openMenuMasterAsAdmin(): Response
    {
        $this->loginAsAdmin();

        $response = $this->getClient()->get('menu_master.php');
        $this->assertOk($response);

        return $response;
    }
}
