<?php
declare(strict_types=1);

namespace Noblestock\Logic;

use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Auth\AuthService;
use Studiogau\Chandra\Support\Utility;
use Studiogau\Chandra\Support\SessionHelper;
use Studiogau\Chandra\Database\RecordNotFoundException;
use Studiogau\Chandra\Database\MultipleRecordsFoundException;
use Noblestock\Logic\LogicConst;
use Noblestock\Logic\MenuRouter;
use Noblestock\DbLogic\Check;
use Noblestock\DbLogic\Stock;
use Noblestock\DbLogic\Product;

/**
 * CommandResult
 * - BarcodeProcessor の処理結果を表すクラス
 */
class CommandResult
{
    public function __construct(
        private bool $redirect = false,
        private ?string $redirectUrl = null,
        private bool $result = false,
        private ?array $resultArray = null,
    ) {}

    // 結果を返さない処理
    public static function done(): self
    {
        return new self();
    }

    // 結果を配列で返す処理
    public static function result(array $result): self
    {
        return new self(false, null, true, $result);
    }
    public function hasResult(): bool
    {
        return $this->result;
    }
    public function getResultArray(): ?array
    {
        return $this->resultArray;
    }

    // redirectする場合URLを保持する
    public static function redirect(string $url): self
    {
        return new self(true, $url);
    }
    public function isRedirect(): bool
    {
        return $this->redirect;
    }
    public function getRedirectUrl(): ?string
    {
        return $this->redirectUrl;
    }
}

/**
 * BarcodeProcessor
 * - バーコード入力を処理するクラス
 */
final class BarcodeProcessor
{
    public const MODE_SELECT    = 'select';    // 商品表示
    public const MODE_STOCK_OUT = 'stock_out';  // 商品出庫
    public const MODE_STOCK_IN  = 'stock_in';  // 商品入庫
    public const MODE_TRANSFER  = 'transfer';   // 商品移動
    public const MODE_CHECK     = 'check';    // 在庫チェック

    private Logger $logger;

    public function __construct(
        private AuthService $auth,
        private string $funcId,
        private string $mode = self::MODE_STOCK_OUT,
        ?Logger $logger = null,
    ) {
        $this->logger = $logger ?? Logger::createDefault(dirname(__DIR__, 1));
    }

