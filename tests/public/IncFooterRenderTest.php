<?php

declare(strict_types=1);

use Tests\Support\Response;
use Tests\Support\WebTestCase;

final class IncFooterRenderTest extends WebTestCase
{
    // footer を使う画面では、共通の著作権表示が最下部に出る
    public function testMenuMasterPageIncludesCommonFooterText(): void
    {
        $response = $this->openMenuMasterAsAdmin();

        $xpath = new DOMXPath($response->dom());
        $footerNodes = $xpath->query('//footer');
        $this->assertNotFalse($footerNodes);
        $this->assertSame(1, $footerNodes->length, 'Footer element was not found.');

        $footerText = preg_replace('/\s+/u', ' ', trim($footerNodes->item(0)?->textContent ?? ''));
        $this->assertSame('NobleStock ver 0.1 © Studio GAU', $footerText);
    }

    // 別画面でも同じ footer が使われていることを確認する
    public function testPasswordEditPageIncludesCommonFooterText(): void
    {
        $this->loginAsAdmin();

        $response = $this->getClient()->get('password_edit.php');
        $this->assertOk($response);
        $this->assertStringContainsString('NobleStock ver 0.1', $response->body);
        $this->assertStringContainsString('Studio GAU', $response->body);
    }

    private function openMenuMasterAsAdmin(): Response
    {
        $this->loginAsAdmin();

        $response = $this->getClient()->get('menu_master.php');
        $this->assertOk($response);

        return $response;
    }
}
