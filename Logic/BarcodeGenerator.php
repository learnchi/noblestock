<?php
namespace Noblestock\Logic;

use Picqer\Barcode\BarcodeGenerator as PicqerBarcode;
use Picqer\Barcode\BarcodeGeneratorPNG;
use Studiogau\Chandra\Support\SessionHelper;
use Studiogau\Chandra\Support\Utility;

/**
 * Barcode generation helper backed by picqer/php-barcode-generator.
 *
 * Mirrors the legacy BarcodeLogic::createBarcode contract so existing
 * call sites can migrate without changing their error handling.
 *
 * 旧ロジックと同じ戻り値・種別判定を維持しつつ Picqer 製ライブラリへ置き換えるヘルパークラス。
 */
class BarcodeGenerator
{
    /**
     * Create a barcode PNG on disk.
     *
     * Return codes align with the historical implementation:
     *   0   : success
     *  -1   : barcode string missing
     *  -2   : destination path missing
     *  -3   : unsupported barcode format
     *  -4   : barcode rendering or file write failed
     *
     * @param string      $barcode  raw barcode value
     * @param string      $filePath destination file path
     * @param int|null    $barSize  optional size selector (1-5), null to auto detect
     * @return int
     */
    public function createBarcode($barcode, $filePath, $barSize = null)
    {
        // 入力されたバーコード文字列が空なら旧仕様どおりエラーコードを返す。
        if ($barcode === null || $barcode === '') {
            return -1;
        }

        // 出力先パスが無い場合も従来実装と同じエラーコードを返却。
        if ($filePath === null || $filePath === '') {
            return -2;
        }

        $resolvedBarSize = $this->resolveBarSize($barSize);
        $type = $this->resolveType($barcode, $resolvedBarSize);

        if ($type === null) {
            return -3;
        }

        $height = $this->resolveHeight($type, $resolvedBarSize);
        $widthFactor = $this->resolveWidthFactor($resolvedBarSize);

        $generator = new BarcodeGeneratorPNG();

        try {
            $binary = $generator->getBarcode($barcode, $type, $widthFactor, $height);
        } catch (\Throwable $exception) {
            return -4;
        }

        if ($binary === '') {
            return -4;
        }

        // 生成した PNG バイナリをファイルに書き出し、失敗時は同じコードを返す。
        if (@file_put_contents($filePath, $binary) === false) {
            return -4;
        }

        return 0;
    }


	/**
	 * バーコードをチェックします。
	 *
	 * @param string $code バーコード
	 * @param int $barsize バーコード設定サイズ
	 * @return int 12：UPC-A、13：JAN、39：Code39、128：Code128、99：不正なバーコード
	 */
	public static function check($code, $barsize = null) {
		$rtnchk = 99;

		if ($barsize === null) {
			$barsize = intval(SessionHelper::getPref("BAR_PRT_SIZE"));
		}

		if (Utility::checkNumeric($code) && mb_strlen($code) === 12) {
			//$rtnchk = 12;
			if (self::checkUPC($code) === 10) $rtnchk = 12;
		} else if (Utility::checkNumeric($code) && mb_strlen($code) === 13) {
			if (self::checkJAN($code) === 10) $rtnchk = 13;
		} else {
			if ($barsize === 2 || $barsize === 3 || $barsize === 4 || $barsize === 5) {
				if (preg_match( "/^[\x20-\x7E]+$/", $code)) $rtnchk = 128;
			} else {
				if (!preg_match( "/[^0-9A-Z\-*+\$%\/. ]/", $code)) $rtnchk = 39;
			}
		}
		return $rtnchk;
	}
	/**
	 * バーコードをチェックします。
	 *
	 * @param string $code バーコード
	 * @param int $barsize バーコード設定サイズ
	 * @return int 12：UPC-A、13：JAN、39：Code39、128：Code128、99：不正なバーコード
	 */
	public static function checkBarCode($code, $barsize = null) {
		$rtnchk = 99;

		if ($barsize === null) {
			$barsize = intval(SessionHelper::getPref("BAR_PRT_SIZE"));
		}

		if (Utility::checkNumeric($code) && mb_strlen($code) === 12) {
			//$rtnchk = 12;
			if (self::checkUPC($code) === 10) $rtnchk = 12;
		} else if (Utility::checkNumeric($code) && mb_strlen($code) === 13) {
			if (self::checkJAN($code) === 10) $rtnchk = 13;
		} else {
			if ($barsize === 2 || $barsize === 3 || $barsize === 4 || $barsize === 5) {
				if (preg_match( "/^[\x20-\x7E]+$/", $code)) $rtnchk = 128;
			} else {
				if (!preg_match( "/[^0-9A-Z\-*+\$%\/. ]/", $code)) $rtnchk = 39;
			}
		}
		return $rtnchk;
	}
	/**
	 * =========================================
	 * PRIVATE METHODS
	 * =========================================
	 */
    private function resolveBarSize($barSize)
    {
        if (is_int($barSize) && $barSize > 0) {
            return $barSize;
        }

        $sessionSize = (int) SessionHelper::getPref('BAR_PRT_SIZE');
        if ($sessionSize > 0) {
            return $sessionSize;
        }

        return 2;
    }