    /**
     * 入力バーコードの振り分け
     * 画面側は $processor->handle($bcin) を呼ぶだけ
     */
    public function handle(string $bcin): CommandResult
    {
        $bcin = trim($bcin);
        if ($bcin === '') {
            return CommandResult::done();
        }

        // メニュー遷移は最優先
        $router = new MenuRouter($this->auth);
        $target = $router->resolve($bcin);

        // バーコードメニュー指定時にリダイレクトする
        if ($target !== null) {
            return CommandResult::redirect($target);
        }

        // 以下、各コマンドの振り分け

        // 取消（PCPO）入庫/出庫/移動
        if ($bcin === LogicConst::CMD_UNDO) {

            return match ($this->mode) {
                self::MODE_STOCK_IN   => $this->undoStock($bcin),
                self::MODE_STOCK_OUT => $this->undoStock($bcin),
                self::MODE_TRANSFER  => $this->undoStock($bcin),
                self::MODE_CHECK     => $this->undoChange($bcin),
                default             => $this->undoStock($bcin),
            };
        }

        // キャンセル（PCPN）
        if ($bcin === LogicConst::CMD_CANCEL) {
            return match ($this->mode) {
                self::MODE_STOCK_IN  => $this->cancelStock($bcin),
                self::MODE_STOCK_OUT => $this->cancelStock($bcin),
                self::MODE_TRANSFER  => $this->cancelStock($bcin),
                self::MODE_CHECK     => $this->cancelChange($bcin),
                default              => $this->cancelStock($bcin),
            };
        }

        // 確定（PCPP）
        if ($bcin === LogicConst::CMD_COMPLETE) {
            return match ($this->mode) {
                self::MODE_STOCK_IN  => $this->completeStockIn($bcin),
                self::MODE_STOCK_OUT => $this->completeStockOut($bcin),
                self::MODE_TRANSFER  => $this->completeTransfer($bcin),
                self::MODE_CHECK     => $this->completeCheck($bcin),
                default              => $this->completeStockOut($bcin),
            };
        }

        // 値入力（PCV...）
        if (strpos($bcin, LogicConst::CMD_VAL) === 0) {
            return match ($this->mode) {
                self::MODE_STOCK_IN   => $this->valueInput($bcin),
                self::MODE_STOCK_OUT => $this->valueInput($bcin),
                self::MODE_TRANSFER  => $this->valueInput($bcin),
                self::MODE_CHECK     => $this->valueInputChange($bcin),
                default              => $this->valueInput($bcin),
            };
        }

        // ロケ入力（PLP...）
        if (strpos($bcin, LogicConst::CMD_LOC) === 0) {
            return match ($this->mode) {
                self::MODE_STOCK_IN => $this->locationInput($bcin),
                self::MODE_STOCK_OUT => $this->locationInput($bcin),
                self::MODE_TRANSFER  => $this->transferLocationInput($bcin),
                self::MODE_CHECK     => $this->locationForCheck($bcin),
                default             => $this->locationInput($bcin),
            };
        }

        // どれにも当たらなければ「商品バーコード」
        return match ($this->mode) {
			self::MODE_SELECT    => $this->productSelect($bcin),
            self::MODE_STOCK_IN  => $this->productInput($bcin),
            self::MODE_STOCK_OUT => $this->productInput($bcin),
            self::MODE_TRANSFER  => $this->transferProductInput($bcin),
            self::MODE_CHECK     => $this->checkProductInput($bcin),
            default             => $this->productInput($bcin),
        };
    }

    // ----------------------------
    // 各コマンドの実装
    // ----------------------------

    /**
     * 取消（PCPO）
     * 入庫/出庫/移動
     * LogicConst::CMD_UNDO;    // PCPO
     */
    private function undoStock(string $bcin): CommandResult
    {
        $proData = SessionHelper::getData($this->funcId, "proData");
        $valData = SessionHelper::getData($this->funcId, "valData");

        if ($valData == null && is_array($proData) && !empty($proData)) {
            $delData = array_pop($proData);    // 末尾の商品を取り出す
            if ($delData["STOCK_COUNT"] != "") {
                // 数量だけ取り消す
                $delData['STOCK_COUNT'] = "";
                $proData[] = $delData;// 数量を取り消した商品を戻す
            }

            SessionHelper::setData($this->funcId, "proData", $proData);
        }
        return CommandResult::done();
    }
    /**
     * 取消処理
     * 在庫チェック
     * LogicConst::CMD_UNDO;    // PCPO
     */
    private function undoChange(string $bcin): CommandResult
    {

        $user_id = $this->auth->getCurrentUser()?->getLoginId();
		$undo_location_no = SessionHelper::getData($this->funcId, "undo_location_no");  // 店舗NO
		if (!empty($undo_location_no)) {
			$undo_management_no = SessionHelper::getData($this->funcId, "undo_management_no");  // 管理番号
			$undo_check_count = SessionHelper::getData($this->funcId, "undo_check_count");  // チェック数

			$check = new Check();
			// チェック数変更処理
			try {
				$rtnChange = $check->change($this->auth->getCurrentUser()?->getLoginId(), $undo_location_no, $undo_management_no, $undo_check_count, false);
				SessionHelper::FlushSuccess(Utility::replaceStr(MessageConst::MSG_OK_BARCODE_007,$undo_management_no,$undo_check_count));
			} catch (\Exception $e) {
				// エラー
				SessionHelper::flushError(MessageConst::MSG_SYS_BARCODE_008);
				$this->logger->fatal(__FILE__." ".MessageConst::MSG_SYS_BARCODE_008." user_id=".$user_id." location_no=".$undo_location_no." management_no=".$undo_management_no." ".$e->getMessage());
			}

			// セッション削除
			SessionHelper::delData($this->funcId, "undo_location_no");
			SessionHelper::delData($this->funcId, "undo_management_no");
			SessionHelper::delData($this->funcId, "undo_check_count");
		}


		return CommandResult::done();
    }
    /**
     * キャンセル処理
     * 入庫/出庫/移動
     * LogicConst::CMD_CANCEL;    // PCPN
     */
    private function cancelStock(string $bcin): CommandResult
    {
		SessionHelper::delData($this->funcId, "proData");
		SessionHelper::delData($this->funcId, "valData");
		SessionHelper::delData($this->funcId, "locData");
		SessionHelper::delData($this->funcId, "locCount");    // 移動でのみ使用
		return CommandResult::done();
    }
    /**
     * キャンセル処理
     * 在庫チェック
     * LogicConst::CMD_CANCEL;    // PCPN
     */
    private function cancelChange(string $bcin): CommandResult
    {

		return CommandResult::done();
    }

