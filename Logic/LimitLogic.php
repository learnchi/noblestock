<?php
namespace Noblestock\Logic;

use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Support\SessionHelper;

/**
 * 在庫数低下通知クラス
 *
 * @author Studio GAU
 * @version 1.0
 */
class LimitLogic {
	/**
	 * 在庫数が下限値を下回った場合にメール送信バッチを起動します
	 *
	 * @param array $prData management_no：管理番号 quantity：出庫後在庫数 STOCK_COUNT：出庫数
	 * @return boolean 結果
	 */
	public function noticeStock($prData) {
		// ロガー
		$logger = Logger::createDefault(dirname(__DIR__, 1));
		$sendFlg = False;  //メール送信フラグ
		
		$lowLimit = intval(SessionHelper::getPref("STOCK_LOW_LIMIT"));  //下限値
		$lowInterval = intval(SessionHelper::getPref("STOCK_LOW_INTERVAL"));  //間隔
		

		// 通知無効
		if ($lowLimit <= 0) return false;

		$stockNow = (int)$prData["quantity"];  //出庫後在庫数
		$stockPre = $stockNow + (int)$prData["STOCK_COUNT"];  //出庫前在庫数


		// そもそも危険ライン未満でなければ通知しない
		if ($stockNow >= $lowLimit) return false;

		// 在庫ゼロは無条件で通知
		if ($stockNow === 0) {
			$sendFlg = true;
		} else {
			// 低在庫通知の種類分岐
			if ($lowInterval === 0) {
				// 1段階通知モード
				if ($stockPre >= $lowLimit) {
					$sendFlg = true;
				}
			} else {
				// 段階通知モード
				for ($lim = $lowLimit; $lim > 0; $lim -= $lowInterval) {
					if ($stockNow < $lim && $stockPre >= $lim) {
						$sendFlg = true;
						break;
					}
				}
			}
		}
		if ($sendFlg) {
			// メール送信処理をクラスとして呼び出す
			$this->runBatch((string)($prData['management_no'] ?? ''));
		}

		return $sendFlg;
	}

	/**
	 * 通知バッチ実行.
	 */
	protected function runBatch(string $managementNo): void
	{
		BatchLogic::run($managementNo);
	}
}
