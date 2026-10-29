<?php

declare(strict_types=1);

use Noblestock\DbLogic\Config;
use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Database\Database;

final class ConfigUpdateTest extends TestCase
{
    /**
     * 既存の設定に同じ値を保存した場合、例外を発生させず変更件数0を返すことを確認する。
     */
    public function testSavingUnchangedExistingSettingSucceeds(): void
    {
        $database = $this->createMock(Database::class);
        $database->expects($this->once())->method('update')->willReturn(0);
        $database->expects($this->once())->method('fetchCount')
            ->with('SELECT COUNT(*) FROM configs WHERE config_key = :config_key', [
                ':config_key' => ['value' => 'EXCEL_VAR', 'datatype' => PDO::PARAM_STR],
            ])->willReturn(1);

        $this->assertSame(0, $this->configWithDatabase($database)->update($this->setting()));
    }

    /**
     * 存在しない設定キーを更新した場合、例外が発生することを確認する。
     */
    public function testMissingSettingStillFails(): void
    {
        $database = $this->createMock(Database::class);
        $database->method('update')->willReturn(0);
        $database->method('fetchCount')->willReturn(0);

        $this->expectException(RuntimeException::class);
        $this->configWithDatabase($database)->update($this->setting());
    }

    /**
     * 設定を1件変更した場合、存在確認の問い合わせを行わず変更件数1を返すことを確認する。
     */
    public function testChangedSettingSucceedsWithoutExistenceQuery(): void
    {
        $database = $this->createMock(Database::class);
        $database->method('update')->willReturn(1);
        $database->expects($this->never())->method('fetchCount');

        $this->assertSame(1, $this->configWithDatabase($database)->update($this->setting()));
    }

    /**
     * コンストラクターを実行せず、指定したDBモックを使う設定更新オブジェクトを生成する。
     */
    private function configWithDatabase(Database $database): Config
    {
        $reflection = new ReflectionClass(Config::class);
        $config = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('database')->setValue($config, $database);
        return $config;
    }

    /**
     * テストで更新するExcel出力設定のデータを返す。
     */
    private function setting(): array
    {
        return ['config_key' => 'EXCEL_VAR', 'value_int' => 1, 'value_str' => null];
    }
}