    /**
     * 出庫 完了処理
     * LogicConst::CMD_COMPLETE;    // PCPP
     */
    private function completeStockOut(string $bcin): CommandResult
    {
		$result = array("status" => "99999", "errMsg" => "未処理");

		$proData = SessionHelper::getData($this->funcId, "proData");
		$valData = SessionHelper::getData($this->funcId, "valData");
		$locData = SessionHelper::getData($this->funcId, "locData");

		if (is_array($proData) && !empty($proData)) {
			$delData = array_pop($proData);
			$stockCnt = $delData["STOCK_COUNT"];
			if ($stockCnt == "") {
				$stockCnt = 1;
				if ($valData != null) $stockCnt = $valData;
			}
			$delData['STOCK_COUNT'] = $stockCnt;
			$proData[] = $delData;
			SessionHelper::setData($this->funcId, "proData", $proData);

			// 在庫数不足チェック
			foreach ($proData ?? [] as $i => $wk) {
				if ($wk['location_stock'] == 0) {
					// 店舗在庫数が0
					SessionHelper::flushError(MessageConst::MSG_VAL_BARCODE_009);  // 在庫のない商品があります。
					break;
				} else {
					// 店舗在庫数が足らない場合
					if ($wk['STOCK_COUNT'] != null && $wk['STOCK_COUNT'] != "") {
						if ($wk['location_stock'] < $wk['STOCK_COUNT']) {
							SessionHelper::flushError(MessageConst::MSG_VAL_BARCODE_010);  // 在庫数が足りない商品があります。
							break;
						}
					}
				}
			}
		}

		if (sessionHelper::hasFlushError() == false) {
			if ($locData != null && $proData != null) {
				// 店舗情報設定
				$wklocData = array();
				$wklocData[0] = $locData;

				// 出庫処理
				$stock = new Stock();
				$result = $stock->reduce($proData, $wklocData); 
				if ($result['errMsg'] !== "") {
					SessionHelper::flushError($result['errMsg']);
				}
				// キャンセルと同一処理でセッションクリア
				self::cancelStock($bcin);
			} else {
				if ($proData == null) {
					SessionHelper::flushError(MessageConst::MSG_INF_BARCODE_003);
				}
				if ($locData == null) {
					SessionHelper::flushError(MessageConst::MSG_INF_BARCODE_004);
				}
			}
		}
		
		return CommandResult::result($result);
    }

