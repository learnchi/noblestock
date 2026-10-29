<?php
namespace Noblestock\Util;

/**
 * Excelファイル操作ユーティリティクラス
 *
 */

use Noblestock\Util\UtilCommon;
use Studiogau\Chandra\Support\SessionHelper;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Support\Utility;
use Noblestock\Logic\LogicConst;
use Noblestock\Logic\BarcodeGenerator;
use Noblestock\Logic\MessageConst;

class UtilExcel {
    public const TYPE_STRING = DataType::TYPE_STRING;

	/**
	 * Excel セルへ値を書き込む。数式として解釈されうる文字列は明示的に文字列として扱う。
	 *
	 * @param Worksheet $sheet
	 * @param string $cell
	 * @param mixed $value
	 * @return void
	 */
	public static function setCellValue(Worksheet $sheet, string $cell, mixed $value): void {
		if (self::shouldWriteCellAsString($value)) {
			$sheet->setCellValueExplicit($cell, (string)$value, DataType::TYPE_STRING);
			return;
		}

		$sheet->setCellValue($cell, $value);
	}

	/**
	 * Excel の数式として解釈されうる文字列かどうかを返す。
	 *
	 * @param mixed $value
	 * @return bool
	 */
	public static function shouldWriteCellAsString(mixed $value): bool {
		if (!is_string($value) || $value === '') {
			return false;
		}

		return preg_match('/^[\s]*[=+\-@]/u', $value) === 1
			|| preg_match('/^[\t\r\n]/', $value) === 1;
	}

	/**
	 * 商品情報Excelファイルデータ取得処理.
	 * @param string $filename ファイル名
	 * @return array Excelファイルデータ{"status", "errMsg", "lists"}
	 */
	public static function getProductExcelData($filename) {
		$productHeader = array("管理番号", "カテゴリNo", "カテゴリ", "メーカーNo", "メーカー", "商品名", "卸価格",
				"小売価格", "仕入原価", "在庫数", "単位区分", "単位", "保管場所", "店舗在庫数", "画像", "備考", "備考２");
		$productColName = array("management_no", "category_id", "category_name", "maker_id",
				"maker_name", "product_name", "wholesale_amount", "retail_amount", "sell_amount", "quantity",
				"unit_id", "unit_name", "storage_place", "SHOP_STOCK", "image_file", "remarks", "remarks2");
		$excelData = self::getExcelData($filename, $productHeader, $productColName);

		// エラーチェック
		if ($excelData["status"] !== "00000") {
			return $excelData;
		}

		// マスタデータ取得
		$makerList = SessionHelper::getMasterList("makerList");
		$categoryList = SessionHelper::getMasterList("categoryList");
		$categoryList = SessionHelper::getMasterList("categoryList");
		$unitList = SessionHelper::getMasterList("unitList");

		// データ整合性チェック
		for ($i = 0; $i < count($excelData["lists"]); $i++) {
			$data = $excelData["lists"][$i];

			// カテゴリマスタ整合性チェック
			$mstArray = UtilCommon::masterConsistencyCheck($categoryList, "id", "category_name", $data["category_id"], $data["category_name"]);
			$data["category_id"] = $mstArray["id"];
			$data["category_name"] = $mstArray["category_name"];

			$mstArray = UtilCommon::masterConsistencyCheck($makerList, "id", "maker_name", $data["maker_id"], $data["maker_name"]);
			$data["maker_id"] = $mstArray["id"];
			$data["maker_name"] = $mstArray["maker_name"];

			$mstArray = UtilCommon::masterConsistencyCheck($unitList, "id", "unit_name", $data["unit_id"], $data["unit_name"]);
			$data["unit_id"] = $mstArray["id"];
			$data["unit_name"] = $mstArray["unit_name"];
			unset($mstArray);

			// 画像ファイル名チェック
			if (!Utility::checkImageName($data["image_file"])) {
				$data["image_file"] = "";
			}

			// 卸価格
			$data["wholesale_amount"] = is_numeric($data["wholesale_amount"] ? $data["wholesale_amount"] : 0);

			// 小売価格
			$data["retail_amount"] = is_numeric($data["retail_amount"] ? $data["retail_amount"] : 0);

			// 仕入原価
			$data["sell_amount"] = is_numeric($data["sell_amount"] ? $data["sell_amount"] : 0);

			// 固定
			$data["quantity"] = 0;
			$data["SHOP_STOCK"] = "";

			$excelData["lists"][$i] = $data;
		}

		return $excelData;
	}

