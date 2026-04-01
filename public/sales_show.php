<?php
/**
 * 実績詳細 画面
 *   遷移元：sales_list.php
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
use Noblestock\DbLogic\History;
use Noblestock\DbLogic\User;
use Noblestock\Logic\MessageConst;

// ロガー
$logger = Logger::createDefault(dirname(__DIR__, 1));
$logger->info("GET=".json_encode($_GET,JSON_UNESCAPED_UNICODE));
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

// セッション管理ID
$funcId = "biz302";

// マスタデータ取得
$locationList = SessionHelper::getMasterList("locationList");

// ----- クエリパラメータを取得→無い場合セッションから取得

// ページ
$pgcnt = filter_input(INPUT_GET, 'p', FILTER_VALIDATE_INT);
if (empty($pgcnt)) {    // 0も不可
	$pgcnt = SessionHelper::getData($funcId, "pgcnt") ?? 1;
}
SessionHelper::setData($funcId, "pgcnt", $pgcnt);

// ソート
$pgsort = filter_input(INPUT_GET, 's', FILTER_VALIDATE_INT);
if (empty($pgsort)) {    // 0も不可
	$pgsort = SessionHelper::getData($funcId, "pgsort") ?? 5;
}
SessionHelper::setData($funcId, "pgsort", $pgsort);

// スクロール位置 301
$scrollPos = filter_input(INPUT_GET, 'pos', FILTER_VALIDATE_INT);
if (!is_null($scrollPos)) {    // 0がありえる
	SessionHelper::setData("biz301", "scrollPos", $scrollPos);
}

// 検索条件
$keys = ['mn','bn','df','dt','ln','un','hk'];    // ユーザーが入力した検索条件のクエリキー
$searched = !empty(array_intersect_key($_GET, array_flip($keys)));    //$keysがGETクエリパラメータにある場合、検索されたとみなす
if ($searched) {
	$searchCondition = SessionHelper::getData($funcId, "searchCondition") ?? [];
	if (filter_input(INPUT_GET, 'mn')) $searchCondition['management_no'] = filter_input(INPUT_GET, 'mn');
	if (filter_input(INPUT_GET, 'bn')) $searchCondition['branch_no'] = filter_input(INPUT_GET, 'bn');
    // df/dt は「キーがある」なら、空でも拾う（ユーザーが消した意思を尊重）
    if (array_key_exists('df', $_GET)) {
        $searchCondition['DATE_FROM'] = trim((string)$_GET['df']); // '' も入る
    }
    if (array_key_exists('dt', $_GET)) {
        $searchCondition['DATE_TO'] = trim((string)$_GET['dt']);   // '' も入る
    }
	if (filter_input(INPUT_GET, 'ln', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY)) $searchCondition['location_name'] = filter_input(INPUT_GET, 'ln', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
	if (filter_input(INPUT_GET, 'un', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY)) $searchCondition['user_name'] = filter_input(INPUT_GET, 'un', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
	if (filter_input(INPUT_GET, 'hk', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY)) $searchCondition['history_kbn'] = filter_input(INPUT_GET, 'hk', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);

	SessionHelper::setData($funcId, "searchCondition", $searchCondition);
} else {
	$searchCondition = SessionHelper::getData($funcId, "searchCondition");
}

// ----- クエリパラメータを取得 ここまで


// ----- いま確定している状態から「正規URL」を作る
$getParams = [];

// ページ
$pgcntStr = (string)$pgcnt;
if ($pgcntStr !== '' && $pgcntStr !== '1') {    // null、空文字、既定値ではない場合のみパラメータとする
    $getParams['p'] = $pgcntStr;
}

// ソート
$sortStr = (string)$pgsort;
if ($sortStr !== '' && $sortStr !== '5') {
    $getParams['s'] = $sortStr;
}

// スクロール位置（0は省略）
$posStr = (string)$scrollPos;
if ($posStr !== '' && $posStr !== '0') {
    $getParams['pos'] = $posStr;
}

// 検索条件
if (!empty($searchCondition['management_no'])) { 
	$getParams['mn'] = $searchCondition['management_no'];
}
if (!empty($searchCondition['branch_no'])) { 
	$getParams['bn'] = $searchCondition['branch_no'];
}
if (!empty($searchCondition['DATE_FROM'])) {
    $getParams['df'] = $searchCondition['DATE_FROM'];
}
if (!empty($searchCondition['DATE_TO'])) {
    $getParams['dt'] = $searchCondition['DATE_TO'];
}
if (!empty($searchCondition['location_name'])) {
    $getParams['ln'] = $searchCondition['location_name'];
}

if (!empty($searchCondition['user_name'])) {
    $getParams['un'] = $searchCondition['user_name'];
}
if (!empty($searchCondition['history_kbn'])) {
    $getParams['hk'] = $searchCondition['history_kbn'];
}


$current = $_GET ?? [];

// いまのURL($_GET)と正規パラメータがズレていたら302
if ($current !== $getParams) {
    $url = basename($_SERVER['PHP_SELF']) . '?' . http_build_query($getParams);
    header('Location: ' . $url, true, 302);
    exit;
}
// ----- いま確定している状態から「正規URL」を作る ここまで

// 画面表示データの取得
$history = new History();
$listCnt = $history->count($searchCondition);
SessionHelper::setData($funcId, "listCnt", $listCnt);
$pageList = null;
if ($listCnt > 0) {
	$pageList = $history->list($searchCondition, $pgsort, LogicConst::PAGE_ITEM, $pgcnt, $listCnt);
} else {
	SessionHelper::FlushError(MessageConst::MSG_VAL_LIST_001);
}

// ユーザー情報取得
$user = new User();
$userList = $user->list();

if (is_null($searchCondition)) {
	http_response_code(400);
	header('Content-Type: text/plain; charset=UTF-8');
	echo MessageConst::MSG_VAL_FILE_018;
	exit;
}
if (empty($searchCondition['management_no']) || empty($searchCondition['branch_no'])) {
	http_response_code(400);
	header('Content-Type: text/plain; charset=UTF-8');
	echo MessageConst::MSG_VAL_FILE_018;
	exit;
}

// 案内メッセージは、エラーや成功通知がない時だけ
$msg = "";
if (!SessionHelper::hasFlushError() && !SessionHelper::hasFlushSuccess()) {
	$msg = Utility::replaceStr(MessageConst::MSG_INF_SALES_001, 
		$searchCondition['management_no'] ?? '',
		$searchCondition['branch_no'] ?? '');
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
	<?php $title = "実績詳細"; require_once(__DIR__."/inc_head.php"); ?>
	<script src="js/multiselect.js"></script>

</head>
<body class="history">

<!-- modal dialog -->
<!-- 使い方: data-bs-toggle="modal" data-bs-target="#searchModal" -->
<div class="modal fade" id="searchModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">検索ダイアログ</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
	  <form method="GET" name="search" action="sales_show.php">
			<input type="hidden" name="pos" value="0"><!-- scrollPosは0にリセット -->
			<input type="hidden" name="p" value="1">
		<input type="hidden" name="mn" value="<?=Utility::h($searchCondition['management_no'] ?? '') ?>">
		<input type="hidden" name="bn" value="<?=Utility::h($searchCondition['branch_no'] ?? '') ?>">
      <div class="modal-body d-flex align-items-center flex-wrap">

		<input type="date" id="date_from" name="df" class="form-control" value="<?=Utility::h($searchCondition['DATE_FROM'] ?? '')?>" />
		～
		<input type="date" id="date_to" name="dt" class="form-control" value="<?=Utility::h($searchCondition['DATE_TO'] ?? '')?>" />
		<select id="history_kbn" name="hk[]" class="multiselect form-select form-select-sm" multiple data-textcontent="履歴区分">
	<?php
	$selected = '';
			if (in_array('0', $searchCondition['history_kbn'] ?? [], true)) {
				$selected = 'selected';
	}
	?>
	<option value="0" <?=$selected?>>入庫</option>
	<?php
	$selected = '';
			if (in_array('1', $searchCondition['history_kbn'] ?? [], true)) {
				$selected = 'selected';
	}
	?>
	<option value="1" <?=$selected?>>出庫</option>
	<?php
	$selected = '';
			if (in_array('5', $searchCondition['history_kbn'] ?? [], true)) {
				$selected = 'selected';
	}
	?>
	<option value="5" <?=$selected?>>移動</option>
	<?php
	$selected = '';
			if (in_array('2', $searchCondition['history_kbn'] ?? [], true)) {
				$selected = 'selected';
	}
	?>
	<option value="2" <?=$selected?>>商品登録</option>
	<?php
	$selected = '';
			if (in_array('3', $searchCondition['history_kbn'] ?? [], true)) {
				$selected = 'selected';
	}
	?>
	<option value="3" <?=$selected?>>商品更新</option>
	<?php
	$selected = '';
			if (in_array('4', $searchCondition['history_kbn'] ?? [], true)) {
				$selected = 'selected';
	}
	?>
	<option value="4" <?=$selected?>>商品削除</option>
	<?php
	$selected = '';
			if (in_array('7', $searchCondition['history_kbn'] ?? [], true)) {
				$selected = 'selected';
	}
	?>
	<option value="7" <?=$selected?>>在庫変更</option>
	<?php
	if ($auth->getCurrentUser()?->getUserId() === 'admin') {

	$selected = '';
				if (in_array('9', $searchCondition['history_kbn'] ?? [], true)) {
					$selected = 'selected';
	}
	?>
	<option value="9" <?=$selected?>>履歴削除</option>
	<?php
	}
	?>
</select>
		<select id="location_name" name="ln[]" class="multiselect form-select form-select-sm" multiple data-textcontent="店舗">
	<?php
	foreach ($locationList ?? [] as $i => $wk) {
				$selected = '';
				if (in_array($wk['location_name'], $searchCondition['location_name'] ?? [], true)) {
					$selected = 'selected';
		}
	?>
	<option value="<?=Utility::h($wk['location_name'])?>" <?=$selected ?>><?=Utility::h($wk['location_name']) ?></option>
	<?php
	}
	?>
	</select>
		<select id="user_name" name="un[]" class="multiselect form-select form-select-sm" multiple data-textcontent="ユーザー">
	<?php
	foreach ($userList ?? [] as $i => $wk) {
		$selected = "";
				if (in_array($wk['user_name'], $searchCondition['user_name'] ?? [], true)) {
					$selected = 'selected';
		}
	?>
	<option value="<?=Utility::h($wk['user_name'])?>" <?=$selected ?>><?=Utility::h($wk['user_name']) ?></option>
	<?php
	}
	?>
</select>
<select id="SORT_NO" name="s" class="form-select form-select-sm">
	<?php $selected = ''; if ($pgsort == 1) $selected = 'selected="selected"'; ?>
	<option value="1" <?=$selected?>>日付 ▲</option>
	<?php $selected = ''; if ($pgsort == 2) $selected = 'selected="selected"'; ?>
	<option value="2" <?=$selected?>>日付 ▼</option>
	<?php $selected = ''; if ($pgsort == 3) $selected = 'selected="selected"'; ?>
	<option value="3" <?=$selected?>>処理 ▲</option>
	<?php $selected = ''; if ($pgsort == 4) $selected = 'selected="selected"'; ?>
	<option value="4" <?=$selected?>>処理 ▼</option>
	<?php $selected = ''; if ($pgsort == 5) $selected = 'selected="selected"'; ?>
	<option value="5" <?=$selected?>>店舗 ▲</option>
	<?php $selected = ''; if ($pgsort == 6) $selected = 'selected="selected"'; ?>
	<option value="6" <?=$selected?>>店舗 ▼</option>
	<?php $selected = ''; if ($pgsort == 7) $selected = 'selected="selected"'; ?>
	<option value="7" <?=$selected?>>入庫数 ▲</option>
	<?php $selected = ''; if ($pgsort == 8) $selected = 'selected="selected"'; ?>
	<option value="8" <?=$selected?>>入庫数 ▼</option>
	<?php $selected = ''; if ($pgsort == 9) $selected = 'selected="selected"'; ?>
	<option value="9" <?=$selected?>>出庫数 ▲</option>
	<?php $selected = ''; if ($pgsort == 10) $selected = 'selected="selected"'; ?>
	<option value="10" <?=$selected?>>出庫数 ▼</option>
	<?php $selected = ''; if ($pgsort == 11) $selected = 'selected="selected"'; ?>
	<option value="11" <?=$selected?>>移動数 ▲</option>
	<?php $selected = ''; if ($pgsort == 12) $selected = 'selected="selected"'; ?>
	<option value="12" <?=$selected?>>移動数 ▼</option>
	<?php $selected = ''; if ($pgsort == 13) $selected = 'selected="selected"'; ?>
	<option value="13" <?=$selected?>>在庫数 ▲</option>
	<?php $selected = ''; if ($pgsort == 14) $selected = 'selected="selected"'; ?>
	<option value="14" <?=$selected?>>在庫数 ▼</option>
	<?php $selected = ''; if ($pgsort == 17) $selected = 'selected="selected"'; ?>
	<option value="17" <?=$selected?>>実施者 ▲</option>
	<?php $selected = ''; if ($pgsort == 18) $selected = 'selected="selected"'; ?>
	<option value="18" <?=$selected?>>実施者 ▼</option>
</select>
		<input type="hidden" name="mode" value="search">
		<input type="hidden" name="pos" value=""><!-- submit時に代入 -->

      </div><!-- modal-body -->
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
        <button type="submit" class="btn btn-primary" data-bs-dismiss="modal">検索</button>
      </div><!-- modal-footer -->
	  </form>
    </div><!-- modal-content -->
  </div>
</div>
<!-- modal dialog end -->

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
		 <?php if (!empty($msg)) : ?>
		<div class="alert alert-info alert-dismissible fade show" role="alert">
			<div><?=Utility::h($msg) ?></div>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>
		<?php endif; ?>

		<div class="d-none d-lg-block my-2"><!-- PC幅だと表示 -->
			<form method="GET" action="sales_show.php">
				<div class="d-flex align-items-center flex-wrap">
					<input type="hidden" name="pos" value="0"><!-- scrollPosは0にリセット -->
					<input type="hidden" name="s" value="<?=Utility::h($pgsort) ?>">
					<input type="hidden" name="p" value="1">
					<input type="hidden" name="mn" value="<?=Utility::h($searchCondition['management_no'] ?? '') ?>">
					<input type="hidden" name="bn" value="<?=Utility::h($searchCondition['branch_no'] ?? '') ?>">
					<input type="date" id="date_from" name="df" class="form-control" value="<?=Utility::h($searchCondition['DATE_FROM'] ?? '')?>" />
					～
					<input type="date" id="date_to" name="dt" class="form-control" value="<?=Utility::h($searchCondition['DATE_TO'] ?? '')?>" />

					<select id="history_kbn" name="hk[]" class="multiselect form-select form-select-sm" multiple data-textcontent="履歴区分">
						<?php
						$selected = '';
						if (in_array('0', $searchCondition['history_kbn'] ?? [], true)) {
							$selected = 'selected';
						}
						?>
						<option value="0" <?=$selected?>>入庫</option>
						<?php
						$selected = '';
						if (in_array('1', $searchCondition['history_kbn'] ?? [], true)) {
							$selected = 'selected';
						}
						?>
						<option value="1" <?=$selected?>>出庫</option>
						<?php
						$selected = '';
						if (in_array('5', $searchCondition['history_kbn'] ?? [], true)) {
							$selected = 'selected';
						}
						?>
						<option value="5" <?=$selected?>>移動</option>
						<?php
						$selected = '';
						if (in_array('2', $searchCondition['history_kbn'] ?? [], true)) {
							$selected = 'selected';
						}
						?>
						<option value="2" <?=$selected?>>商品登録</option>
						<?php
						$selected = '';
						if (in_array('3', $searchCondition['history_kbn'] ?? [], true)) {
							$selected = 'selected';
						}
						?>
						<option value="3" <?=$selected?>>商品更新</option>
						<?php
						$selected = '';
						if (in_array('4', $searchCondition['history_kbn'] ?? [], true)) {
							$selected = 'selected';
						}
						?>
						<option value="4" <?=$selected?>>商品削除</option>
						<?php
						$selected = '';
						if (in_array('7', $searchCondition['history_kbn'] ?? [], true)) {
							$selected = 'selected';
						}
						?>
						<option value="7" <?=$selected?>>在庫変更</option>
						<?php
						if ($auth->getCurrentUser()?->getUserId() === 'admin') {

						$selected = '';
							if (in_array('9', $searchCondition['history_kbn'] ?? [], true)) {
								$selected = 'selected';
						}
						?>
						<option value="9" <?=$selected?>>履歴削除</option>
						<?php
						}
						?>
					</select>
					<select id="location_name" name="ln[]" class="multiselect form-select form-select-sm" multiple data-textcontent="店舗">
						<?php
						foreach ($locationList ?? [] as $i => $wk) {
							$selected = '';
							if (in_array($wk['location_name'], $searchCondition['location_name'] ?? [], true)) {
								$selected = 'selected';
							}
						?>
						<option value="<?=Utility::h($wk['location_name'])?>" <?=$selected ?>><?=Utility::h($wk['location_name']) ?></option>
						<?php
						}
						?>
					</select>
					<select id="user_name" name="un[]" class="multiselect form-select form-select-sm" multiple data-textcontent="ユーザー">
						<?php
						foreach ($userList ?? [] as $i => $wk) {
							$selected = "";
							if (in_array($wk['user_name'], $searchCondition['user_name'] ?? [], true)) {
								$selected = 'selected';
							}
						?>
						<option value="<?=Utility::h($wk['user_name'])?>" <?=$selected ?>><?=Utility::h($wk['user_name']) ?></option>
						<?php
						}
						?>
					</select>
					<button type="submit" class="btn btn-primary text-nowrap">検索</button>
				</div>		
			</form>
		</div><!-- PC幅だと表示 ここまで -->
		<div class="d-lg-none d-block my-2"><!-- スマホ幅だと表示 -->
			<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#searchModal">検索ダイアログ</button>
		</div><!-- スマホ幅だと表示 ここまで -->

		<?php
		if (count($pageList ?? []) > 0) :
		?>
		<div id="table-wrapper" class="h-100 overflow-y-auto narrow">
		<table class="table table-sm table-responsive">
			<thead>
			<tr>
				<?php
				$noSort = 1;
				$strSort = "";
				if ($pgsort === 1) {
					$noSort = 2;
					$strSort = " ▲";
				} else if ($pgsort === 2) {
					$noSort = 1;
					$strSort = " ▼";
				}
				?>

				<th scope="col" class="text-nowrap">
					<form method="GET" action="sales_show.php">
						<input type="hidden" name="pos" value=""><!-- submit時に代入 scrollPos -->
						<input type="hidden" name="s" value="<?= Utility::h($noSort) ?>">
						<input type="hidden" name="p" value="1">
						<button type="submit" class="btn w-100">日付<?=$strSort?></button>
					</form>
				</th>
				<th scope="col" class="text-nowrap">管理番号</th>
				<?php
				$noSort = 3;
				$strSort = "";
				if ($pgsort === 3) {
					$noSort = 4;
					$strSort = " ▲";
				} else if ($pgsort === 4) {
					$noSort = 3;
					$strSort = " ▼";
				}
				?>
				<th scope="col" class="text-nowrap">
					<form method="GET" action="sales_show.php">
						<input type="hidden" name="pos" value=""><!-- submit時に代入 scrollPos -->
						<input type="hidden" name="s" value="<?= Utility::h($noSort) ?>">
						<input type="hidden" name="p" value="1">
						<button type="submit" class="btn w-100">処理内容<?=$strSort?></button>
					</form>
				</th>
				<?php
				$noSort = 5;
				$strSort = "";
				if ($pgsort === 5) {
					$noSort = 6;
					$strSort = " ▲";
				} else if ($pgsort === 6) {
					$noSort = 5;
					$strSort = " ▼";
				}
				?>
				<th scope="col" class="text-nowrap">
					<form method="GET" action="sales_show.php">
						<input type="hidden" name="pos" value=""><!-- submit時に代入 scrollPos -->
						<input type="hidden" name="s" value="<?= Utility::h($noSort) ?>">
						<input type="hidden" name="p" value="1">
						<button type="submit" class="btn w-100">店舗<?=$strSort?></button>
					</form>
				</th>
				<th scope="col" class="text-nowrap">カテゴリ</th>
				<th scope="col" class="text-nowrap">メーカー</th>
				<th scope="col" class="text-nowrap">商品名</th>
				<?php
				$noSort = 7;
				$strSort = "";
				if ($pgsort === 7) {
					$noSort = 8;
					$strSort = " ▲";
				} else if ($pgsort === 8) {
					$noSort = 7;
					$strSort = " ▼";
				}
				?>
				<th scope="col" class="text-nowrap">
					<form method="GET" action="sales_show.php">
						<input type="hidden" name="pos" value=""><!-- submit時に代入 scrollPos -->
						<input type="hidden" name="s" value="<?= Utility::h($noSort) ?>">
						<input type="hidden" name="p" value="1">
						<button type="submit" class="btn w-100">入庫数<?=$strSort?></button>
					</form>
				</th>
				<?php
				$noSort = 9;
				$strSort = "";
				if ($pgsort === 9) {
					$noSort = 10;
					$strSort = " ▲";
				} else if ($pgsort === 10) {
					$noSort = 9;
					$strSort = " ▼";
				}
				?>
				<th scope="col" class="text-nowrap">
					<form method="GET" action="sales_show.php">
						<input type="hidden" name="pos" value=""><!-- submit時に代入 scrollPos -->
						<input type="hidden" name="s" value="<?= Utility::h($noSort) ?>">
						<input type="hidden" name="p" value="1">
						<button type="submit" class="btn w-100">出庫数<?=$strSort?></button>
					</form>
				</th>
				<?php
				$noSort = 11;
				$strSort = "";
				if ($pgsort === 11) {
					$noSort = 12;
					$strSort = " ▲";
				} else if ($pgsort === 12) {
					$noSort = 11;
					$strSort = " ▼";
				}
				?>
				<th scope="col" class="text-nowrap">
					<form method="GET" action="sales_show.php">
						<input type="hidden" name="pos" value=""><!-- submit時に代入 scrollPos -->
						<input type="hidden" name="s" value="<?= Utility::h($noSort) ?>">
						<input type="hidden" name="p" value="1">
						<button type="submit" class="btn w-100">移動数<?=$strSort?></button>
					</form>
				</th>
				<?php
				$noSort = 13;
				$strSort = "";
				if ($pgsort === 13) {
					$noSort = 14;
					$strSort = " ▲";
				} else if ($pgsort === 14) {
					$noSort = 13;
					$strSort = " ▼";
				}
				?>
				<th scope="col" class="text-nowrap">
					<form method="GET" action="sales_show.php">
						<input type="hidden" name="pos" value=""><!-- submit時に代入 scrollPos -->
						<input type="hidden" name="s" value="<?= Utility::h($noSort) ?>">
						<input type="hidden" name="p" value="1">
						<button type="submit" class="btn w-100">在庫数<?=$strSort?></button>
					</form>
				</th>
				<?php
				$noSort = 17;
				$strSort = "";
				if ($pgsort === 17) {
					$noSort = 18;
					$strSort = " ▲";
				} else if ($pgsort === 18) {
					$noSort = 17;
					$strSort = " ▼";
				}
				?>
				<th scope="col" class="text-nowrap">
					<form method="GET" action="sales_show.php">
						<input type="hidden" name="pos" value=""><!-- submit時に代入 scrollPos -->
						<input type="hidden" name="s" value="<?= Utility::h($noSort) ?>">
						<input type="hidden" name="p" value="1">
						<button type="submit" class="btn w-100">実施者<?=$strSort?></button>
					</form>
				</th>

				<th scope="col" class="text-nowrap">編集</th>
			</tr>
			</thead>
			<tbody>
			<?php
			foreach ($pageList as $i => $wk) :
			?>
			<tr>
				<td class="text-center"><?=Utility::h($wk['history_yy']."/".$wk['history_mm']."/".$wk['history_dd']) ?></td>

				<?php
				if ($wk['del_flg'] == 0) :
				?>
				<td>
					<form method="POST" action="product_edit.php">
						<?= \Studiogau\Chandra\Support\Utility::renderCsrfHiddenInput('sales_show.actions') ?>
						<input type="hidden" name="mngNo" value="<?=Utility::h($wk['management_no'])?>">
						<input type="hidden" name="filename" value="<?=$filename?>">
						<input type="hidden" name="pos" value=""><!-- submit時に代入 scrollPos -->
						<button type="submit" class="btn w-100"><?=Utility::h($wk['management_no']) ?>&nbsp;[<?=Utility::h($wk['branch_no']) ?>]</button>
					</form>
					</td>
				<?php
				else:
				?>
				<td class="text-danger"><?=Utility::h($wk['management_no']) ?>&nbsp;[<?=Utility::h($wk['branch_no']) ?>]</td>
				<?php
				endif;
				?>
				<td class="text-center"><?=History::getKbnName($wk['history_kbn']) ?></td>
				<td><?=Utility::h($wk['location_name']) ?></td>
				<td><?=Utility::h($wk['category_name']) ?></td>
				<td><?=Utility::h($wk['maker_name']) ?></td>
				<td><?=Utility::h($wk['product_name']) ?></td>
				<td class="text-end"><?=number_format($wk['stock_in']) ?></td>
				<td class="text-end"><?=number_format($wk['quantity']) ?></td>
				<td class="text-end"><?=number_format($wk['move_stock']) ?></td>
				<td class="text-end"><?=number_format($wk['location_stock']) ?></td>
				<td class="text-center"><?=Utility::h($wk['CREATE_USER_NAME']) ?></td>
				<td class="text-center">
					<form method="POST" action="history_edit.php">
						<?= \Studiogau\Chandra\Support\Utility::renderCsrfHiddenInput('sales_show.actions') ?>
						<input type="hidden" name="HISTORY_NO" value="<?=Utility::h($wk['id'])?>">
						<input type="hidden" name="filename" value="<?=$filename?>">
						<input type="hidden" name="pos" value=""><!-- submit時に代入 scrollPos -->
						<button type="submit" class="btn w-100">更新</button>
					</form>
				</td>
			</tr>
			<?php
			endforeach;    // $pageList
			?>
			</tbody>
		</table>
		</div><!-- table-wrapper -->
		<?php
		endif;    // count($pageList)
		?>

		<?php $pagination_action = basename(__FILE__); require_once(__DIR__."/inc_pagination.php"); ?>


		<!-- ボタン -->
		<div class="d-flex justify-content-evenly">

			<a href="sales_list.php" type="button" class="btn btn-primary">戻る</a>
			<?php
			if ($listCnt > 0) {
				if ($listCnt <= LogicConst::PAGE_ITEM_EXCEL) {
			?>
			<form method="POST" action="sales_show_export.php">
				<?= \Studiogau\Chandra\Support\Utility::renderCsrfHiddenInput('sales_show.actions') ?>
				<input type="hidden" name="mode" value="export">
				<button type="submit" class="btn btn-primary">Excel出力</button>
			</form>
			<?php } else { ?>
					<form method="GET" action="list_export_split.php">
					<input type="hidden" name="fn" value="<?= $filename ?>">
					<button type="submit" class="btn btn-primary" >Excel出力</button>
				</form>
			<?php
				}
			}
			?>
		</div>

	</main>
	<?php require_once(__DIR__."/inc_footer.php"); ?>
	</body>
</html>
