<?php

declare(strict_types=1);

namespace Noblestock\DbLogic;

require_once __DIR__ . '/../vendor/autoload.php';

use DateTimeImmutable;
use PDO;
use Noblestock\Logic\LogicConst;
use Studiogau\Chandra\Database\Database;
use Studiogau\Chandra\Logging\Logger;

/**
 * ログイン試行制限用テーブルへのアクセスをまとめた repository。
 *
 * login_attempts / login_locks の読み書きをこのクラスに閉じ込めることで、
 * guard 側では SQL を意識せず、認証ルールの記述に集中できるようにしている。
 *
 * 設計としては、
 * - login_attempts は「何回失敗したかを数えるための履歴」
 * - login_locks は「現在ロック中かどうかを見るための状態」
 * と役割を分けて扱っている。
 */
final class LoginRateLimitRepository
{
    private const ATTEMPTS_TABLE = 'login_attempts';
    private const LOCKS_TABLE = 'login_locks';
    private const AUDIT_USER = 'SYSTEM';

    private Database $database;
    private Logger $logger;

    /**
     * 既存の dblogic と同じく、Database と Logger は注入可能にしている。
     * 通常利用では DB 設定ファイルから接続を生成する。
     */
    public function __construct(?Database $database = null, ?Logger $logger = null)
    {
        $this->logger = $logger ?? Logger::createDefault(dirname(__DIR__, 1));
        $this->database = $database ?? Database::fromConfiguredSource(
            dirname(__DIR__, 1) . LogicConst::DB_CONFIG_PATH,
            $this->logger
        );
    }

    /**
     * 1 回分のログイン試行を履歴として保存する。
     *
     * 成功・失敗のどちらもここに積むが、
     * 回数制限の集計では通常「失敗かつ特定の失敗理由」だけを数える。
     */
    public function recordAttempt(
        string $loginId,
        ?string $clientIp,
        bool $successful,
        ?string $failureReason,
        DateTimeImmutable $attemptedAt
    ): void {
        $sql = sprintf(
            'INSERT INTO %s (login_id, client_ip, result_flg, failure_reason, attempted_at, created_at, created_by)
             VALUES (:login_id, :client_ip, :result_flg, :failure_reason, :attempted_at, :created_at, :created_by)',
            self::ATTEMPTS_TABLE
        );

        $stmt = $this->pdo()->prepare($sql);
        $timestamp = $this->formatDateTime($attemptedAt);
        $stmt->execute([
            ':login_id' => $loginId,
            ':client_ip' => $clientIp ?? '',
            ':result_flg' => $successful ? 1 : 0,
            ':failure_reason' => $failureReason,
            ':attempted_at' => $timestamp,
            ':created_at' => $timestamp,
            ':created_by' => self::AUDIT_USER,
        ]);
    }

    /**
     * 指定アカウントの直近失敗回数を数える。
     *
     * どの失敗を回数制限対象にするかは呼び出し側で決めるため、
     * failureReason を引数で受けている。
     */
    public function countRecentFailuresForLoginId(
        string $loginId,
        string $failureReason,
        DateTimeImmutable $since
    ): int {
        $sql = sprintf(
            'SELECT COUNT(*) FROM %s
             WHERE login_id = :login_id
               AND result_flg = 0
               AND failure_reason = :failure_reason
               AND attempted_at >= :attempted_at',
            self::ATTEMPTS_TABLE
        );

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([
            ':login_id' => $loginId,
            ':failure_reason' => $failureReason,
            ':attempted_at' => $this->formatDateTime($since),
        ]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * 指定 IP の直近失敗回数を数える。
     */
    public function countRecentFailuresForIp(
        string $clientIp,
        string $failureReason,
        DateTimeImmutable $since
    ): int {
        $sql = sprintf(
            'SELECT COUNT(*) FROM %s
             WHERE client_ip = :client_ip
               AND result_flg = 0
               AND failure_reason = :failure_reason
               AND attempted_at >= :attempted_at',
            self::ATTEMPTS_TABLE
        );

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([
            ':client_ip' => $clientIp,
            ':failure_reason' => $failureReason,
            ':attempted_at' => $this->formatDateTime($since),
        ]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * 現在保存されているロック状態を 1 件取得する。
     * ロックの有効期限判定そのものは guard 側で行う。
     */
    public function findLock(string $lockType, string $lockKey): ?array
    {
        $sql = sprintf(
            'SELECT id, lock_type, lock_key, failed_count, last_failed_at, locked_until
             FROM %s
             WHERE lock_type = :lock_type AND lock_key = :lock_key',
            self::LOCKS_TABLE
        );

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([
            ':lock_type' => $lockType,
            ':lock_key' => $lockKey,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * ロック状態を保存する。
     *
     * 既に同じ lock_type + lock_key の行があれば更新し、
     * なければ新規作成する。
     */
    public function saveLock(
        string $lockType,
        string $lockKey,
        int $failedCount,
        DateTimeImmutable $lastFailedAt,
        DateTimeImmutable $lockedUntil
    ): void {
        $sql = sprintf(
            'INSERT INTO %s (lock_type, lock_key, failed_count, last_failed_at, locked_until, created_at, created_by)
             VALUES (:lock_type, :lock_key, :failed_count, :last_failed_at, :locked_until, :created_at, :created_by)
             ON DUPLICATE KEY UPDATE
                 failed_count = VALUES(failed_count),
                 last_failed_at = VALUES(last_failed_at),
                 locked_until = VALUES(locked_until),
                 updated_at = VALUES(created_at),
                 updated_by = VALUES(created_by)',
            self::LOCKS_TABLE
        );

        $stmt = $this->pdo()->prepare($sql);
        $failedAt = $this->formatDateTime($lastFailedAt);
        $unlockAt = $this->formatDateTime($lockedUntil);
        $stmt->execute([
            ':lock_type' => $lockType,
            ':lock_key' => $lockKey,
            ':failed_count' => $failedCount,
            ':last_failed_at' => $failedAt,
            ':locked_until' => $unlockAt,
            ':created_at' => $failedAt,
            ':created_by' => self::AUDIT_USER,
        ]);
    }

    /**
     * ロック状態を削除する。
     *
     * 解除時刻を過ぎたロックの掃除や、成功ログイン後の解除に使う。
     */
    public function clearLock(string $lockType, string $lockKey): void
    {
        $sql = sprintf(
            'DELETE FROM %s WHERE lock_type = :lock_type AND lock_key = :lock_key',
            self::LOCKS_TABLE
        );

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([
            ':lock_type' => $lockType,
            ':lock_key' => $lockKey,
        ]);
    }

    /**
     * 指定アカウントの失敗履歴を削除する。
     *
     * 成功ログイン後に「過去の失敗を持ち越さない」ために使う。
     * 今回は INVALID_CREDENTIALS のような対象失敗だけを消せるようにしている。
     */
    public function clearFailuresForLoginId(string $loginId, string $failureReason): void
    {
        $sql = sprintf(
            'DELETE FROM %s
             WHERE login_id = :login_id
               AND result_flg = 0
               AND failure_reason = :failure_reason',
            self::ATTEMPTS_TABLE
        );

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([
            ':login_id' => $loginId,
            ':failure_reason' => $failureReason,
        ]);
    }

    /**
     * 低レベルな PDO 取得はこのクラスに閉じ込める。
     */
    private function pdo(): PDO
    {
        return $this->database->getConnection()->getPdo();
    }

    /**
     * DB 保存用の日時文字列に整形する。
     */
    private function formatDateTime(DateTimeImmutable $dateTime): string
    {
        return $dateTime->format('Y-m-d H:i:s');
    }
}
