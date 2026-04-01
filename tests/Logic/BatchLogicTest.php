<?php

declare(strict_types=1);

use Noblestock\Logic\BatchLogic;
use Noblestock\Logic\LogicConst;
use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Logging\Logger;
use Tests\Support\TestDatabase;

final class BatchLogicTest extends TestCase
{
    private const MANAGEMENT_NO = 'ABC001';
    private const LOG_DIR = __DIR__ . '/../tmp/batch-logic-mail';

    /** @var array<string, string|null> */
    private array $envBackup = [];
    private string $mailConfigPath;
    private ?string $mailConfigBackup = null;
    private bool $mailConfigExisted = false;

    public static function setUpBeforeClass(): void
    {
        TestDatabase::seed();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailConfigPath = dirname(__DIR__, 2) . LogicConst::MAIL_CONFIG_PATH;
        $this->mailConfigExisted = is_file($this->mailConfigPath);
        $this->mailConfigBackup = $this->mailConfigExisted
            ? (string) file_get_contents($this->mailConfigPath)
            : null;

        foreach ($this->mailEnvKeys() as $key) {
            $value = getenv($key);
            $this->envBackup[$key] = ($value === false) ? null : (string) $value;
            putenv($key);
        }

        if (!is_dir(self::LOG_DIR)) {
            mkdir(self::LOG_DIR, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->mailEnvKeys() as $key) {
            putenv($key);
            if ($this->envBackup[$key] !== null) {
                putenv($key . '=' . $this->envBackup[$key]);
            }
        }

        if ($this->mailConfigExisted) {
            file_put_contents($this->mailConfigPath, (string) $this->mailConfigBackup);
        } elseif (is_file($this->mailConfigPath)) {
            unlink($this->mailConfigPath);
        }

        parent::tearDown();
    }

    public function testSendLowStockMailUsesIniMailConfigByDefault(): void
    {
        $iniFrom = 'ini-mail-test@example.com';
        $this->writeMailConfig(
            "smtp_host=smtp.ini.example.com\n"
            . "smtp_port=587\n"
            . "smtp_auth=true\n"
            . "smtp_user=ini-user\n"
            . "smtp_pass=ini-pass\n"
            . "smtp_secure=tls\n"
            . "mail_from={$iniFrom}\n"
        );

        $log = $this->invokeSendLowStockMailAndReadLog('batch_ini_mail.log');

        $this->assertStringContainsString($iniFrom, $log);
    }

    public function testSendLowStockMailUsesEnvMailConfigWhenSwitchIsEnv(): void
    {
        $iniFrom = 'ini-should-not-be-used@example.com';
        $envFrom = 'env-mail-test@example.com';

        $this->writeMailConfig(
            "smtp_host=smtp.ini.example.com\n"
            . "smtp_port=587\n"
            . "smtp_auth=true\n"
            . "smtp_user=ini-user\n"
            . "smtp_pass=ini-pass\n"
            . "smtp_secure=tls\n"
            . "mail_from={$iniFrom}\n"
        );

        putenv('CHANDRA_MAIL_SOURCE=env');
        putenv('MAIL_SMTP_HOST=smtp.env.example.com');
        putenv('MAIL_SMTP_PORT=2525');
        putenv('MAIL_SMTP_AUTH=true');
        putenv('MAIL_SMTP_USER=env-user');
        putenv('MAIL_SMTP_PASS=env-pass');
        putenv('MAIL_SMTP_SECURE=tls');
        putenv('MAIL_FROM=' . $envFrom);

        $log = $this->invokeSendLowStockMailAndReadLog('batch_env_mail.log');

        $this->assertStringContainsString($envFrom, $log);
        $this->assertStringNotContainsString($iniFrom, $log);
    }

    private function writeMailConfig(string $contents): void
    {
        file_put_contents($this->mailConfigPath, $contents);
    }

    private function invokeSendLowStockMailAndReadLog(string $fileName): string
    {
        $logPath = self::LOG_DIR . '/' . date('Ym') . '_' . $fileName;
        if (is_file($logPath)) {
            unlink($logPath);
        }

        $logger = new Logger(self::LOG_DIR, $fileName, Logger::LEVEL_DEBUG);
        $logic = new BatchLogic($logger);

        $method = new ReflectionMethod(BatchLogic::class, 'sendLowStockMail');
        $method->setAccessible(true);
        $method->invoke($logic, self::MANAGEMENT_NO);

        $this->assertFileExists($logPath);

        return (string) file_get_contents($logPath);
    }

    /**
     * @return list<string>
     */
    private function mailEnvKeys(): array
    {
        return [
            'CHANDRA_MAIL_SOURCE',
            'MAIL_SMTP_HOST',
            'MAIL_SMTP_PORT',
            'MAIL_SMTP_AUTH',
            'MAIL_SMTP_USER',
            'MAIL_SMTP_PASS',
            'MAIL_SMTP_SECURE',
            'MAIL_FROM',
        ];
    }
}
