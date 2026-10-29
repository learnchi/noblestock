<?php

declare(strict_types=1);

namespace Noblestock\Logic;

use DateInterval;
use DateTimeImmutable;
use Noblestock\DbLogic\LoginRateLimitRepository;
use Studiogau\Chandra\Auth\AuthException;
use Studiogau\Chandra\Auth\LoginAttempt;
use Studiogau\Chandra\Auth\LoginFailure;
use Studiogau\Chandra\Auth\LoginFailureReason;
use Studiogau\Chandra\Auth\LoginGuardInterface;
use Studiogau\Chandra\Auth\LoginUser;
use Studiogau\Chandra\Logging\Logger;

/**
 * ログイン試行制限を担当する Chandra の guard 実装。
 *
 * このクラスは Chandra 側の LoginGuardInterface にぶら下がる形で、
 * ログイン前・成功時・失敗時の 3 タイミングに処理を差し込む。
 *
 * 役割は次のとおり。
 * - ログイン前に、アカウント単位 / IP単位のロック状態を確認する
 * - ログイン失敗時に試行履歴を記録し、閾値超過ならロックを作る
 * - ログイン成功時に成功履歴を記録し、アカウントロックや失敗履歴を解除する
 *
 * 実際の保存処理は repository に寄せ、
 * ここでは「どのタイミングで何を数え、いつロックするか」という
 * 認証フロー上の判断だけを持つようにしている。
 */
final class LoginRateLimitGuard implements LoginGuardInterface
{
    private const LOCK_TYPE_ACCOUNT = 'account';
    private const LOCK_TYPE_IP = 'ip';
    private const ACCOUNT_FAILURE_THRESHOLD = 5;
    private const ACCOUNT_FAILURE_WINDOW = 'PT15M';
    private const ACCOUNT_LOCK_DURATION = 'PT15M';
    private const IP_FAILURE_THRESHOLD = 10;
    private const IP_FAILURE_WINDOW = 'PT15M';
    private const IP_LOCK_DURATION = 'PT15M';

    private LoginRateLimitRepository $repository;
    private Logger $logger;

    /**
     * repository と logger は差し替え可能にしておき、
     * テストや将来の差し替えに備える。
     */
    public function __construct(?LoginRateLimitRepository $repository = null, ?Logger $logger = null)
    {
        $this->logger = $logger ?? Logger::createDefault(dirname(__DIR__, 1));
        $this->repository = $repository ?? new LoginRateLimitRepository(null, $this->logger);
    }

    /**
     * ログイン試行の入口。
     *
     * ここでは資格情報の照合は行わず、
     * 既に有効なロックがあるかだけを確認する。
     * locked_until が現在時刻を過ぎていれば AuthException を投げてログインを拒否する。
     */
    public function assertCanAttempt(LoginAttempt $attempt): void
    {
        $now = new DateTimeImmutable();
        $loginId = trim($attempt->getUserId());
        $clientIp = $this->normalizeIp($attempt->getClientIp());

        if ($loginId !== '') {
            $this->assertNotLocked(self::LOCK_TYPE_ACCOUNT, $loginId, $now);
        }

        if ($clientIp !== null) {
            $this->assertNotLocked(self::LOCK_TYPE_IP, $clientIp, $now);
        }
    }

    /**
     * ログイン成功時の後処理。
     *
     * 成功履歴を残したうえで、
     * 同じ login_id に対して残っているロックと失敗履歴を片付ける。
     * 今回はアカウント単位の失敗履歴だけをクリアしており、
     * IP 単位ロックは自然解除に任せている。
     */
    public function recordSuccessfulLogin(LoginAttempt $attempt, LoginUser $user): void
    {
        $now = new DateTimeImmutable();
        $loginId = trim($attempt->getUserId());
        $clientIp = $this->normalizeIp($attempt->getClientIp());

        $this->repository->recordAttempt($loginId, $clientIp, true, null, $now);

        if ($loginId !== '') {
            $this->repository->clearLock(self::LOCK_TYPE_ACCOUNT, $loginId);
            $this->repository->clearFailuresForLoginId($loginId, LoginFailureReason::INVALID_CREDENTIALS->value);
        }
    }