    private function resolveType($barcode, $barSize)
    {
        $result = self::checkBarCode($barcode, $barSize);

        switch ($result) {
            case 12:
                return PicqerBarcode::TYPE_UPC_A;
            case 13:
                return PicqerBarcode::TYPE_EAN_13;
            case 39:
                return PicqerBarcode::TYPE_CODE_39;
            case 128:
                return PicqerBarcode::TYPE_CODE_128;
            default:
                return null;
        }
    }

    private function resolveHeight($type, $barSize)
    {
        $height = 55;

        if (
            ($type === PicqerBarcode::TYPE_UPC_A
                || $type === PicqerBarcode::TYPE_EAN_13
                || $type === PicqerBarcode::TYPE_CODE_128)
            && ($barSize === 4 || $barSize === 5)
        ) {
            $height = 45;
        }

        return $height;
    }

    private function resolveWidthFactor($barSize)
    {
        // 幅の倍率は従来の barsize をそのまま活用し、範囲外の場合のみ丸める。
        if (!is_int($barSize)) {
            $barSize = (int) $barSize;
        }

        if ($barSize <= 0) {
            return 2;
        }

        if ($barSize > 5) {
            return 5;
        }

        return $barSize;
    }

	/**
	 * UPC-Aコードをチェックします。
	 *
	 * @param string $code バーコード
	 * @return 10：正しいJAN、99：数字13桁以外 0-9：正しいチェックデジット
	 */
	private static function checkUPC($code) {
		$rtnchk = 99;
		if (Utility::checkNumeric($code) && mb_strlen($code) === 12) {
			$chkno = substr($code, 0, 11);
			$chkdg = intval(substr($code, -1));

			// チェックデジット計算
			$arr = str_split($chkno);
			$odd = 0;
			$mod = 0;
			for ($i=0; $i<count($arr); $i++) {
				if(($i+1) % 2 == 0) {
					$mod += intval($arr[$i]);
				} else {
					$odd += intval($arr[$i]);
				}
			}
			//右から奇数位置の総和を3倍＋偶数位置総和を加算して下1桁の数字を10から引く
			//$cd = 10 - intval(substr((string)($mod * 3) + $odd,-1));
			$cd = 10 - intval(substr((string)($odd * 3) + $mod,-1));
			//10なら1の位は0なので、0を返す
			$rtnchk = $cd === 10 ? 0 : $cd;

			if ($chkdg === $rtnchk) $rtnchk = 10;
		}
		return $rtnchk;
	}

	/**
	 * JANコードをチェックします。
	 *
	 * @param string $code バーコード
	 * @return 10：正しいJAN、99：数字13桁以外 0-9：正しいチェックデジット
	 */
	public static function checkJAN($code) {
		$rtnchk = 99;
		if (Utility::checkNumeric($code) && mb_strlen($code) === 13) {
			$chkno = substr($code, 0, 12);
			$chkdg = intval(substr($code, -1));

			// チェックデジット計算
			$arr = str_split($chkno);
			$odd = 0;
			$mod = 0;
			for ($i=0; $i<count($arr); $i++) {
				if(($i+1) % 2 == 0) {
					$mod += intval($arr[$i]);
				} else {
					$odd += intval($arr[$i]);
				}
			}
			//右から奇数位置の総和を3倍＋偶数位置総和を加算して下1桁の数字を10から引く
			$cd = 10 - intval(substr((string)($mod * 3) + $odd,-1));
			//10なら1の位は0なので、0を返す。
			$rtnchk = $cd === 10 ? 0 : $cd;

			if ($chkdg === $rtnchk) $rtnchk = 10;
		}
		return $rtnchk;
	}
}
