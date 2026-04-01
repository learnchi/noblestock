<?php
/**
 * 画像一括登録 画面
 */
session_cache_limiter("none");
@session_start();
date_default_timezone_set('Asia/Tokyo');


// composerを使用
require_once(__DIR__ . '/../vendor/autoload.php');
use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Support\SessionHelper;
use Studiogau\Chandra\Support\Utility;
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

// 実行時間
set_time_limit(LogicConst::RUN_TIME_LIMIT);

// メモリアップ
ini_set("memory_limit",LogicConst::MEMORY_LIMIT);

// セッション管理ID 画像選択登録
$funcId = "mst005";

// rtnlistを取り出す
$rtnlist = SessionHelper::getData($funcId, "rtnlist");

// ロジック処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$csrfScope = (string)($_POST[Utility::getCsrfScopeFieldName()] ?? '');
	$csrfToken = (string)($_POST[Utility::getCsrfFieldName()] ?? '');
	if (!Utility::validatePostedCsrfToken($csrfScope, $csrfToken)) {
		$logger->error(basename(__FILE__).' op=image.bulk.create msg="Invalid csrf token" page=image_bulk_create.php user_id='.$auth->getCurrentUser()?->getUserId());
		SessionHelper::FlushError(MessageConst::MSG_SYS_COMMON_900);
		header("Location: " . $_SERVER['PHP_SELF']);
		exit;
	}
    if (($_POST["mode"] ?? '') === "upload") {
		// rtnlistをクリア
		$rtnlist = null;
		SessionHelper::delData($funcId, "rtnlist");

		$dirName = __DIR__."/".LogicConst::DIR_IMAGES."/";
		foreach ($_FILES['upfile']['tmp_name'] as $i => $tmpName) {
			$msg = "";
			if (is_uploaded_file($tmpName)) {
				$imgFileName = $_FILES["upfile"]["name"][$i];
				if (Utility::checkImageName($imgFileName)) {
					if (Utility::checkImageFileSize($_FILES["upfile"]["size"][$i], 2000000)) {
						$dirFileName = $dirName."/".$imgFileName;
						if (move_uploaded_file($tmpName, $dirFileName)) {
							// アップロード成功
							//chmod($dirFileName, 0644);
							// サムネイル作成
							$rtnSmall = Utility::creatSmallImage($dirName, $imgFileName, 240);
							if (!$rtnSmall) {
								$logger->warn(basename(__FILE__, '.php').' op=bulk.imgup msg="failed to make thumbnail image." page='.$filename.' file='.$dirFileName.' user_id='.$auth->getCurrentUser()?->getUserId());
							}
							// 1件でも登録できれば、成功メッセージを表示
							sessionHelper::flushSuccess(MessageConst::MSG_OK_IMAGE_003);
						} else {
							// アップロードエラー
							$msg = MessageConst::MSG_SYS_IMAGE_009;
							$logger->warn(basename(__FILE__).' op=buulk.imgup msg="image file upload error" page='.$filename.' file='.$imgFileName.' user_id='.$auth->getCurrentUser()?->getUserId());
						}
					} else {
						// ファイルサイズオーバー
						$msg = Utility::replaceStr(MessageConst::MSG_VAL_IMAGE_006,"2MB");
					}
				} else {
				// ファイル名無効
					$msg = MessageConst::MSG_VAL_IMAGE_007;
				}
				$rtnlist[] = array("fileName" => $imgFileName, "msg" => $msg);
			} else {
				// ファイルが選択されていない
				SessionHelper::flushError(MessageConst::MSG_VAL_FILE_001);
			}
		}    // foreach

		SessionHelper::setData($funcId, "rtnlist", $rtnlist);
	}
    // 画面表示はGETでredirect
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// 画像ファイル一覧取得
$dirImages = __DIR__."/".LogicConst::DIR_IMAGES."/";
$imgFileList = Utility::getFileName($dirImages);
$imgList = array();
foreach ($imgFileList as $i => $imgName) {
	if (strpos($imgName, 's_') !== 0 && Utility::checkImageName($imgName)) {
        $fullPath = $dirImages . $imgName;
        $imgList[] = [
            'fileName' => $imgName,
            'type'     => pathinfo($imgName, PATHINFO_EXTENSION), // png / jpg / jpeg など
            'size'     => filesize($fullPath) / 1024,      // kb
        ];
	}
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
	<?php $title = "画像一括登録"; require_once(__DIR__."/inc_head.php"); ?>
	<link rel="stylesheet" href="css/imgpreview.css">
	<script src="js/imgpreview.js"></script>
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
			<div><?= MessageConst::MSG_INF_IMAGE_001 ?></div>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>

		<form method="post"  enctype="multipart/form-data" action="image_bulk_create.php" class="js-confirm" data-confirm="<?= MessageConst::MSG_CNF_COMMON_014 ?>">
			<?= Utility::renderCsrfHiddenInput('image_bulk_create.form') ?>
			<input type="file" name="upfile[]" class="form-input" accept="image/*" multiple>
			<button type="submit" class="btn btn-primary" name="mode" value="upload">読込</button>
		</form>

		<?php if (!empty($rtnlist)): ?>
		<div id="table-wrapper" class="h-100 overflow-y-auto narrow">
			<table class="table table-sm table-responsive">
				<thead>
					<!-- 画像ファイル登録結果 -->
					<tr>
						<th scope="col">結果</th>
						<th scope="col">ファイル名</th>
						<th scope="col">イメージ</th>
						<th scope="col">備考</th>
					</tr>

				</thead>
				<tbody>
				<?php foreach ($rtnlist ?? [] as $wk): ?>
				<?php
					$cls = "text-primary-emphasis";
					$resultStr = "成功";
					if (!empty($wk["msg"])) {
						$cls = "text-danger";
						$resultStr = "失敗";
					}
				?>
				<tr>
					<td class="text-center <?=$cls?>"><?=$resultStr ?></td>
					<td><?=Utility::h($wk['fileName']) ?></td>
					<td>
					<?php if (!empty($wk["fileName"])) :?>
						<img src="<?=Utility::h(LogicConst::DIR_IMAGES.'/s_'.$wk['fileName']) ?>" />
					<?php else:?>
						<br/>
					<?php endif; ?>
					</td>
					<td><?=Utility::h($wk['msg']) ?></td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div><!-- table-wrapper -->
		<?php endif; ?>

		<?php if (count($imgList) > 0) :?>
		<div class="row">
			<?php foreach ($imgList as $i => $imgInfo) : ?>

			<div class="card col-sm-4 col-md-3 col-lg-2 p-1">
				 <img src="<?=Utility::h(LogicConst::DIR_IMAGES.'/s_'.$imgInfo['fileName']) ?>" class="card-img-top card-img-fit" data-bs-toggle="modal" data-bs-target="#imageViewer" data-bs-image="<?=Utility::h(LogicConst::DIR_IMAGES.'/'.$imgInfo['fileName']) ?>"/>
				<div class="card-body">
					<h5 class="card-title"><?=Utility::h($imgInfo['fileName']) ?></h5>
					<p class="card-text">形式：<?=Utility::h($imgInfo['type']) ?></p>
					<p class="card-text">ファイルサイズ：<?=number_format($imgInfo['size'],1) ?>KB</p>
				</div>
			</div><!-- card -->

			<?php endforeach; ?>

		</div><!-- row -->
		<?php endif; ?>


		<!-- ボタン -->
		<div class="d-flex justify-content-evenly">
			<a href="menu_master.php" type="button" class="btn btn-primary">戻る</a>
		</div>

	</main>
	<?php require_once(__DIR__."/inc_footer.php"); ?>
	</body>
</html>
