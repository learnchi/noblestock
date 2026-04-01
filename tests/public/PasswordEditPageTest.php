<?php

declare(strict_types=1);

use Noblestock\Logic\MessageConst;
use Studiogau\Chandra\Support\Utility;
use Tests\Support\Response;
use Tests\Support\TestDatabase;
use Tests\Support\WebClient;
use Tests\Support\WebTestCase;

final class PasswordEditPageTest extends WebTestCase
{
    private const VALID_COMPLEX_PASSWORD = 'Passphrase-2026!OpenAI';

    // ログインしていない状態では index.php に戻される
    public function testPasswordEditRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('password_edit.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // セッション切れでも index.php に戻り、案内メッセージが出る
    public function testPasswordEditRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('password_edit.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    // この画面はログイン済みなら全ユーザー共通で使えるので、noauth でも表示できる
    public function testPasswordEditDisplaysForNoauth(): void
    {
        $this->preparePasswordEditAs('noauth', 'noauth00');

        $response = $this->getClient()->get('password_edit.php');
        $this->assertOk($response);
        $this->assertInputValue($response, 'user_id', 'noauth');
    }

    // 初期表示では案内文が出て、ログインIDだけが表示される
    public function testPasswordEditDisplaysGuideMessageAndCurrentUserId(): void
    {
        $response = $this->openPasswordEditAsAdmin();

        $this->assertInputValue($response, 'user_id', 'admin');
        $this->assertInputValue($response, 'old_pass', '');
        $this->assertInputValue($response, 'new_pass', '');
        $this->assertInputAttribute($response, 'old_pass', 'maxlength', '128');
        $this->assertInputAttribute($response, 'new_pass', 'maxlength', '128');
        $this->assertInputAttribute($response, 'new_pass', 'minlength', '8');
        $this->assertFormUsesBootstrapValidation($response);
        $this->assertSame(64, strlen($this->extractCsrfToken($response)));
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_004, $response->body);
        $this->assertStringContainsString('data-confirm="' . MessageConst::MSG_CNF_AUTH_007 . '"', $response->body);
    }

    // 現在パスワードが正しければ更新でき、index.php に戻って成功メッセージが出る
    public function testPasswordEditUpdatesPasswordAndRedirectsToIndex(): void
    {
        $this->preparePasswordEditAsAdmin();
        $beforeHash = $this->readPasswordHash('admin');
        $csrfFields = $this->fetchPasswordEditCsrfFields();

        $response = $this->getClient()->post('password_edit.php', [
            'mode' => 'update',
            'old_pass' => 'admin000',
            'new_pass' => self::VALID_COMPLEX_PASSWORD,
        ] + $csrfFields);

        $this->assertOk($response);
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_OK_AUTH_005, $response->body);

        $afterHash = $this->readPasswordHash('admin');
        $this->assertNotSame($beforeHash, $afterHash);
        $this->assertTrue(password_verify(self::VALID_COMPLEX_PASSWORD, $afterHash));
    }

