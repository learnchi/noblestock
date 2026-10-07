<?php

declare(strict_types=1);

use Noblestock\Logic\MessageConst;
use Tests\Support\Response;
use Tests\Support\WebClient;
use Tests\Support\WebTestCase;

final class UserConfirmPageTest extends WebTestCase
{
    private const VALID_COMPLEX_PASSWORD = 'Passphrase-2026!OpenAI';

    // ログインセッションをクリア後、この画面にアクセスすると、indexに遷移する
    public function testUserConfirmRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('user_confirm.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // ログインセッションをクリア後、この画面にアクセスすると、認証エラーメッセージ付きでindexに遷移する
    public function testUserConfirmRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('user_confirm.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    // 正常な入力を confirm に送ると、登録確認画面が表示される
    public function testUserConfirmDisplays(): void
    {
        $this->prepareUserConfirmAsAdmin();
        $response = $this->postValidConfirm();
        $this->assertOk($response);
    }

    // この画面に権限があるユーザー[perm18]で200で表示される
    public function testUserConfirmDisplaysForPerm18(): void
    {
        $this->prepareUserConfirmAsPerm18();
        $response = $this->postValidConfirm();
        $this->assertOk($response);
    }

    // この画面に必須の情報が無い場合400エラー
    public function testNonDataPostAccessReturns400(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->post('user_confirm.php', [
            'mode' => 'invalid',
        ]);
        $this->assertSame(400, $response->status);
    }

    // この画面に必須の情報が無い場合400エラー
    public function testNonDataGetAccessReturns400(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->get('user_confirm.php');
        $this->assertSame(400, $response->status);
    }

    // この画面に権限がないユーザーnoauthで403エラーとなる
    public function testUserConfirmReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->post('user_confirm.php', [
            'mode' => 'confirm',
        ]);
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }

    // confirm 後の確認画面では入力内容と権限の○×表示がそのまま見える
    public function testUserConfirmDisplaysPostedValuesAndAuthoritySummary(): void
    {
        $this->prepareUserConfirmAsAdmin();

        $response = $this->postValidConfirm([
            'login_id' => 'codex901',
            'password_hash' => self::VALID_COMPLEX_PASSWORD,
            'user_name' => '確認テスト',
            'furigana' => 'カクニンテスト',
            'email' => 'confirm@example.com',
            'sort_order' => '22',
            'auth_screen' => ['0', '3', '18'],
        ]);

        $this->assertGuideMessage($response, MessageConst::MSG_INF_PRODUCT_007);
        $this->assertInfoCellValue($response, 'ログインID', 'codex901');
        $this->assertInfoCellValue($response, 'パスワード', self::VALID_COMPLEX_PASSWORD);
        $this->assertInfoCellValue($response, 'ユーザー名', '確認テスト');
        $this->assertInfoCellValue($response, 'フリガナ', 'カクニンテスト');
        $this->assertInfoCellValue($response, 'メール', 'confirm@example.com');
        $this->assertInfoCellValue($response, 'ソート順', '22');
        $this->assertInfoCellContains($response, '権限', '○ 商品表示');
        $this->assertInfoCellContains($response, '権限', '○ 商品入庫');
        $this->assertInfoCellContains($response, '権限', '○ ユーザー管理');
        $this->assertInfoCellContains($response, '権限', '× 商品一覧');
        $this->assertElementExists($response, "//button[@formaction='user_create.php' and @name='mode' and @value='back' and normalize-space()='戻る']");
        $this->assertElementExists($response, "//button[@formaction='user_confirm.php' and @name='mode' and @value='insert' and normalize-space()='登録']");
    }

    // 確認画面から戻ると、入力値を保持したまま user_create に戻る
    public function testUserConfirmBackReturnsToUserCreateWithValuesPreserved(): void
    {
        $this->prepareUserConfirmAsAdmin();
        $this->postValidConfirm([
            'login_id' => 'back901',
            'password_hash' => self::VALID_COMPLEX_PASSWORD,
            'user_name' => '戻るテスト',
            'furigana' => 'モドルテスト',
            'email' => 'back@example.com',
            'sort_order' => '25',
            'auth_screen' => ['0', '18'],
        ]);

        $response = $this->getClient()->post('user_create.php', [
            'mode' => 'back',
        ]);
        $this->assertOk($response);

        $this->assertCreateInputValue($response, 'login_id', 'back901');
        $this->assertCreateInputValue($response, 'password_hash', self::VALID_COMPLEX_PASSWORD);
        $this->assertCreateInputValue($response, 'user_name', '戻るテスト');
        $this->assertCreateInputValue($response, 'furigana', 'モドルテスト');
        $this->assertCreateInputValue($response, 'email', 'back@example.com');
        $this->assertCreateInputValue($response, 'sort_order', '25');
        $this->assertCreateAuthorityChecked($response, '0');
        $this->assertCreateAuthorityChecked($response, '18');
        $this->assertCreateAuthorityNotChecked($response, '1');
    }

    // insert すると user_list に遷移し、成功メッセージと登録済みユーザーが見える
    public function testUserConfirmInsertCreatesUserAndRedirectsToUserList(): void
    {
        $this->prepareUserConfirmAsAdmin();
        $this->postValidConfirm([
            'login_id' => 'new901',
            'password_hash' => self::VALID_COMPLEX_PASSWORD,
            'user_name' => '登録テスト',
            'furigana' => 'トウロクテスト',
            'email' => 'new901@example.com',
            'sort_order' => '21',
            'auth_screen' => ['0', '18'],
        ]);

        $response = $this->getClient()->post('user_confirm.php', [
            'mode' => 'insert',
        ]);
        $this->assertInitialStatus($response, 303);
        $this->assertOk($response);

        $this->assertStringContainsString('ユーザーを登録しました ログインID：new901', $response->body);
        $this->assertStringContainsString('new901', $response->body);

        $user = $this->selectUserByLoginId('new901');
        $this->assertSame('登録テスト', (string) ($user['user_name'] ?? ''));
        $this->assertSame('トウロクテスト', (string) ($user['furigana'] ?? ''));
        $this->assertSame('new901@example.com', (string) ($user['email'] ?? ''));
        $this->assertSame('21', (string) ($user['sort_order'] ?? ''));
        $this->assertSame('10000000000000000010', (string) ($user['authority'] ?? ''));
        $this->assertTrue(password_verify(self::VALID_COMPLEX_PASSWORD, (string) ($user['password_hash'] ?? '')));
    }

    // 既存のログインIDで confirm すると user_create に差し戻され、重複エラーが表示される
    public function testUserConfirmDuplicateLoginIdRedirectsBackToCreateWithError(): void
    {
        $this->prepareUserConfirmAsAdmin();

        $response = $this->postValidConfirm([
            'login_id' => 'admin',
            'password_hash' => 'admin000',
            'user_name' => '重複テスト',
            'furigana' => 'チョウフク',
            'email' => 'dup@example.com',
            'sort_order' => '9',
            'auth_screen' => ['0'],
        ]);

        $this->assertCreateInputValue($response, 'login_id', 'admin');
        $this->assertCreateInvalidFeedback($response, 'login_id', MessageConst::MSG_SYS_USER_003);
    }

    // sort_order が整数以外の形式なら user_create に戻してエラーを表示する
    public function testUserConfirmInvalidSortOrderRedirectsBackToCreateWithError(): void
    {
        $this->prepareUserConfirmAsAdmin();

        $response = $this->postValidConfirm([
            'sort_order' => '1.5',
        ]);

        $this->assertCreateInputValue($response, 'sort_order', '1.5');
        $this->assertCreateInvalidFeedback($response, 'sort_order', MessageConst::MSG_VAL_PRODUCT_004);
    }

    private function prepareUserConfirmAsAdmin(): void
    {
        \Tests\Support\TestDatabase::seed();
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->loginAsAdmin();
        $this->getClient()->get('menu.php');
    }

    private function prepareUserConfirmAsPerm18(): void
    {
        \Tests\Support\TestDatabase::seed();
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->loginAs('perm18', 'perm1800');
        $this->getClient()->get('menu.php');
    }

    private function postValidConfirm(array $overrides = []): Response
    {
        $response = $this->getClient()->post('user_confirm.php', array_merge([
            'mode' => 'confirm',
            'id' => '',
            'login_id' => 'confirm901',
            'password_hash' => self::VALID_COMPLEX_PASSWORD,
            'user_name' => '確認ユーザー',
            'furigana' => 'カクニンユーザー',
            'email' => 'confirm901@example.com',
            'sort_order' => '20',
            'auth_screen' => ['0', '1', '18'],
        ], $overrides));
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

    private function assertInfoCellValue(Response $response, string $label, string $expectedValue): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//table//tr[th[normalize-space()='{$label}']]/td");
        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length, "Info cell for {$label} was not found.");
        $this->assertSame($expectedValue, $this->normalizeText($nodes->item(0)?->textContent ?? ''));
    }

