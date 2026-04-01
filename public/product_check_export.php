<?php
/**
 * 在庫チェック→Excel出力
 */
session_cache_limiter("none");
@session_start();
date_default_timezone_set('Asia/Tokyo');


// composerを使用
require_once(__DIR__ . '/../vendor/autoload.php');
use Studiogau\Chandra\Logging\Logger;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Studiogau\Chandra\Support\SessionHelper;
use Studiogau\Chandra\Support\Utility;
use Studiogau\Chandra\Auth\AuthService;
use Noblestock\Logic\LogicConst;
use Noblestock\Logic\MessageConst;
use Noblestock\DbLogic\UserRepository;
use Noblestock\DbLogic\Stock;
use Noblestock\Util\UtilExcel;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
	http_response_code(405);
	header('Allow: POST');
	header('Content-Type: text/plain; charset=UTF-8');
	echo MessageConst::MSG_VAL_FILE_017;
	exit;
}
if (($_POST['mode'] ?? '') !== 'export') {
	http_response_code(400);
	header('Content-Type: text/plain; charset=UTF-8');
	echo MessageConst::MSG_VAL_FILE_018;
	exit;
}

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

// セッション管理ID
$csrfScope = (string)($_POST[Utility::getCsrfScopeFieldName()] ?? '');
$csrfToken = (string)($_POST[Utility::getCsrfFieldName()] ?? '');
if (!Utility::validatePostedCsrfToken($csrfScope, $csrfToken)) {
	$logger->error(basename(__FILE__).' op=csrf.validate msg="Invalid csrf token" page='.$filename.' user_id='.$auth->getCurrentUser()?->getUserId());
	SessionHelper::FlushError(MessageConst::MSG_SYS_COMMON_900);
	header("Location: product_check.php");
	exit;
}

$funcId = "biz601";

$itemlimit = 0;
$listPage = 0;
$listCnt = 0;
$pgcnt = 1;
$searchKey = SessionHelper::getData($funcId, "searchKey");
$pgsort = SessionHelper::getData($funcId, "pgsort");
$excelName = "product_check_".date("Ymd");

if (isset($_POST["listPage"])) {
	$itemlimit = LogicConst::PAGE_ITEM_EXCEL;
	$listPage = $_POST["listPage"];
	$pgcnt = $listPage;
	$listCnt = SessionHelper::getData($funcId, "listCnt");
	$excelName .= "-".$listPage;
}

// 在庫チェック一覧
$stock = new Stock();
$checkList = $stock->list($searchKey['user_id'], $searchKey['location_no'], $pgsort, $itemlimit, $pgcnt, $listCnt);

// Excelバージョン
$excelVar = "Xls";
$excelExt = ".xls";
$excelCon = 'Content-Type: application/vnd.ms-excel';
if (intval(SessionHelper::getPref("EXCEL_VAR")) === 1) {
	$excelVar = "Xlsx";
	$excelExt = ".xlsx";
	$excelCon = 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
}

// テンプレート
$tmpfile = __DIR__.'/../'.LogicConst::DIR_FILES.'/checklist'.$excelExt;

// PhpSpreadsheetオブジェクト生成
$phpExcel = new Spreadsheet();

// テンプレート読込
$objReader = IOFactory::createReader($excelVar);
$phpExcel = $objReader->load($tmpfile);
$phpExcel->setActiveSheetIndex(0);
$sheet = $phpExcel->getActiveSheet();

// スタイルコピー
for ($i = 1; $i < count($checkList ?? []); $i++) {
	$no = $i + 1;
	$no2 = $i + 2;

	for ($char = ord('A'); $char <= ord('G'); $char++) {
		$style = $sheet->getStyle(chr($char).$no);
		$sheet->duplicateStyle($style, chr($char).$no2);
	}
}

// 一覧セット
foreach ($checkList ?? [] as $i => $wk) {
	$no = $i + 2;

	$checkStr = "";
	if ($wk["quantity"] == $wk["check_count"]) {
		$checkStr = "○";
	} else {
		if ($wk["quantity"] < $wk["check_count"]) {
			$checkStr = "超過";
		}
	}

	$sheet->setCellValueExplicit("A".$no, $wk['management_no'], DataType::TYPE_STRING);  // セルを文字列に
	UtilExcel::setCellValue($sheet, "B".$no, $wk['category_name']);
	UtilExcel::setCellValue($sheet, "C".$no, $wk['maker_name']);
	UtilExcel::setCellValue($sheet, "D".$no, $wk['product_name']);
	$sheet->setCellValue("E".$no, $wk['quantity']);
	$sheet->setCellValue("F".$no, $wk['check_count']);
	UtilExcel::setCellValue($sheet, "G".$no, $checkStr);

	$sheet->getRowDimension($no)->setRowHeight(-1);  // 行高さ自動調整
}

// 出力
$writerType = 'Xls';
$contentType = 'application/vnd.ms-excel';
$ext = '.xls';
if (intval(SessionHelper::getPref('EXCEL_VAR')) === 1) {
	$writerType = 'Xlsx';
	$contentType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
	$ext = '.xlsx';
}

// 余計な出力が混ざらないようにバッファをクリア
if (ob_get_length()) {
	ob_end_clean();
}
header('Content-Type: ' . $contentType);
header('Content-Disposition: attachment;filename="' . $excelName . $ext . '"');
header('Cache-Control: max-age=0');
$writer = IOFactory::createWriter($phpExcel, $writerType);
$writer->save('php://output');
exit;
?>
