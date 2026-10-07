<?php
/**
 * ユーザー管理 画面
 *  登録確認 user_create
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
use Noblestock\DbLogic\User;
use Noblestock\Util\UtilExcel;
use Noblestock\Logic\MessageConst;

// ロガー
$logger = Logger::createDefault(dirname(__DIR__, 1));

// 認証チェック
$auth = new AuthService(new UserRepository(), $logger);
if (!$auth->checkUserSession()) {
	$logger->error(basename(__FILE__)." checkUserSession failed for user id id=".$auth->getCurrentUser()?->getLoginId());
	// チェック結果がエラーの場合ログイン画面に遷移
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	SessionHelper::FlushError(MessageConst::MSG_INF_AUTH_002);
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
$funcId = "mst902";

// 自機能以外のSessionデータをクリア
SessionHelper::clearDataExceptFunc($funcId);

// rtnlistを取り出す
$rtnlist = SessionHelper::getData($funcId, "rtnlist");
$strl = SessionHelper::getData($funcId, "strl");

$userHeader = array("ログインID", "パスワード", "名前", "ソート順", "フリガナ", "メールアドレス", "権限");
$userColName = array("login_id", "password_hash", "user_name", "sort_order", "furigana", "email", "authority");

// ロジック処理
$user = new User();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$csrfScope = (string)($_POST[Utility::getCsrfScopeFieldName()] ?? '');
	$csrfToken = (string)($_POST[Utility::getCsrfFieldName()] ?? '');
	if (!Utility::validatePostedCsrfToken($csrfScope, $csrfToken)) {
		$logger->error(basename(__FILE__).' op=user.list msg="Invalid csrf token" page=user_list.php user_id='.$auth->getCurrentUser()?->getLoginId());
		SessionHelper::FlushError(MessageConst::MSG_SYS_COMMON_900);
		header('Location: user_list.php', true, 303);
		exit;
	}

    if (($_POST["mode"] ?? '') === "upload") {
		// rtnlistをクリア
		$rtnlist = null;
		SessionHelper::delData($funcId, "rtnlist");
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
					$filenm = "user".date("YmdHis").$ext;
					if (move_uploaded_file($_FILES["upfile"]["tmp_name"], "../".LogicConst::DIR_TMP."/".$filenm)) {
						// アップロード成功
						$excelData = UtilExcel::getExcelData($filenm, $userHeader, $userColName);
						if (($excelData["status"] ?? null) === "00000") {
							
							$rtnlist = $user->bulkChange($excelData["lists"]);

							if (($rtnlist["status"] ?? null) === "00000") {
							} else {
								sessionHelper::flushError($rtnlist["errMsg"]);
							}

						} else {
							sessionHelper::flushError($excelData["errMsg"]);
							$rtnlist = $excelData;
						}
						SessionHelper::setData($funcId, "rtnlist", $rtnlist);
						SessionHelper::setData($funcId, "strl", $strl);
					} else {
						// ファイルアップロードエラー
						sessionHelper::flushError(MessageConst::MSG_SYS_FILE_004);
						$logger->error(basename(__FILE__).' op=user msg="Error occurred during file upload" page='.$filename.' user_id='.$auth->getCurrentUser()?->getLoginId());
					}
				} else {
					// Excel以外
					sessionHelper::flushError(MessageConst::MSG_VAL_FILE_005);
				}
			} else {
				// ファイル名想定外
				sessionHelper::flushError(MessageConst::MSG_VAL_FILE_006);
			}
		} else {
			// ファイルが選択されていない
			sessionHelper::flushError(MessageConst::MSG_VAL_FILE_001);
		}
	}

    // 画面表示はGETでredirect
    header('Location: user_list.php', true, 303);
    exit;
}

// ユーザー情報取得
$userList = $user->list();

?>

<!DOCTYPE html>
<html lang="ja">
	<head>
		<?php $title = "ユーザー管理"; require_once(__DIR__."/inc_head.php"); ?>
		<script>const scrollPos= <?= (int)($scrollPos ?? 0) ?>;</script><!-- スクロール位置 -->
		<script src="js/scrollPosition.js"></script>
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
			<div><?=MessageConst::MSG_INF_FILE_002?></div>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>

		<form method="post"  enctype="multipart/form-data" action="user_list.php" class="js-confirm" data-confirm="<?= MessageConst::MSG_CNF_COMMON_014 ?>">
			<?= Utility::renderCsrfHiddenInput('user_list.actions') ?>
			<div class="d-flex flex-column flex-md-row align-items-center justify-content-start">
				<input type="file" name="upfile" class="form-input" maxlength="50" />
				<button type="submit" class="btn btn-primary" name="mode" value="upload">読込</button>
			</div>
			<div>
				<?php if ($strl): ?>
					<input type="text" readonly class="form-control-sm form-control-plaintext" value="現在の読み込み中ファイル：<?= Utility::h($strl) ?>">
				<?php endif; ?>
			</div>
		</form>

		<?php if (($rtnlist['status'] ?? null) === '00000'): ?>
			<div id="table-wrapper" class="h-100 overflow-y-auto narrow">
				<table class="table table-sm table-responsive">
					<thead>
					<tr>
						<th scope="col">結果</th>
						<th scope="col">ソート順</th>
						<th scope="col">ログインID</th>
						<th scope="col">ユーザー名</th>
						<th scope="col">フリガナ</th>
						<th scope="col">メール</th>
						<th scope="col">権限</th>
					</tr>
					</thead>
					<tbody>
				<?php 
					foreach ($rtnlist["lists"] as $i => $wk) :

						$cls = "text-primary-emphasis";
						if ($wk["status"] === "失敗") {
							$cls = "text-danger";
						} else if ($wk["status"] === "更新") {
							$cls = "text-success";
						}	
						$auth_name = "制限なし";
						if ($wk['authority'] == 1) $auth_name = "制限ユーザ";
				?>
					<tr>
						<td class="text-center <?=$cls?>"><?= Utility::h($wk['status'] ?? '') ?></td>
						<td class="text-end"><?= Utility::h($wk['sort_order'] ?? '') ?></td>
						<td><?= Utility::h($wk['login_id'] ?? '') ?></td>
						<td><?= Utility::h($wk['user_name'] ?? '') ?></td>
						<td><?= Utility::h($wk["furigana"] ?? '') ?></td>
						<td><?= Utility::h($wk["email"] ?? '') ?></td>
						<td><?=$auth_name ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
				</table>
			</div>
		<?php else: ?>
			<?php if ($userList != null): ?>
			<div id="table-wrapper" class="h-100 overflow-y-auto narrow">
				<table class="table table-sm table-responsive">
					<thead>
					<tr>
						<th scope="col" >ソート順</th>
						<th scope="col">ログインID</th>
						<th scope="col">ユーザー名</th>
						<th scope="col">フリガナ</th>
						<th scope="col">メール</th>
						<th scope="col">権限</th>
						<th scope="col">編集</th>
					</tr>
					</thead>
					<tbody>
					<?php
					foreach ($userList as $i => $wk) :
					?>
					<tr>
						<td class="text-end"><?= Utility::h($wk["sort_order"] ?? '') ?></td>
						<td><?= Utility::h($wk["login_id"] ?? '') ?></td>
						<td><?= Utility::h($wk["user_name"] ?? '') ?></td>
						<td><?= Utility::h($wk["furigana"] ?? '') ?></td>
						<td><?= Utility::h($wk["email"] ?? '') ?></td>
						<td><?= Utility::h($wk['authority'] ?? '') ?></td>
						<td class="text-center">
							<form method="POST" action="user_edit.php">
								<?= Utility::renderCsrfHiddenInput('user_list.actions') ?>
								<input type="hidden" name="id" value="<?= Utility::h($wk['id'] ?? '') ?>">
								<input type="hidden" name="pos" value=""><!-- submit時に代入 -->
								<button type="submit" class="btn">更新</button>
							</form>
						</td>
					</tr>
					<?php endforeach; ?>
				</table>
			</div>
			<?php endif; ?>
		<?php endif; ?>

		<!-- ボタン -->
		<div class="d-flex justify-content-evenly">
			<a href="menu_master.php" type="button" class="btn btn-primary">戻る</a>

			<form method="post" action="user_create.php">
				<?= Utility::renderCsrfHiddenInput('user_list.actions') ?>
				<input type="hidden" name="mode" value="insert">
				<input type="hidden" name="pos" value=""><!-- submit時に代入 -->
				<button type="submit" class="btn btn-primary" >ユーザー登録</button>
			</form>

			<?php if ($userList != null) :?>
				<form method="POST" action="user_export.php">
					<?= Utility::renderCsrfHiddenInput('user_list.actions') ?>
					<input type="hidden" name="mode" value="export">
					<button type="submit" class="btn btn-primary">Excel出力</button>
				</form>
			<?php endif;?>

		</div>
		
	</main>
	<?php require_once(__DIR__."/inc_footer.php"); ?>
</body>
</html>
