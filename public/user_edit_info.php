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
use Noblestock\Logic\MessageConst;
/**
 * 編集できるユーザー情報
 * $userDataが必要です。
 * エラーを表示する場合、$errorsが必要です。
 * $pwRequired: True: パスワード必須（ユーザー登録時） False: パスワード未入力可能（ユーザー更新時）
 */
$pwRequired = isset($pwRequired) ? $pwRequired: False;
?>

<input type="hidden" name="id" value="<?=Utility::h($userData['id'] ?? '') ?>">
<div class="row mb-3">
<label for="management_no" class="col-sm-2 col-form-label">ログインID</label>
<div class="col-sm-10">
	<input type="text" class="form-control form-control-sm <?= isset($errors['login_id']) ? 'is-invalid': '' ?>" id="login_id" name="login_id" maxlength="16" value="<?=Utility::h($userData['login_id'] ?? '') ?>" required>
	<div class="invalid-feedback"><?= isset($errors['login_id']) ? Utility::h($errors['login_id']) : MessageConst::MSG_SYS_USER_005 ?></div>
</div>
</div>

<div class="row mb-3">
<label for="password_hash" class="col-sm-2 col-form-label">パスワード</label>
<div class="col-sm-10">
	<input type="text" class="form-control form-control-sm <?= isset($errors['password_hash']) ? 'is-invalid': '' ?>" id="password_hash" name="password_hash" maxlength="128" minlength="8" value="<?php if($pwRequired) {echo Utility::h($userData['password_hash'] ?? ''); }  ?>" <?php if($pwRequired) {?>required<?php }  ?>>
	<div class="invalid-feedback"><?= isset($errors['password_hash']) ? Utility::h($errors['password_hash']) : MessageConst::MSG_SYS_USER_006 ?></div>
</div>
</div>

<div class="row mb-3">
<label for="user_name" class="col-sm-2 col-form-label">ユーザー名</label>
<div class="col-sm-10">
	<input type="text" class="form-control form-control-sm <?= isset($errors['user_name']) ? 'is-invalid': '' ?>" id="user_name" name="user_name" maxlength="40" value="<?=Utility::h($userData['user_name'] ?? '') ?>" required>
	<div class="invalid-feedback"><?= isset($errors['user_name']) ? Utility::h($errors['user_name']) : MessageConst::MSG_VAL_PRODUCT_003 ?></div>
</div>
</div>

<div class="row mb-3">
<label for="furigana" class="col-sm-2 col-form-label">フリガナ</label>
<div class="col-sm-10">
	<input type="text" class="form-control form-control-sm <?= isset($errors['furigana']) ? 'is-invalid': '' ?>" id="furigana" name="furigana" maxlength="40" value="<?=Utility::h($userData['furigana'] ?? '') ?>">
	<div class="invalid-feedback"><?= isset($errors['furigana']) ? Utility::h($errors['furigana']) : '' ?></div>
</div>
</div>

<div class="row mb-3">
<label for="email" class="col-sm-2 col-form-label">メール</label>
<div class="col-sm-10">
	<input type="text" class="form-control form-control-sm <?= isset($errors['email']) ? 'is-invalid': '' ?>" id="email" name="email" maxlength="80" value="<?=Utility::h($userData['email'] ?? '') ?>">
	<div class="invalid-feedback"><?= isset($errors['email']) ? Utility::h($errors['email']) : MessageConst::MSG_SYS_USER_007 ?></div>
</div>
</div>

<div class="row mb-3">
<label for="sort_order" class="col-sm-2 col-form-label">ソート順</label>
<div class="col-sm-10">
	<input type="number" min="0" max="4294967295" class="form-control form-control-sm <?= isset($errors['sort_order']) ? 'is-invalid': '' ?>" id="sort_order" name="sort_order" value="<?=Utility::h($userData['sort_order'] ?? '') ?>" required>
	<div class="invalid-feedback"><?= isset($errors['sort_order']) ? Utility::h($errors['sort_order']) : MessageConst::MSG_VAL_PRODUCT_004 ?></div><!-- required -->
</div>
</div>

<div class="row mb-3">
<label for="sort_order" class="col-sm-2 col-form-label">権限</label>
<div class="col-sm-10">
<div class="list-group list-group-flush list-group-horizontal flex-wrap">

<?php
$auth = (string)($userData['authority'] ?? '');

foreach (LogicConst::PERMISSION_DEFS as $i => $def) {
    $checked = (substr($auth, $i, 1) === '1') ? ' checked' : '';
?>
    <div class="form-check list-group-item">
        <input class="form-check-input" type="checkbox" name="auth_screen[]" value="<?=Utility::h($i)?>" id="auth_screen<?=Utility::h($i)?>" <?=$checked?>>
        <label class="form-check-label" for="auth_screen<?=Utility::h($i)?>">
            <?=$def['label']?>
        </label>
    </div>
<?php
}
?>

	</div><!-- .list-group -->
	<div class="invalid-feedback"><?= isset($errors['authority']) ? Utility::h($errors['authority']) : MessageConst::MSG_VAL_PRODUCT_003 ?></div>
</div>
</div>
