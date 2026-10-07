<?php
/**
 * 商品更新確認画面
 * 遷移元：商品更新画面 product_edit
 */
session_start();
date_default_timezone_set('Asia/Tokyo');


// composerを使用
require_once(__DIR__ . '/../vendor/autoload.php');
use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Support\SessionHelper;
use Studiogau\Chandra\Support\Utility;
use Studiogau\Chandra\Auth\AuthService;
use Noblestock\Util\UtilCommon;
use Noblestock\DbLogic\UserRepository;
use Noblestock\DbLogic\Product;
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

// セッション管理ID biz003: 商品単票
$funcId = "biz003";

// マスタデータ取得
$makerList = SessionHelper::getMasterList("makerList");
$categoryList = SessionHelper::getMasterList("categoryList");
$unitList = SessionHelper::getMasterList("unitList");


// セッション情報取得
$productData = SessionHelper::getData($funcId, "productData");
$backTo = SessionHelper::getData($funcId, "backTo");
if (is_null($backTo)) {
	$backTo = "menu";
}

// エラー情報をクリア
SessionHelper::delData($funcId, "validation-errors");

// ロジック処理
$product = new Product();
if (isset($_POST["mode"])) {
	$csrfScope = (string)($_POST[Utility::getCsrfScopeFieldName()] ?? '');
	$csrfToken = (string)($_POST[Utility::getCsrfFieldName()] ?? '');
	if (!Utility::validatePostedCsrfToken($csrfScope, $csrfToken)) {
		$logger->error(basename(__FILE__).' op=product.edit.confirm msg="Invalid csrf token" page=product_edit_confirm.php user_id='.$auth->getCurrentUser()?->getLoginId());
		SessionHelper::FlushError(MessageConst::MSG_SYS_COMMON_900);
		header("Location: product_edit.php", true, 303);
		exit;
	}
	if ($_POST["mode"] === "confirm") {
		// 数値データは生値のまま受け取り、検証後に数値化
		$wholesaleRaw = (string)($_POST['wholesale_amount'] ?? '');
		$retailRaw = (string)($_POST["retail_amount"] ?? '');
		$sellRaw = (string)($_POST["sell_amount"] ?? '');
		$quantityRaw = (string)($_POST["quantity"] ?? '');
		$categoryIdRaw = (string)($_POST["category_id"] ?? '');
		$makerIdRaw = (string)($_POST["maker_id"] ?? '');
		$unitIdRaw = (string)($_POST["unit_id"] ?? '');
		$currentCategory = UtilCommon::getTargetArray($categoryList, "id", $categoryIdRaw);
		$currentMaker =    UtilCommon::getTargetArray($makerList, "id", $makerIdRaw);
		$currentUnit =     UtilCommon::getTargetArray($unitList, "id", $unitIdRaw);
		$productData = array("management_no" => $_POST["management_no"],
					"category_id" => $categoryIdRaw,
					"category_name" => $currentCategory["category_name"] ?? "",
					"maker_id" => $makerIdRaw,
					"maker_name" => $currentMaker["maker_name"] ?? "",
					"product_name" => $_POST["product_name"],
					"wholesale_amount" => $wholesaleRaw,
					"retail_amount" => $retailRaw,
					"sell_amount" => $sellRaw,
					"quantity" => $quantityRaw,
					"unit_id" => $unitIdRaw,
					"unit_name" => $currentUnit["unit_name"] ?? "",
					"storage_place" => $_POST["storage_place"],
					"image_file" => $_POST["image_file"],
					"remarks" => $_POST["remarks"],
					"remarks2" => $_POST["remarks2"]);
		SessionHelper::setData($funcId, "productData", $productData);


		// エラー項目=>エラーメッセージ
		$errors = [];
		// 必須入力チェック(管理番号)
		if ($productData["management_no"] == null || empty($productData["management_no"])) {
			$errors['management_no'] = MessageConst::MSG_VAL_PRODUCT_003;
		}
		// 必須入力チェック(商品名)
		if ($productData["product_name"] == null || empty($productData["product_name"])) {
			$errors['product_name'] = MessageConst::MSG_VAL_PRODUCT_003;
		}
		// 選択肢不正チェック
		if ($currentCategory === null) {
			$errors['category_id'] = MessageConst::MSG_VAL_PRODUCT_003;
		}
		if ($currentMaker === null) {
			$errors['maker_id'] = MessageConst::MSG_VAL_PRODUCT_003;
		}
		if ($currentUnit === null) {
			$errors['unit_id'] = MessageConst::MSG_VAL_PRODUCT_003;
		}
		// 数値チェック
		if ($wholesaleRaw !== '' && !is_numeric($wholesaleRaw)) {
			$errors['wholesale_amount'] = MessageConst::MSG_VAL_PRODUCT_004;
		}
		if ($retailRaw !== '' && !is_numeric($retailRaw)) {
			$errors['retail_amount'] = MessageConst::MSG_VAL_PRODUCT_004;
		}
		if ($sellRaw !== '' && !is_numeric($sellRaw)) {
			$errors['sell_amount'] = MessageConst::MSG_VAL_PRODUCT_004;
		}
		
		if (!empty($errors)) {

			// バリデーションエラーなので、入力画面をもう一度描画
			SessionHelper::setData($funcId, "validation-errors", $errors);
			header("Location: product_edit.php", true, 303);
			exit;
		}

		$productData["category_id"] = $currentCategory["id"];
		$productData["category_name"] = $currentCategory["category_name"];
		$productData["maker_id"] = $currentMaker["id"];
		$productData["maker_name"] = $currentMaker["maker_name"];
		$productData["unit_id"] = $currentUnit["id"];
		$productData["unit_name"] = $currentUnit["unit_name"];
		$productData["wholesale_amount"] = ($wholesaleRaw === '') ? 0 : (int)$wholesaleRaw;
		$productData["retail_amount"] = ($retailRaw === '') ? 0 : (int)$retailRaw;
		$productData["sell_amount"] = ($sellRaw === '') ? 0 : (int)$sellRaw;
		$productData["quantity"] = ($quantityRaw === '' || !is_numeric($quantityRaw)) ? 0 : (int)$quantityRaw;
		SessionHelper::setData($funcId, "productData", $productData);

	} else if ($_POST["mode"] === "update") {

		try{
			$updCnt = $product->update($productData);
			// 更新成功
			$resultMsg = Utility::replaceStr(MessageConst::MSG_OK_PRODUCT_011, $productData['management_no']);
			SessionHelper::flushSuccess($resultMsg);
			header("Location:".$backTo.".php", true, 303);
			exit;
		} catch (\Throwable $e) {
			$logger->error(basename(__FILE__) .  " ".$e->getMessage());
			SessionHelper::flushError(MessageConst::MSG_SYS_COMMON_900); 
			header("Location: product_edit.php", true, 303);
			exit;
		}

	}
}

if (is_null($productData)) {
	http_response_code(400);
	header('Content-Type: text/plain; charset=UTF-8');
	echo MessageConst::MSG_VAL_FILE_018;
	exit;
}
?>

<!DOCTYPE html>
<html lang="ja">
	<head>
		<?php $title = "商品更新確認"; require_once(__DIR__."/inc_head.php"); ?>
	</head>
	<body class="product">
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

		<?php include(__DIR__."/product_info.php"); // 商品詳細 ?>
		
		<!-- ボタン -->
		<form method="POST" class="d-flex justify-content-evenly">
			<?= Utility::renderCsrfHiddenInput('product_edit_confirm.form') ?>
			<button type="submit" formaction="product_edit.php" name="mode" value="back" class="btn btn-primary" >戻る</button>
			<button type="submit" formaction="product_edit_confirm.php" name="mode" value="update" class="btn btn-primary" >更新</button>
		</form>

	</main>
	<?php require_once(__DIR__."/inc_footer.php"); ?>
	</body>
</html>

<?php
?>