    // 現在パスワードが違うと変更できず、画面に戻ってエラーが出る
    public function testPasswordEditShowsErrorWhenCurrentPasswordIsWrong(): void
    {
        $this->preparePasswordEditAsAdmin();
        $beforeHash = $this->readPasswordHash('admin');
        $csrfFields = $this->fetchPasswordEditCsrfFields();

        $response = $this->getClient()->post('password_edit.php', [
            'mode' => 'update',
            'old_pass' => 'wrongpass',
            'new_pass' => self::VALID_COMPLEX_PASSWORD,
        ] + $csrfFields);

        $this->assertOk($response);
        $this->assertStringContainsString('Location: /noblestock/public/password_edit.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_SYS_AUTH_006, $response->body);
        $this->assertSame($beforeHash, $this->readPasswordHash('admin'));
    }

    // 画面文言どおり、8文字未満の新パスワードはサーバー側でも弾く
    public function testPasswordEditRejectsTooShortNewPasswordOnServerSide(): void
    {
        $this->preparePasswordEditAsAdmin();
        $beforeHash = $this->readPasswordHash('admin');
        $csrfFields = $this->fetchPasswordEditCsrfFields();

        $response = $this->getClient()->post('password_edit.php', [
            'mode' => 'update',
            'old_pass' => 'admin000',
            'new_pass' => 'Abc1234',
        ] + $csrfFields);

        $this->assertOk($response);
        $this->assertStringContainsString('Location: /noblestock/public/password_edit.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_SYS_AUTH_006, $response->body);
        $this->assertSame($beforeHash, $this->readPasswordHash('admin'));
    }

    // 新パスワードが129文字以上なら、サーバー側でも弾いて変更しない
    public function testPasswordEditRejectsTooLongNewPasswordOnServerSide(): void
    {
        $this->preparePasswordEditAsAdmin();
        $beforeHash = $this->readPasswordHash('admin');
        $csrfFields = $this->fetchPasswordEditCsrfFields();

        $response = $this->getClient()->post('password_edit.php', [
            'mode' => 'update',
            'old_pass' => 'admin000',
            'new_pass' => str_repeat('a', 129),
        ] + $csrfFields);

        $this->assertOk($response);
        $this->assertStringContainsString('Location: /noblestock/public/password_edit.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_SYS_AUTH_006, $response->body);
        $this->assertSame($beforeHash, $this->readPasswordHash('admin'));
    }

    // mode なし POST は単に画面へ戻り、更新は走らない
    public function testPasswordEditPostWithoutModeRedirectsBackWithoutChangingPassword(): void
    {
        $this->preparePasswordEditAsAdmin();
        $beforeHash = $this->readPasswordHash('admin');

        $response = $this->getClient()->post('password_edit.php', [
            'old_pass' => 'admin000',
            'new_pass' => self::VALID_COMPLEX_PASSWORD,
        ]);

        $this->assertOk($response);
        $this->assertStringContainsString('Location: /noblestock/public/password_edit.php', $response->headers);
        $this->assertSame($beforeHash, $this->readPasswordHash('admin'));
    }

    // CSRF トークンがない更新要求は受け付けず、パスワードも変更しない
    public function testPasswordEditRejectsUpdateWithoutCsrfToken(): void
    {
        $this->preparePasswordEditAsAdmin();
        $beforeHash = $this->readPasswordHash('admin');

        $response = $this->getClient()->post('password_edit.php', [
            'mode' => 'update',
            'old_pass' => 'admin000',
            'new_pass' => self::VALID_COMPLEX_PASSWORD,
            '_skip_auto_csrf' => true,
        ]);

        $this->assertOk($response);
        $this->assertStringContainsString('Location: /noblestock/public/password_edit.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_SYS_COMMON_900, $response->body);
        $this->assertSame($beforeHash, $this->readPasswordHash('admin'));
    }

    private function preparePasswordEditAsAdmin(): void
    {
        $this->preparePasswordEditAs('admin', 'admin000');
    }

    private function preparePasswordEditAs(string $loginId, string $password): void
    {
        TestDatabase::seed();
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->loginAs($loginId, $password);
        $this->getClient()->get('menu.php');
    }

    private function openPasswordEditAsAdmin(): Response
    {
        $this->preparePasswordEditAsAdmin();

        $response = $this->getClient()->get('password_edit.php');
        $this->assertOk($response);

        return $response;
    }

    // パスワード変更画面から発行された CSRF トークンを取得する
    private function fetchPasswordEditCsrfFields(): array
    {
        return $this->extractCsrfPostData($this->openPasswordEditAsAdmin());
    }

    private function assertInputValue(Response $response, string $name, string $expectedValue): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//input[@name='{$name}']");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, "Input '{$name}' was not found.");

        $actual = (string) ($nodes->item(0)?->attributes?->getNamedItem('value')?->nodeValue ?? '');
        $this->assertSame($expectedValue, $actual, "Unexpected value for input '{$name}'.");
    }

    // 入力欄に設定された HTML 属性値を検証する
    private function assertInputAttribute(Response $response, string $name, string $attribute, string $expectedValue): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//input[@name='{$name}']");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, "Input '{$name}' was not found.");

        $actual = (string) ($nodes->item(0)?->attributes?->getNamedItem($attribute)?->nodeValue ?? '');
        $this->assertSame($expectedValue, $actual, "Unexpected {$attribute} for input '{$name}'.");
    }

    // パスワード変更画面の form が Bootstrap の標準バリデーション設定になっていることを確認する
    private function assertFormUsesBootstrapValidation(Response $response): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//form[@action='password_edit.php']");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Password edit form was not found.');

        $form = $nodes->item(0);
        $this->assertInstanceOf(DOMElement::class, $form);
        $classAttr = ' ' . $form->getAttribute('class') . ' ';
        $this->assertStringContainsString(' needs-validation ', $classAttr);
        $this->assertTrue($form->hasAttribute('novalidate'));
    }

    // hidden input に埋め込まれた CSRF トークンを取り出す
    private function extractCsrfToken(Response $response): string
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//input[@name='" . Utility::getCsrfFieldName() . "']");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'CSRF token input was not found.');

        return (string) ($nodes->item(0)?->attributes?->getNamedItem('value')?->nodeValue ?? '');
    }

    private function readPasswordHash(string $loginId): string
    {
        $pdo = $this->createTestPdo();
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE login_id = :login_id');
        $stmt->execute([
            'login_id' => $loginId,
        ]);

        return (string) $stmt->fetchColumn();
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
    // セッション期限切れ時に認証情報と画面用セッションが破棄されることを確認する
    public function testPasswordEditClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('password_edit.php');
    }
}
