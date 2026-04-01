<?php
/**
 * 商品登録
 */
session_cache_limiter("none");
@session_start();
date_default_timezone_set('Asia/Tokyo');


// composerを使用
require_once(__DIR__ . '/../vendor/autoload.php');
use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Support\SessionHelper;
use Studiogau\Chandra\Auth\AuthService;
use Studiogau\Chandra\Support\Utility;
use Noblestock\Logic\LogicConst;
use Noblestock\DbLogic\UserRepository;
use Noblestock\Logic\MessageConst;

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

// セッション管理ID biz003: 商品単票
$funcId = "biz003";

// マスタデータ取得
$makerList = SessionHelper::getMasterList("makerList");
$categoryList = SessionHelper::getMasterList("categoryList");
$unitList = SessionHelper::getMasterList("unitList");

// 商品データをセッションから取得　
$productData = SessionHelper::getData($funcId, "productData");
if (empty($productData)) {
	$productData = array("management_no" => "", 
					"category_id" => 1, 
					"maker_id" => 1, 
					"product_name" => "", 
					"wholesale_amount" => "", 
					"retail_amount" => "", 
					"sell_amount" => "", 
					"quantity" => "", 
					"unit_id" => 1, 
					"storage_place" => "", 
					"image_file" => "", 
					"remarks" => "", 
					"remarks2" => "");
}

// エラー情報を取得
$errors = SessionHelper::getData($funcId, "validation-errors");

// ロジック処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$csrfScope = (string)($_POST[Utility::getCsrfScopeFieldName()] ?? '');
	$csrfToken = (string)($_POST[Utility::getCsrfFieldName()] ?? '');
	if (!Utility::validatePostedCsrfToken($csrfScope, $csrfToken)) {
		$logger->error(basename(__FILE__).' op=product.create msg="Invalid csrf token" page=product_create.php user_id='.$auth->getCurrentUser()?->getUserId());
		SessionHelper::FlushError(MessageConst::MSG_SYS_COMMON_900);
		header("Location: " . $_SERVER['PHP_SELF'], true, 303);
		exit;
	}

	// リセット押下の場合は画面内容をクリアする
	if ($_POST['mode'] === 'reset') {
		SessionHelper::delData($funcId, "productData");
		SessionHelper::delData($funcId, "validation-errors");
		$productData = null;
		$errors = null;    // 確認画面(product_confirm)で設定したエラー情報
	}
	// スクロール位置をセッションに保持
	if (isset($_POST["pos"])) {
		SessionHelper::setData("biz002", "scrollPos", $_POST["pos"]);
	}

	// 画面表示はGETでredirect
	header("Location: " . $_SERVER['PHP_SELF'], true, 303);   // 302になることがあるため303を明示
	exit;
}

// バーコード規格の説明として表示する
$barPrtSize = intval(SessionHelper::getPref("BAR_PRT_SIZE"));
$barmsg = LogicConst::BAR_PRT_SIZE_DEFS[$barPrtSize] ?? LogicConst::BAR_PRT_SIZE_DEFS[1];
?>

