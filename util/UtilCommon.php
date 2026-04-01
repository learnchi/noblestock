<?php
namespace Noblestock\Util;

/**
 * ユーティリティクラス.
 * @author Studio GAU
 *
 */
class UtilCommon {

	/**
	 * $rows から、$field === $value の最初の行を返す。なければ null。
	 *
	 * @param array<int|string, array<string,mixed>> $rows
	 * @param string $field
	 * @param mixed $value
	 * @return array<string,mixed>|null
	 */
	public static function getTargetArray(?array $rows, string $field, mixed $value): ?array
	{

		if (empty($rows)) return null;

		foreach ($rows as $row) {
			if (!is_array($row) || !array_key_exists($field, $row)) {
				continue;
			}

			// 値が0–9だけで構成されている場合、整数に変換する
			$lhs = ctype_digit((string)$row[$field]) ? (int)$row[$field] : $row[$field];
			$rhs = ctype_digit((string)$value)       ? (int)$value       : $value;

			if ($lhs === $rhs) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * マスタデータのデータ補完処理.
	 * キー項目に値が設定されている場合、名称をDBの値で置き換える。
	 * キー項目が設定されていない場合、名称に該当するキー値をキー項目に設定する。
	 * 該当するデータがない場合、デフォルト値を設定する。
	 *
	 * @param array $masterData マスタデータリスト
	 * @param string $keyColName キー項目名
	 * @param string $valColName 名称項目名
	 * @param string $keyColValue キー項目設定値
	 * @param string $valColValue 名称項目設定値
	 * @return array 該当データ配列
	 */
	public static function masterConsistencyCheck($masterData, $keyColName, $valColName, $keyColValue, $valColValue) {
		$key = null;
		$value = null;
		// マスタ整合性チェック
		if ($keyColValue != null && !empty($keyColValue)) {
			// キー項目に値が設定されている場合、マスタの該当行を取得
			$currentArray = self::getTargetArray($masterData, $keyColName, $keyColValue);
			$key = $currentArray[$keyColName] ?? null;
			$value = $currentArray[$valColName] ?? null;

		} else if ($valColValue != null && !empty($valColValue)) {
			// キー項目に値が設定されていない場合、かつ、項目名に値が設定されている場合、マスタの該当行を取得
			$currentArray = self::getTargetArray($masterData, $valColName, $valColValue);
			$key = $currentArray[$keyColName] ?? null;
			$value = $currentArray[$valColName] ?? null;
		}
		return array($keyColName => $key, $valColName => $value);
	}

	/**
	 * 0〜4294967295 の範囲に入る整数文字列かを判定する
	 *
	 * @param mixed $value
	 * @return bool
	 */
	public static function isUnsignedInt32String(mixed $value): bool
	{
		$value = (string)$value;
		if ($value === '' || !ctype_digit($value)) {
			return false;
		}

		return strlen($value) < 10 || (strlen($value) === 10 && strcmp($value, '4294967295') <= 0);
	}

	/**
	 * 新規設定用パスワードの妥当性を確認する。
	 * 8～128文字を許容し、制御文字は受け付けない。
	 */
	public static function isValidPassword(mixed $value, int $min = 8, int $max = 128): bool
	{
		if (!is_string($value)) {
			return false;
		}

		$length = mb_strlen($value, 'UTF-8');
		if ($length < $min || $length > $max) {
			return false;
		}

		return preg_match('/[\x00-\x1F\x7F]/u', $value) !== 1;
	}

	/**
	 * 既存パスワード入力欄の受け取り値として扱えるかを確認する。
	 * 既存の短いパスワードも変更できるよう、最小文字数は設けない。
	 */
	public static function isAcceptablePasswordInput(mixed $value, int $max = 128): bool
	{
		if (!is_string($value) || $value === '') {
			return false;
		}

		$length = mb_strlen($value, 'UTF-8');
		if ($length > $max) {
			return false;
		}

		return preg_match('/[\x00-\x1F\x7F]/u', $value) !== 1;
	}

}
?>