    /**
     * 入庫 完了処理
     * LogicConst::CMD_COMPLETE;    // PCPP
     */
    private function completeStockIn(string $bcin): CommandResult
    {
		$result = array("status" => "99999", "errMsg" => "未処理");

		$proData = SessionHelper::getData($this->funcId, "proData");
		$valData = SessionHelper::getData($this->funcId, "valData");
		$locData = SessionHelper::getData($this->funcId, "locData");

		if (is_array($proData) && !empty($proData)) {
			$delData = array_pop($proData);
			$stockCnt = $delData["STOCK_COUNT"];
			if ($stockCnt == "") {
				$stockCnt = 1;
				if ($valData != null) $stockCnt = $valData;
			}
			$delData['STOCK_COUNT'] = $stockCnt;
			$proData[] = $delData;
			SessionHelper::setData($this->funcId, "proData", $proData);
		}

		if ($locData != null && $proData != null) {
			// 店舗情報設定
			$wklocData = array();
			$wklocData[0] = $locData;

			// 入庫処理
			$stock = new Stock();
			$result = $stock->add($proData, $wklocData);
			if ($result['errMsg'] !== "") {
				SessionHelper::flushError($result['errMsg']);
			}
			// キャンセルと同一処理でセッションクリア
			self::cancelStock($bcin);

		} else {
			if ($proData == null) {
				SessionHelper::flushError(MessageConst::MSG_INF_BARCODE_003);
			}
			if ($locData == null) {
				SessionHelper::flushError(MessageConst::MSG_INF_BARCODE_004);
			}
		}
		return CommandResult::result($result);
    }

    /**
     * 移動 完了処理
     * LogicConst::CMD_COMPLETE;    // PCPP
     */
    private function completeTransfer(string $bcin): CommandResult
    {
		$result = array("status" => "99999", "errMsg" => "未処理");

		$proData = SessionHelper::getData($this->funcId, "proData");
		$valData = SessionHelper::getData($this->funcId, "valData");
		$locData = SessionHelper::getData($this->funcId, "locData");

		if (is_array($proData) && !empty($proData)) {
			$delData = array_pop($proData);
			$stockCnt = $delData["STOCK_COUNT"];
			if ($stockCnt == "") {
				$stockCnt = 1;
				if ($valData != null) $stockCnt = $valData;
			}
			$delData['STOCK_COUNT'] = $stockCnt;
			$proData[] = $delData;
			SessionHelper::setData($this->funcId, "proData", $proData);

			// 在庫数不足チェック
			foreach ($proData ?? [] as $wk) {
				if ($wk['location_stock'] == 0) {
					// 店舗在庫数が0
					SessionHelper::flushError(MessageConst::MSG_VAL_BARCODE_009);
					break;
				} else {
					// 店舗在庫数が足らない場合
					if ($wk['STOCK_COUNT'] != null && $wk['STOCK_COUNT'] != "") {
						if ($wk['location_stock'] < $wk['STOCK_COUNT']) {
							SessionHelper::flushError(MessageConst::MSG_VAL_BARCODE_010);  // 在庫数が足りない商品があります。
							break;
						}
					}
				}
			}
		}

		if (sessionHelper::hasFlushError() == false) {
			if ($locData != null && $proData != null) {
				// 移動処理
				$stock = new Stock();
				$result = $stock->transfer($proData, $locData);
				if ($result['errMsg'] !== "") {
					SessionHelper::flushError($result['errMsg']);
				}
				// キャンセルと同一処理でセッションクリア
				self::cancelStock($bcin);
			} else {
				if ($proData == null) {
					SessionHelper::flushError(MessageConst::MSG_INF_BARCODE_003);
				}
				if ($locData == null) {
					SessionHelper::flushError(MessageConst::MSG_INF_BARCODE_004);
				}
			}
		}
		
		return CommandResult::result($result);
    }

    /**
     * 在庫チェック 完了処理
     * LogicConst::CMD_COMPLETE;    // PCPP
     */
    private function completeCheck(string $bcin): CommandResult
    {
		return CommandResult::done();
    }