<!DOCTYPE html>
<html lang="ja">
	<head>
		<?php $title = "商品登録"; require_once(__DIR__."/inc_head.php"); ?>
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
			<div><?=MessageConst::MSG_INF_PRODUCT_001 ?><br/><?=Utility::replaceStr(MessageConst::MSG_INF_PRODUCT_002, $barmsg) ?></div>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>

		<form method="post" action="product_confirm.php" class="needs-validation" novalidate>
			<?= Utility::renderCsrfHiddenInput('product_create.form') ?>
			<div class="row mb-3">
			<label for="management_no" class="col-sm-2 col-form-label">管理番号※</label>
		    <div class="col-sm-10">
				<input type="text" class="form-control <?= isset($errors['management_no']) ? 'is-invalid': '' ?>" id="management_no" name="management_no" maxlength="18" value="<?=Utility::h($productData['management_no']) ?>" required>
				<div class="invalid-feedback"><?= isset($errors['management_no']) ? Utility::h($errors['management_no']) : MessageConst::MSG_VAL_PRODUCT_003 ?></div><!-- required -->
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
					<option value="<?=Utility::h($wk['id'])?>" <?=$selected ?>><?=Utility::h($wk['category_name']) ?></option>
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
					<option value="<?=Utility::h($wk['id'])?>" <?=$selected ?>><?=Utility::h($wk['maker_name']) ?></option>
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
				<input type="text" class="form-control <?= isset($errors['product_name']) ? 'is-invalid': '' ?>" id="product_name" name="product_name" maxlength="100" value="<?=Utility::h($productData['product_name']) ?>" required>
				<div class="invalid-feedback"><?= isset($errors['product_name']) ? Utility::h($errors['product_name']) : MessageConst::MSG_VAL_PRODUCT_003 ?></div><!-- required -->
			</div>
			</div>
			
			<div class="row mb-3">
			<label for="wholesale_amount" class="col-sm-2 col-form-label">卸価格</label>
			<div class="col-sm-10">
				<input type="number" min="0" max="4294967295" id="wholesale_amount" name="wholesale_amount" class="form-control <?= isset($errors['wholesale_amount']) ? 'is-invalid': '' ?>" value="<?=Utility::h($productData['wholesale_amount']) ?>" />
				<div class="invalid-feedback"><?= isset($errors['wholesale_amount']) ? Utility::h($errors['wholesale_amount']) : MessageConst::MSG_VAL_PRODUCT_004 ?></div><!-- pattern -->
			</div>
			</div>
			
			
			<div class="row mb-3">
			<label for="retail_amount" class="col-sm-2 col-form-label">小売価格</label>
			<div class="col-sm-10">
				<input type="number" min="0" max="4294967295" id="retail_amount" name="retail_amount" class="form-control <?= isset($errors['retail_amount']) ? 'is-invalid': '' ?>" value="<?=Utility::h($productData['retail_amount']) ?>" />
				<div class="invalid-feedback"><?= isset($errors['retail_amount']) ? Utility::h($errors['retail_amount']) : MessageConst::MSG_VAL_PRODUCT_004 ?></div><!-- pattern -->
			</div>
			</div>
			
			<div class="row mb-3">
			<label for="sell_amount" class="col-sm-2 col-form-label">仕入原価</label>
			<div class="col-sm-10">
				<input type="number" min="0" max="4294967295" id="sell_amount" name="sell_amount" class="form-control <?= isset($errors['sell_amount']) ? 'is-invalid': '' ?>" value="<?=Utility::h($productData['sell_amount']) ?>" />
				<div class="invalid-feedback"><?= isset($errors['sell_amount']) ? Utility::h($errors['sell_amount']) : MessageConst::MSG_VAL_PRODUCT_004 ?></div><!-- pattern -->
			</div>
			</div>
			
			<div class="row mb-3">
			<label for="unit_id" class="col-sm-2 col-form-label">単位※</label>
			<div class="col-sm-10">
				<select id="unit_id" name="unit_id" class="form-select <?= isset($errors['unit_id']) ? 'is-invalid': '' ?>">
					<?php
					foreach ($unitList ?? [] as $i => $wk) {
						if($wk['id'] == $productData['unit_id']) {
							$selected = "selected";
						} else {
							$selected = "";
						}
					?>
					<option value="<?=Utility::h($wk['id'])?>" <?=$selected ?>><?=Utility::h($wk['unit_name']) ?></option>
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
				<input type="text" class="form-control" id="image_file" name="image_file"  maxlength="100" value="<?=Utility::h($productData['image_file']) ?>" />
				<button type="submit" class="btn btn-primary" formaction="image_create.php" name="filename" value="<?= $filename ?>">画像登録へ</button>
			</div>
			</div>
			
			<?php
			$imgFile = $productData['image_file'] ?? "";
			if (Utility::checkImageCreate(__DIR__."/".LogicConst::DIR_IMAGES."/", $imgFile, 240)) {
			?>
			<a href="<?=Utility::h(LogicConst::DIR_IMAGES.'/'.$imgFile) ?>" data-bs-toggle="modal" data-bs-target="#imageViewer" data-bs-image="<?=Utility::h(LogicConst::DIR_IMAGES.'/'.$imgFile) ?>"><img src="<?=Utility::h(LogicConst::DIR_IMAGES.'/s_'.$imgFile) ?>" width="110"/></a>
			<?php
			}
			?>
			
			<div class="row mb-3">
			<label for="storage_place" class="col-sm-2 col-form-label">保管場所</label>
			<div class="col-sm-10">
				<input type="text" class="form-control" id="storage_place" name="storage_place"  maxlength="100" value="<?=Utility::h($productData['storage_place']) ?>" />
			</div>
			</div>
			
			<div class="row mb-3">
			<label for="remarks" class="col-sm-2 col-form-label">備考</label>
			<div class="col-sm-10">
				<textarea class="form-control" id="remarks" name="remarks" rows="4"><?=Utility::h($productData['remarks']) ?></textarea>
			</div>
			</div>

			<div class="row mb-3">
			<label for="remarks2" class="col-sm-2 col-form-label">備考２</label>
			<div class="col-sm-10">
				<textarea class="form-control" id="remarks2" name="remarks2" rows="4"><?=Utility::h($productData['remarks2']) ?></textarea>
			</div>
			</div>

			<input type="hidden" name="quantity" value="0" />
			<input type="hidden" name="filename" value="<?= $filename ?>">

			<!-- ボタン -->
			<div class="d-flex justify-content-evenly">
				<a href="product_list.php" type="button" class="btn btn-primary">戻る</a>
				<button type="submit" class="btn btn-primary" formaction="product_create.php" name="mode" value="reset">リセット</button>
				<button type="submit" class="btn btn-primary" name="mode" value="confirm">登録</button>

			</div>
			
		</form>
		
	</main>
	<?php require_once(__DIR__."/inc_footer.php"); ?>
	</body>
</html>
