<?php

declare(strict_types=1);

use Noblestock\Logic\LogicConst;
use Noblestock\Logic\MenuRouter;
use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Auth\AuthService;
use Studiogau\Chandra\Auth\LoginUser;
use Studiogau\Chandra\Auth\UserRepositoryInterface;

final class MenuRouterTest extends TestCase
{
    /**
     * メニューコマンドを解決し、権限不要でmenu.phpへ遷移先が決まることを確認する。
     */
    public function testResolveReturnsMenuForMenuCommand(): void
    {
        $router = new MenuRouter($this->createAuthService([]));

        $this->assertSame('menu.php', $router->resolve(LogicConst::CMD_MENU));
    }

    /**
     * 権限を持つユーザーで在庫入力コマンドを解決し、stock_in.phpが返ることを確認する。
     */
    public function testResolveReturnsScreenWhenPermissionAllowed(): void
    {
        $router = new MenuRouter($this->createAuthService(['stock_in']));

        $this->assertSame('stock_in.php', $router->resolve(LogicConst::CMD_STOCK));
    }

    /**
     * 権限を持たないユーザーで在庫入力コマンドを解決し、遷移先がnullになることを確認する。
     */
    public function testResolveReturnsNullWhenPermissionDenied(): void
    {
        $router = new MenuRouter($this->createAuthService([]));

        $this->assertNull($router->resolve(LogicConst::CMD_STOCK));
    }

    /**
     * 未定義コマンドを解決し、遷移先がnullになることを確認する。
     */
    public function testResolveReturnsNullForUnknownCommand(): void
    {
        $router = new MenuRouter($this->createAuthService(['stock_in']));

        $this->assertNull($router->resolve('UNKNOWN'));
    }

    private function createAuthService(array $permissions): AuthService
    {
        $repo = new class implements UserRepositoryInterface {
            public function findByCredentials(string $userId, string $password): ?array
            {
                return null;
            }
        };

        $auth = new AuthService($repo);
        $auth->setCurrentUser(new LoginUser('tester-id', 'tester', 'Tester', $permissions));

        return $auth;
    }
}
