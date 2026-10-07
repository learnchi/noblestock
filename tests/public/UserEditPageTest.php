<?php

declare(strict_types=1);

use Noblestock\Logic\MessageConst;
use Tests\Support\Response;
use Tests\Support\WebClient;
use Tests\Support\WebTestCase;

final class UserEditPageTest extends WebTestCase
{
    // ログインセッションをクリア後、この画面にアクセスすると、indexに遷移する
    public function testUserEditRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('user_edit.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertInitialStatus($response, 302);
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // ログインセッションをクリア後、この画面にアクセスすると、認証エラーメッセージ付きでindexに遷移する
    public function testUserEditRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('user_edit.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertInitialStatus($response, 302);
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    public function testUserEditDisplays(): void
    {
        $this->prepareUserEditAsAdmin();
        $response = $this->openUserEdit('2');
        $this->assertOk($response);
    }

    // 初回 POST で選んだユーザーの情報が表示され、パスワード欄だけは空で表示される
    public function testUserEditDisplaysSelectedUserValuesOnInitialPost(): void
    {
        $this->prepareUserEditAsAdmin();

        $response = $this->openUserEdit('2');

        $this->assertGuideMessage($response, MessageConst::MSG_INF_USER_002);
        $this->assertInputValue($response, 'id', '2');
        $this->assertInputValue($response, 'login_id', 'user1');
        $this->assertInputValue($response, 'password_hash', '');
        $this->assertInputValue($response, 'user_name', 'ユーザ1');
        $this->assertInputValue($response, 'furigana', '');
        $this->assertInputValue($response, 'email', '');
        $this->assertInputValue($response, 'sort_order', '3');
        $this->assertCheckedAuthorityCount($response, 18);
        $this->assertAuthorityCheckboxChecked($response, '0');
        $this->assertAuthorityCheckboxChecked($response, '17');
        $this->assertAuthorityCheckboxNotChecked($response, '18');
        $this->assertElementExists($response, "//button[@name='mode' and @value='confirm' and normalize-space()='更新']");
        $this->assertElementExists($response, "//button[@name='mode' and @value='reset' and normalize-space()='リセット']");
        $this->assertElementExists($response, "//form[@id='delForm']//input[@name='mode' and @value='del']");
    }

    // 一度 POST で開いた後の GET でも、同じユーザー情報をセッションから再表示できる
    public function testUserEditDisplaysSameUserOnGetAfterInitialPost(): void
    {
        $this->prepareUserEditAsAdmin();
        $this->openUserEdit('2');

        $response = $this->getClient()->get('user_edit.php');
        $this->assertOk($response);

        $this->assertInputValue($response, 'id', '2');
        $this->assertInputValue($response, 'login_id', 'user1');
        $this->assertInputValue($response, 'user_name', 'ユーザ1');
        $this->assertInputValue($response, 'sort_order', '3');
    }

    // user_edit_confirm でバリデーションエラーになると、入力値とエラーメッセージを保持して戻る
    public function testUserEditDisplaysValidationErrorsAndRestoresPostedValues(): void
    {
        $this->prepareUserEditAsAdmin();
        $editResponse = $this->openUserEdit('2');

        $response = $this->getClient()->post('user_edit_confirm.php', [
            'mode' => 'confirm',
            'id' => '2',
            'login_id' => 'ab',
            'password_hash' => 'Abc1234',
            'user_name' => '',
            'furigana' => 'テストフリガナ',
            'email' => 'invalid-mail',
            'sort_order' => 'abc',
            'auth_screen' => ['0', '18'],
        ] + $this->extractCsrfPostData($editResponse));
        $this->assertOk($response);

        $this->assertInputValue($response, 'login_id', 'ab');
        $this->assertInputValue($response, 'password_hash', '');
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

    // 既存のログインIDへ変えようとすると、重複エラーで入力画面へ戻る
    public function testUserEditDuplicateLoginIdShowsValidationError(): void
    {
        $this->prepareUserEditAsAdmin();
        $editResponse = $this->openUserEdit('2');

        $response = $this->getClient()->post('user_edit_confirm.php', [
            'mode' => 'confirm',
            'id' => '2',
            'login_id' => 'admin',
            'password_hash' => '',
            'user_name' => '重複テスト',
            'furigana' => '',
            'email' => '',
            'sort_order' => '3',
            'auth_screen' => ['0'],
        ] + $this->extractCsrfPostData($editResponse));
        $this->assertOk($response);

        $this->assertInputValue($response, 'login_id', 'admin');
        $this->assertInvalidFeedback($response, 'login_id', MessageConst::MSG_SYS_USER_003);
    }

    // reset を押すと編集途中の値が消え、DB にある元のユーザー情報へ戻る
    public function testUserEditResetRestoresOriginalDatabaseValues(): void
    {
        $this->prepareUserEditAsAdmin();
        $editResponse = $this->openUserEdit('2');

        $confirmResponse = $this->getClient()->post('user_edit_confirm.php', [
            'mode' => 'confirm',
            'id' => '2',
            'login_id' => 'changed1',
            'password_hash' => 'pass1234',
            'user_name' => '変更後ユーザー',
            'furigana' => 'ヘンコウゴユーザー',
            'email' => 'changed@example.com',
            'sort_order' => '99',
            'auth_screen' => ['18'],
        ] + $this->extractCsrfPostData($editResponse));

        $response = $this->getClient()->post('user_edit.php', [
            'mode' => 'reset',
        ] + $this->extractCsrfPostData($confirmResponse));
        $this->assertOk($response);

        $this->assertInputValue($response, 'id', '2');
        $this->assertInputValue($response, 'login_id', 'user1');
        $this->assertInputValue($response, 'password_hash', '');
        $this->assertInputValue($response, 'user_name', 'ユーザ1');
        $this->assertInputValue($response, 'furigana', '');
        $this->assertInputValue($response, 'email', '');
        $this->assertInputValue($response, 'sort_order', '3');
        $this->assertXPathCount($response, "//input[contains(@class,'is-invalid')]", 0);
        $this->assertCheckedAuthorityCount($response, 18);
        $this->assertAuthorityCheckboxNotChecked($response, '18');
    }

    // delete を押すと対象ユーザーが削除され、user_list へ成功メッセージ付きで戻る
    public function testUserEditDeleteRemovesTargetUserAndRedirectsToUserList(): void
    {
        $this->prepareUserEditAsAdmin();
        $editResponse = $this->openUserEdit('120');

        $response = $this->getClient()->post('user_edit.php', [
            'mode' => 'del',
            'id' => '120',
        ] + $this->extractCsrfPostData($editResponse));
        $this->assertOk($response);

        $this->assertStringContainsString('ユーザーを削除しました ログインID：perm19', $response->body);
        $this->assertNull($this->selectUserByIdNullable(120));
    }

    // この画面に必須の情報が無い場合400エラー
    public function testNonDataPostAccessReturns400(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->post('user_edit.php', []);
        $this->assertSame(400, $response->status);
    }

    // この画面に必須の情報が無い場合400エラー
    public function testNonDataGetAccessReturns400(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->get('user_edit.php');
        $this->assertSame(400, $response->status);
    }

    // この画面に権限があるユーザー[perm18]で200で表示される
    public function testUserEditDisplaysForPerm18(): void
    {
        $this->prepareUserEditAsPerm18();
        $response = $this->openUserEdit('2');
        $this->assertOk($response);
    }

    // この画面に権限がないユーザーnoauthで403エラーとなる
    public function testUserEditReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->post('user_edit.php', [
            'id' => '2',
        ]);
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }

    private function prepareUserEditAsAdmin(): void
    {
        \Tests\Support\TestDatabase::seed();
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->loginAsAdmin();
        $this->getClient()->get('menu.php');
    }

    private function prepareUserEditAsPerm18(): void
    {
        \Tests\Support\TestDatabase::seed();
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->loginAs('perm18', 'perm1800');
        $this->getClient()->get('menu.php');
    }

    private function openUserEdit(string $id): Response
    {
        $sourceResponse = $this->getClient()->get('user_list.php');
        $this->assertOk($sourceResponse);

        $response = $this->getClient()->post('user_edit.php', [
            'id' => $id,
        ] + $this->extractCsrfPostData($sourceResponse));
        $this->assertInitialStatus($response, 303);
        $this->assertOk($response);

        return $response;
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
        $nodes = $xpath->query("//form[@action='user_edit_confirm.php']//input[@name='{$name}']");
        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length, "Input {$name} was not found.");
        $this->assertSame($expectedValue, (string) $nodes->item(0)?->attributes?->getNamedItem('value')?->nodeValue);
    }

    private function assertInvalidFeedback(Response $response, string $name, string $expectedMessage): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//form[@action='user_edit_confirm.php']//input[@name='{$name}' and contains(@class,'is-invalid')]/following-sibling::div[contains(@class,'invalid-feedback')][1]");
        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length, "Invalid feedback for {$name} was not found.");
        $this->assertSame($expectedMessage, $this->normalizeText($nodes->item(0)?->textContent ?? ''));
    }

    private function assertCheckedAuthorityCount(Response $response, int $expectedCount): void
    {
        $this->assertXPathCount($response, "//form[@action='user_edit_confirm.php']//input[@name='auth_screen[]' and @checked]", $expectedCount);
    }

    private function assertAuthorityCheckboxChecked(Response $response, string $value): void
    {
        $this->assertXPathCount($response, "//form[@action='user_edit_confirm.php']//input[@name='auth_screen[]' and @value='{$value}' and @checked]", 1);
    }

    private function assertAuthorityCheckboxNotChecked(Response $response, string $value): void
    {
        $this->assertXPathCount($response, "//form[@action='user_edit_confirm.php']//input[@name='auth_screen[]' and @value='{$value}' and @checked]", 0);
    }

    private function assertElementExists(Response $response, string $xpathExpression): void
    {
        $this->assertXPathCount($response, $xpathExpression, 1);
    }

    private function assertXPathCount(Response $response, string $xpathExpression, int $expectedCount): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query($xpathExpression);
        $this->assertNotFalse($nodes, "Invalid XPath: {$xpathExpression}");
        $this->assertSame($expectedCount, $nodes->length, "Unexpected node count for XPath: {$xpathExpression}");
    }

    private function selectUserByIdNullable(int $id): ?array
    {
        $pdo = $this->createTestPdo();
        $stmt = $pdo->prepare(
            'SELECT id, login_id, user_name, furigana, email, sort_order, authority
             FROM users WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function createTestPdo(): PDO
    {
        $configPath = dirname(__DIR__, 2) . '/config/dbconfig.ini';
        $config = parse_ini_file($configPath);

        $this->assertIsArray($config, 'Failed to read dbconfig.ini.');
        foreach (['dbhost', 'dbuser', 'dbpass', 'dbname'] as $requiredKey) {
            $this->assertArrayHasKey($requiredKey, $config, "Missing {$requiredKey} in dbconfig.ini.");
        }

        return new PDO(
            sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                (string) $config['dbhost'],
                (string) $config['dbname']
            ),
            (string) $config['dbuser'],
            (string) $config['dbpass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private function normalizeText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
    // セッション期限切れ時に認証情報と画面用セッションが破棄されることを確認する
    public function testUserEditClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('user_edit.php');
    }
}
