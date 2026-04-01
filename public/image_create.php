<?php
/**
 * 画像登録画面
 * 遷移元：商品更新 product_edit
 *        商品登録 product_create
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

// セッション管理ID 画像選択登録
$funcId = "biz007";
// backTo に許可する画面名
$allowedBackTo = ['product_create', 'product_edit'];

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
} else {
	$backTo = SessionHelper::getData($funcId, "backTo");
	if (!is_string($backTo) || !in_array($backTo, $allowedBackTo, true)) {
		http_response_code(400);
		header('Content-Type: text/plain; charset=UTF-8');
		echo MessageConst::MSG_VAL_FILE_018;
		exit;
	}
}

// 商品データを登録・更新画面セッションから取得
$productData = SessionHelper::getData('biz003', "productData");    // セッション管理ID biz003: 商品単票

// ロジック処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$csrfScope = (string)($_POST[Utility::getCsrfScopeFieldName()] ?? '');
	$csrfToken = (string)($_POST[Utility::getCsrfFieldName()] ?? '');
	if (!Utility::validatePostedCsrfToken($csrfScope, $csrfToken)) {
		$logger->error(basename(__FILE__).' op=image.create msg="Invalid csrf token" page=image_create.php user_id='.$auth->getCurrentUser()?->getUserId());
		SessionHelper::FlushError(MessageConst::MSG_SYS_COMMON_900);
		header("Location: " . $_SERVER['PHP_SELF']);
		exit;
	}

    if (($_POST["mode"] ?? '') === "imgup") {
        // 画像アップロード処理

		if (is_uploaded_file($_FILES["upfile"]["tmp_name"])) {
			$fnm = $_FILES["upfile"]["name"];

			// ファイル名チェック
			if (Utility::checkImageName($fnm)) {
				// ファイルサイズチェック
				if (Utility::checkImageFileSize($_FILES["upfile"]["size"], 2000000)) {
					$uploadPath = __DIR__."/".LogicConst::DIR_IMAGES."/".$fnm;
					if (is_dir($uploadPath)) {
						sessionHelper::flushError(MessageConst::MSG_SYS_IMAGE_009);
						$logger->warn(basename(__FILE__).' op=imgup msg="upload destination is a directory" page='.$filename.' path='.$uploadPath.' user_id='.$auth->getCurrentUser()?->getUserId());
					} else if (move_uploaded_file($_FILES["upfile"]["tmp_name"], $uploadPath)) {
						$productData['image_file'] = $fnm;
						Utility::checkImageCreate(__DIR__."/".LogicConst::DIR_IMAGES."/", $fnm, 240);
						sessionHelper::flushSuccess(MessageConst::MSG_OK_IMAGE_003);
					} else {
						// アップロードエラー
						sessionHelper::flushError(MessageConst::MSG_SYS_IMAGE_009);
						$logger->warn(basename(__FILE__).' op=imgup msg="image file upload error" page='.$filename.' user_id='.$auth->getCurrentUser()?->getUserId());
					}
				} else {
					// サイズオーバーエラーの表示
					sessionHelper::flushError(Utility::replaceStr(MessageConst::MSG_VAL_IMAGE_006, "2MB"));
				}
			} else {
				// ファイル名無効
				sessionHelper::flushError(MessageConst::MSG_VAL_IMAGE_007);
			}
		} else {
			// ファイルが選択されていない
			sessionHelper::flushError(MessageConst::MSG_VAL_FILE_001);
		}

    }
    if (($_POST["mode"] ?? '') === "delImage") {
        // 画像削除処理
		$delImg = __DIR__."/".LogicConst::DIR_IMAGES."/".$productData['image_file'];
		if (file_exists($delImg)) {
			if (!@unlink($delImg)) {
				// 削除失敗
				sessionHelper::flushError(Utility::replaceStr(MessageConst::MSG_SYS_IMAGE_010, $delImg));
				$logger->warn(basename(__FILE__).' op=delImage msg="image file delete error" page='.$filename.' filename='.$delImg.' user_id='.$auth->getCurrentUser()?->getUserId());

			} else {
				sessionHelper::flushSuccess(Utility::replaceStr(MessageConst::MSG_OK_IMAGE_004, $delImg));
			}
		}
		$delSmallImg = __DIR__."/".LogicConst::DIR_IMAGES."/"."/s_".$productData['image_file'];
		if (file_exists($delSmallImg)) {
			if (!@unlink($delSmallImg)) {
				// 削除失敗
				sessionHelper::flushError(Utility::replaceStr(MessageConst::MSG_SYS_IMAGE_011, $delSmallImg));
				$logger->warn(basename(__FILE__).' op=delImage msg="image thumbnail file delete error" page='.$filename.' filename='.$delSmallImg.' user_id='.$auth->getCurrentUser()?->getUserId());
			}
		}

		$productData['image_file'] = "";
    }
    else if (isset($_POST["imgName"])) {
		// アップロード済画像から選択
		$productData['image_file'] = $_POST["imgName"];
    }
	else if (isset($_POST["management_no"])) {
        // 商品データ受け取り
		$productData = array("management_no" => $_POST["management_no"],
						"category_id" => $_POST["category_id"],
						"maker_id" => $_POST["maker_id"],
						"product_name" => $_POST["product_name"],
						"wholesale_amount" => $_POST["wholesale_amount"],
						"retail_amount" => $_POST["retail_amount"],
						"sell_amount" => $_POST["sell_amount"],
						"quantity" => $_POST["quantity"],
						"unit_id" => $_POST["unit_id"],
						"storage_place" => $_POST["storage_place"],
						"image_file" => $_POST["image_file"],
						"remarks" => $_POST["remarks"],
						"remarks2" => $_POST["remarks2"]);
    }

	// 商品データを登録・更新画面セッションに設定
	SessionHelper::setData('biz003', "productData", $productData);    // セッション管理ID biz003: 商品単票

    // 画面表示はGETでredirect
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// 画像ファイル一覧取得
$dirImages = __DIR__."/".LogicConst::DIR_IMAGES."/";
$imgFileList = Utility::getFileName($dirImages);
$imgList = array();
for ($i = 0; $i < count($imgFileList); $i++) {
	$imgName = $imgFileList[$i];
	if (strpos($imgName, 's_') !== 0 && Utility::checkImageName($imgName)) {
		$imgList[] = $imgName;
	}
}
$imgCount = count($imgList);

// 画像ファイルパス
$imgFilePass = __DIR__."/".LogicConst::DIR_IMAGES."/".$productData['image_file'];


?>

<!DOCTYPE html>
<html lang="ja">
	<head>
		<?php $title = "画像登録"; require_once(__DIR__."/inc_head.php"); ?>
	</head>
	<body class="product">
	<?php require_once(__DIR__."/mdl_imageviewer.php"); ?>
	<?php $userName = $auth->getCurrentUser()?->getUserName(); require_once(__DIR__."/inc_nav.php"); ?>

	<main class="container py-2">

		<?php if (SessionHelper::hasFlushError()) : ?>
		<div class="alert alert-danger alert-dismissible fade show" role="alert">
			<div><?= Utility::h(SessionHelper::getFlushError()) ?></div>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>
		<?php endif; ?>

		<?php if (SessionHelper::hasFlushSuccess()) : ?>
		<div class="alert alert-success alert-dismissible fade show" role="alert">
			<div><?= Utility::h(SessionHelper::getFlushSuccess()) ?></div>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>
		<?php endif; ?>

		<!-- 案内 -->
		<div class="alert alert-info alert-dismissible fade show" role="alert">
			<div><?=MessageConst::MSG_INF_IMAGE_001 ?></div>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>

		<form method="post"  enctype="multipart/form-data" action="image_create.php">
			<?= Utility::renderCsrfHiddenInput('image_create.form') ?>
			<input type="file" name="upfile" class="form-input" maxlength="50" />
			<button type="submit" class="btn btn-primary" name="mode" value="imgup">アップロード</button>
		</form>

		<?php if (!empty($productData['image_file'])) : ?>
			<h3>選択画像</h3>
			<?=Utility::h($productData['image_file']) ?>

			<?php if (file_exists($imgFilePass)) : ?>
				<a href="<?=Utility::h(LogicConst::DIR_IMAGES.'/'.$productData['image_file']) ?>" data-bs-toggle="modal" data-bs-target="#imageViewer" data-bs-image="<?=Utility::h(LogicConst::DIR_IMAGES.'/'.$productData['image_file']) ?>">
					<img src="<?=Utility::h(LogicConst::DIR_IMAGES.'/s_'.$productData['image_file']) ?>"/>
				</a>

			<form method="post" action="image_create.php" class="js-confirm" data-confirm="<?= '画像を削除してよろしいですか？' ?>">
				<?= Utility::renderCsrfHiddenInput('image_create.form') ?>
				<button type="submit" class="btn btn-primary" name="mode" value="delImage">画像削除</button>
			</form>

			<?php else: ?>
				<?= MessageConst::MSG_INF_IMAGE_005 ?>
				<form method="post" action="image_create.php">
					<?= Utility::renderCsrfHiddenInput('image_create.form') ?>
					<button type="submit" class="btn btn-primary" name="mode" value="delImage" name="mode" value="delImage">画像ファイル名クリア</button>
				</form>
			<?php endif; ?>

		<?php endif;    // productData['image_file'] ?>

		<?php if ($imgCount > 0) : ?>

		<h3>アップロード済み画像ファイル</h3>
		<!-- 案内 -->
		<div class="alert alert-info alert-dismissible fade show" role="alert">
			<div><?=MessageConst::MSG_INF_IMAGE_002 ?></div>
			<!-- <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button> -->
		</div>

		<div class="row">
			<?php
			foreach ($imgList as $i => $imgName) :
				if (($productData['image_file'] ?? "") == $imgName) :
			?>

			<div class="card col-sm-4 col-md-3 col-lg-2 p-1">
			<img src="<?=Utility::h(LogicConst::DIR_IMAGES.'/s_'.$imgName) ?>" class="card-img-top card-img-fit" data-bs-toggle="modal" data-bs-target="#imageViewer" data-bs-image="<?=Utility::h(LogicConst::DIR_IMAGES.'/'.$imgName) ?>"/>
			<div class="card-body">
				<h5 class="card-title"><?=Utility::h($imgName) ?></h5>
				<p class="card-text">選択中</p>
			</div>
			</div><!-- card -->

			<?php else: ?>

			<div class="card col-sm-4 col-md-3 col-lg-2 p-1">
			<img src="<?=Utility::h(LogicConst::DIR_IMAGES.'/s_'.$imgName) ?>" class="card-img-top card-img-fit" class="card-img-top card-img-fit" data-bs-toggle="modal" data-bs-target="#imageViewer" data-bs-image="<?=Utility::h(LogicConst::DIR_IMAGES.'/'.$imgName) ?>" />
			<div class="card-body">
				<h5 class="card-title"><?=Utility::h($imgName) ?></h5>
				<form method="POST" action="image_create.php">
					<?= Utility::renderCsrfHiddenInput('image_create.form') ?>
					<input type="hidden" name="imgName" value="<?=Utility::h($imgName) ?>">
					<button type="submit" class="btn btn-primary">
						この画像を使用
					</button>
				</form>
			</div>
			</div><!-- card -->

			<?php
				endif;
			endforeach;
			?>
		</div><!-- row -->
		<?php  endif;  ?>

		<a href="<?=Utility::h($backTo) ?>.php" type="button" class="btn btn-primary">戻る</a>

	</main>
	<?php require_once(__DIR__."/inc_footer.php"); ?>
	</body>
</html>