	/**
	 * 在庫情報Excelファイルデータ取得処理.
	 * @param string $filename ファイル名
	 * @return array Excelファイルデータ{"status", "errMsg", "lists"}
	 */
	public static function getStockExcelData($filename) {
		$stockHeader = array("管理番号", "店舗No", "店舗名", "在庫数");
		$stockColName = array("management_no", "location_id", "location_name", "quantity");

		// Excelデータ取得、チェック
		$excelData = self::getExcelData($filename, $stockHeader, $stockColName);
		if ($excelData["status"] !== "00000") {
			// 店舗別商品一覧出力Excelからデータ取得
			$stockHeader = array( "店舗No", "店舗名", "管理番号", "カテゴリNo", "カテゴリ", "メーカーNo", "メーカー", "商品名", "卸価格", "小売価格", "仕入原価", "在庫数", "単位区分", "単位", "保管場所", "画像", "備考", "備考２");
			$stockColName = array("location_id", "location_name", "management_no", "category_id", "category_name", "maker_id", "maker_name", "product_name", "wholesale_amount", "retail_amount", "sell_amount", "quantity", "unit_id", "unit_name", "storage_place", "image_file", "remarks", "remarks2");
			$excelData = self::getExcelData($filename, $stockHeader, $stockColName);
			if ($excelData["status"] !== "00000") {
				return $excelData;
			}
		}

		// 店舗マスタデータ取得
		$locationList = SessionHelper::getMasterList("locationList");

		// データ整合性チェック
		for ($i = 0; $i < count($excelData["lists"]); $i++) {
			$data = $excelData["lists"][$i];

			// 店舗マスタ整合性チェック
			$mstArray = UtilCommon::masterConsistencyCheck($locationList, "id", "location_name", $data["location_id"], $data["location_name"]);
			$data["location_id"] = $mstArray["id"];
			$data["location_name"] = $mstArray["location_name"];
			unset($mstArray);

			// 在庫数
			$data["quantity"] = (is_numeric($data["quantity"]) ? $data["quantity"] : 0);

			$excelData["lists"][$i] = $data;
		}

		return $excelData;
	}

	/**
	 * Excelファイルデータ読み込み処理.
	 * @param string $filename ファイル名
	 * @param array $headerArray ヘッダ論理名配列
	 * @param array $colNameArray ヘッダ物理名配列
	 * @param int $headerRow ヘッダ行の開始インデックス
	 * @return array Excelファイルデータ{"status", "errMsg", "lists"}
	 */
	
