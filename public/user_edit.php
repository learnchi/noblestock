<?php
/**
 * ユーザー更新
 */
session_cache_limiter("none");
session_start();
date_default_timezone_set('Asia/Tokyo');


// composerを使用
require_once(__DIR__ . '/../vendor/autoload.php');
use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Support\Utility;
use Studiogau\Chandra\Support\SessionHelper;
use Studiogau\Chandra\Auth\AuthService;
use Noblestock\Logic\LogicConst;
use Noblestock\DbLogic\UserRepository;
use Noblestock\DbLogic\User;
use Noblestock\Logic\MessageConst;

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
// screenごとの権限チェック
$filename = basename(__FILE__, '.php');
if ($auth->getCurrentUser()?->can($filename) === false) {
    $logger->error(basename(__FILE__).' op=auth msg="Permission denied" page='.$filename.' user_id='.$auth->getCurrentUser()?->getLoginId());
	http_response_code(403);
	header('Content-Type: text/plain; charset=UTF-8');
	echo MessageConst::MSG_INF_AUTH_003;
	exit;
}

// セッション管理ID
$funcId = "mst905";

// ユーザーデータをセッションから取得　
$id = SessionHelper::getData($funcId, "id");

// サーバサイドバリデーションエラーを取得
$errors = SessionHelper::getData($funcId, "validation-errors");

// ロジック処理
$user = new User();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$csrfScope = (string)($_POST[Utility::getCsrfScopeFieldName()] ?? '');
	$csrfToken = (string)($_POST[Utility::getCsrfFieldName()] ?? '');
	if (!Utility::validatePostedCsrfToken($csrfScope, $csrfToken)) {
		$logger->error(basename(__FILE__).' op=user.edit msg="Invalid csrf token" page=user_edit.php user_id='.$auth->getCurrentUser()?->getLoginId());
		SessionHelper::FlushError(MessageConst::MSG_SYS_COMMON_900);
		header('Location: user_edit.php', true, 303);
		exit;
	}

	// USER_IDを取り出す
	if (isset($_POST["id"])) {
		// user_listから遷移
		$id = $_POST["id"];
		SessionHelper::setData($funcId, "id", $id);

		if (($_POST["mode"] ?? '') !== 'del') {
			SessionHelper::delData($funcId, "userData");
			$userData = null;
			SessionHelper::delData($funcId, "validation-errors");
			$errors = null;    // 確認画面(user_edit_confirm)で設定したエラー情報
		}
		
	}

	// スクロール位置をセッションに保持
	if (isset($_POST["pos"])) {
		SessionHelper::setData("mst902", "scrollPos", $_POST["pos"]);
	}

	$mode = $_POST["mode"] ?? '';
	if ($mode === "confirm" ) {
		// 更新エラーまたは更新確認画面から戻った
		// セッションから商品情報を戻す
	} else if ($mode === 'reset') {
		SessionHelper::delData($funcId, "userData");
		SessionHelper::delData($funcId, "validation-errors");
		$userData = null;
		$errors = null;    // 確認画面(user_edit_confirm)で設定したエラー情報
	} else if ($mode === 'del') {
		// ユーザー削除
		try {
			$userData = SessionHelper::getData($funcId, "userData");
			if (empty($userData) && !empty($id)) {
				$userData = $user->select($id);
			}
			$user->delete($id, null);
			SessionHelper::flushSuccess(Utility::replaceStr(MessageConst::MSG_OK_USER_010, $userData['login_id'] ?? ''));
		} catch (\Exception $e) { 
			$loginId = is_array($userData ?? null) ? ($userData['login_id'] ?? '') : '';
			$logger->error(
				basename(__FILE__)
				.' op=user.delete msg="Error occurred during user delete" page='.$filename.' user_id='
				.$auth->getCurrentUser()?->getLoginId()
				.' target_user_id='.$id
				.' detail='.$e->getMessage()
			);
			SessionHelper::FlushError(Utility::replaceStr(MessageConst::MSG_SYS_USER_011, $loginId));
		}
		header("Location: user_list.php", true, 303);
		exit;
	} 

	// 画面表示はGETでredirect
	header('Location: user_edit.php', true, 303);
	exit;

}

if (is_null($id)) {
	http_response_code(400);
	header('Content-Type: text/plain; charset=UTF-8');
	echo MessageConst::MSG_VAL_FILE_018;
	exit;
}

$userData = SessionHelper::getData($funcId, "userData");
if (empty($userData) || $id !== $userData['id']) {
	try {
		$userData = $user->select($id);
		$id = $userData['id'];
		SessionHelper::setData($funcId, "userData", $userData);
	} catch (\Exception $e) {
		$msg = Utility::replaceStr(MessageConst::MSG_SYS_USER_004, $id);
		SessionHelper::flushError($msg);
		$logger->error(basename(__FILE__).' op=user.select msg="Error occurred during product select" page='.$filename.' user_id='.$auth->getCurrentUser()?->getLoginId()).' detail='.$e->getMessage();
		header("Location: user_list.php", true, 302);
		exit;
	}
}
?>

<!DOCTYPE html>
<html lang="ja">
	<head>
		<?php $title = "ユーザー更新"; require_once(__DIR__."/inc_head.php"); ?>
		<script src="js/Validation.js"></script>
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
			<div><?=MessageConst::MSG_INF_USER_002 ?></div>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>

		<form method="post" action="user_edit_confirm.php" class="needs-validation" novalidate>
			<?= Utility::renderCsrfHiddenInput('user_edit.form') ?>

			<?php 
				// ユーザー情報
				include(__DIR__."/user_edit_info.php");
			?>

			<!-- ボタン -->
			<div class="d-flex justify-content-evenly">
				<a href="user_list.php" type="button" class="btn btn-primary">戻る</a>
				<button type="submit" class="btn btn-primary" formaction="user_edit.php" name="mode" value="reset">リセット</button>
				<button type="submit" class="btn btn-primary" name="mode" value="confirm">更新</button>
				<button type="submit" form="delForm" class="btn btn-primary" >削除</button>

			</div>
		</form>
		<form method="post" action="user_edit.php" id="delForm" class="js-confirm" data-confirm="<?= MessageConst::MSG_CNF_COMMON_016 ?>">
			<?= Utility::renderCsrfHiddenInput('user_edit.delete') ?>
			<input type="hidden" name="mode" value="del">
			<input type="hidden" name="login_id" value="<?=$userId ?>">
		</form>

	</main>
	<?php require_once(__DIR__."/inc_footer.php"); ?>
	</body>
</html>