    /**
     * ログイン失敗時の後処理。
     *
     * まず失敗履歴を保存し、
     * 失敗理由が INVALID_CREDENTIALS のときだけ回数制限の対象として数える。
     * 内部エラーまで制限対象に含めると、障害時に不必要なロックを量産するため除外している。
     */
    public function recordFailedLogin(LoginAttempt $attempt, LoginFailure $failure): void
    {
        $now = new DateTimeImmutable();
        $loginId = trim($attempt->getUserId());
        $clientIp = $this->normalizeIp($attempt->getClientIp());
        $failureReason = $failure->getReason()->value;

        $this->repository->recordAttempt($loginId, $clientIp, false, $failureReason, $now);

        if ($failure->getReason() !== LoginFailureReason::INVALID_CREDENTIALS) {
            return;
        }

        if ($loginId !== '') {
            $this->lockAccountIfThresholdExceeded($loginId, $now);
        }

        if ($clientIp !== null) {
            $this->lockIpIfThresholdExceeded($clientIp, $now);
        }
    }

    /**
     * 対象キーに有効なロックがあるかを確認する。
     *
     * ロック期限が過ぎていればその場で削除し、
     * まだ有効ならログインを拒否する。
     */
    private function assertNotLocked(string $lockType, string $lockKey, DateTimeImmutable $now): void
    {
        $lock = $this->repository->findLock($lockType, $lockKey);
        if ($lock === null) {
            return;
        }

        $lockedUntil = new DateTimeImmutable((string) $lock['locked_until']);
        if ($lockedUntil <= $now) {
            $this->repository->clearLock($lockType, $lockKey);
            return;
        }

        $this->logger->info(
            __METHOD__
            . ' login blocked'
            . ' lock_type=' . $lockType
            . ' lock_key=' . $lockKey
            . ' locked_until=' . $lockedUntil->format('Y-m-d H:i:s')
        );

        throw new AuthException('Login attempt is temporarily locked.');
    }

    /**
     * アカウント単位の失敗回数を数え、
     * 閾値に達していればアカウントロックを保存する。
     */
    private function lockAccountIfThresholdExceeded(string $loginId, DateTimeImmutable $now): void
    {
        $count = $this->repository->countRecentFailuresForLoginId(
            $loginId,
            LoginFailureReason::INVALID_CREDENTIALS->value,
            $now->sub(new DateInterval(self::ACCOUNT_FAILURE_WINDOW))
        );

        if ($count < self::ACCOUNT_FAILURE_THRESHOLD) {
            return;
        }

        $this->repository->saveLock(
            self::LOCK_TYPE_ACCOUNT,
            $loginId,
            $count,
            $now,
            $now->add(new DateInterval(self::ACCOUNT_LOCK_DURATION))
        );
    }

    /**
     * IP 単位の失敗回数を数え、
     * 閾値に達していれば IP ロックを保存する。
     */
    private function lockIpIfThresholdExceeded(string $clientIp, DateTimeImmutable $now): void
    {
        $count = $this->repository->countRecentFailuresForIp(
            $clientIp,
            LoginFailureReason::INVALID_CREDENTIALS->value,
            $now->sub(new DateInterval(self::IP_FAILURE_WINDOW))
        );

        if ($count < self::IP_FAILURE_THRESHOLD) {
            return;
        }

        $this->repository->saveLock(
            self::LOCK_TYPE_IP,
            $clientIp,
            $count,
            $now,
            $now->add(new DateInterval(self::IP_LOCK_DURATION))
        );
    }

    /**
     * 空文字や空白だけの IP を null に寄せ、
     * 以降の判定では「IP が取得できなかった」ケースとして扱いやすくする。
     */
    private function normalizeIp(?string $clientIp): ?string
    {
        $clientIp = trim((string) $clientIp);
        return $clientIp === '' ? null : $clientIp;
    }
}