    public static function getExcelData(string $filename, array $headerArray, array $colNameArray, int $headerRow = 1): array {
		// ロガー
		$logger = Logger::createDefault(dirname(__DIR__, 1));

		// ファイル名入力チェック
		if ($filename === '') {
            return array("status" => "90002", "errMsg" => MessageConst::MSG_VAL_FILE_007);
        }
        // Excelファイル存在チェック
		$filenm = __DIR__."/../".LogicConst::DIR_TMP."/".$filename;
        if (!is_file($filenm) || !is_readable($filenm)) {
            return array("status" => "90003", "errMsg" => MessageConst::MSG_VAL_FILE_008);
        }

		// Excelバージョン判定
        $colCount = count($headerArray);
        if ($colCount !== count($colNameArray)) {
            return array("status" => "90004", "errMsg" => MessageConst::MSG_VAL_FILE_009);
        }
        try {
            $spreadsheet = IOFactory::load($filenm);
            $sheet = $spreadsheet->getActiveSheet();
            for ($i = 0; $i < $colCount; $i++) {
                $colIndex = $i + 1;
                $addr = Coordinate::stringFromColumnIndex($colIndex) . $headerRow;
                $cell = $sheet->getCell($addr);
                $actual = self::getText($cell, true);
                if ($actual !== (string)$headerArray[$i]) {
                    return array("status" => "90004", "errMsg" => MessageConst::MSG_VAL_FILE_010);
                }
            }

			// Excelデータを配列に変換
            $excelData = array();
            $highestRow = $sheet->getHighestRow();
            for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
                $record = array();
                $empty = 0;
                for ($i = 0; $i < $colCount; $i++) {
                    $colIndex = $i + 1;
                    $addr = Coordinate::stringFromColumnIndex($colIndex) . $row;
                    $cell = $sheet->getCell($addr);
                    $value = self::getText($cell, false);
                    if ($value === '') {
                        $empty++;
                    }
                    $record[$colNameArray[$i]] = $value;
                }
                if ($empty === $colCount) {
                    continue;
                }
                $excelData[] = $record;
            }

			// ファイル削除
            if (!unlink($filenm)) {
			// 削除失敗
                $logger->warn(Utility::replaceStr(MessageConst::MSG_SYS_FILE_016,$filenm));
            }

			// レコード数チェック
            if (count($excelData) < 1) {
                return array("status" => "90005", "errMsg" => MessageConst::MSG_VAL_FILE_011);
            }
            return array("status" => "00000", "errMsg" => "", "lists" => $excelData);
        } catch (\Throwable $e) {
            $logger->warn("Excel読込エラー: ".$e->getMessage());
            return array("status" => "90003", "errMsg" => MessageConst::MSG_VAL_FILE_008);
        }
    }


	/* 指定したセルの文字列を取得 */
	private static function getText(Cell $cell, bool $headerMode = false): string {
        if ($headerMode) {
            return trim((string)$cell->getFormattedValue());
        }
        $value = $cell->getCalculatedValue();
        if ($value === null) {
            return "";
        }
        if (is_numeric($value) && ExcelDate::isDateTime($cell)) {
            try {
                $dt = ExcelDate::excelToDateTimeObject((float)$value);
                return $dt->format("Y-m-d H:i:s");
            } catch (\Throwable $e) {
                return trim((string)$value);
            }
        }
        return trim((string)$value);
    }

	/**
	 * Excelファイルを読込み、管理番号とバーコードの配列を返す
	 *
	 * @param string $filename ファイル名
	 * @param string $nofrom 開始管理No
	 * @param string $noto 終了管理No
	 * @return array 管理番号とバーコードの配列
	 */
	public static function getImportBarcode($filename, $nofrom, $noto) {
		// ロガー
		$logger = Logger::createDefault(dirname(__DIR__, 1));

		$barcodeOldHeader = array("管理番号", "バーコード");
		$barcodeNewHeader = array("SEQ", "管理番号");
		$barcodeColName = array("SEQ", "management_no");
		$checkFlg = false;

		for ($i = 1; $i <= 3; $i++) {
			$wklist = self::getExcelData($filename, $barcodeOldHeader, $barcodeColName, $i);
			if ($wklist["status"] === "00000") {
				$checkFlg = true;
				break;
			} else {
				$wklist = self::getExcelData($filename, $barcodeNewHeader, $barcodeColName, $i);
				if ($wklist["status"] === "00000") {
					$checkFlg = true;
					break;
				}
			}
		}

		if (!$checkFlg) {
			return $wklist;
		}

		// from、toによる絞込み
		$list = array();
		$st = false;
		if (empty($nofrom)) {
			$st = true;
		}
		// 範囲指定
		foreach ($wklist["lists"] as $wk) {
			if (!empty($wk["SEQ"]) && !empty($wk["management_no"])) {
				if ($wk["SEQ"] == $nofrom) {
					$st = true;
				}
				if ($st) {
					$barno = $wk["SEQ"];
					$barcd = $wk["management_no"];
					if (preg_match("/^[0-9a-zA-Z\.\/\-\+\s]+$/", $barno)) {
						if (BarcodeGenerator::check($barcd) != 99) {
							$list[] = array("SEQ" => $barno, "management_no" => $barcd);
						}
					}
				}
				if ($wk["SEQ"] == $noto) {
					break;
				}
			}
		}
		unset($wklist);
		// レコード数チェック
		if (count($list) < 1) {
			return array("status" => "90005", "errMsg" => MessageConst::MSG_VAL_FILE_011);
		}

		return array("status" => "00000", "errMsg" => "", "lists" => $list);
	}

	/**
	 * バーコードExcel出力レイアウト取得
	 *
	 * @param array $productData 商品情報
	 * @param string $seqNo SEQ
	 * @return array レイアウトデータ
	 */
	public static function getBarcodeLayout($productData, $seqNo = "") {
		$rtnLayout = null;

		$barmng = $productData["management_no"] ?? '';
		$barimg = $productData['image_file'] ?? '';

		$bartop = "";
		$label_upper = intval(SessionHelper::getPref("LABEL_UPPER"));
		if ($label_upper === 1) {
			// 管理番号
			$bartop = $productData["management_no"] ?? '';
		} else if ($label_upper === 2) {
			// カテゴリ
			$bartop = $productData["category_name"] ?? '';
		} else if ($label_upper === 3) {
			// メーカー
			$bartop = $productData["maker_name"] ?? '';
		} else if ($label_upper === 4) {
			// 商品名
			$bartop = $productData["product_name"] ?? '';
		} else if ($label_upper === 5) {
			// 卸価格
			if ($productData['wholesale_amount'] != null && $productData['wholesale_amount'] != "") {
				$bartop = '\\'.number_format($productData['wholesale_amount']);
			}
		} else if ($label_upper === 6) {
			// 小売価格
			if ($productData['retail_amount'] != null && $productData['retail_amount'] != "") {
				$bartop = '\\'.number_format($productData['retail_amount']);
			}
		} else if ($label_upper === 7) {
			// 仕入原価
			if ($productData['sell_amount'] != null && $productData['sell_amount'] != "") {
				$bartop = '\\'.number_format($productData['sell_amount']);
			}
		} else if ($label_upper === 8) {
			// 保管場所
			$bartop = $productData["storage_place"] ?? '';
		} else if ($label_upper === 9) {
			// SEQ No
			$bartop = $seqNo;
		} else if ($label_upper === 0) {
			// 非表示
			$bartop = "";
		}

		$barbtm = "";
		$label_lower = intval(SessionHelper::getPref("LABEL_LOWER"));
		if ($label_lower === 1) {
			// 管理番号
			$barbtm = $productData["management_no"] ?? '';
		} else if ($label_lower === 2) {
			// カテゴリ
			$barbtm = $productData["category_name"] ?? '';
		} else if ($label_lower === 3) {
			// メーカー
			$barbtm = $productData["maker_name"] ?? '';
		} else if ($label_lower === 4) {
			// 商品名
			$barbtm = $productData["product_name"] ?? '';
		} else if ($label_lower === 5) {
			// 卸価格
			if ($productData['wholesale_amount'] != null && $productData['wholesale_amount'] != "") {
				$barbtm = '\\'.number_format($productData['wholesale_amount']);
			}
		} else if ($label_lower === 6) {
			// 小売価格
			if ($productData['retail_amount'] != null && $productData['retail_amount'] != "") {
				$barbtm = '\\'.number_format($productData['retail_amount']);
			}
		} else if ($label_lower === 7) {
			// 仕入原価
			if ($productData['sell_amount'] != null && $productData['sell_amount'] != "") {
				$barbtm = '\\'.number_format($productData['sell_amount']);
			}
		} else if ($label_lower === 8) {
			// 保管場所
			$barbtm = $productData["storage_place"] ?? '';
		} else if ($label_lower === 9) {
			// SEQ No
			$barbtm = $seqNo;
		} else if ($label_lower === 0) {
			// 非表示
			$barbtm = "";
		}

		$rtnLayout = array("barmng" => $barmng, "barimg" => $barimg, "bartop" => $bartop, "barbtm" => $barbtm);

		return $rtnLayout;
	}

}
?>
