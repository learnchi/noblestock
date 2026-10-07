<?php
declare(strict_types=1);

namespace Tests\Support;

use Noblestock\Logic\LogicConst;


/**
 * Backs up the current noblestock database, replaces it with testdata.sql,
 * and restores the original after the test suite finishes.
 */
final class TestDatabase
{
    private const DBCONFIG = __DIR__ . '/../..' . LogicConst::DB_CONFIG_PATH;
    private const DEFAULT_MYSQL_BASE = __DIR__ . '/../../../../mysql/bin';  // 注意

    private static ?string $backupFile = null;
    private static bool $registeredShutdown = false;

    public static function seed(): void
    {
        self::ensureBackupExists();
        self::replaceData(self::getTestdataPath());
        self::registerRestoreOnShutdown();
    }

    public static function restoreOriginal(): void
    {
        if (self::$backupFile === null) {
            return;
        }
        self::replaceData(self::$backupFile);
    }

    private static function ensureBackupExists(): void
    {
        if (self::$backupFile !== null) {
            return;
        }

        $tmpDir = __DIR__ . '/../tmp';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0777, true);
        }

        $backupPath = $tmpDir . '/noblestock-backup.sql';
        $cmd = self::buildMysqldumpCommandDataOnly();
        self::runProcess($cmd, null, $backupPath);

        self::$backupFile = $backupPath;
    }

    /**
     * TRUNCATE all tables then load the given SQL file (data only).
     */
    private static function replaceData(string $sqlFile): void
    {
        self::truncateAllTables();
        self::importSqlFile($sqlFile);
    }

    private static function buildMysqldumpCommandDataOnly(): string
    {
        $mysqldump = self::detectBinary('mysqldump');
        return sprintf(
            '"%s" --host=%s --user=%s --password=%s --no-create-info --skip-triggers --single-transaction %s',
            $mysqldump,
            escapeshellarg(self::getHost()),
            escapeshellarg(self::getUser()),
            escapeshellarg(self::getPass()),
            escapeshellarg(self::getDbName())
        );
    }

    private static function truncateAllTables(): void
    {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', self::getHost(), self::getDbName());
        $pdo = new \PDO($dsn, self::getUser(), self::getPass(), [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $pdo->exec("TRUNCATE TABLE `{$table}`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private static function importSqlFile(string $sqlFile): void
    {
        $db = self::getDbName();
        $mysql = self::detectBinary('mysql');

        $cmd = sprintf(
            '"%s" --host=%s --user=%s --password=%s %s',
            $mysql,
            escapeshellarg(self::getHost()),
            escapeshellarg(self::getUser()),
            escapeshellarg(self::getPass()),
            escapeshellarg($db)
        );

        self::runProcess($cmd, $sqlFile);
    }

    private static function detectBinary(string $name): string
    {
        $env = getenv(strtoupper($name) . '_BIN');
        if ($env && is_file($env)) {
            return $env;
        }

        $default = self::DEFAULT_MYSQL_BASE . DIRECTORY_SEPARATOR . $name . (stripos(PHP_OS, 'WIN') === 0 ? '.exe' : '');
        if (is_file($default)) {
            return $default;
        }

        return $name; // fallback to PATH
    }

    private static function getTestdataPath(): string
    {
        $path = dirname(__DIR__, 2) . '/doc/testdata.sql';
        if (!is_file($path)) {
            throw new \RuntimeException("Missing test data file at {$path}");
        }
        return $path;
    }

    private static function runProcess(string $command, ?string $stdinFile = null, ?string $stdoutFile = null): void
    {
        $descriptors = [
            0 => $stdinFile ? ['file', $stdinFile, 'r'] : \STDIN,
            1 => $stdoutFile ? ['file', $stdoutFile, 'w'] : ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);
        if (!\is_resource($process)) {
            throw new \RuntimeException("Failed to start process: {$command}");
        }

        $stdout = $stdoutFile ? '' : stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $code = proc_close($process);
        if ($code !== 0) {
            throw new \RuntimeException("Command failed ({$code}): {$command}\nSTDOUT: {$stdout}\nSTDERR: {$stderr}");
        }
    }

    private static function registerRestoreOnShutdown(): void
    {
        if (self::$registeredShutdown) {
            return;
        }
        self::$registeredShutdown = true;
        register_shutdown_function([self::class, 'restoreOriginal']);
    }

    private static function getHost(): string
    {
        return self::config('dbhost');
    }

    private static function getUser(): string
    {
        return self::config('dbuser');
    }

    private static function getPass(): string
    {
        return self::config('dbpass');
    }

    private static function getDbName(): string
    {
        return self::config('dbname');
    }

    private static function config(string $key): string
    {
        static $config = null;
        if ($config === null) {
            if (!is_file(self::DBCONFIG)) {
                throw new \RuntimeException('DB config file not found: ' . self::DBCONFIG);
            }
            $config = parse_ini_file(self::DBCONFIG);
            if ($config === false) {
                throw new \RuntimeException('Failed to parse ' . basename(LogicConst::DB_CONFIG_PATH));
            }
        }

        if (!array_key_exists($key, $config)) {
            throw new \RuntimeException("Missing '{$key}' in " . basename(LogicConst::DB_CONFIG_PATH));
        }

        return (string) $config[$key];
    }
}
