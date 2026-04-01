<?php

declare(strict_types=1);

namespace Tests\Support;

use Noblestock\DbLogic\Config;

trait ExcelSettingsHelper
{
    // 現在の Excel 出力設定を退避する
    protected function snapshotExcelSettings(): array
    {
        $settings = [];
        foreach ((new Config())->list() as $row) {
            $key = (string) ($row['config_key'] ?? '');
            if ($key === 'EXCEL_VAR') {
                $settings[$key] = (string) ($row['value_int'] ?? '');
            }
        }

        return $settings;
    }

    // settings.php 経由で Excel 出力設定を更新する
    protected function updateExcelSettings(array $settings): void
    {
        $response = $this->getClient()->post('settings.php', array_merge($settings, [
            'mode' => 'update',
        ]));

        $this->assertOk($response);
        $this->assertStringContainsString('settings.php', $response->headers . $response->body);
    }

    // 退避していた Excel 出力設定へ戻す
    protected function restoreExcelSettings(array $settings): void
    {
        if ($settings === []) {
            return;
        }

        $this->updateExcelSettings($settings);
    }
}
