<?php
/**
 * ユーザー更新確認
 */
session_cache_limiter("none");
@session_start();
date_default_timezone_set('Asia/Tokyo');


// composerを使用
require_once(__DIR__ . '/../vendor/autoload.php');
use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Support\Utility;
use Studiogau\Chandra\Support\SessionHelper;
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
	$logger->error(basename(__FILE__)." checkUserSession failed for user id id=".$auth->getCurrentUser()?->getUserId());
	if (session_status() !== PHP_SESSION_ACTIVE) {
		@session_start();
	}
	SessionHelper::FlushError(MessageConst::MSG_INF_AUTH_002);
	// チェック結果がエラーの場合ログイン画面に遷移
	header("Location: index.php");
	exit;
}
// screenごとの権限チェック
$filename = basename(__FILE__, '.php');
if ($auth->getCurrentUser()?->can($filename) === false) {
    $logger->error(basename(__FILE__).' op=auth msg="Permission denied" page='.$filename.' user_id='.$auth->getCurrentUser()?->getUserId());
	http_response_code(403);
	header('Content-Type: text/plain; charset=UTF-8');
	echo MessageConst::MSG_INF_AUTH_003;
	exit;
}

// セッション管理ID
$funcId = "mst905";
// ユーザーデータをセッションから取得　
$userData = SessionHelper::getData($funcId, "userData");

// サーバサイドバリデーションエラーをクリア
SessionHelper::delData($funcId, "validation-errors");

// ロジック処理
$user = new User();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$csrfScope = (string)($_POST[Utility::getCsrfScopeFieldName()] ?? '');
	$csrfToken = (string)($_POST[Utility::getCsrfFieldName()] ?? '');
	if (!Utility::validatePostedCsrfToken($csrfScope, $csrfToken)) {
		$logger->error(basename(__FILE__).' op=user.edit.confirm msg="Invalid csrf token" page=user_edit_confirm.php user_id='.$auth->getCurrentUser()?->getUserId());
		SessionHelper::FlushError(MessageConst::MSG_SYS_COMMON_900);
		header("Location: user_edit.php");
		exit;
	}
	if (($_POST["mode"] ?? '') === "confirm") {  // 003画面から登録ボタン押下

		$userData = SessionHelper::getData($funcId, "userData");
		$userData["id"] = $_POST["id"];
		$userData["login_id"] = $_POST["login_id"];
		$userData["password_hash"] = $_POST["password_hash"];
		$userData["user_name"] = $_POST["user_name"];
		$userData["furigana"] = $_POST["furigana"];
		$userData["email"] = $_POST["email"];
		$userData["sort_order"] = $_POST["sort_order"];

		// POSTされたチェック（例: ["0","3","18"]）を配列で取得
		$auth_screen = [];
		if (isset($_POST['auth_screen']) && is_array($_POST['auth_screen'])) {
			$auth_screen = $_POST['auth_screen'];
		}

		// 探索を速くするため Set 化
		$selected = array_fill_keys(array_map('strval', $auth_screen), true);

		$authority = "";

		foreach (LogicConst::PERMISSION_DEFS as $i => $def) {
			$authsc = isset($selected[(string)$i]) ? "1" : "0";
			$authority .= $authsc;

		}
		$userData["authority"] = $authority;

		SessionHelper::setData($funcId, "userData", $userData);
		
		// エラー項目=>エラーメッセージ
		$errors = [];
		if (!Utility::checkAlphanumeric($userData["login_id"], 3, 16)) {
			$errors['login_id'] = MessageConst::MSG_SYS_USER_005;

		}
		if ($userData["password_hash"] != "" && !UtilCommon::isValidPassword($userData["password_hash"])) {
			$errors['password_hash'] = MessageConst::MSG_SYS_USER_006;
		}
		if ($userData["user_name"] == "") {
			$errors['user_name'] = MessageConst::MSG_VAL_PRODUCT_003;
		}
		if ($userData["email"] != "" && filter_var($userData["email"], FILTER_VALIDATE_EMAIL) == false) {
			$errors['email'] = MessageConst::MSG_SYS_USER_007;
		}
		if (!UtilCommon::isUnsignedInt32String($userData["sort_order"])) {
			$errors['sort_order'] = MessageConst::MSG_VAL_PRODUCT_004;
		}

		// ユーザーID存在チェック
		$existedUserId = $user->getIdByUserId($userData["login_id"], $userData["id"]);

		if ($existedUserId > 0) {
			$errors['login_id'] = MessageConst::MSG_SYS_USER_003;
		}

		if (!empty($errors)) {

			// バリデーションエラーなので、入力画面をもう一度描画
			SessionHelper::setData($funcId, "validation-errors", $errors);
    		header("Location: user_edit.php");
			exit;
		}

	}
	if (($_POST["mode"] ?? '') === "update") {
		try {
			$updCnt = $user->update($userData);
			SessionHelper::flushSuccess(Utility::replaceStr(MessageConst::MSG_OK_USER_009, $userData['login_id']));
			header("Location: user_list.php");
			exit;
		} catch (\Throwable $e) { 
			$logger->error(basename(__FILE__).' op=user.update msg="Error occurred during user update" page='.$filename.' user_id='.$auth->getCurrentUser()?->getUserId()).' detail='.$e->getMessage();
			SessionHelper::flushError(MessageConst::MSG_SYS_COMMON_900);
    		header("Location: user_edit.php");
			exit;
		}
	}
    // 画面表示はGETでredirect
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

if (is_null($userData)) {
	http_response_code(400);
	header('Content-Type: text/plain; charset=UTF-8');
	echo MessageConst::MSG_VAL_FILE_018;
	exit;
}
?>

<!DOCTYPE html>
<html lang="ja">
	<head>
		<?php $title = "ユーザー更新確認"; require_once(__DIR__."/inc_head.php"); ?>
	</head>
	<body class="maintenance">
	<?php require_once(__DIR__."/mdl_imageviewer.php"); ?>
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
			<div><?=MessageConst::MSG_INF_PRODUCT_007 ?></div>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>

		<?php 
			// ユーザー情報
			include(__DIR__."/user_info.php");
		?>

		<!-- ボタン -->
		<form method="POST" class="d-flex justify-content-evenly">
			<?= Utility::renderCsrfHiddenInput('user_edit_confirm.form') ?>
			<button type="submit" formaction="user_edit.php" name="mode" value="back" class="btn btn-primary" >戻る</button>
			<button type="submit" formaction="user_edit_confirm.php" name="mode" value="update" class="btn btn-primary" >登録</button>
		</form>
	</main>
	<?php require_once(__DIR__."/inc_footer.php"); ?>
	</body>
</html>