    /**
     * 数値入力処理
     *  'PVP1'～'PVP9'
     * 入庫/出庫/移動
     * LogicConst::CMD_VAL;    // PCV...
     */
    private function valueInput(string $bcin): CommandResult
    {
		$proData = SessionHelper::getData($this->funcId, "proData");
		$valData = SessionHelper::getData($this->funcId, "valData");

		if (strlen($bcin) === 4) {
			if (Utility::checkNumeric($bcin[3])) {
				if (is_array($proData) && !empty($proData)) {
					$endData = end($proData);
					if ($endData["STOCK_COUNT"] == "") {
						if ($valData == null) {
							if ($bcin[3] !== "0") {
								$valData = "".$bcin[3];
							}
						} else {
							$valData = $valData.$bcin[3];
						}
						SessionHelper::setData($this->funcId, "valData", $valData);
					} else {
						SessionHelper::delData($this->funcId, "valData");
					}
				}
			}
		}
		return CommandResult::done();
    }
    /**
     * 数値入力処理
     *  'PVP1'～'PVP9'
     * 在庫チェック
     * LogicConst::CMD_VAL;    // PCV...
     */
    private function valueInputChange(string $bcin): CommandResult
    {
		return CommandResult::done();
    }

    /**
     * 店舗入力処理
     * 入庫/出庫
     * LogicConst::CMD_LOC;    // PLP...
     */
    private function locationInput(string $bcin): CommandResult
    {
		$proData = SessionHelper::getData($this->funcId, "proData");
		
		// マスタデータ取得
		$locationList = SessionHelper::getMasterList("locationList");

		$locFlg = false;
		foreach ($locationList ?? [] as $i => $wk) {
			if (LogicConst::CMD_LOC.$wk['id'] === $bcin) {
				$locData = array("location_id" => $wk['id'], "location_name" => $wk['location_name']);
				SessionHelper::setData($this->funcId, "locData", $locData);
				$locFlg = true;
				break;
			}
		}
		if ($locFlg) {    // 存在する店舗コード
			// 店舗在庫数取得
			if ($proData != null) {
				$wkproData = array();
				// ロジック
				$stock = new Stock();
				foreach ($proData ?? [] as $i => $wk) {
					$locStock = 0;
					try{
						$retStock = $stock->select($wk['management_no'], $locData['location_id']);
						if (!empty($retStock)) {
							$locStock = $retStock['quantity'];
						}
					} catch (RecordNotFoundException | MultipleRecordsFoundException $e) {
						// 店舗在庫がない
						$this->logger->info("店舗在庫がありません".$e->getMessage());
					}
					$wk['location_stock'] = $locStock;
					$wkproData[] = $wk;
				}
				$proData = $wkproData;
				SessionHelper::setData($this->funcId, "proData", $proData);
			}
		} else {
			SessionHelper::flushError(MessageConst::MSG_VAL_BARCODE_012);    // "店舗が存在しません。"
		}

		return CommandResult::done();
    }
    /**
     * 店舗入力処理
     * 移動
     * LogicConst::CMD_LOC;    // PLP...
     */
    private function transferLocationInput(string $bcin): CommandResult
    {
		$proData = SessionHelper::getData($this->funcId, "proData");
		$locData = SessionHelper::getData($this->funcId, "locData");
		$locCount = SessionHelper::getData($this->funcId, "locCount");

		// マスタデータ取得
		$locationList = SessionHelper::getMasterList("locationList");

		$locFlg = false;
		foreach ($locationList ?? [] as $wk) {
			if (LogicConst::CMD_LOC.$wk['id'] === $bcin) {
				if ($locData == null) {
					$locData = array();
					$locData[0] = array("location_id" => $wk['id'], "location_name" => $wk['location_name']);
					$locCount = 2;
				} else {
					if ($locCount == 1) {
						$locData[0] = array("location_id" => $wk['id'], "location_name" => $wk['location_name']);
						$locCount = 2;
					} else if ($locCount == 2) {
						$locData[1] = array("location_id" => $wk['id'], "location_name" => $wk['location_name']);
						$locCount = 1;
					}
				}
				if (count($locData ?? []) == 2) {
					$locData = array_unique($locData, SORT_REGULAR);
					if (count($locData ?? []) == 1) {
						$locCount = 2;
					}
				}
				SessionHelper::setData($this->funcId, "locData", $locData);
				SessionHelper::setData($this->funcId, "locCount", $locCount);
				$locFlg = true;
				break;
			}
		}
		if ($locFlg) {    // 存在する店舗コード
			// 店舗在庫数取得
			if ($proData != null) {
				$wkproData = array();	
				// ロジック
				$stock = new Stock();					
				foreach ($proData ?? [] as $wk) {
					if ($locData[0] != null) {
						$locStock = 0;
						try {
							$retStock = $stock->select($wk['management_no'], $locData[0]['location_id']);
							if ($retStock != null) {
								$locStock = $retStock['quantity'];
							}
						} catch (RecordNotFoundException | MultipleRecordsFoundException $e) {
							// 店舗在庫がない
						}
						$wk['location_stock'] = $locStock;
					}
					if ($locData[1] != null) {
						$locStock = 0;
						try {
							$retStock = $stock->select($wk['management_no'], $locData[1]['location_id']);
							if ($retStock != null) {
								$locStock = $retStock['quantity'];
							}
						} catch (RecordNotFoundException | MultipleRecordsFoundException $e) {
							// 店舗在庫がない
							
						}
						$wk['LOCATION_STOCK_TO'] = $locStock;
					}
					$wkproData[] = $wk;
				}
				$proData = $wkproData;
				SessionHelper::setData($this->funcId, "proData", $proData);
			}
		} else {
			SessionHelper::flushError(MessageConst::MSG_VAL_BARCODE_012);    // "店舗が存在しません。"
		}

		return CommandResult::done();
    }

