<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Auth\AuthService;
use Studiogau\Chandra\Auth\LoginCredentials;
use Studiogau\Chandra\Auth\LoginUser;
use Studiogau\Chandra\Auth\UserRepositoryInterface;
use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Support\SessionHelper;

/** Chandra v0.3.0で追加されたタイムアウト指定と、アプリのセッション利用との互換性。 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ChandraAuthCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/noblestock/public/index.php';
        session_start();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
    }

    private function auth(?int $timeout = null): AuthService
    {
        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findByCredentials')->willReturn([
            'id' => 1, 'login_id' => 'admin', 'user_name' => '管理者',
            'permissions' => 'menu, stock_in',
        ]);
        $logger = new Logger(__DIR__ . '/../tmp', threshold: 0);

        return $timeout === null
            ? new AuthService($repo, $logger)
            : new AuthService($repo, $logger, loginTimeout: $timeout);
    }

    public function testNegativeTimeoutIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->auth(-1);
    }

    public static function sessionCases(): array
    {
        return [
            'default remains disabled' => [null, 3600, true],
            'explicitly disabled' => [0, 3600, true],
            'before expiry' => [3600, 10, true],
            'at expiry' => [1, 1, false],
            'past expiry' => [1, 10, false],
            'missing access time' => [60, null, true],
        ];
    }

    #[DataProvider('sessionCases')]
    public function testSessionTimeout(?int $timeout, ?int $age, bool $valid): void
    {
        $startTime = $age === null ? null : time() - $age;
        $auth = $this->auth($timeout);
        $auth->setCurrentUser(new LoginUser(1, 'admin', '管理者', ['menu'], $startTime));
        SessionHelper::setData('stock', 'marker', 'pending');
        $before = time();

        $this->assertSame($valid, $auth->checkUserSession());
        if (!$valid) {
            $this->assertNull($auth->getCurrentUser());
            $this->assertNull(SessionHelper::getData('stock', 'marker'));
            return;
        }

        $this->assertSame('pending', SessionHelper::getData('stock', 'marker'));
        $actual = $auth->getCurrentUser()?->getStartTime();
        if ($timeout === null || $timeout === 0) {
            $this->assertSame($startTime, $actual);
        } else {
            $this->assertGreaterThanOrEqual($before, $actual);
            $this->assertLessThanOrEqual(time(), $actual);
        }
    }

    public static function loginCases(): array
    {
        return ['default' => [null], 'disabled' => [0], 'enabled' => [60]];
    }

    #[DataProvider('loginCases')]
    public function testLoginInitializesAccessTime(?int $timeout): void
    {
        $auth = $this->auth($timeout);
        $oldId = session_id();
        $before = time();
        $user = $auth->login(new LoginCredentials('admin', 'password'));

        $this->assertNotSame($oldId, session_id());
        $this->assertSame('admin', $auth->getCurrentUser()?->getLoginId());
        if ($timeout === 60) {
            $this->assertGreaterThanOrEqual($before, $user->getStartTime());
            $this->assertLessThanOrEqual(time(), $user->getStartTime());
        } else {
            $this->assertNull($user->getStartTime());
        }
    }

    public function testEnabledTimeoutRejectsMissingUser(): void
    {
        $this->assertFalse($this->auth(60)->checkUserSession());
    }
}
