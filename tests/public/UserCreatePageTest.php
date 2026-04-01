<?php

declare(strict_types=1);

use Noblestock\Logic\MessageConst;
use Tests\Support\Response;
use Tests\Support\WebClient;
use Tests\Support\WebTestCase;

final class UserCreatePageTest extends WebTestCase
{
    // ログインセッションをクリア後、この画面にアクセスすると、indexに遷移する
    public function testUserCreateRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('user_create.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // ログインセッションをクリア後、この画面にアクセスすると、認証エラーメッセージ付きでindexに遷移する
    public function testUserCreateRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('user_create.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    public function testUserCreateDisplays(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->get('user_create.php');
        $this->assertOk($response);
    }

    // この画面に権限があるユーザー[perm18]で200で表示される
    public function testUserCreateDisplaysForPerm18(): void
    {
        $this->loginAs('perm18', 'perm1800');

        $response = $this->getClient()->get('user_create.php');
        $this->assertOk($response);
    }

    // この画面に権限がないユーザーnoauthで403エラーとなる
    public function testUserCreateReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->get('user_create.php');
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }

    // 初期表示では案内文と空の入力欄、次のソート順、既定の権限チェック状態が表示される
    public function testUserCreateShowsDefaultValuesOnInitialDisplay(): void
    {
        $response = $this->openUserCreateAsAdmin();

        $this->assertGuideMessage($response, MessageConst::MSG_INF_USER_001);
        $this->assertInputValue($response, 'login_id', '');
        $this->assertInputValue($response, 'password_hash', '');
        $this->assertInputValue($response, 'user_name', '');
        $this->assertInputValue($response, 'email', '');
        $this->assertInputValue($response, 'sort_order', '121');
        $this->assertCheckedAuthorityCount($response, 18);
        $this->assertAuthorityCheckboxChecked($response, '0');
        $this->assertAuthorityCheckboxChecked($response, '17');
        $this->assertAuthorityCheckboxNotChecked($response, '18');
        $this->assertAuthorityCheckboxNotChecked($response, '19');
    }

    // confirm でバリデーションエラーになると user_create へ戻り、入力値とエラーメッセージが保持される
    public function testUserCreateDisplaysValidationErrorsAndRestoresInputValues(): void
    {
        $this->prepareUserCreateAsAdmin();

        $response = $this->getClient()->post('user_confirm.php', [
            'mode' => 'confirm',
            'id' => '',
            'login_id' => 'ab',
            'password_hash' => 'Abc1234',
            'user_name' => '',
            'furigana' => 'テストフリガナ',
            'email' => 'invalid-mail',
            'sort_order' => 'abc',
            'auth_screen' => ['0', '18'],
        ]);
        $this->assertOk($response);

        $this->assertInputValue($response, 'login_id', 'ab');
        $this->assertInputValue($response, 'password_hash', 'Abc1234');
        $this->assertInputValue($response, 'furigana', 'テストフリガナ');
        $this->assertInputValue($response, 'email', 'invalid-mail');
        $this->assertInputValue($response, 'sort_order', 'abc');
        $this->assertInvalidFeedback($response, 'login_id', MessageConst::MSG_SYS_USER_005);
        $this->assertInvalidFeedback($response, 'password_hash', MessageConst::MSG_SYS_USER_006);
        $this->assertInvalidFeedback($response, 'user_name', MessageConst::MSG_VAL_PRODUCT_003);
        $this->assertInvalidFeedback($response, 'email', MessageConst::MSG_SYS_USER_007);
        $this->assertInvalidFeedback($response, 'sort_order', MessageConst::MSG_VAL_PRODUCT_004);
        $this->assertAuthorityCheckboxChecked($response, '0');
        $this->assertAuthorityCheckboxChecked($response, '18');
        $this->assertAuthorityCheckboxNotChecked($response, '1');
    }

    // reset を押すと入力途中の userData とバリデーションエラーが消え、初期値へ戻る
    public function testUserCreateResetClearsSessionValuesAndReturnsToDefaults(): void
    {
        $this->prepareUserCreateAsAdmin();

        $this->getClient()->post('user_confirm.php', [
            'mode' => 'confirm',
            'id' => '',
            'login_id' => 'ab',
            'password_hash' => 'Abc1234',
            'user_name' => '',
            'furigana' => 'テストフリガナ',
            'email' => 'invalid-mail',
            'sort_order' => 'abc',
            'auth_screen' => ['0', '18'],
        ]);

        $response = $this->getClient()->post('user_create.php', [
            'mode' => 'reset',
            'pos' => '123',
        ]);
        $this->assertOk($response);

        $this->assertInputValue($response, 'login_id', '');
        $this->assertInputValue($response, 'password_hash', '');
        $this->assertInputValue($response, 'user_name', '');
        $this->assertInputValue($response, 'email', '');
        $this->assertInputValue($response, 'sort_order', '121');
        $this->assertXPathCount($response, "//input[contains(@class,'is-invalid')]", 0);
        $this->assertCheckedAuthorityCount($response, 18);
    }

    private function openUserCreateAsAdmin(): Response
    {
        $this->prepareUserCreateAsAdmin();

        $response = $this->getClient()->get('user_create.php');
        $this->assertOk($response);

        return $response;
    }

    private function prepareUserCreateAsAdmin(): void
    {
        \Tests\Support\TestDatabase::seed();
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->loginAsAdmin();
        $this->getClient()->get('menu.php');
    }

    private function assertGuideMessage(Response $response, string $expectedMessage): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//div[contains(@class,'alert-info')]/div");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Guide message alert was not found.');
        $this->assertSame($expectedMessage, $this->normalizeText($nodes->item(0)?->textContent ?? ''));
    }

    private function assertInputValue(Response $response, string $name, string $expectedValue): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//input[@name='{$name}']");
        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length, "Input {$name} was not found.");
        $this->assertSame($expectedValue, (string) $nodes->item(0)?->attributes?->getNamedItem('value')?->nodeValue);
    }

    private function assertInvalidFeedback(Response $response, string $name, string $expectedMessage): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//input[@name='{$name}' and contains(@class,'is-invalid')]/following-sibling::div[contains(@class,'invalid-feedback')][1]");
        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length, "Invalid feedback for {$name} was not found.");
        $this->assertSame($expectedMessage, $this->normalizeText($nodes->item(0)?->textContent ?? ''));
    }

    private function assertCheckedAuthorityCount(Response $response, int $expectedCount): void
    {
        $this->assertXPathCount($response, "//input[@name='auth_screen[]' and @checked]", $expectedCount);
    }

    private function assertAuthorityCheckboxChecked(Response $response, string $value): void
    {
        $this->assertXPathCount($response, "//input[@name='auth_screen[]' and @value='{$value}' and @checked]", 1);
    }

    private function assertAuthorityCheckboxNotChecked(Response $response, string $value): void
    {
        $this->assertXPathCount($response, "//input[@name='auth_screen[]' and @value='{$value}' and @checked]", 0);
    }

    private function assertXPathCount(Response $response, string $xpathExpression, int $expectedCount): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query($xpathExpression);
        $this->assertNotFalse($nodes, "Invalid XPath: {$xpathExpression}");
        $this->assertSame($expectedCount, $nodes->length, "Unexpected node count for XPath: {$xpathExpression}");
    }

    private function normalizeText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
    // セッション期限切れ時に認証情報と画面用セッションが破棄されることを確認する
    public function testUserCreateClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('user_create.php');
    }
}
