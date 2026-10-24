<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Logging\Logger;

/** ログ保存失敗でも業務処理を継続し、PHP標準ログへ原因を残す契約。 */
final class ChandraLoggerCompatibilityTest extends TestCase
{
    private string $directory;
    private string $previousErrorLog;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/noble-log-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->previousErrorLog = (string) ini_get('error_log');
        ini_set('error_log', $this->directory . '/php-error.log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testUnavailableLogDirectoryDoesNotThrowAndReportsWriteFailure(): void
    {
        // ファイルを親にすることで、OSの権限設定によらず作成不能にする。
        $blocker = $this->directory . '/blocker';
        file_put_contents($blocker, 'file');
        $target = $blocker . '/log';
        $logger = new Logger($target);
        $this->assertFalse($logger->error('inventory update failed'));

        $fallback = file_get_contents($this->directory . '/php-error.log');
        $this->assertStringContainsString('Unable to create log directory: ' . $target, $fallback);
        $this->assertStringContainsString('Failed to write log:', $fallback);
        $this->assertStringContainsString('ERROR inventory update failed', $fallback);
    }

    public function testWriteFailureAfterConstructionFallsBackToPhpLog(): void
    {
        $logger = new Logger($this->directory, 'missing/application.log');
        $this->assertFalse($logger->info('stock operation completed'));
        $fallback = file_get_contents($this->directory . '/php-error.log');
        $this->assertStringContainsString('Failed to write log:', $fallback);
        $this->assertStringContainsString('INFO stock operation completed', $fallback);
    }

    public function testSuccessfulWriteDoesNotUseFallback(): void
    {
        $logger = new Logger($this->directory, 'application.log');
        $this->assertTrue($logger->info('stock operation completed'));
        $this->assertStringContainsString('INFO stock operation completed',
            file_get_contents($this->directory . '/' . date('Ym') . '_application.log'));
        $this->assertFileDoesNotExist($this->directory . '/php-error.log');
    }
}