    /**
     * 店舗入力処理
     * 在庫チェック
     * LogicConst::CMD_LOC;    // PLP...
     */
    private function locationForCheck(string $bcin): CommandResult
    {
		// マスタデータ取得
		$locationList = SessionHelper::getMasterList("locationList");

		$locFlg = false;
		foreach ($locationList ?? [] as $wk) {
			if (LogicConst::CMD_LOC.$wk['id'] === $bcin) {
				$locData = array("location_id" => $wk['id'], "location_name" => $wk['location_name']);
				SessionHelper::setData($this->funcId, "locData", $locData);
				$locFlg = true;
				break;
			}
		}
		if (!$locFlg) {
			// 店舗が存在しない
			SessionHelper::flushError(MessageConst::MSG_VAL_BARCODE_012);
		}

		return CommandResult::done();
    }

    /**
     * 商品コード入力処理
     * 入庫/出庫
     */
    private function productInput(string $bcin): CommandResult
    {		
		$proData = SessionHelper::getData($this->funcId, "proData");
		$valData = SessionHelper::getData($this->funcId, "valData");
		$locData = SessionHelper::getData($this->funcId, "locData");
		// ロジック
		$product = new Product();
		$stock = new Stock();

		try {
			// 商品取得
			$rtnProduct = $product->select($bcin);
			if (!is_array($proData) or empty($proData)) {
				$proData = array();
			} else {
				// バーコード入力個数確定（個数空は1を設定）
				$delData = array_pop($proData);
				$stockCnt = $delData["STOCK_COUNT"];
				if ($stockCnt == "") {
					$stockCnt = 1;
					if ($valData != null) $stockCnt = $valData;
				}
				$delData['STOCK_COUNT'] = $stockCnt;
				$proData[] = $delData;
			}

			// 店舗在庫数取得
			$locStock = "";
			if ($locData != null) {
				$locStock = 0;
				try {
					$retStock = $stock->select($rtnProduct['management_no'], $locData['location_id']);
					if (!empty($retStock)) {
						$locStock = $retStock['quantity'];
					}
				} catch (RecordNotFoundException | MultipleRecordsFoundException $e) {
					// 店舗在庫がない（許容されるエラー）
				}
			}
			$proData[] = array("management_no" => $rtnProduct['management_no'], "category_name" => $rtnProduct['category_name'], "maker_name" => $rtnProduct['maker_name'], "product_name" => $rtnProduct['product_name'], "quantity" => $rtnProduct['quantity'], "location_stock" => $locStock, "STOCK_COUNT" => "");
			SessionHelper::setData($this->funcId, "proData", $proData);
			SessionHelper::delData($this->funcId, "valData");
			
		} catch (RecordNotFoundException | MultipleRecordsFoundException $e) {
			SessionHelper::flushError(MessageConst::MSG_VAL_BARCODE_002);
			
		}
		return CommandResult::done();
    }

