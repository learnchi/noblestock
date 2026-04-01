<?php

declare(strict_types=1);

use Noblestock\Logic\LogicConst;
use Tests\Support\Response;
use Tests\Support\TestDatabase;
use Tests\Support\WebClient;
use Tests\Support\WebTestCase;

final class UserEditInfoRenderTest extends WebTestCase
{
    // user_create では user_edit_info のパスワード欄が必須で、権限チェック欄も全件描画されることを確認する
    public function testUserEditInfoRequiresPasswordOnCreateScreen(): void
    {
        $this->prepareUserCreateAsAdmin();

        $response = $this->getClient()->get('user_create.php');
        $this->assertOk($response);

        $password = $this->getSingleElement($response, "//form[@action='user_confirm.php']//input[@name='password_hash']");
        $this->assertTrue($password->hasAttribute('required'));
        $this->assertSame('128', $password->getAttribute('maxlength'));
        $this->assertSame('8', $password->getAttribute('minlength'));
        $this->assertSame('', $password->getAttribute('value'));
        $this->assertXPathCount($response, "//form[@action='user_confirm.php']//input[@name='auth_screen[]']", LogicConst::AUTH_BITS);
        $this->assertXPathCount($response, "//form[@action='user_confirm.php']//div[contains(@class,'invalid-feedback')]", 7);
    }

    // user_edit では既存パスワードを再表示せず、権限チェックだけ元データどおり復元されることを確認する
    public function testUserEditInfoLeavesPasswordOptionalOnEditScreen(): void
    {
        $this->prepareUserEditAsAdmin();

        $response = $this->getClient()->post('user_edit.php', [
            'id' => '2',
        ]);
        $this->assertOk($response);

        $id = $this->getSingleElement($response, "//form[@action='user_edit_confirm.php']//input[@name='id']");
        $password = $this->getSingleElement($response, "//form[@action='user_edit_confirm.php']//input[@name='password_hash']");

        $this->assertSame('2', $id->getAttribute('value'));
        $this->assertFalse($password->hasAttribute('required'));
        $this->assertSame('128', $password->getAttribute('maxlength'));
        $this->assertSame('8', $password->getAttribute('minlength'));
        $this->assertSame('', $password->getAttribute('value'));
        $this->assertXPathCount($response, "//form[@action='user_edit_confirm.php']//input[@name='auth_screen[]' and @checked]", 18);
        $this->assertXPathCount($response, "//form[@action='user_edit_confirm.php']//input[@name='auth_screen[]' and @value='18' and @checked]", 0);
    }

    private function prepareUserCreateAsAdmin(): void
    {
        TestDatabase::seed();
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->loginAsAdmin();
        $this->getClient()->get('menu.php');
    }

    private function prepareUserEditAsAdmin(): void
    {
        TestDatabase::seed();
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->loginAsAdmin();
        $this->getClient()->get('menu.php');
    }

    private function assertXPathCount(Response $response, string $xpathExpression, int $expectedCount): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query($xpathExpression);
        $this->assertNotFalse($nodes, "Invalid XPath: {$xpathExpression}");
        $this->assertSame($expectedCount, $nodes->length, "Unexpected node count for XPath: {$xpathExpression}");
    }

    private function getSingleElement(Response $response, string $xpathExpression): DOMElement
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query($xpathExpression);
        $this->assertNotFalse($nodes, "Invalid XPath: {$xpathExpression}");
        $this->assertSame(1, $nodes->length, "Expected exactly one element for XPath: {$xpathExpression}");
        $node = $nodes->item(0);
        $this->assertInstanceOf(DOMElement::class, $node);

        return $node;
    }
}