    private function assertInfoCellContains(Response $response, string $label, string $expectedFragment): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//table//tr[th[normalize-space()='{$label}']]/td");
        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length, "Info cell for {$label} was not found.");
        $this->assertStringContainsString($expectedFragment, $this->normalizeText($nodes->item(0)?->textContent ?? ''));
    }

    private function assertCreateInputValue(Response $response, string $name, string $expectedValue): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//input[@name='{$name}']");
        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length, "Input {$name} was not found.");
        $this->assertSame($expectedValue, (string) $nodes->item(0)?->attributes?->getNamedItem('value')?->nodeValue);
    }

    private function assertCreateInvalidFeedback(Response $response, string $name, string $expectedMessage): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//input[@name='{$name}' and contains(@class,'is-invalid')]/following-sibling::div[contains(@class,'invalid-feedback')][1]");
        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length, "Invalid feedback for {$name} was not found.");
        $this->assertSame($expectedMessage, $this->normalizeText($nodes->item(0)?->textContent ?? ''));
    }

    private function assertCreateAuthorityChecked(Response $response, string $value): void
    {
        $this->assertXPathCount($response, "//input[@name='auth_screen[]' and @value='{$value}' and @checked]", 1);
    }

    private function assertCreateAuthorityNotChecked(Response $response, string $value): void
    {
        $this->assertXPathCount($response, "//input[@name='auth_screen[]' and @value='{$value}' and @checked]", 0);
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

    private function selectUserByLoginId(string $loginId): array
    {
        $pdo = $this->createTestPdo();
        $stmt = $pdo->prepare(
            'SELECT login_id, password_hash, user_name, furigana, email, sort_order, authority
             FROM users WHERE login_id = :login_id'
        );
        $stmt->execute([
            'login_id' => $loginId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row, "User {$loginId} was not found.");

        return $row;
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
    public function testUserConfirmClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('user_confirm.php');
    }
}
