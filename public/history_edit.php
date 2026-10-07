<?php
/**
 * 履歴更新画面
 * 遷移元：実績詳細 sales_show 
 *        履歴一覧 history_list 
 * 
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
use Noblestock\DbLogic\History;
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
$funcId = "biz302";
// backTo に許可する画面名
$allowedBackTo = ['history_list', 'sales_show'];

// マスタデータ取得
$historyNo = SessionHelper::getData($funcId, "historyNo");
$historyData = SessionHelper::getData($funcId, "historyData");
$backTo = SessionHelper::getData($funcId, "backTo");

// サーバサイドバリデーションエラーを取得
$errors = SessionHelper::getData($funcId, "validation-errors");

// ロジック処理
$history = new History();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$csrfScope = (string)($_POST[Utility::getCsrfScopeFieldName()] ?? '');
	$csrfToken = (string)($_POST[Utility::getCsrfFieldName()] ?? '');
	if (!Utility::validatePostedCsrfToken($csrfScope, $csrfToken)) {
		$logger->error(basename(__FILE__).' op=history.edit msg="Invalid csrf token" page=history_edit.php user_id='.$auth->getCurrentUser()?->getLoginId());
		SessionHelper::FlushError(MessageConst::MSG_SYS_COMMON_900);
		header('Location: history_edit.php', true, 303);
		exit;
	}

	// HISTORY_NOを取り出す
	if (isset($_POST["HISTORY_NO"])) {
		$historyNo = $_POST["HISTORY_NO"];
		SessionHelper::setData($funcId, "historyNo", $historyNo);
	} 

	// rtnScreenを取り出す
	if (isset($_POST["filename"])) {
		$backTo = $_POST["filename"];
		if (!in_array($backTo, $allowedBackTo, true)) {
			http_response_code(400);
			header('Content-Type: text/plain; charset=UTF-8');
			echo MessageConst::MSG_VAL_FILE_018;
			exit;
		}
		SessionHelper::setData($funcId, "backTo", $backTo);
	}
		// スクロール位置をセッションに保持
	if (isset($_POST["pos"])) {
		if ($backTo == 'sales_show') {
			SessionHelper::setData("biz302", "scrollPos", $_POST["pos"]);
		} else if ($backTo == 'history_list') {
			SessionHelper::setData("biz305", "scrollPos", $_POST["pos"]);
		}
	}

	$mode = $_POST["mode"] ?? '';
	if ($mode === "confirm" ||  $mode === "back") {
	} else if ($mode === "reset") {    // リセット
		// 履歴情報をselectするため、セッションを削除
		SessionHelper::delData($funcId, "historyData");
		SessionHelper::delData($funcId, "validation-errors");
		$productData = null;
		$errors = null;    // 確認画面(product_confirm)で設定したエラー情報

	} else if ($mode === "delhis" ) {    // 履歴削除

		$rtndel = 0;
		try {
			$history_no = $_POST["delhis"];
			$data['history_kbn'] = 9; 
			$rtndel = $history->updateByHistoryNo($data, $history_no);

			// 商品情報と入出庫実績との在庫数整合性チェック
			$stockData = $history->compareStock($historyData["management_no"], $historyData["branch_no"]);
			$his_stock = (int)($stockData["HIS_STOCK"] ?? 0);
			$pr_stock = (int)($stockData["PR_STOCK"] ?? 0);
			if ($his_stock !== $pr_stock) {
				SessionHelper::FlushSuccess(Utility::replaceStr(MessageConst::MSG_OK_HISTORY_002, $historyData["management_no"], $historyData["branch_no"], $historyData['history_yy']."/".$historyData['history_mm']."/".$historyData['history_dd']));
				SessionHelper::FlushError(Utility::replaceStr(MessageConst::MSG_INF_HISTORY_003, $pr_stock, $his_stock));
			} else {
				SessionHelper::FlushSuccess(Utility::replaceStr(MessageConst::MSG_OK_HISTORY_002, $historyData["management_no"], $historyData["branch_no"], $historyData['history_yy']."/".$historyData['history_mm']."/".$historyData['history_dd']));
			}
			header("Location: " . $backTo .".php", true, 303);   // 302になることがあるため303を明示
			exit;
		} catch (\Exception $e) { 
			SessionHelper::FlushError(Utility::replaceStr(MessageConst::MSG_SYS_HISTORY_005, $historyData["management_no"], $historyData["branch_no"], $historyData['history_yy']."/".$historyData['history_mm']."/".$historyData['history_dd']));
			$logger->error(basename(__FILE__).' op=history.update msg="update failed" page='.$filename.' user_id='.$auth->getCurrentUser()?->getLoginId()).' detail='.$e->getMessage();
		}
	}

	// 画面表示はGETでredirect
	header('Location: history_edit.php', true, 303);   // 302になることがあるため303を明示
	exit;
}

if (!is_string($backTo) || !in_array($backTo, $allowedBackTo, true)) {
	http_response_code(400);
	header('Content-Type: text/plain; charset=UTF-8');
	echo MessageConst::MSG_VAL_FILE_018;
	exit;
}

if (is_null($historyData)) {
	$historyData = $history->select($historyNo);
	SessionHelper::setData($funcId, "historyData", $historyData);
}
?>

<!DOCTYPE html>
<html lang="ja">
	<head>
		<?php $title = "履歴更新"; require_once(__DIR__."/inc_head.php"); ?>
		<script src="js/Validation.js"></script>
	</head>
	<body class="history">
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
			<div><?=MessageConst::MSG_INF_PRODUCT_008 ?></div>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>
		
		<form method="post" action="history_confirm.php" class="needs-validation" novalidate>
			<?= Utility::renderCsrfHiddenInput('history_edit.form') ?>

			<div class="row mb-3">
			<label class="col-sm-2 col-form-label">日付</label>
		    <div class="col-sm-10">
				<input type="text" class="form-control-plaintext form-control-sm" value="<?=Utility::h($historyData['history_yy']."/".$historyData['history_mm']."/".$historyData['history_dd']) ?>">
			</div>
			</div>

			<div class="row mb-3">
			<label class="col-sm-2 col-form-label">処理内容</label>
		    <div class="col-sm-10">
				<input type="text" class="form-control-plaintext form-control-sm" value="<?=History::getKbnName($historyData['history_kbn'])?>">
			</div>
			</div>
			
			<div class="row mb-3">
			<label class="col-sm-2 col-form-label">管理番号</label>
		    <div class="col-sm-10">
				<input type="text" class="form-control-plaintext form-control-sm" value="<?=Utility::h($historyData['management_no']) ?>">
			</div>
			</div>

			<div class="row mb-3">
			<label class="col-sm-2 col-form-label">枝番</label>
		    <div class="col-sm-10">
				<input type="text" class="form-control-plaintext form-control-sm" value="<?=Utility::h($historyData['branch_no']) ?>">
			</div>
			</div>

			<div class="row mb-3">
			<label class="col-sm-2 col-form-label">店舗</label>
		    <div class="col-sm-10">
				<input type="text" class="form-control-plaintext form-control-sm" value="<?=Utility::h($historyData['location_name']) ?>">
			</div>
			</div>

			<div class="row mb-3">
			<label class="col-sm-2 col-form-label">カテゴリ</label>
		    <div class="col-sm-10">
				<input type="text" class="form-control-plaintext form-control-sm" value="<?=Utility::h($historyData['category_name']) ?>">
			</div>
			</div>
			
			<div class="row mb-3">
			<label class="col-sm-2 col-form-label">メーカー</label>
		    <div class="col-sm-10">
				<input type="text" class="form-control-plaintext form-control-sm" value="<?=Utility::h($historyData['maker_name']) ?>">
			</div>
			</div>
			
			<div class="row mb-3">
			<label class="col-sm-2 col-form-label">商品名</label>
		    <div class="col-sm-10">
				<input type="text" class="form-control-plaintext form-control-sm" value="<?=Utility::h($historyData['product_name']) ?>">
			</div>
			</div>

				<tr>
				<?php
				if ($historyData['history_kbn'] == 0) {
				?>

			<div class="row mb-3">
			<label class="col-sm-2 col-form-label">入庫数※</label>
		    <div class="col-sm-10">
				<input type="number" min="0" max="4294967295" name="stock_in" class="form-control form-control-sm" value="<?=Utility::h($historyData['stock_in']) ?>" required>
				<div class="invalid-feedback"><?= isset($errors['stock_in']) ? Utility::h($errors['stock_in']) : MessageConst::MSG_VAL_PRODUCT_004 ?></div>
			</div>
			</div>

				<?php
				} else {
				?>


			<div class="row mb-3">
			<label class="col-sm-2 col-form-label">入庫数</label>
		    <div class="col-sm-10">
				<input type="text" class="form-control-plaintext form-control-sm" value="<?=Utility::h($historyData['stock_in']) ?>">
			</div>
			</div>

				<?php
				}
				?>
				</tr>
				<tr>
				<?php
				if ($historyData['history_kbn'] == 1) {
				?>


			<div class="row mb-3">
			<label class="col-sm-2 col-form-label">出庫数※</label>
		    <div class="col-sm-10">
				<input name="quantity" class="form-control form-control-sm"  type="number" min="0" max="4294967295" value="<?=Utility::h($historyData['quantity']) ?>" required>
				<div class="invalid-feedback"><?= isset($errors['quantity']) ? Utility::h($errors['quantity']) : MessageConst::MSG_VAL_PRODUCT_004 ?></div>
			</div>
			</div>

				<?php
				} else {
				?>


			<div class="row mb-3">
			<label class="col-sm-2 col-form-label">出庫数</label>
		    <div class="col-sm-10">
				<input type="text" name="quantity" class="form-control-plaintext form-control-sm" value="<?=Utility::h($historyData['quantity']) ?>">
			</div>
			</div>

				<?php
				}
				?>
				</tr>
				<tr>
				<?php
				if ($historyData['history_kbn'] == 5 || $historyData['history_kbn'] == 6) {
				?>


			<div class="row mb-3">
			<label class="col-sm-2 col-form-label">移動数※</label>
		    <div class="col-sm-10">
				<input type="number" min="0" max="4294967295" name="move_stock" class="form-control form-control-sm" value="<?=Utility::h($historyData['move_stock']) ?>" required>
				<div class="invalid-feedback"><?= isset($errors['move_stock']) ? Utility::h($errors['move_stock']) : MessageConst::MSG_VAL_PRODUCT_004 ?></div>
			</div>
			</div>

				<?php
				} else {
				?>



			<div class="row mb-3">
			<label class="col-sm-2 col-form-label">移動数</label>
		    <div class="col-sm-10">
				<input type="text" name="move_stock" class="form-control-plaintext form-control-sm" value="<?=Utility::h($historyData['move_stock']) ?>">
			</div>
			</div>

				<?php
				}
				?>
				</tr>
				<tr>
				<?php
				if ($historyData['history_kbn'] == 0 || $historyData['history_kbn'] == 1
				|| $historyData['history_kbn'] == 5 || $historyData['history_kbn'] == 6
				|| $historyData['history_kbn'] == 7) {
				?>

			<div class="row mb-3">
			<label class="col-sm-2 col-form-label">店舗在庫※</label>
		    <div class="col-sm-10">
				<input type="number" min="0" max="4294967295" name="location_stock" class="form-control form-control-sm" value="<?=Utility::h($historyData['location_stock']) ?>" required>
				<div class="invalid-feedback"><?= isset($errors['location_stock']) ? Utility::h($errors['location_stock']) : MessageConst::MSG_VAL_PRODUCT_004 ?></div>
			</div>
			</div>

				<?php
				} else {
				?>

			<div class="row mb-3">
			<label class="col-sm-2 col-form-label">店舗在庫</label>
		    <div class="col-sm-10">
				<input type="text" name="location_stock" class="form-control-plaintext form-control-sm" value="<?=Utility::h($historyData['location_stock']) ?>">
			</div>
			</div>

				<?php
				}
				?>
				
				<?php
				if ($historyData['history_kbn'] == 0 || $historyData['history_kbn'] == 1
				|| $historyData['history_kbn'] == 5 || $historyData['history_kbn'] == 6
				|| $historyData['history_kbn'] == 3 || $historyData['history_kbn'] == 7) {
				?>

			<div class="row mb-3">
			<label class="col-sm-2 col-form-label">総在庫数※</label>
		    <div class="col-sm-10">
				<input type="number" min="0" max="4294967295" name="stock" class="form-control form-control-sm" value="<?=Utility::h($historyData['stock']) ?>" required>
				<div class="invalid-feedback"><?= isset($errors['stock']) ? Utility::h($errors['stock']) : MessageConst::MSG_VAL_PRODUCT_004 ?></div>
			</div>
			</div>

				<?php
				} else {
				?>

			<div class="row mb-3">
			<label class="col-sm-2 col-form-label">総在庫数</label>
		    <div class="col-sm-10">
				<input type="text" name="stock" class="form-control-plaintext form-control-sm" value="<?=Utility::h($historyData['stock']) ?>">
			</div>
			</div>
			
				<?php
				}
				?>

			<div class="row mb-3">
			<label class="col-sm-2 col-form-label">実施日時</label>
		    <div class="col-sm-10">
				<input type="text" class="form-control-plaintext form-control-sm" value="<?=date("Y/n/j H:i:s", strtotime($historyData['created_at'])) ?>">
			</div>
			</div>

			<div class="row mb-3">
			<label class="col-sm-2 col-form-label">実施者</label>
		    <div class="col-sm-10">
				<input type="text" class="form-control-plaintext form-control-sm" value="<?=Utility::h($historyData['CREATE_USER_NAME']) ?>">
			</div>
			</div>

			<div class="row mb-3">
			<label class="col-sm-2 col-form-label">更新日時</label>
		    <div class="col-sm-10">
				<input type="text" class="form-control-plaintext form-control-sm" value="<?=date("Y/n/j H:i:s", strtotime($historyData['updated_at'])) ?>">
			</div>
			</div>

			<div class="row mb-3">
			<label class="col-sm-2 col-form-label">更新者</label>
		    <div class="col-sm-10">
				<input type="text" class="form-control-plaintext form-control-sm" value="<?=Utility::h($historyData['user_name'])?>">
			</div>
			</div>

			<!-- ボタン -->
			<div class="d-flex justify-content-evenly py-2">
				<a class="btn btn-primary" href="<?=Utility::h($backTo)?>.php">戻る</a>
			<?php
			if ($historyData['history_kbn'] == 0 || $historyData['history_kbn'] == 1
			|| $historyData['history_kbn'] == 5 || $historyData['history_kbn'] == 6
			|| $historyData['history_kbn'] == 3 || $historyData['history_kbn'] == 7) {
			?>
				<button type="submit" class="btn btn-primary" formaction="history_edit.php" name="mode" value="reset">リセット</button>
				<button type="submit" class="btn btn-primary" name="mode" value="confirm">更新</button>
			<?php
			}
			?>
				<button type="submit" form="delForm" class="btn btn-primary" >削除</button>
			</div>
		</form>

		<div class="d-flex justify-content-evenly py-2">
		<form method="post" action="history_edit.php" id="delForm" class="js-confirm" data-confirm="<?= MessageConst::MSG_CNF_COMMON_016 ?>">
			<?= Utility::renderCsrfHiddenInput('history_edit.delete') ?>
			<input type="hidden" name="mode" value="delhis">
			<input type="hidden" name="delhis" value="<?=Utility::h($historyData['id']) ?>">
		</form>
		</div>

	</main>
	<?php require_once(__DIR__."/inc_footer.php"); ?>
	</body>
</html>
