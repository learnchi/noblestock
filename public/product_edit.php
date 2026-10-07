<?php
/**
 * 商品更新画面
 * 遷移元：商品一覧 product_list
 *        実績詳細 sales_show 
 *        履歴一覧 history_list 
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
use Noblestock\DbLogic\Product;
use Noblestock\DbLogic\Stock;
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
// backTo に許可する画面名
$allowedBackTo = ['history_list', 'product_list', 'sales_show'];

// マスタデータ取得
$makerList = SessionHelper::getMasterList("makerList");
$categoryList = SessionHelper::getMasterList("categoryList");
$unitList = SessionHelper::getMasterList("unitList");

// management_noを取り出す 優先順位 ① POST ② クエリパラメータ ③ セッション
if (filter_input(INPUT_GET, 'mn')) {
	$mngNo = filter_input(INPUT_GET, 'mn');
	SessionHelper::setData($funcId, "mngNo", $mngNo);

	// 商品情報をselectするため、セッションを削除
	SessionHelper::delData($funcId, "productData");
	SessionHelper::delData($funcId, "validation-errors");
	$productData = null;
	$errors = null;    // 確認画面(product_confirm)で設定したエラー情報

} else {
	$mngNo = SessionHelper::getData($funcId, "mngNo") ?? "";
}

// rtnScreenを取り出す
$backTo = SessionHelper::getData($funcId, "backTo");

// サーバサイドバリデーションエラーを取得
$errors = SessionHelper::getData($funcId, "validation-errors");

// ロジック処理
$product = new Product();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$csrfScope = (string)($_POST[Utility::getCsrfScopeFieldName()] ?? '');
	$csrfToken = (string)($_POST[Utility::getCsrfFieldName()] ?? '');
	if (!Utility::validatePostedCsrfToken($csrfScope, $csrfToken)) {
		$logger->error(basename(__FILE__).' op=product.edit msg="Invalid csrf token" page=product_edit.php user_id='.$auth->getCurrentUser()?->getLoginId());
		SessionHelper::FlushError(MessageConst::MSG_SYS_COMMON_900);
		header('Location: product_edit.php', true, 303);
		exit;
	}

	// management_noを取り出す
	if (isset($_POST["mngNo"])) {
		$mngNo = $_POST["mngNo"];
		SessionHelper::setData($funcId, "mngNo", $mngNo);
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
		if ($backTo == 'product_list') {
			SessionHelper::setData("biz002", "scrollPos", $_POST["pos"]);
		} else if ($backTo == 'sales_show') {
			SessionHelper::setData("biz302", "scrollPos", $_POST["pos"]);
		} else if ($backTo == 'history_list') {
			SessionHelper::setData("biz305", "scrollPos", $_POST["pos"]);
		}
	}

	$mode = $_POST["mode"] ?? '';
	if ($mode === "confirm" ||  $mode === "back") {
		// 更新エラーまたは更新確認画面から戻ったまたは画像選択から戻った
		// セッションから商品情報を戻す
	} else if ($mode === "reset") {    // リセット
		// 商品情報をselectするため、セッションを削除
		SessionHelper::delData($funcId, "productData");
		SessionHelper::delData($funcId, "validation-errors");
		$productData = null;
		$errors = null;    // 確認画面(product_confirm)で設定したエラー情報
	} else if ($mode === "del") {    // 商品削除
		
		try{
			$rtndel = $product->delete($mngNo);
			SessionHelper::flushSuccess(Utility::replaceStr(MessageConst::MSG_OK_PRODUCT_012, $mngNo));

			header("Location: " .$backTo.".php", true, 303);
			exit;
		} catch (\Exception $e) {
			$msg = Utility::replaceStr(MessageConst::MSG_SYS_PRODUCT_013, $mngNo);
			SessionHelper::flushError($msg);
			$logger->error(basename(__FILE__).' op=product.delete msg="Error occurred during product delete" page='.$filename.' user_id='.$auth->getCurrentUser()?->getLoginId()).' detail='.$e->getMessage();
		}
	}

	// ----- いま確定している状態から「正規URL」を作る
	$getParams = ['mn' => $mngNo];
	$current = $_GET ?? [];
	$url = 'product_edit.php' . '?' . http_build_query($getParams);
	header('Location: ' . $url, true, 303);
	exit;
}

if (!is_string($backTo) || !in_array($backTo, $allowedBackTo, true)) {
	http_response_code(400);
	header('Content-Type: text/plain; charset=UTF-8');
	echo MessageConst::MSG_VAL_FILE_018;
	exit;
}

// セッションから商品情報を戻す
$productData = SessionHelper::getData($funcId, "productData");
if (is_null($productData)) {
	try {
		// 商品を取得
		$productData = $product->select($mngNo);
		$mngNo = $productData['management_no'];

		// 在庫数取得用
		$stock = new Stock();
		$locationStock = $stock->getStockPerLocation($productData['management_no']);
		$productData['location_stock'] = $locationStock;

		SessionHelper::setData($funcId, "productData", $productData);
	} catch (\Exception $e) {
		$msg = Utility::replaceStr(MessageConst::MSG_SYS_PRODUCT_009, $mngNo);
		SessionHelper::flushError($msg);
		$logger->error(basename(__FILE__).' op=product.select msg="Error occurred during product select" page='.$filename.' user_id='.$auth->getCurrentUser()?->getLoginId()).' detail='.$e->getMessage();
		header("Location: ".$backTo.".php", true, 302);
		exit;
	}
}
		

?>

<!DOCTYPE html>
<html lang="ja">
	<head>
		<?php $title = "商品更新"; require_once(__DIR__."/inc_head.php"); ?>
		<script src="js/Validation.js"></script>
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
			<div><?=MessageConst::MSG_INF_PRODUCT_008 ?></div>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>

		<form method="post" action="product_edit_confirm.php" class="needs-validation" novalidate>
			<?= Utility::renderCsrfHiddenInput('product_edit.form') ?>
			<div class="row mb-3">
			<label for="management_no" class="col-sm-2 col-form-label">管理番号</label>
		    <div class="col-sm-10">
				<input type="text" class="form-control form-control-sm <?= isset($errors['management_no']) ? 'is-invalid': '' ?>" id="management_no" name="management_no" readonly maxlength="18" value="<?=Utility::h($productData['management_no'] ?? '') ?>" required>
			</div>
			</div>

			<div class="row mb-3">
			<label for="category_id" class="col-sm-2 col-form-label">カテゴリ※</label>
			<div class="col-sm-10">
				<select id="category_id" name="category_id" class="form-select <?= isset($errors['category_id']) ? 'is-invalid': '' ?>">
					<?php
					foreach ($categoryList ?? [] as $i => $wk) {
						if($wk['id'] == $productData['category_id']) {
							$selected = "selected";
						} else {
							$selected = "";
						}
					?>
					<option value="<?=Utility::h($wk['id'] ?? '')?>" <?=$selected ?>><?=Utility::h($wk['category_name'] ?? '') ?></option>
					<?php
					}
					?>
				</select>
				<div class="invalid-feedback"><?= isset($errors['category_id']) ? Utility::h($errors['category_id']) : MessageConst::MSG_VAL_PRODUCT_003 ?></div>
			</div>
			</div>
			
			<div class="row mb-3">
			<label for="maker_id" class="col-sm-2 col-form-label">メーカー※</label>
			<div class="col-sm-10">
				<select id="maker_id" name="maker_id" class="form-select <?= isset($errors['maker_id']) ? 'is-invalid': '' ?>">
					<?php
					foreach ($makerList ?? [] as $i => $wk) {
						if($wk['id'] == $productData['maker_id']) {
							$selected = "selected";
						} else {
							$selected = "";
						}
					?>
					<option value="<?=Utility::h($wk['id'] ?? '')?>" <?=$selected ?>><?=Utility::h($wk['maker_name'] ?? '') ?></option>
					<?php
					}
					?>
				</select>
				<div class="invalid-feedback"><?= isset($errors['maker_id']) ? Utility::h($errors['maker_id']) : MessageConst::MSG_VAL_PRODUCT_003 ?></div>
			</div>
			</div>

			<div class="row mb-3">
			<label for="product_name" class="col-sm-2 col-form-label">商品名※</label>
			<div class="col-sm-10">
				<input type="text" class="form-control form-control-sm <?= isset($errors['product_name']) ? 'is-invalid': '' ?>" id="product_name" name="product_name" maxlength="100" value="<?=Utility::h($productData['product_name'] ?? '') ?>" required>
				<div class="invalid-feedback"><?= isset($errors['product_name']) ? Utility::h($errors['product_name']) : MessageConst::MSG_VAL_PRODUCT_003 ?></div><!-- required -->
			</div>
			</div>

			
			<div class="row mb-3">
			<label for="wholesale_amount" class="col-sm-2 col-form-label">卸価格</label>
			<div class="col-sm-10">
				<input type="number" min="0" max="4294967295" id="wholesale_amount" name="wholesale_amount" class="form-control form-control-sm <?= isset($errors['wholesale_amount']) ? 'is-invalid': '' ?>" value="<?=Utility::h($productData['wholesale_amount'] ?? '') ?>" />
				<div class="invalid-feedback"><?= isset($errors['wholesale_amount']) ? Utility::h($errors['wholesale_amount']) : MessageConst::MSG_VAL_PRODUCT_004 ?></div><!-- pattern -->
			</div>
			</div>
			
			
			<div class="row mb-3">
			<label for="retail_amount" class="col-sm-2 col-form-label">小売価格</label>
			<div class="col-sm-10">
				<input type="number" min="0" max="4294967295" id="retail_amount" name="retail_amount" class="form-control form-control-sm <?= isset($errors['retail_amount']) ? 'is-invalid': '' ?>" value="<?=Utility::h($productData['retail_amount'] ?? '') ?>" />
				<div class="invalid-feedback"><?= isset($errors['retail_amount']) ? Utility::h($errors['retail_amount']) : MessageConst::MSG_VAL_PRODUCT_004 ?></div><!-- pattern -->
			</div>
			</div>
			
			<div class="row mb-3">
			<label for="sell_amount" class="col-sm-2 col-form-label">仕入原価</label>
			<div class="col-sm-10">
				<input type="number" min="0" max="4294967295" id="sell_amount" name="sell_amount" class="form-control form-control-sm <?= isset($errors['sell_amount']) ? 'is-invalid': '' ?>" value="<?=Utility::h($productData['sell_amount'] ?? '') ?>" />
				<div class="invalid-feedback"><?= isset($errors['sell_amount']) ? Utility::h($errors['sell_amount']) : MessageConst::MSG_VAL_PRODUCT_004 ?></div><!-- pattern -->
			</div>
			</div>


			<div class="row mb-3">
			<label for="unit_id" class="col-sm-2 col-form-label">在庫数</label>
			<div class="col-sm-10">
				<?=Utility::h($productData['quantity'] ?? '') ?>
				<select id="unit_id" name="unit_id" class="form-select <?= isset($errors['unit_id']) ? 'is-invalid': '' ?>">
					<?php
					foreach ($unitList ?? [] as $i => $wk) {
						if($wk['id'] == $productData['unit_id']) {
							$selected = "selected";
						} else {
							$selected = "";
						}
					?>
					<option value="<?=Utility::h($wk['id'] ?? '')?>" <?=$selected ?>><?=Utility::h($wk['unit_name'] ?? '') ?></option>
					<?php
					}
					?>
				</select>
				<div class="invalid-feedback"><?= isset($errors['unit_id']) ? Utility::h($errors['unit_id']) : MessageConst::MSG_VAL_PRODUCT_003 ?></div>
			</div>
			</div>

			<div class="row mb-3">
			<label for="image_file" class="col-sm-2 col-form-label">画像</label>
			<div class="col-sm-10 d-flex">
				<input type="text" class="form-control" id="image_file" name="image_file"  maxlength="100" value="<?=Utility::h($productData['image_file'] ?? '') ?>" />
				<button type="submit" class="btn btn-primary" formaction="image_create.php" name="filename" value="<?= $filename ?>">画像登録へ</button>
			</div>
			</div>

			<?php
			$imgFile = $productData['image_file'] ?? "";
			if (Utility::checkImageCreate(__DIR__."/".LogicConst::DIR_IMAGES."/", $imgFile, 240)) {
			?>
			<a href="<?=Utility::h(LogicConst::DIR_IMAGES.'/'.$imgFile) ?>" data-bs-toggle="modal" data-bs-target="#imageViewer" data-bs-image="<?=Utility::h(LogicConst::DIR_IMAGES.'/'.$imgFile) ?>"><img src="<?=Utility::h(LogicConst::DIR_IMAGES.'/s_'.$imgFile) ?>" width="110" border="0" /></a>
			<?php
			}
			?>

			<div class="row mb-3">
			<label for="storage_place" class="col-sm-2 col-form-label">保管場所</label>
			<div class="col-sm-10">
				<input type="text" class="form-control" id="storage_place" name="storage_place"  maxlength="100" value="<?=Utility::h($productData['storage_place'] ?? '') ?>" />
			</div>
			</div>

			<div class="row mb-3">
			<label for="SHOP_QUANTITY" class="col-sm-2 col-form-label">店舗在庫数</label>
			<div class="col-sm-10">
				<input type="text" class="form-control-plaintext" id="SHOP_QUANTITY" readonly value="<?= Utility::h($productData['location_stock'] ?? '') ?>" /><!-- name属性を指定しない -->
			</div>
			</div>

			<div class="row mb-3">
			<label for="remarks" class="col-sm-2 col-form-label">備考</label>
			<div class="col-sm-10">
				<textarea class="form-control" id="remarks" name="remarks" rows="4"><?=Utility::h($productData['remarks'] ?? '') ?></textarea>
			</div>
			</div>

			<div class="row mb-3">
			<label for="remarks2" class="col-sm-2 col-form-label">備考２</label>
			<div class="col-sm-10">
				<textarea class="form-control" id="remarks2" name="remarks2" rows="4"><?=Utility::h($productData['remarks2'] ?? '') ?></textarea>
			</div>
			</div>

			<input type="hidden" name="quantity" value="<?=Utility::h($productData['quantity'] ?? '')?>" />

			<!-- ボタン -->
			<div class="d-flex justify-content-evenly py-2">
				<a class="btn btn-primary" href="<?=Utility::h($backTo ?? '')?>.php">戻る</a>
				<button type="submit" class="btn btn-primary" formaction="product_edit.php" name="mode" value="reset">リセット</button>
				<button type="submit" class="btn btn-primary" name="mode" value="confirm">更新</button>
				<button type="submit" form="delForm" class="btn btn-primary" >削除</button>
			</div>
			
		</form>
		
		<div class="d-flex justify-content-evenly py-2">
		<form method="post" action="product_edit.php" id="delForm" class="js-confirm" data-confirm="<?= MessageConst::MSG_CNF_COMMON_016 ?>">
			<?= Utility::renderCsrfHiddenInput('product_edit.delete') ?>
			<input type="hidden" name="mode" value="del">
			<input type="hidden" name="mngNo" value="<?=Utility::h($productData['management_no'] ?? '') ?>">
		</form>
		</div>
	</main>
	<?php require_once(__DIR__."/inc_footer.php"); ?>
	</body>
</html>
