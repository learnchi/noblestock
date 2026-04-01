<?php

declare(strict_types=1);

use Noblestock\Logic\MessageConst;
use Tests\Support\TestDatabase;
use Tests\Support\WebClient;
use Tests\Support\WebTestCase;

final class LoginRateLimitTest extends WebTestCase
{
    private const ACCOUNT_LOCK_THRESHOLD = 5;
    private const IP_LOCK_THRESHOLD = 10;
    private const LOCK_TYPE_ACCOUNT = 'account';
    private const LOCK_TYPE_IP = 'ip';

    // 同じアカウントでパスワード誤りを5回繰り返すと、アカウント単位のロックが作られ、
    // 正しいパスワードでもログインできなくなることを確認する
    public function testAccountIsLockedAfterFiveFailedAttempts(): void
    {
        $this->resetLoginAttemptState();

        for ($attempt = 0; $attempt < self::ACCOUNT_LOCK_THRESHOLD; $attempt++) {
            $response = $this->getClient()->login('admin', 'wrong-password');
            $this->assertLoginRejected($response);
        }

        $lock = $this->selectLoginLock(self::LOCK_TYPE_ACCOUNT, 'admin');
        $this->assertIsArray($lock);
        $this->assertSame((string) self::ACCOUNT_LOCK_THRESHOLD, (string) ($lock['failed_count'] ?? ''));
        $this->assertNotEmpty((string) ($lock['locked_until'] ?? ''));

        $blockedResponse = $this->getClient()->login('admin', 'admin000');
        $this->assertLoginRejected($blockedResponse);
        $this->assertNull($this->runAuthSessionBridge('snapshot')['user'] ?? null);
    }

    // 同じIPから複数アカウントに対して誤ったログインを10回行うと、
    // IP単位のロックが作られ、そのIPからの正常ログインも拒否されることを確認する
    public function testIpIsLockedAfterTenFailedAttemptsAcrossAccounts(): void
    {
        $this->resetLoginAttemptState();

        $loginIds = [
            'admin',
            'user1',
            'user2',
            'user3',
            'perm00',
            'perm01',
            'perm02',
            'perm03',
            'perm04',
            'perm05',
        ];

        foreach ($loginIds as $loginId) {
            $response = $this->getClient()->login($loginId, 'wrong-password');
            $this->assertLoginRejected($response);
        }

        $ipLock = $this->selectLatestLoginLockByType(self::LOCK_TYPE_IP);
        $this->assertIsArray($ipLock);
        $this->assertSame((string) self::IP_LOCK_THRESHOLD, (string) ($ipLock['failed_count'] ?? ''));
        $this->assertNotEmpty((string) ($ipLock['locked_until'] ?? ''));

        $blockedResponse = $this->getClient()->login('admin', 'admin000');
        $this->assertLoginRejected($blockedResponse);
        $this->assertNull($this->runAuthSessionBridge('snapshot')['user'] ?? null);
    }

    // アカウントロックの解除時刻を過去に進めると、ロック期限切れとして扱われ、
    // 正しいパスワードで再びログインできることを確認する
    public function testExpiredAccountLockAllowsLoginAgain(): void
    {
        $this->resetLoginAttemptState();

        for ($attempt = 0; $attempt < self::ACCOUNT_LOCK_THRESHOLD; $attempt++) {
            $response = $this->getClient()->login('admin', 'wrong-password');
            $this->assertLoginRejected($response);
        }

        $this->setLockExpiration(self::LOCK_TYPE_ACCOUNT, 'admin', '-1 minute');

        $response = $this->getClient()->login('admin', 'admin000');
        $this->assertOk($response);
        $this->assertNotNull($this->runAuthSessionBridge('snapshot')['user'] ?? null);
    }

    // 既存データとログイン試行状態を初期化し、毎テストを独立した状態から始める
    private function resetLoginAttemptState(): void
    {
        TestDatabase::seed();
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->getClient()->get('index.php');
    }

    // ログイン拒否時の共通アサーション
    // index.php へのリダイレクトと認証エラーメッセージを確認する
    private function assertLoginRejected($response): void
    {
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_VAL_AUTH_001, $response->body);
    }

    // 指定したロック種別・ロック対象キーに対応するロック行を取得する
    private function selectLoginLock(string $lockType, string $lockKey): ?array
    {
        $pdo = $this->createTestPdo();
        $stmt = $pdo->prepare(
            'SELECT lock_type, lock_key, failed_count, last_failed_at, locked_until
             FROM login_locks
             WHERE lock_type = :lock_type AND lock_key = :lock_key'
        );
        $stmt->execute([
            'lock_type' => $lockType,
            'lock_key' => $lockKey,
        ]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    // 指定したロック種別の最新ロック行を取得する
    private function selectLatestLoginLockByType(string $lockType): ?array
    {
        $pdo = $this->createTestPdo();
        $stmt = $pdo->prepare(
            'SELECT lock_type, lock_key, failed_count, last_failed_at, locked_until
             FROM login_locks
             WHERE lock_type = :lock_type
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([
            'lock_type' => $lockType,
        ]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    // テスト用に locked_until を変更し、ロック期限切れの状態を作る
    private function setLockExpiration(string $lockType, string $lockKey, string $relativeTime): void
    {
        $pdo = $this->createTestPdo();
        $stmt = $pdo->prepare(
            'UPDATE login_locks
             SET locked_until = :locked_until, updated_at = :updated_at, updated_by = :updated_by
             WHERE lock_type = :lock_type AND lock_key = :lock_key'
        );
        $timestamp = date('Y-m-d H:i:s', strtotime($relativeTime));
        $stmt->execute([
            'locked_until' => $timestamp,
            'updated_at' => $timestamp,
            'updated_by' => 'test',
            'lock_type' => $lockType,
            'lock_key' => $lockKey,
        ]);

        $this->assertSame(1, $stmt->rowCount(), 'Expected exactly one login lock row to be updated.');
    }

    // テストDBを直接参照するためのPDOを生成する
    private function createTestPdo(): \PDO
    {
        $configPath = dirname(__DIR__, 2) . '/config/dbconfig.ini';
        $config = parse_ini_file($configPath);

        $this->assertIsArray($config, 'Failed to read dbconfig.ini.');
        foreach (['dbhost', 'dbuser', 'dbpass', 'dbname'] as $requiredKey) {
            $this->assertArrayHasKey($requiredKey, $config, "Missing {$requiredKey} in dbconfig.ini.");
        }

        return new \PDO(
            sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                (string) $config['dbhost'],
                (string) $config['dbname']
            ),
            (string) $config['dbuser'],
            (string) $config['dbpass'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
    }
}
