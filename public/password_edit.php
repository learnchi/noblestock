<?php
/**
 * パスワード変更 画面
 */
session_cache_limiter("none");
session_start();
date_default_timezone_set('Asia/Tokyo');


// composerを使用
require_once(__DIR__ . '/../vendor/autoload.php');
use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Support\SessionHelper;
use Studiogau\Chandra\Support\Utility;
use Studiogau\Chandra\Auth\AuthService;
use Noblestock\Logic\LogicConst;
use Noblestock\Logic\MessageConst;
use Noblestock\Util\UtilCommon;
use Noblestock\DbLogic\UserRepository;
use Noblestock\DbLogic\User;

// ロガー
$logger = Logger::createDefault(dirname(__DIR__, 1));

// 認証チェック
$auth = new AuthService(new UserRepository(), $logger);
if (!$auth->checkUserSession()) {
	$logger->error(basename(__FILE__)." checkUserSession failed for user id id=".$auth->getCurrentUser()?->getLoginId());
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	SessionHelper::FlushError(MessageConst::MSG_INF_AUTH_002);
	// チェック結果がエラーの場合ログイン画面に遷移
	header("Location: index.php", true, 302);
	exit;
}
// screenごとの権限チェック不要


$user_id = $auth->getCurrentUser()?->getLoginId();

// ロジック処理
$user = new User();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST["mode"] ?? '') === "update") {
        $csrfScope = (string)($_POST[Utility::getCsrfScopeFieldName()] ?? '');
        $csrfToken = (string)($_POST[Utility::getCsrfFieldName()] ?? '');
        if (!Utility::validatePostedCsrfToken($csrfScope, $csrfToken)) {
            $logger->error(
                basename(__FILE__)
                .' op=changePassword msg="Invalid csrf token" page=password_edit.php user_id='
                .$auth->getCurrentUser()?->getLoginId()
            );
            SessionHelper::FlushError(MessageConst::MSG_SYS_COMMON_900);
            header('Location: password_edit.php', true, 303);
            exit;
        }
		
		$old_pass = (string)($_POST["old_pass"] ?? '');
		$new_pass = (string)($_POST["new_pass"] ?? '');
		// 画面の案内文どおり、サーバー側でも半角英数字 3-16 文字を確認
		if (!UtilCommon::isAcceptablePasswordInput($old_pass) || !UtilCommon::isValidPassword($new_pass)) {
			SessionHelper::FlushError(MessageConst::MSG_SYS_AUTH_006);
			header('Location: password_edit.php', true, 303);
			exit;
		}
		try {
			$user->changePassword($user_id, $old_pass, $new_pass);
			// 成功
			SessionHelper::FlushSuccess(MessageConst::MSG_OK_AUTH_005);
			// ログイン画面に遷移
			header("Location: index.php", true, 303);
			exit;
		} catch (\Throwable $e) { 
			$logger->error(
				basename(__FILE__)
				.' op=changePassword msg="Error occurred during password update" page=password_edit.php user_id='
				.$auth->getCurrentUser()?->getLoginId()
				.' detail='.$e->getMessage()
			);
			SessionHelper::FlushError(MessageConst::MSG_SYS_AUTH_006);
		}

	}
    // 画面表示はGETでredirect
    header('Location: password_edit.php', true, 303);
    exit;
}

?>

<!DOCTYPE html>
<html lang="ja">
	<head>
		<?php $title = "パスワード変更"; require_once(__DIR__."/inc_head.php"); ?>
		<script src="js/Validation.js"></script>
	</head>
<body class="maintenance">
	<?php $userName = $auth->getCurrentUser()?->getUserName(); require_once(__DIR__."/inc_nav.php"); ?>
	<main class="container py-2">
		<?php if (SessionHelper::hasFlushError()) { ?>
		<div class="alert alert-danger alert-dismissible fade show" role="alert">
			<div><?= Utility::h(SessionHelper::getFlushError()) ?></div>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
			</div>
		<?php } ?>

		<?php if (SessionHelper::hasFlushSuccess()) { ?>
		<div class="alert alert-success alert-dismissible fade show" role="alert">
			<div><?= Utility::h(SessionHelper::getFlushSuccess()) ?></div>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
			</div>
		<?php } ?>

		<!-- 案内 -->
		<div class="alert alert-info alert-dismissible fade show" role="alert">
			<div><?=MessageConst::MSG_INF_AUTH_004 ?></div>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>

		<form method="post" action="password_edit.php" class="needs-validation js-confirm" data-confirm="<?= MessageConst::MSG_CNF_AUTH_007 ?>" novalidate>
			<?= Utility::renderCsrfHiddenInput('password_edit.update') ?>

			<div class="row mb-3">
			<label for="user_id" class="col-sm-2 col-form-label">ログインID</label>
			<div class="col-sm-10">
				<input type="text" class="form-control form-control-sm form~control-plaintext" id="user_id" name="user_id" readonly value="<?=Utility::h($user_id) ?>">
			</div>
			</div>

			<div class="row mb-3">
			<label for="old_pass" class="col-sm-2 col-form-label">現在のパスワード</label>
			<div class="col-sm-10">
				<input type="password" class="form-control form-control-sm" id="old_pass" name="old_pass" maxlength="128" value="" autocomplete="current-password" required>
				<div class="invalid-feedback"><?= MessageConst::MSG_INF_AUTH_008 ?></div>
			</div>
			</div>

			<div class="row mb-3">
			<label for="old_pass" class="col-sm-2 col-form-label">新しいパスワード</label>
			<div class="col-sm-10">
				<input type="password" class="form-control form-control-sm" id="new_pass" name="new_pass" maxlength="128" minlength="8" value="" autocomplete="new-password" required>
				<div class="invalid-feedback"><?= MessageConst::MSG_INF_AUTH_009 ?></div>
			</div>
			</div>

			<!-- ボタン -->
			<div class="d-flex justify-content-evenly">
				<a href="menu_master.php" type="button" class="btn btn-primary">メニューへ</a>
				<button type="submit" class="btn btn-primary" name="mode" value="update">変更</button>
			</div>
			
		</form>

	</main>
	<?php require_once(__DIR__."/inc_footer.php"); ?>
	</body>
</html>
