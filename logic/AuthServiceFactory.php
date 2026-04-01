<?php

declare(strict_types=1);

namespace Noblestock\Logic;

use Noblestock\DbLogic\UserRepository;
use Studiogau\Chandra\Auth\AuthService;
use Studiogau\Chandra\Logging\Logger;

/**
 * noblestock 用の AuthService を生成する小さなファクトリ。
 *
 * このアプリでは、単に UserRepository を渡した AuthService ではなく、
 * ログイン試行制限用の guard を組み込んだ AuthService を
 * 毎回同じ構成で生成したい。
 *
 * 生成処理をここに寄せることで、呼び出し側は
 * 「認証サービスを作るときに何を注入すべきか」を意識せずに済み、
 * menu.php などの入口で LoginAttempt の渡し忘れ以外の設定漏れを防ぎやすくしている。
 */
final class AuthServiceFactory
{
    /**
     * ログイン試行制限 guard を含んだ AuthService を生成する。
     *
     * 現時点では Web ログインで使う構成を 1 つに固定しており、
     * UserRepository と LoginRateLimitGuard を組み合わせて返す。
     */
    public static function create(?Logger $logger = null): AuthService
    {
        $logger = $logger ?? Logger::createDefault(dirname(__DIR__, 1));

        return new AuthService(
            new UserRepository(),
            $logger,
            [new LoginRateLimitGuard(null, $logger)]
        );
    }
}
