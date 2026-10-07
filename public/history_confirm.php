<?php
/**
 * 履歴更新確認
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
use Noblestock\Logic\MessageConst;
use Noblestock\DbLogic\UserRepository;
use Noblestock\DbLogic\History;

// ロガー
$logger = Logger::createDefault(dirname(__DIR__, 1));
$logger->info("POST=".json_encode($_POST,JSON_UNESCAPED_UNICODE));

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

// 履歴データをセッションから取得
$historyData = SessionHelper::getData($funcId, "historyData");

// rtnScreenを取り出す
$backTo = SessionHelper::getData($funcId, "backTo");
if (!is_string($backTo) || !in_array($backTo, $allowedBackTo, true)) {
	http_response_code(400);
	header('Content-Type: text/plain; charset=UTF-8');
	echo MessageConst::MSG_VAL_FILE_018;
	exit;
}

// エラー情報をクリア
SessionHelper::delData($funcId, "validation-errors");

// ロジック処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$csrfScope = (string)($_POST[Utility::getCsrfScopeFieldName()] ?? '');
	$csrfToken = (string)($_POST[Utility::getCsrfFieldName()] ?? '');
	if (!Utility::validatePostedCsrfToken($csrfScope, $csrfToken)) {
		$logger->error(basename(__FILE__).' op=history.confirm msg="Invalid csrf token" page=history_confirm.php user_id='.$auth->getCurrentUser()?->getLoginId());
		SessionHelper::FlushError(MessageConst::MSG_SYS_COMMON_900);
		header("Location: history_edit.php", true, 303);
		exit;
	}

	if (isset($_POST["mode"])) {
		if ($_POST["mode"] === "confirm") {
			// 数値データは生値のまま受け取り、入力チェック後に数値化
			$stockInRaw = (string)($_POST['stock_in'] ?? '');
			$quantityRaw = (string)($_POST['quantity'] ?? '');
			$moveStockRaw = (string)($_POST['move_stock'] ?? '');
			$locationStockRaw = (string)($_POST['location_stock'] ?? '');
			$stockRaw = (string)($_POST['stock'] ?? '');
			$historyData["stock_in"] = $stockInRaw;
			$historyData["quantity"] = $quantityRaw;
			$historyData["move_stock"] = $moveStockRaw;
			$historyData["location_stock"] = $locationStockRaw;
			$historyData["stock"] = $stockRaw;
			SessionHelper::setData($funcId, "historyData", $historyData);

			// エラー項目=>エラーメッセージ
			$errors = [];

			// 数値チェック
			if ($stockInRaw !== '' && !is_numeric($stockInRaw)) {
				$errors['stock_in'] = MessageConst::MSG_VAL_PRODUCT_004;
			}
			if ($quantityRaw !== '' && !is_numeric($quantityRaw)) {
				$errors['quantity'] = MessageConst::MSG_VAL_PRODUCT_004;
			}
			if ($moveStockRaw !== '' && !is_numeric($moveStockRaw)) {
				$errors['move_stock'] = MessageConst::MSG_VAL_PRODUCT_004;
			}
			if ($locationStockRaw !== '' && !is_numeric($locationStockRaw)) {
				$errors['location_stock'] = MessageConst::MSG_VAL_PRODUCT_004;
			}
			if ($stockRaw !== '' && !is_numeric($stockRaw)) {
				$errors['stock'] = MessageConst::MSG_VAL_PRODUCT_004;
			}

			if (!empty($errors)) {

				// バリデーションエラーなので、入力画面をもう一度描画
				SessionHelper::setData($funcId, "validation-errors", $errors);
				header("Location: history_edit.php", true, 303);
				exit;
			}

			$historyData["stock_in"] = ($stockInRaw === '') ? 0 : (int)$stockInRaw;
			$historyData["quantity"] = ($quantityRaw === '') ? 0 : (int)$quantityRaw;
			$historyData["move_stock"] = ($moveStockRaw === '') ? 0 : (int)$moveStockRaw;
			$historyData["location_stock"] = ($locationStockRaw === '') ? 0 : (int)$locationStockRaw;
			$historyData["stock"] = ($stockRaw === '') ? 0 : (int)$stockRaw;
			SessionHelper::setData($funcId, "historyData", $historyData);

		} else if ($_POST["mode"] === "update") {
			$history = new History();
			try{
				$updCnt = $history->updateByHistoryNo($historyData, $historyData['id']);

				// 商品情報と入出庫実績との在庫数整合性チェック
				$stockData = $history->compareStock($historyData["management_no"], $historyData["branch_no"]);
				$his_stock = (int)($stockData["HIS_STOCK"] ?? 0);
				$pr_stock = (int)($stockData["PR_STOCK"] ?? 0);
				if ($his_stock !== $pr_stock) {
					// 商品情報と履歴情報で在庫数不一致
					SessionHelper::flushSuccess(Utility::replaceStr(MessageConst::MSG_OK_HISTORY_001, $historyData['management_no'], $historyData["branch_no"]));
					SessionHelper::flushError(Utility::replaceStr(MessageConst::MSG_INF_HISTORY_003, $pr_stock, $his_stock));
				} else {
					// 更新成功
					SessionHelper::FlushSuccess(Utility::replaceStr(MessageConst::MSG_OK_HISTORY_001,$historyData['management_no'],$historyData["branch_no"]));
				}
				header("Location:".$backTo.".php", true, 303);
				exit;
			} catch (Exception $e) {
    			$logger->error(basename(__FILE__).' op=history.update msg="update failed" page='.$filename.' user_id='.$auth->getCurrentUser()?->getLoginId()).' detail='.$e->getMessage();
				SessionHelper::flushError(Utility::replaceStr(MessageConst::MSG_SYS_HISTORY_004,$historyData['management_no'],$historyData["branch_no"])); 
				header("Location: history_edit.php", true, 303);
				exit;
			}
		}
	}
}
if (is_null($historyData)) {
	http_response_code(400);
	header('Content-Type: text/plain; charset=UTF-8');
	echo MessageConst::MSG_VAL_FILE_018;
	exit;
}
?>

<!DOCTYPE html>
<html lang="ja">
	<head>
		<?php $title = "履歴更新確認"; require_once(__DIR__."/inc_head.php"); ?>
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
			<div><?=MessageConst::MSG_INF_PRODUCT_007 ?></div>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>

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

		<div class="row mb-3">
		<label class="col-sm-2 col-form-label">入庫数</label>
		<div class="col-sm-10">
			<input type="text" class="form-control-plaintext form-control-sm" value="<?=Utility::h($historyData['stock_in']) ?>">
		</div>
		</div>

		<div class="row mb-3">
		<label class="col-sm-2 col-form-label">出庫数</label>
		<div class="col-sm-10">
			<input type="text" name="quantity" class="form-control-plaintext form-control-sm" value="<?=Utility::h($historyData['quantity']) ?>">
		</div>
		</div>

		<div class="row mb-3">
		<label class="col-sm-2 col-form-label">移動数</label>
		<div class="col-sm-10">
			<input type="text" name="move_stock" class="form-control-plaintext form-control-sm" value="<?=Utility::h($historyData['move_stock']) ?>">
		</div>
		</div>

		<div class="row mb-3">
		<label class="col-sm-2 col-form-label">店舗在庫</label>
		<div class="col-sm-10">
			<input type="text" name="location_stock" class="form-control-plaintext form-control-sm" value="<?=Utility::h($historyData['location_stock']) ?>">
		</div>
		</div>

		<div class="row mb-3">
		<label class="col-sm-2 col-form-label">総在庫数</label>
		<div class="col-sm-10">
			<input type="text" name="stock" class="form-control-plaintext form-control-sm" value="<?=Utility::h($historyData['stock']) ?>">
		</div>
		</div>

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
		<form method="POST" class="d-flex justify-content-evenly">
			<?= Utility::renderCsrfHiddenInput('history_confirm.form') ?>
			<button type="submit" formaction="history_edit.php" name="mode" value="back" class="btn btn-primary" >戻る</button>
			<button type="submit" formaction="history_confirm.php" name="mode" value="update" class="btn btn-primary" >更新</button>
		</form>
	</main>
	<?php require_once(__DIR__."/inc_footer.php"); ?>
	</body>
</html>
