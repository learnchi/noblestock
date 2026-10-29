<?php
namespace Noblestock\Logic;
/**
 * バーコードメニューコードからメニューを返却する
 * 使用例
 *     $router = new MenuRouter($auth);
 *     $target = $router->resolve($bcin);
 * 
 *     if ($target !== null) {
 *         header("Location: {$target}", true, 303);
 *         exit;
 *     }
 * 
 */
require_once(__DIR__ . '/../vendor/autoload.php');
use Studiogau\Chandra\Auth\AuthService;
use Noblestock\Logic\LogicConst;

class MenuRouter
{
    private array $map;
    private AuthService $auth;

    public function __construct(AuthService $auth)
    {
        $this->auth = $auth;
        $this->map =  [
            LogicConst::CMD_MENU => [    // メニュー
                'screen' => 'menu.php',
                'permission' => null,
            ],
            LogicConst::CMD_VIEW => [	// 商品表示
                'screen' => 'product_show.php',
                'permission' => 'product_show',
            ],
            LogicConst::CMD_STOCK => [	// 入庫
                'screen' => 'stock_in.php',
                'permission' => 'stock_in',
            ],
            LogicConst::CMD_SHIPPING => [	// 出庫
                'screen' => 'stock_out.php',
                'permission' => 'stock_out',
            ],
            LogicConst::CMD_LOCCHG => [	// 移動
                'screen' => 'stock_move.php',
                'permission' => 'stock_move',
            ],
            LogicConst::CMD_CREATE => [	// バーコード生成
                'screen' => 'barcode_create.php',
                'permission' => 'barcode_create',
            ],
        ];
    }

    public function resolve(string $command): ?string
    {
        if (!isset($this->map[$command])) {
            return null;
        }

        $entry = $this->map[$command];

        if ($entry['permission'] !== null) {
            if (!$this->auth->getCurrentUser()?->can($entry['permission'])) {
                return null;
            }
        }

        return $entry['screen'];
    }
}