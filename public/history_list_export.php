<?php
/**
 * 履歴一覧→Excel出力
 */
session_cache_limiter("none");
session_start();
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
use Noblestock\DbLogic\UserRepository;
use Noblestock\DbLogic\History;
use Noblestock\Util\UtilExcel;
use Noblestock\Logic\MessageConst;

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
	$logger->error(basename(__FILE__)." checkUserSession failed for user id id=".$auth->getCurrentUser()?->getLoginId());
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	SessionHelper::FlushError(MessageConst::MSG_INF_AUTH_002);
	// チェック結果がエラーの場合ログイン画面に遷移
	header("Location: index.php", true, 303);
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

// 実行時間（デフォルト30秒）
set_time_limit(LogicConst::RUN_TIME_LIMIT);

// メモリアップ
ini_set("memory_limit",LogicConst::MEMORY_LIMIT);

// セッション管理ID
$csrfScope = (string)($_POST[Utility::getCsrfScopeFieldName()] ?? '');
$csrfToken = (string)($_POST[Utility::getCsrfFieldName()] ?? '');
if (!Utility::validatePostedCsrfToken($csrfScope, $csrfToken)) {
	$logger->error(basename(__FILE__).' op=csrf.validate msg="Invalid csrf token" page='.$filename.' user_id='.$auth->getCurrentUser()?->getLoginId());
	SessionHelper::FlushError(MessageConst::MSG_SYS_COMMON_900);
	header("Location: history_list.php", true, 303);
	exit;
}

$funcId = "biz305";

$itemlimit = 0;
$listPage = 0;
$listCnt = 0;
$excelName = "history_list_".date("Ymd");
if (isset($_POST["listPage"])) {
	$itemlimit = LogicConst::PAGE_ITEM_EXCEL;
	$listPage = $_POST["listPage"];
	$listCnt = SessionHelper::getData($funcId, "listCnt");
	$excelName .= "-".$listPage;
}

// 履歴一覧
$searchCondition = SessionHelper::getData($funcId, "searchCondition");
$pgsort = SessionHelper::getData($funcId, "pgsort") ?? 5;
$history = new History();
$list = $history->list($searchCondition, $pgsort, $itemlimit, $listPage, $listCnt);
$cnt = count($list ?? []);

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
$tmpfile = __DIR__.'/../'.LogicConst::DIR_FILES.'/shipresults'.$excelExt;

// PhpSpreadsheetオブジェクト生成
$phpExcel = new Spreadsheet();

// テンプレート読込
$objReader = IOFactory::createReader($excelVar);
$phpExcel = $objReader->load($tmpfile);
$phpExcel->setActiveSheetIndex(0);
$sheet = $phpExcel->getActiveSheet();

// タイトル
$date_from = $searchCondition["DATE_FROM"];
$date_to = $searchCondition["DATE_TO"];
$excelTitle = "履歴一覧";
if (!empty($date_from) || !empty($date_to)) {
	$excelTitle .= " （";
	if (!empty($date_from) || !empty($date_to)) {
		if ($date_from == $date_to) {
			$excelTitle .= $date_from;
		} else {
			$excelTitle .= $date_from."～".$date_to;
		}
	}
	$excelTitle .= "）";
}
UtilExcel::setCellValue($sheet, "A1", $excelTitle);

// スタイルコピー
for ($i = 1; $i < $cnt; $i++) {
	$no = $i + 3;
	$no2 = $i + 4;

	for ($char = ord('A'); $char <= ord('P'); $char++) {
		$style = $sheet->getStyle(chr($char).$no);
		$sheet->duplicateStyle($style, chr($char).$no2);
	}
}

// 一覧セット
foreach ($list ?? [] as $i => $wk) {
	$no = $i + 4;

	$sheet->setCellValue("A".$no, $wk['history_yy']."/".$wk['history_mm']."/".$wk['history_dd']);
	UtilExcel::setCellValue($sheet, "B".$no, History::getKbnName($wk['history_kbn']));
	$sheet->setCellValueExplicit("C".$no, $wk['management_no'], DataType::TYPE_STRING);  // セルを文字列に
	$sheet->setCellValue("D".$no, $wk['branch_no']);
	UtilExcel::setCellValue($sheet, "E".$no, $wk['location_name']);
	UtilExcel::setCellValue($sheet, "F".$no, $wk['category_name']);
	UtilExcel::setCellValue($sheet, "G".$no, $wk['maker_name']);
	UtilExcel::setCellValue($sheet, "H".$no, $wk['product_name']);
	$sheet->setCellValue("I".$no, $wk['stock_in']);
	$sheet->setCellValue("J".$no, $wk['quantity']);
	$sheet->setCellValue("K".$no, $wk['move_stock']);
	$sheet->setCellValue("L".$no, $wk['location_stock']);
	$sheet->setCellValue("M".$no, date("Y/n/j H:i", strtotime($wk['created_at'])));
	UtilExcel::setCellValue($sheet, "N".$no, $wk['CREATE_USER_NAME']);
	$sheet->setCellValue("O".$no, date("Y/n/j H:i", strtotime($wk['updated_at'])));
	UtilExcel::setCellValue($sheet, "P".$no, $wk['user_name']);

	// $sheet->getRowDimension($no)->setRowHeight(-1);  // 行高さ自動調整
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
