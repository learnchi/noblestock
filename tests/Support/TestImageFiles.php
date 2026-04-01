<?php
declare(strict_types=1);

namespace Tests\Support;

/**
 * public/uploads をテスト用画像へ差し替えるための共通クラス。
 */
final class TestImageFiles
{
    private const FIXTURE_DIR = __DIR__ . '/uploads';
    private const TARGET_DIR = __DIR__ . '/../../public/uploads';
    private const TMP_DIR = __DIR__ . '/../tmp';

    private static ?string $backupDir = null;
    private static bool $registeredShutdown = false;

    // 現在の public/uploads を退避して、Tests/Support/uploads の内容へ差し替える
    public static function seed(): void
    {
        self::assertDirectoryExists(self::FIXTURE_DIR, 'Fixture uploads directory was not found');
        self::assertDirectoryExists(self::TARGET_DIR, 'Target uploads directory was not found');

        if (self::$backupDir === null) {
            self::ensureDirectoryExists(self::TMP_DIR);
            self::$backupDir = self::TMP_DIR . '/public-uploads-backup-' . uniqid('', true);
            self::ensureDirectoryExists(self::$backupDir);
            self::moveDirectoryContents(self::TARGET_DIR, self::$backupDir);
        } else {
            self::clearDirectoryByMoving(self::TARGET_DIR);
        }

        self::copyDirectoryContents(self::FIXTURE_DIR, self::TARGET_DIR);
        self::registerRestoreOnShutdown();
    }

    // seed 前の public/uploads の状態へ戻す
    public static function restoreOriginal(): void
    {
        if (self::$backupDir === null) {
            return;
        }

        self::clearDirectoryByMoving(self::TARGET_DIR);
        self::moveDirectoryContents(self::$backupDir, self::TARGET_DIR);
        self::$backupDir = null;
    }

    private static function copyDirectoryContents(string $sourceDir, string $targetDir): void
    {
        self::assertDirectoryExists($sourceDir, 'Source directory was not found');
        self::ensureDirectoryExists($targetDir);

        $iterator = new \FilesystemIterator($sourceDir, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $item) {
            $sourcePath = $item->getPathname();
            $targetPath = $targetDir . DIRECTORY_SEPARATOR . $item->getBasename();

            if ($item->isDir()) {
                self::copyDirectoryRecursive($sourcePath, $targetPath);
                continue;
            }

            self::copyWithRetry($sourcePath, $targetPath);
        }
    }

    private static function copyDirectoryRecursive(string $sourceDir, string $targetDir): void
    {
        self::ensureDirectoryExists($targetDir);

        $iterator = new \FilesystemIterator($sourceDir, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $item) {
            $sourcePath = $item->getPathname();
            $targetPath = $targetDir . DIRECTORY_SEPARATOR . $item->getBasename();

            if ($item->isDir()) {
                self::copyDirectoryRecursive($sourcePath, $targetPath);
                continue;
            }

            self::copyWithRetry($sourcePath, $targetPath);
        }
    }

    private static function moveDirectoryContents(string $sourceDir, string $targetDir): void
    {
        self::assertDirectoryExists($sourceDir, 'Source directory was not found');
        self::ensureDirectoryExists($targetDir);

        $iterator = new \FilesystemIterator($sourceDir, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $item) {
            $sourcePath = $item->getPathname();
            $targetPath = $targetDir . DIRECTORY_SEPARATOR . $item->getBasename();
            self::movePathWithRetry($sourcePath, $targetPath);
        }
    }

    private static function clearDirectoryByMoving(string $dir): void
    {
        self::assertDirectoryExists($dir, 'Directory to clear was not found');

        $trashDir = self::TMP_DIR . '/public-uploads-trash-' . uniqid('', true);
        self::ensureDirectoryExists($trashDir);
        self::moveDirectoryContents($dir, $trashDir);
    }

    private static function ensureDirectoryExists(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory {$dir}");
        }
    }

    private static function assertDirectoryExists(string $dir, string $message): void
    {
        if (!is_dir($dir)) {
            throw new \RuntimeException("{$message}: {$dir}");
        }
    }

    private static function movePathWithRetry(string $sourcePath, string $targetPath): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            if (@rename($sourcePath, $targetPath)) {
                return;
            }

            usleep(200000);
        }

        throw new \RuntimeException("Failed to move {$sourcePath} to {$targetPath}");
    }

    private static function copyWithRetry(string $sourcePath, string $targetPath): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            if (@copy($sourcePath, $targetPath)) {
                return;
            }

            usleep(200000);
        }

        throw new \RuntimeException("Failed to copy file from {$sourcePath} to {$targetPath}");
    }

    private static function registerRestoreOnShutdown(): void
    {
        if (self::$registeredShutdown) {
            return;
        }

        self::$registeredShutdown = true;
        register_shutdown_function([self::class, 'restoreOriginal']);
    }
}
