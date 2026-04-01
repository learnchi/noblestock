<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) {
	// 直接指定での実行はできない
    http_response_code(404);
    exit;
}
?>
<?php
use Studiogau\Chandra\Support\Utility;
use Noblestock\Logic\LogicConst;
/**
 * ユーザー情報
 * $userDataが必要です。
 */
?>

<table class="table table-sm table-responsive">
	<tbody>
		<tr>
			<th scope="row" class="text-nowrap">ログインID</th>
			<td><?=Utility::h($userData['login_id'] ?? '') ?></td>
		</tr>
		<tr>
			<th scope="row" class="text-nowrap">パスワード</th>
			<td><?=Utility::h($userData['password_hash'] ?? '') ?></td>
		</tr>
		<tr>
			<th scope="row" class="text-nowrap">ユーザー名</th>
			<td><?=Utility::h($userData['user_name'] ?? '') ?></td>
		</tr>
		<tr>
			<th scope="row" class="text-nowrap">フリガナ</th>
			<td><?=Utility::h($userData['furigana'] ?? '') ?></td>
		</tr>
		<tr>
			<th scope="row" class="text-nowrap">メール</th>
			<td><?=Utility::h($userData['email'] ?? '') ?></td>
		</tr>
		<tr>
			<th scope="row" class="text-nowrap">ソート順</th>
			<td><?=Utility::h($userData['sort_order'] ?? '') ?></td>
		</tr>
		<tr>
			<th scope="row" class="text-nowrap">権限</th>
			<td>
				<?php
				// authority を安全に文字列化（null対策）
				$auth = (string)($userData['authority'] ?? '');

				// PERMISSION_DEFS の順番で表示する
				foreach (LogicConst::PERMISSION_DEFS as $i => $def) {
					$checked = (substr($auth, $i, 1) === '1') ? '○' : '×';
					?>
					<?=$checked?> <?=$def['label']?>
					<?php
				}
				?>
			</td>
		</tr>
	</tbody>
</table>
