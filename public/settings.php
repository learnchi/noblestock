<?php
/**
 * 設定 画面
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
use Noblestock\Logic\MessageConst;
use Noblestock\DbLogic\UserRepository;
use Noblestock\DbLogic\Config;

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

// セッション管理ID 画像選択登録
$funcId = "mst909";

$setMst = [];
$setMst[] = [
	"config_key" => "EXCEL_VAR",
	"SET_VAL"  => [0 => "Excel97-2003", 1 => "Excel 2007 以降"],
	"SET_NAME" => "Excelバージョン",
	"DEFAULT_VAL" => 0,
	"CURRENT_VAL" => null,
];
$setMst[] = [
	"config_key" => "BAR_PRT_SIZE",
	"SET_VAL"  => [
		1 => "70.0mm×33.9mm 24面 Code39",
		2 => "70.0mm×33.9mm 24面 Code128",
		3 => "70.0mm×33.9mm 24面 Code128 画像",
		4 => "48.3mm×25.4mm 44面 Code128",
		5 => "38.1mm×21.2mm 65面 Code128",
	],
	"SET_NAME" => "ラベルサイズ",
	"DEFAULT_VAL" => 1,
	"CURRENT_VAL" => null,
];
$setMst[] = [
	"config_key" => "LABEL_UPPER",
	"SET_VAL"  => [
		1 => "管理番号",
		2 => "カテゴリ",
		3 => "メーカー",
		4 => "商品名",
		5 => "卸価格",
		6 => "小売価格",
		7 => "仕入原価",
		8 => "保管場所",
		9 => "SEQ No",
		0 => "非表示",
	],
	"SET_NAME" => "ラベル表示（上段）",
	"DEFAULT_VAL" => 2,
	"CURRENT_VAL" => null,
];
$setMst[] = [
	"config_key" => "LABEL_LOWER",
	"SET_VAL"  => [
		1 => "管理番号",
		2 => "カテゴリ",
		3 => "メーカー",
		4 => "商品名",
		5 => "卸価格",
		6 => "小売価格",
		7 => "仕入原価",
		8 => "保管場所",
		9 => "SEQ No",
		0 => "非表示",
	],
	"SET_NAME" => "ラベル表示（下段）",
	"DEFAULT_VAL" => 4,
	"CURRENT_VAL" => null,
];

// 設定値ロジック
$config = new Config();
// ロジック処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$csrfScope = (string)($_POST[Utility::getCsrfScopeFieldName()] ?? '');
	$csrfToken = (string)($_POST[Utility::getCsrfFieldName()] ?? '');
	if (!Utility::validatePostedCsrfToken($csrfScope, $csrfToken)) {
		$logger->error(basename(__FILE__).' op=settings.update msg="Invalid csrf token" page=settings.php user_id='.$auth->getCurrentUser()?->getLoginId());
		SessionHelper::FlushError(MessageConst::MSG_SYS_COMMON_900);
		header('Location: settings.php', true, 303);
		exit;
	}

    if (($_POST["mode"] ?? '') === "update") {
		foreach ($setMst as $wk) {
			if (!isset($_POST[$wk["config_key"]])) {
				continue;
			}

			// 選択肢にない設定値のPOSTを拒否する
			if (!array_key_exists((string)($_POST[$wk["config_key"]]), $wk["SET_VAL"])) {
				http_response_code(400);
				header('Content-Type: text/plain; charset=UTF-8');
				echo MessageConst::MSG_VAL_FILE_018;
				exit;
			}
		}
		// 設定更新
		foreach ($setMst as $i => $wk) {

			if (isset($_POST[$wk["config_key"]])) {
				$wkup = [
					"config_key" => $wk["config_key"],
					"value_int" => $_POST[$wk["config_key"]],
					"value_str" => null,
				];
				if ($config->update($wkup) === 1) {
					SessionHelper::setPref($wk["config_key"], $_POST[$wk["config_key"]]);
					SessionHelper::flushSuccess(MessageConst::MSG_OK_CONF_002);
				} else {
					SessionHelper::flushError(MessageConst::MSG_SYS_COMMON_900);
				}
			}
		}
	}
    // 画面表示はGETでredirect
    header('Location: settings.php', true, 303);
    exit;
}

// 設定値取得
$setList = $config->list();

$cureentValues = array_column($setList, 'value_int', 'config_key');

foreach ($setMst as &$mst) {
    $mst['CURRENT_VAL'] = $cureentValues[$mst['config_key']] ?? $mst['DEFAULT_VAL'];
}
unset($mst);    // 参照（&）を 次の処理に持ち越さない

?>

<!DOCTYPE html>
<html lang="ja">
	<head>
		<?php $title = "設定"; require_once(__DIR__."/inc_head.php"); ?>
</head>
<body class="maintenance">
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
			<div><?=MessageConst::MSG_INF_CONF_001 ?></div>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>
		
		<form method="POST" action="settings.php">
		<?= Utility::renderCsrfHiddenInput('settings.form') ?>
		<?php foreach ($setMst as $i => $setItm): ?>
			<div class="row mb-3">
			<label for="<?= $setItm["config_key"] ?>" class="col-sm-2 col-form-label"><?= $setItm["SET_NAME"] ?></label>
		    <div class="col-sm-10">
				<select id="<?= $setItm["config_key"] ?>" name="<?= $setItm["config_key"] ?>" class="form-select">
					<?php foreach ($setItm["SET_VAL"] as $j => $val):
						$selected = ''; 
						if ($setItm["CURRENT_VAL"] == $j) $selected = 'selected="selected"';	
					?>
						<option value="<?= $j ?>" <?=$selected?>><?= $val ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			</div>
		<?php endforeach; ?>

		<!-- ボタン -->
		<div class="d-flex justify-content-evenly">
			<a href="menu_master.php" type="button" class="btn btn-primary">戻る</a>
			<button type="submit" class="btn btn-primary" name="mode" value="update">登録</button>
		</div>

		</form>

	</main>
	<?php require_once(__DIR__."/inc_footer.php"); ?>
	</body>
</html>
