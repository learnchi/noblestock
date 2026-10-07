<?php
namespace Noblestock\DbLogic;

/**
 * ログインユーザー情報をChandraに渡す
 **/
require_once(__DIR__."/User.php");

// composerを使用
require_once(__DIR__ . '/../vendor/autoload.php');
use Studiogau\Chandra\Auth\UserRepositoryInterface;
use Studiogau\Chandra\Auth\AuthException;
use Noblestock\Logic\LogicConst;
use Noblestock\Logic\MessageConst;

class UserRepository implements UserRepositoryInterface
{
    public function findByCredentials(string $userId, string $password): ?array
    {
        $userModel = new User();
        try {
            $row = $userModel->login($userId, $password);
        } catch (\InvalidArgumentException $e) {
            throw new AuthException(MessageConst::MSG_VAL_AUTH_001);
        }

        // カラム名を合わせる
        $ret['id'] = $row['id'];
        $ret['login_id'] = $row['login_id'];
        $ret['user_name'] = $row['user_name'];

        // authority の長さを揃える（事故防止）
        $auth = (string)($row['authority'] ?? '');
        if (strlen($auth) < LogicConst::AUTH_BITS) {
            $auth = str_pad($auth, LogicConst::AUTH_BITS, '0', STR_PAD_RIGHT);
        } elseif (strlen($auth) > LogicConst::AUTH_BITS) {
            $auth = substr($auth, 0, LogicConst::AUTH_BITS);
        }

        // 権限で許可された screen をフラットに集める
        $allowed = [];
        foreach (str_split($auth) as $index => $bit) {
            if ($bit !== '1') {
                continue;
            }
            $def = LogicConst::PERMISSION_DEFS[$index] ?? null;
            if ($def === null) {
                continue;
            }
            foreach (($def['screens'] ?? []) as $screen) {
                $allowed[] = $screen;
            }
        }

        // ユニーク化して配列で返す（Chandra側がそのまま受け取れる）
        $ret['permissions'] = array_values(array_unique($allowed));

        return $ret ?: null;

    }
}
