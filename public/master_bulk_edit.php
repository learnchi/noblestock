<?php
/**
 * マスタ登録 画面
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
use Noblestock\Util\UtilExcel;
use Noblestock\DbLogic\UserRepository;
use Noblestock\DbLogic\Maker;
use Noblestock\DbLogic\Category;
use Noblestock\DbLogic\Unit;
use Noblestock\DbLogic\Location;
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

// 実行時間
set_time_limit(LogicConst::RUN_TIME_LIMIT);

// メモリアップ
ini_set("memory_limit",LogicConst::MEMORY_LIMIT);

// セッション管理ID 
$funcId = "mst904";

$masterInfo = LogicConst::MASTER_BULK_DEFS;

$masterNo = SessionHelper::getData($funcId, "masterNo") ?? 0;
$checkedExcelList = SessionHelper::getData($funcId, "checkedExcelList");
$registResultList = SessionHelper::getData($funcId, "registResultList");
$strl = SessionHelper::getData($funcId, "strl");

if ($masterNo > 0) {
	$listKey = $masterInfo[$masterNo]['listKey'] ?? null;
	$masterList = $listKey ? SessionHelper::getMasterList($listKey) : null;

	// マスタクラスインポート

	// マスタクラスインスタンス化
	$masterClass = "Noblestock\\DbLogic\\" . ($masterInfo[$masterNo]['name'] ?? '');
	$masterLogic = new $masterClass();
}

// ロジック処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$csrfScope = (string)($_POST[Utility::getCsrfScopeFieldName()] ?? '');
	$csrfToken = (string)($_POST[Utility::getCsrfFieldName()] ?? '');
	if (!Utility::validatePostedCsrfToken($csrfScope, $csrfToken)) {
		$logger->error(basename(__FILE__).' op=master.bulk.edit msg="Invalid csrf token" page=master_bulk_edit.php user_id='.$auth->getCurrentUser()?->getLoginId());
		SessionHelper::FlushError(MessageConst::MSG_SYS_COMMON_900);
		header('Location: master_bulk_edit.php', true, 303);
		exit;
	}

    if (($_POST["mode"] ?? '') === "selectMaster") {
		$masterNo = $_POST["masterNo"] ?? 0;
		SessionHelper::setData($funcId, "masterNo", $masterNo);

		// マスタ切替時は前回の一覧/結果を破棄して表示不整合を防ぐ
		$checkedExcelList = null;
		$registResultList = null;
		$strl = "";
		SessionHelper::delData($funcId, "checkedExcelList");
		SessionHelper::delData($funcId, "registResultList");
		SessionHelper::delData($funcId, "strl");
	} else if (($_POST["mode"] ?? '') === "excel") {    // Excelファイルアップロード

		$checkedExcelList = null;
		SessionHelper::delData($funcId, "checkedExcelList");
		$strl = "";
		SessionHelper::delData($funcId, "strl");

		if (is_uploaded_file($_FILES["upfile"]["tmp_name"])) {
			$strl = strtolower($_FILES["upfile"]["name"]);
			$pos = mb_strrpos($strl, '.');
			$len = mb_strlen($strl);
			// ドットが見つかり、ファイル名と拡張子の両方が存在する
			if ($pos !== false && $pos > 0 && $pos < $len - 1) {
				$ext = mb_substr($strl, $pos, $len);
				if ($ext === ".xls" || $ext === ".xlsx") {
					$filenm = strtolower($masterInfo[$masterNo]['name']).date("YmdHis").$ext;
					if (move_uploaded_file($_FILES["upfile"]["tmp_name"], "../".LogicConst::DIR_TMP."/".$filenm)) {
						// アップロード成功
						$excelData = UtilExcel::getExcelData($filenm, $masterInfo[$masterNo]['excelHeader'], $masterInfo[$masterNo]['itemName']);
						if (($excelData["status"] ?? null) === "00000") {
							// Excel抽出データチェック
							$checkMethod = "checkExcel";
							$checkedExcelList = $masterLogic->$checkMethod($excelData["lists"]);
							SessionHelper::setData($funcId, "checkedExcelList", $checkedExcelList);
							SessionHelper::flushSuccess(MessageConst::MSG_OK_MASTER_004);
						} else {
							SessionHelper::flushError($excelData["errMsg"]);
						}
						
						SessionHelper::setData($funcId, "strl", $strl);
					} else {
						// ファイルアップロードエラー
						SessionHelper::flushError(MessageConst::MSG_SYS_FILE_004);
						$logger->error(basename(__FILE__).' op=master msg="update failed" page='.$filename.' user_id='.$auth->getCurrentUser()?->getLoginId()).' detail='.$e->getMessage();
					}
				} else {
					// Excel以外
					SessionHelper::flushError(MessageConst::MSG_VAL_FILE_005);
				}
			} else {
				// ファイル名想定外
				SessionHelper::flushError(MessageConst::MSG_VAL_FILE_006);
			}
		} else {
			// ファイルが選択されていない
			SessionHelper::flushError(MessageConst::MSG_VAL_FILE_001);
		}
	} else if (($_POST["mode"] ?? '') === "insert") {
		// 登録
		$registResultList = null;
		SessionHelper::delData($funcId, "registResultList");
		$insertMethod = "bulkChange";
		$registResultList = $masterLogic->$insertMethod($checkedExcelList);
		SessionHelper::setData($funcId, "registResultList", $registResultList);
		if (($registResultList["status"] ?? null) === "00000") {
			SessionHelper::flushSuccess(MessageConst::MSG_OK_MASTER_009);

			// 再度登録しないようキャンセルと同じ処理をして画面を戻す
			$checkedExcelList = null;
			SessionHelper::delData($funcId, "checkedExcelList");
			$registResultList = null;
			SessionHelper::delData($funcId, "registResultList");

			(function () {		// マスタデータを取得
				

					$maker = new Maker();
					$makerList = $maker->list();
					$category = new Category();
					$categoryList = $category->list();
					$unit = new Unit();
					$unitList = $unit->list();
					$location = new Location();
					$locationList = $location->list();

					$masterMap = ["makerList" => $makerList, "categoryList" => $categoryList, "unitList" => $unitList, "locationList" => $locationList];
					sessionHelper::setMaster($masterMap);
			})();
			$listKey = $masterInfo[$masterNo]['listKey'] ?? null;
			$masterList = $listKey ? SessionHelper::getMasterList($listKey) : null;
		} else {
			SessionHelper::flushError($registResultList["errMsg"]);
		}
	} else if (($_POST["mode"] ?? '') === "cancel") {
		$checkedExcelList = null;
		SessionHelper::delData($funcId, "checkedExcelList");
		$registResultList = null;
		SessionHelper::delData($funcId, "registResultList");
	}

    // 画面表示はGETでredirect
    header('Location: master_bulk_edit.php', true, 303);
    exit;
}

// メニューバーコード出力
if ($masterNo == 0) {
	$barlist = [];

	// メニュー
	$barlist[] = ["bar_no" => LogicConst::CMD_MENU, "img_file" => "", "bar_top" => "", "bar_btm" => "メニュー"];
	$barlist[] = ["bar_no" => LogicConst::CMD_VIEW, "img_file" => "", "bar_top" => "", "bar_btm" => "表示"];
	$barlist[] = ["bar_no" => LogicConst::CMD_CREATE, "img_file" => "", "bar_top" => "", "bar_btm" => "バーコード生成"];

	$barlist[] = ["bar_no" => LogicConst::CMD_STOCK, "img_file" => "", "bar_top" => "", "bar_btm" => "入庫"];
	$barlist[] = ["bar_no" => LogicConst::CMD_SHIPPING, "img_file" => "", "bar_top" => "", "bar_btm" => "出庫"];
	$barlist[] = ["bar_no" => LogicConst::CMD_LOCCHG, "img_file" => "", "bar_top" => "", "bar_btm" => "移動"];

	$barlist[] = ["bar_no" => LogicConst::CMD_UNDO, "img_file" => "", "bar_top" => "", "bar_btm" => "取消"];
	$barlist[] = ["bar_no" => LogicConst::CMD_COMPLETE, "img_file" => "", "bar_top" => "", "bar_btm" => "完了"];
	$barlist[] = ["bar_no" => LogicConst::CMD_CANCEL, "img_file" => "", "bar_top" => "", "bar_btm" => "キャンセル"];

	$barlist[] = ["bar_no" => "", "img_file" => "", "bar_top" => "", "bar_btm" => ""];
	$barlist[] = ["bar_no" => "", "img_file" => "", "bar_top" => "", "bar_btm" => ""];
	$barlist[] = ["bar_no" => "", "img_file" => "", "bar_top" => "", "bar_btm" => ""];

	$barlist[] = ["bar_no" => LogicConst::CMD_VAL."0", "img_file" => "", "bar_top" => "", "bar_btm" => "0"];
	$barlist[] = ["bar_no" => LogicConst::CMD_VAL."1", "img_file" => "", "bar_top" => "", "bar_btm" => "1"];
	$barlist[] = ["bar_no" => LogicConst::CMD_VAL."2", "img_file" => "", "bar_top" => "", "bar_btm" => "2"];

	$barlist[] = ["bar_no" => LogicConst::CMD_VAL."3", "img_file" => "", "bar_top" => "", "bar_btm" => "3"];
	$barlist[] = ["bar_no" => LogicConst::CMD_VAL."4", "img_file" => "", "bar_top" => "", "bar_btm" => "4"];
	$barlist[] = ["bar_no" => LogicConst::CMD_VAL."5", "img_file" => "", "bar_top" => "", "bar_btm" => "5"];

	$barlist[] = ["bar_no" => LogicConst::CMD_VAL."6", "img_file" => "", "bar_top" => "", "bar_btm" => "6"];
	$barlist[] = ["bar_no" => LogicConst::CMD_VAL."7", "img_file" => "", "bar_top" => "", "bar_btm" => "7"];
	$barlist[] = ["bar_no" => LogicConst::CMD_VAL."8", "img_file" => "", "bar_top" => "", "bar_btm" => "8"];

	$barlist[] = ["bar_no" => LogicConst::CMD_VAL."9", "img_file" => "", "bar_top" => "", "bar_btm" => "9"];
	$barlist[] = ["bar_no" => "", "img_file" => "", "bar_top" => "", "bar_btm" => ""];
	$barlist[] = ["bar_no" => "", "img_file" => "", "bar_top" => "", "bar_btm" => ""];

	SessionHelper::setData("com901", "barlist", $barlist);
	SessionHelper::setData("com901", "barSize", 1);
	SessionHelper::setData("com901", "barFile", "menu");
}

// バーコード出力（店舗）
if ($masterNo == 4) {

	if (count($masterList ?? []) > 0) {
		$barlist = [];
		foreach ($masterList as $i => $wk) {
			$no = $masterInfo[$masterNo]['itemName'][0];
			$barmng = $masterInfo[$masterNo]['barCmd'].$wk[$no];
			$barimg = "";
			$bartop = "";
			$nm = $masterInfo[$masterNo]['itemName'][1];
			$barbtm = $wk[$nm]."(".$wk[$no].")";    // 店舗名（店舗コード）
			$barlist[] = ["bar_no" => $barmng, "img_file" => $barimg, "bar_top" => $bartop, "bar_btm" => $barbtm];
		}

		SessionHelper::setData("com901", "barlist", $barlist);
		SessionHelper::setData("com901", "barSize", 1);
		SessionHelper::setData("com901", "barFile", "shop");
	}
}

if ($checkedExcelList == null) {
	$guide = MessageConst::MSG_INF_MASTER_005;
}
if (count($checkedExcelList ?? [])  > 0) {
	$guide = MessageConst::MSG_INF_PRODUCT_006;
} else if ($masterNo == 0) {
	$nm = $masterInfo[$masterNo]['nameStr'] ?? "";
	$guide = $nm.MessageConst::MSG_INF_MASTER_006;
}

?>

<!DOCTYPE html>
<html lang="ja">
<head>
	<?php $title = "マスタ登録"; require_once(__DIR__."/inc_head.php"); ?>
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
		<?php if ($guide) :?>
			<div class="alert alert-info alert-dismissible fade show" role="alert">
				<div><?=$guide ?></div>
				<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
			</div>
		<?php endif; ?>

		<?php // if (!empty($checkedExcelList)) :?>
			<form method="post" enctype="multipart/form-data" action="master_bulk_edit.php">
				<?= Utility::renderCsrfHiddenInput('master_bulk_edit.form') ?>
				
				<div class="d-flex align-items-center flex-wrap">
					<select class="form-select form-select-sm" name="masterNo">
						<?php foreach ($masterInfo as $i => $minfo): ?>
							<?php $selected = ''; if ($masterNo == $i) $selected = 'selected="selected"'; ?>
							<option value="<?= $i ?>" <?=$selected?>><?= $minfo['nameStr'] ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="btn btn-primary" name="mode" value="selectMaster">選択</button>

					<?php if ($masterNo > 0) :?>
						<input type="file" name="upfile" class="upfile" />
						<button type="submit" class="btn btn-primary" name="mode" value="excel">登録</button>
					<?php endif; ?>
				</div>
				<div>
					<?php if (!empty($checkedExcelList)): ?>
						<input type="text" readonly class="form-control-sm form-control-plaintext" value="現在の読み込み中ファイル：<?= Utility::h($strl) ?>">
					<?php endif; ?>
				</div>
			</form>
		<?php // endif; ?>

		<?php
		if (count($checkedExcelList ?? [])  > 0) :
		?>
		<table class="table table-sm table-responsive">
			<thead>
			<tr>
				<th scope="col">チェック</th>
				<?php foreach (($masterInfo[$masterNo]['excelHeader'] ?? []) as $i => $header): ?>
				<th scope="col"><?=$header ?></th>
				<?php endforeach; ?>
			</tr>
			</thead>
			
			<tbody>
			<?php
			foreach (($checkedExcelList ?? []) as $i => $wk):
				$cls = "text-primary-emphasis";
				$currStatus = $wk["status"];
				if ($currStatus != "AAA") {    // チェック結果OK→A、NG→Eがカラムの数だけ並ぶ
					$cls = "text-danger";
				}

				$checkResult = "";
				foreach (($masterInfo[$masterNo]['excelHeader'] ?? []) as $j => $itemStr):
					// // チェック結果OK→A、NG→Eがカラムの数だけ並ぶ
					if ($currStatus[$j] != "A") {
						$checkResult .= $itemStr." ";
					}
				endforeach;
				if ($checkResult === "") {
					$checkResult = "チェックOK";
				} else {
					$checkResult .= "チェックエラー";
				}
			?>
			<tr>
				<td class="text-center <?=$cls?>"> <?=  $checkResult ?></td>
				<?php
				foreach (($masterInfo[$masterNo]['itemName'] ?? []) as $j => $itemStr):
					$ecls = "";
					// // チェック結果OK→A、NG→Eがカラムの数だけ並ぶ
					if ($currStatus[$j] != "A") $ecls = 'bg-danger-subtle';
					if ($j == 0) :
				?>
				<td class="text-end <?=$ecls?>"><?=Utility::h($wk[$itemStr]) ?></td>
				<?php
					else: 
				?>
				<td class="<?=$ecls?>"><?=Utility::h($wk[$itemStr]) ?></td>
				<?php
					endif;
				endforeach;
				?>
			</tr>
			<?php endforeach; ?>
			
			</tbody>
		</table>
		<?php elseif (($registResultList["status"] ?? null) === "00000") : ?>
		<table class="table table-sm table-responsive">
			<thead>
			<tr>
				<th scope="col">結果</th>
				
				<?php foreach (($masterInfo[$masterNo]['excelHeader'] ?? []) as $j => $header): ?>
				<th scope="col"><?=$header ?></th>
				<?php endforeach;?>
			</tr>
			</tthead>
			
			<tbody>
			<?php
			
			foreach (($registResultList["lists"] ?? []) as $i => $wk):
				$cls = "text-primary-emphasis";
				if (str_contains($wk['status'], '失敗')) {
					$cls = "text-danger";
				}
			?>
			<tr>
				<td class="text-center <?=$cls?>"><?=Utility::h($wk['status']) ?></td>
				<td class="text-end"><?=Utility::h($wk['MASTER_NO']) ?></td>
				<td><?=Utility::h($wk['MASTER_NAME']) ?></td>
				<td><?=Utility::h($wk['remarks']) ?></td>
			</tr>
			<?php endforeach;?>
			
			</tbody>
		</table>
		<?php
		else:
			if ($masterNo > 0 && !empty($masterList)) :
		?>
		<table class="table table-sm table-responsive">
			<thead>
			<tr>
				<?php foreach (($masterInfo[$masterNo]['excelHeader'] ?? []) as $j => $header): ?>
				<th scope="col"><?=$header ?></th>
				<?php endforeach;?>
			</tr>
			</tthead>
			
			<tbody>
			<?php foreach (($masterList ?? []) as $j => $wk): ?>
			<tr>
				<?php
				foreach (($masterInfo[$masterNo]['itemName'] ?? []) as $j => $itemStr):
					if ($j == 0) :
				?>
				<td class="text-end"><?=Utility::h($wk[$itemStr]?? "") ?></td>
				<?php
					else:
				?>
				<td><?=Utility::h($wk[$itemStr]??"") ?></td>
				<?php endif;
				endforeach;
				?>
			</tr>
			<?php endforeach;?>
			
			</tbody>
		</table>
		<?php
			endif;
		endif;
		?>

<h5>
<?php 
if ($masterNo > 0 ):
	if (count($masterList ?? []) > 0):
?>
<?= $masterInfo[$masterNo]['nameStr'].Utility::replaceStr(MessageConst::MSG_INF_MASTER_007, count($masterList)) ?>
<?php
	else:
		$nm = $masterInfo[$masterNo]['nameStr'] ?? "";
?>
<?= MessageConst::MSG_INF_MASTER_008 ?>
<?php
	endif;
endif;
?>
</h5>
		<!-- ボタン -->
		<form  method="post" action="master_bulk_edit.php">
			<div class="d-flex justify-content-evenly">
			<a href="menu_master.php" type="button" class="btn btn-primary">戻る</a>
				<?php if (count($checkedExcelList ?? []) > 0) :?>
					<button type="submit" class="btn btn-primary" name="mode" value="insert">マスターに登録</button>
					<button type="submit" class="btn btn-primary" name="mode" value="cancel">キャンセル</button>
				<?php endif; ?>
				<?php if ($masterNo > 0 && !empty($masterList)) :?>
					<button type="submit" class="btn btn-primary" formaction="master_bulk_export.php" formmethod="post" name="mode" value="export">Excel出力</button>
				<?php endif; ?>
				<?php if ($masterNo == 0 || $masterNo == 4) :?>
					<button type="submit" class="btn btn-primary" formaction="barcode_bulk_export.php" formmethod="post" name="mode" value="export">バーコード出力</button>
				<?php endif; ?>
			</div>
		</form>

	</main>
	<?php require_once(__DIR__."/inc_footer.php"); ?>
	</body>
</html>