    /**
     * 商品コード入力処理
     * 移動
     */
    private function transferProductInput(string $bcin): CommandResult
    {
		$proData = SessionHelper::getData($this->funcId, "proData");
		$valData = SessionHelper::getData($this->funcId, "valData");
		$locData = SessionHelper::getData($this->funcId, "locData");
		// ロジック
		$product = new Product();
		$stock = new Stock();

        try{
			$rtnProduct = $product->select($bcin);
			if ($proData == null) {
				$proData = array();
			} else {
				// バーコード入力個数確定（個数空は1を設定）
				$delData = array_pop($proData);
				$stockCnt = $delData["STOCK_COUNT"];
				if ($stockCnt == "") {
					$stockCnt = 1;
					if ($valData != null) $stockCnt = $valData;
				}
				$delData['STOCK_COUNT'] = $stockCnt;
				$proData[] = $delData;
			}

			// 店舗在庫数取得
			$locStockFrom = "";
			if (!empty($locData[0])) {
				$locStockFrom = 0;
				try {
					$retStock = $stock->select($rtnProduct['management_no'], $locData[0]['location_id']);

					if ($retStock != null) {
						$locStockFrom = $retStock['quantity'];
					}
				} catch (RecordNotFoundException | MultipleRecordsFoundException $e) {
					// 店舗在庫がない（許容されるエラー）
				}
			}
			$locStockTo = "";
			if (!empty($locData[1])) {
				$locStockTo = 0;
				try {
					$retStock = $stock->select($rtnProduct['management_no'], $locData[1]['location_id']);

					if ($retStock != null) {
						$locStockTo = $retStock['quantity'];
					}
				} catch (RecordNotFoundException | MultipleRecordsFoundException $e) {
					// 店舗在庫がない（許容されるエラー）
				}
			}

			$proData[] = array("management_no" => $rtnProduct['management_no'], "category_name" => $rtnProduct['category_name'], "maker_name" => $rtnProduct['maker_name'], "product_name" => $rtnProduct['product_name'], "quantity" => $rtnProduct['quantity'], "location_stock" => $locStockFrom, "LOCATION_STOCK_TO" => $locStockTo, "STOCK_COUNT" => "");
			SessionHelper::setData($this->funcId, "proData", $proData);
			SessionHelper::delData($this->funcId, "valData");
        } catch (RecordNotFoundException | MultipleRecordsFoundException $e) {
			SessionHelper::flushError(MessageConst::MSG_VAL_BARCODE_002);
			
		}
		return CommandResult::done();
    }

