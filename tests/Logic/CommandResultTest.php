<?php

declare(strict_types=1);

use Noblestock\Logic\CommandResult;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/Logic/BarcodeProcessor.php';

final class CommandResultTest extends TestCase
{
    /**
     * doneを実行し、リダイレクトも結果配列も持たない初期状態になることを確認する。
     */
    public function testDoneCreatesDefaultState(): void
    {
        $result = CommandResult::done();

        $this->assertFalse($result->isRedirect());
        $this->assertNull($result->getRedirectUrl());
        $this->assertFalse($result->hasResult());
        $this->assertNull($result->getResultArray());
    }

    /**
     * resultを実行し、結果配列を保持してhasResultがtrueになることを確認する。
     */
    public function testResultStoresPayload(): void
    {
        $payload = ['status' => '00000', 'errMsg' => ''];
        $result = CommandResult::result($payload);

        $this->assertTrue($result->hasResult());
        $this->assertSame($payload, $result->getResultArray());
        $this->assertFalse($result->isRedirect());
    }

    /**
     * redirectを実行し、リダイレクト先URLが保持されることを確認する。
     */
    public function testRedirectStoresUrl(): void
    {
        $result = CommandResult::redirect('menu.php');

        $this->assertTrue($result->isRedirect());
        $this->assertSame('menu.php', $result->getRedirectUrl());
        $this->assertFalse($result->hasResult());
    }
}

