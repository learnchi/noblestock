<?php

declare(strict_types=1);

use Noblestock\Logic\MessageConst;
use Tests\Support\Response;
use Tests\Support\WebClient;
use Tests\Support\WebTestCase;

final class UserEditConfirmPageTest extends WebTestCase
{
    private const VALID_COMPLEX_PASSWORD = 'Passphrase-2026!OpenAI';

    // ログインセッションをクリア後、この画面にアクセスすると、indexに遷移する
    public function testUserEditConfirmRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('user_edit_confirm.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // ログインセッションをクリア後、この画面にアクセスすると、認証エラーメッセージ付きでindexに遷移する
    public function testUserEditConfirmRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('user_edit_confirm.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    // 正常な入力を confirm に送ると、更新確認画面が表示される
    public function testUserEditConfirmDisplays(): void
    {
        $this->prepareUserEditConfirmAsAdmin();
        $response = $this->postValidConfirm();
        $this->assertOk($response);
    }

    // confirm 後の確認画面では編集後の入力内容と権限の○×表示がそのまま見える
    public function testUserEditConfirmDisplaysPostedValuesAndAuthoritySummary(): void
    {
        $this->prepareUserEditConfirmAsAdmin();

        $response = $this->postValidConfirm([
            'id' => '3',
            'login_id' => 'user2upd',
            'password_hash' => self::VALID_COMPLEX_PASSWORD,
            'user_name' => '更新確認テスト',
            'furigana' => 'コウシンカクニン',
            'email' => 'edit-confirm@example.com',
            'sort_order' => '44',
            'auth_screen' => ['0', '4', '18'],
        ]);

        $this->assertGuideMessage($response, MessageConst::MSG_INF_PRODUCT_007);
        $this->assertInfoCellValue($response, 'ログインID', 'user2upd');
        $this->assertInfoCellValue($response, 'パスワード', self::VALID_COMPLEX_PASSWORD);
        $this->assertInfoCellValue($response, 'ユーザー名', '更新確認テスト');
        $this->assertInfoCellValue($response, 'フリガナ', 'コウシンカクニン');
        $this->assertInfoCellValue($response, 'メール', 'edit-confirm@example.com');
        $this->assertInfoCellValue($response, 'ソート順', '44');
        $this->assertInfoCellContains($response, '権限', '○ 商品表示');
        $this->assertInfoCellContains($response, '権限', '○ 商品出庫');
        $this->assertInfoCellContains($response, '権限', '○ ユーザー管理');
        $this->assertInfoCellContains($response, '権限', '× 商品一覧');
        $this->assertElementExists($response, "//button[@formaction='user_edit.php' and @name='mode' and @value='back' and normalize-space()='戻る']");
        $this->assertElementExists($response, "//button[@formaction='user_edit_confirm.php' and @name='mode' and @value='update' and normalize-space()='登録']");
    }

    // 確認画面から戻ると、入力値を保持したまま user_edit に戻る
    public function testUserEditConfirmBackReturnsToUserEditWithValuesPreserved(): void
    {
        $this->prepareUserEditConfirmAsAdmin();
        $confirmResponse = $this->postValidConfirm([
            'id' => '3',
            'login_id' => 'backedit1',
            'password_hash' => self::VALID_COMPLEX_PASSWORD,
            'user_name' => '戻る更新テスト',
            'furigana' => 'モドルコウシン',
            'email' => 'back-edit@example.com',
            'sort_order' => '55',
            'auth_screen' => ['0', '18'],
        ]);

        $response = $this->getClient()->post('user_edit.php', [
            'mode' => 'back',
        ] + $this->extractCsrfPostData($confirmResponse));
        $this->assertOk($response);

        $this->assertEditInputValue($response, 'login_id', 'backedit1');
        $this->assertEditInputValue($response, 'password_hash', '');
        $this->assertEditInputValue($response, 'user_name', '戻る更新テスト');
        $this->assertEditInputValue($response, 'furigana', 'モドルコウシン');
        $this->assertEditInputValue($response, 'email', 'back-edit@example.com');
        $this->assertEditInputValue($response, 'sort_order', '55');
        $this->assertEditAuthorityChecked($response, '0');
        $this->assertEditAuthorityChecked($response, '18');
        $this->assertEditAuthorityNotChecked($response, '1');
    }

    // update すると user_list に遷移し、成功メッセージと更新済みユーザー情報がDBに反映される
    public function testUserEditConfirmUpdatePersistsUserAndRedirectsToUserList(): void
    {
        $this->prepareUserEditConfirmAsAdmin();
        $this->postValidConfirm([
            'id' => '3',
            'login_id' => 'user2upd',
            'password_hash' => self::VALID_COMPLEX_PASSWORD,
            'user_name' => '更新テスト',
            'furigana' => 'コウシンテスト',
            'email' => 'user2upd@example.com',
            'sort_order' => '14',
            'auth_screen' => ['0', '18'],
        ]);

        $response = $this->getClient()->post('user_edit_confirm.php', [
            'mode' => 'update',
        ]);
        $this->assertOk($response);

        $this->assertStringContainsString('ユーザーを更新しました ログインID：user2upd', $response->body);

        $user = $this->selectUserById(3);
        $this->assertSame('user2upd', (string) ($user['login_id'] ?? ''));
        $this->assertSame('更新テスト', (string) ($user['user_name'] ?? ''));
        $this->assertSame('コウシンテスト', (string) ($user['furigana'] ?? ''));
        $this->assertSame('user2upd@example.com', (string) ($user['email'] ?? ''));
        $this->assertSame('14', (string) ($user['sort_order'] ?? ''));
        $this->assertSame('10000000000000000010', (string) ($user['authority'] ?? ''));
        $this->assertTrue(password_verify(self::VALID_COMPLEX_PASSWORD, (string) ($user['password_hash'] ?? '')));
    }

    // 既存の別ユーザー login_id へ変えようとすると user_edit に差し戻され、重複エラーが出る
    public function testUserEditConfirmDuplicateLoginIdRedirectsBackToEditWithError(): void
    {
        $this->prepareUserEditConfirmAsAdmin();

        $response = $this->postValidConfirm([
            'id' => '3',
            'login_id' => 'admin',
            'password_hash' => '',
            'user_name' => '重複更新テスト',
            'furigana' => '',
            'email' => '',
            'sort_order' => '4',
            'auth_screen' => ['0'],
        ]);

        $this->assertEditInputValue($response, 'login_id', 'admin');
        $this->assertEditInvalidFeedback($response, 'login_id', MessageConst::MSG_SYS_USER_003);
    }

    // sort_order が整数以外の形式なら user_edit に戻してエラーを表示する
    public function testUserEditConfirmInvalidSortOrderRedirectsBackToEditWithError(): void
    {
        $this->prepareUserEditConfirmAsAdmin();

        $response = $this->postValidConfirm([
            'sort_order' => '1.5',
        ]);

        $this->assertEditInputValue($response, 'sort_order', '1.5');
        $this->assertEditInvalidFeedback($response, 'sort_order', MessageConst::MSG_VAL_PRODUCT_004);
    }

    // この画面に権限があるユーザー[perm18]で200で表示される
    public function testUserEditConfirmDisplaysForPerm18(): void
    {
        $this->prepareUserEditConfirmAsPerm18();
        $response = $this->postValidConfirm();
        $this->assertOk($response);
    }
    
    // この画面に必須の情報が無い場合400エラー
    public function testNonDataPostAccessReturns400(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->post('user_edit_confirm.php', [
            'mode' => 'invalid',
        ]);
        $this->assertSame(400, $response->status);
    }

    // この画面に必須の情報が無い場合400エラー
    public function testNonDataGetAccessReturns400(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->get('user_edit_confirm.php');
        $this->assertSame(400, $response->status);
    }

    // この画面に権限がないユーザーnoauthで403エラーとなる
    public function testUserEditConfirmReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->post('user_edit_confirm.php', [
            'mode' => 'confirm',
        ]);
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }

    private function prepareUserEditConfirmAsAdmin(): void
    {
        \Tests\Support\TestDatabase::seed();
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->loginAsAdmin();
        $this->getClient()->get('menu.php');
        $this->getClient()->post('user_edit.php', [
            'id' => '3',
        ]);
    }

    private function prepareUserEditConfirmAsPerm18(): void
    {
        \Tests\Support\TestDatabase::seed();
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->loginAs('perm18', 'perm1800');
        $this->getClient()->get('menu.php');
        $this->getClient()->post('user_edit.php', [
            'id' => '3',
        ]);
    }

    private function postValidConfirm(array $overrides = []): Response
    {
        $response = $this->getClient()->post('user_edit_confirm.php', array_merge([
            'id' => '3',
            'login_id' => 'user2',
            'password_hash' => '',
            'user_name' => 'ユーザ2',
            'furigana' => '',
            'email' => '',
            'sort_order' => '4',
            'auth_screen' => array_map('strval', range(0, 17)),
            'mode' => 'confirm',
        ], $overrides));
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

    private function assertEditInputValue(Response $response, string $name, string $expectedValue): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//form[@action='user_edit_confirm.php']//input[@name='{$name}']");
        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length, "Input {$name} was not found.");
        $this->assertSame($expectedValue, (string) $nodes->item(0)?->attributes?->getNamedItem('value')?->nodeValue);
    }

    private function assertEditInvalidFeedback(Response $response, string $name, string $expectedMessage): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//form[@action='user_edit_confirm.php']//input[@name='{$name}' and contains(@class,'is-invalid')]/following-sibling::div[contains(@class,'invalid-feedback')][1]");
        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length, "Invalid feedback for {$name} was not found.");
        $this->assertSame($expectedMessage, $this->normalizeText($nodes->item(0)?->textContent ?? ''));
    }

    private function assertEditAuthorityChecked(Response $response, string $value): void
    {
        $this->assertXPathCount($response, "//form[@action='user_edit_confirm.php']//input[@name='auth_screen[]' and @value='{$value}' and @checked]", 1);
    }

    private function assertEditAuthorityNotChecked(Response $response, string $value): void
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

    private function selectUserById(int $id): array
    {
        $pdo = $this->createTestPdo();
        $stmt = $pdo->prepare(
            'SELECT id, login_id, password_hash, user_name, furigana, email, sort_order, authority
             FROM users WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row, "User id {$id} was not found.");

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
    public function testUserEditConfirmClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('user_edit_confirm.php');
    }
}