    /**
     * 商品コード入力処理
     * 在庫チェック
     */
    private function checkProductInput(string $bcin): CommandResult
    {
		$locData = SessionHelper::getData($this->funcId, "locData");
		// ロジック
		$stock = new Stock();
        
        $user_id = $this->auth->getCurrentUser()?->getLoginId();

		if (!empty($locData)) {
			// 在庫チェック情報取得
			try {
				$checkData = $stock->select4Check($user_id, $locData["location_id"], $bcin);
			} catch (\Exception $e) { 
				// 商品が存在しない
				SessionHelper::flushError(MessageConst::MSG_VAL_BARCODE_002);    // "対象商品が存在しません。"
				$this->logger->error(__FILE__." ".MessageConst::MSG_VAL_BARCODE_002." ".$e->getMessage());
			}

			if (!empty($checkData)) {
				$undo_check_count = $checkData["check_count"];  // 変更前チェック数

				// チェック数加算処理
				$check = new Check();
				try {
					$rtnCheck = $check->change($user_id, $locData["location_id"], $bcin, 1, true);

				} catch (\Exception $e) {
					// エラー
					SessionHelper::flushError(MessageConst::MSG_SYS_BARCODE_013);
					$this->logger->fatal(__FILE__." ".MessageConst::MSG_SYS_BARCODE_013." user_id=".$user_id." location_no=".$locData["location_id"]." management_no=".$bcin." ".$e->getMessage());
				}

				if ($rtnCheck) {
					// 在庫チェック情報取得
					try {
						$checkData = $stock->select4Check($user_id, $locData["location_id"], $bcin);

						// 結果メッセージ生成
						$checkMsg = Utility::replaceStr(MessageConst::MSG_INF_BARCODE_014, $checkData["product_name"], $checkData["management_no"], $checkData["check_count"]);

						if ($checkData["quantity"] == $checkData["check_count"]) {
							$checkMsg = Utility::replaceStr(MessageConst::MSG_OK_BARCODE_015, $checkData["product_name"], $checkData["management_no"], $checkData["quantity"], $checkData["check_count"], $checkData["check_count"]);
						} else {
							if ($checkData["quantity"] < $checkData["check_count"]) {
								$checkMsg = Utility::replaceStr(MessageConst::MSG_SYS_BARCODE_016, $checkData["product_name"], $checkData["management_no"], $checkData["quantity"], $checkData["check_count"], $checkData["quantity"]);
							}
						}
						SessionHelper::flushSuccess($checkMsg);

						// 取消（元に戻す）用に変更前データをセッションに保存
						SessionHelper::setData($this->funcId, "undo_location_no", $locData["location_id"]);  // 店舗NO
						SessionHelper::setData($this->funcId, "undo_management_no", $bcin);  // 管理番号
						SessionHelper::setData($this->funcId, "undo_check_count", $undo_check_count);  // チェック数

					} catch (\Exception $e) { 
						// エラー
						SessionHelper::flushError(MessageConst::MSG_SYS_BARCODE_017);
						$this->logger->fatal(__FILE__." ".MessageConst::MSG_SYS_BARCODE_017." user_id=".$user_id." location_no=".$locData["location_id"]." management_no=".$bcin." ".$e->getMessage());

					}
				}
			}
		} else {
			// 店舗が選択されていない
			SessionHelper::flushError(MessageConst::MSG_INF_BARCODE_005);    // "店舗バーコードを入力してください。"
		}

		return CommandResult::done();
    }

    /**
     * 商品情報取得処理
     * 商品情報表示
     */
    private function productSelect(string $bcin): CommandResult
    {
		$productData = null;

		// ロジック
		$product = new Product();
		$stock = new Stock();

		// 商品情報取得
		try {
			$productData = $product->select($bcin);

			// 店舗在庫数取得
			$stock = new Stock();
			$locationStock = "";
			$locationStock = $stock->getStockPerLocation($productData['management_no']);
			$productData['SHOP_QUANTITY'] = $locationStock;

		} catch (RecordNotFoundException | MultipleRecordsFoundException $e) { 
			SessionHelper::flushError(MessageConst::MSG_VAL_BARCODE_002);
		}

		return $productData ? CommandResult::result($productData):  CommandResult::done();

    }
}
