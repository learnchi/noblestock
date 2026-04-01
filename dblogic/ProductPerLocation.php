<?php
namespace Noblestock\DbLogic;


use Studiogau\Chandra\Database\Database;
use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Support\Utility;
use Studiogau\Chandra\Auth\AuthService;
use Noblestock\Logic\LogicConst;

/**
 * ロジッククラス（店舗別商品一覧用）
 *
 * @author Studio GAU
 * @version 1.0
 */
class ProductPerLocation {

    private const TABLE_NAME = 'products';

	private const PRODUCT_LIST_SELECT_SQL = <<<SQL
SELECT
    ST.management_no       AS management_no,
    LO.id                  AS SHOP_NO,
    LO.location_name       AS SHOP_NAME,
    PR.category_id         AS category_id,
    CA.category_name       AS category_name,
    PR.maker_id            AS maker_id,
    MA.maker_name          AS maker_name,
    PR.product_name        AS product_name,
    PR.wholesale_amount           AS wholesale_amount,
    PR.retail_amount         AS retail_amount,
    PR.sell_amount          AS sell_amount,
    IFNULL(ST.quantity, 0) AS quantity,
    PR.unit_id            AS unit_id,
    UN.unit_name           AS unit_name,
    PR.storage_place       AS storage_place,
    PR.image_file          AS image_file,
    PR.remarks             AS remarks,
    PR.remarks2            AS remarks2
FROM
    stocks ST
    LEFT JOIN products PR ON ST.management_no = PR.management_no
    LEFT JOIN categories CA ON PR.category_id   = CA.id
    LEFT JOIN makers MA ON PR.maker_id      = MA.id
    LEFT JOIN units UN ON PR.unit_id      = UN.id
    LEFT JOIN locations LO ON ST.location_id   = LO.id
SQL;

	private Database $database;
    private Logger $logger;

	public function __construct(?Database $database = null, ?Logger $logger = null) {
        $this->logger = $logger ?? Logger::createDefault(dirname(__DIR__, 1));

        // Databaseクラスのインスタンスは設定ファイルを指定して生成
		$this->database = $database ?? Database::fromConfiguredSource(dirname(__DIR__, 1) . LogicConst::DB_CONFIG_PATH, $this->logger);

        // 監査項目用 userId を設定（取れなければ Database 側の SYSTEM のまま）
        
        $auth = new AuthService(new UserRepository(), $this->logger);
        $userId = $auth->getCurrentUser()?->getUserId();
        if (!empty($userId)) {
            $this->database->setCurrentUserId($userId);
        }

	}
	/**
	 * Where句を付与した状態で行数をカウントする。
	 * @return int
	 */
	public function count($param = []) {

		$rows = 0;

		// SQL生成
		$sql = self::PRODUCT_LIST_SELECT_SQL;
		// WHERE句
        $search = $this->makeWhere($param);
        // 値の取り出し
        $where = $search['where'];
        $bindings = $search['bindings'];

		if ($where != "") {
			$sql .= " WHERE".$where;
		}

		$sql .= " GROUP BY PR.management_no, ST.location_id";
		$sql = Utility::replaceStr(
			'SELECT COUNT(STA.management_no) AS PRCNT FROM({0}) AS STA',
			$sql,
		);

		$rows = $this->database->fetchCount($sql, $bindings);
		return $rows;
	}
	public function list($param = [], $sortno = 5, $limit = 0, $page = 1, $count = 0) {

		// SQL生成
		$sql = self::PRODUCT_LIST_SELECT_SQL;
		// WHERE句
        $search = $this->makeWhere($param);
        // 値の取り出し
        $where = $search['where'];
        $bindings = $search['bindings'];

		if ($where != "") {
			$sql .= " WHERE".$where;
		}

		$sql .= " GROUP BY PR.management_no, ST.location_id";
		$sql = Utility::replaceStr(
			'SELECT STA.* FROM ({0}) AS STA',
			$sql,
		);

		// ORDER BY句生成
		$sortStr = self::convertSortStrFromSortNo($sortno);
		$sql.=$sortStr;

		// LIMIT句生成
		if ($limit > 0) {
			$lst = $page * $limit - $limit;
			if ($count >= ($lst + 1)) {
				$sql .= " LIMIT ".$lst.", ".$limit;
			} else {
				$this->logger->error(
                    __METHOD__
                    . ' op=productPerLocation.list'
                    . ' msg="limit is illegal"'
                    . ' detail=lst=' . $lst
                );
                throw new \InvalidArgumentException('limit is illegal: '.$lst);
			}
		}

		return $this->database->fetchList($sql, $bindings);
	}

	/**
	 * =========================================
	 * PRIVATE METHODS
	 * =========================================
	 */
	/**
	 * where句の作成
	 */
	private function makeWhere($param = []) {

		$where = "";

		// 管理番号
		// if (!empty($param["management_no"])) {
		// 	if ($where != "") $where .= " AND";
		// 	$where .= " PR.management_no = '".$param["management_no"]."'";
		// }		if (!empty($param["management_no"])) {

		if (!empty($param["management_no"])) {
			if ($where != "") $where .= " AND";
			$where .= " PR.management_no = :management_no";
            $bindings[':management_no'] = [
                'value' => $param["management_no"],
                'datatype' => \PDO::PARAM_STR,
            ];
		}

		// 店舗
		// if (!empty($param["location_id"])) {
		// 	if ($where != "") $where .= " AND";
		// 	$where .= ' LO.id IN(';
		// 	$mstNo = $param["location_id"];
		// 	for ($i = 0; $i < count($mstNo); $i++) {
		// 		if ($i == 0) {
		// 			$where .= $mstNo[$i];
		// 		} else {
		// 			$where .= ','.$mstNo[$i];
		// 		}
		// 	}
		// 	$where .= ')';
		// }
		if (!empty($param["location_id"])) {

            $placeholders = [];
            foreach ($param["location_id"] as $i => $name) {
                $ph = ":location_id{$i}";
                $placeholders[] = $ph;
                $bindings[$ph] = [
                    'value' => $name,
                    'datatype' => \PDO::PARAM_INT,
                ];
            }
            // IN句を組み立て
			if ($where != "") $where .= " AND";
            $where .= ' LO.id IN (' . implode(',', $placeholders) . ')';        
		}

		// カテゴリ
		// if (!empty($param["category_id"])) {
		// 	if ($where != "") $where .= " AND";
		// 	$where .= ' CA.id IN(';
		// 	$mstNo = $param["category_id"];
		// 	for ($i = 0; $i < count($mstNo); $i++) {
		// 		if ($i == 0) {
		// 			$where .= $mstNo[$i];
		// 		} else {
		// 			$where .= ','.$mstNo[$i];
		// 		}
		// 	}
		// 	$where .= ')';
		// }
		if (!empty($param["category_id"])) {
            $placeholders = [];
            foreach ($param["category_id"] as $i => $name) {
                $ph = ":category_id{$i}";
                $placeholders[] = $ph;
                $bindings[$ph] = [
                    'value' => $name,
                    'datatype' => \PDO::PARAM_INT,
                ];
            }
            // IN句を組み立て
			if ($where != "") $where .= " AND";
            $where .= ' CA.id IN (' . implode(',', $placeholders) . ')';    
		}

		// メーカー
		// if (!empty($param["maker_id"])) {
		// 	if ($where != "") $where .= " AND";
		// 	$where .= ' MA.id IN(';
		// 	$mstNo = $param["maker_id"];
		// 	for ($i = 0; $i < count($mstNo); $i++) {
		// 		if ($i == 0) {
		// 			$where .= $mstNo[$i];
		// 		} else {
		// 			$where .= ','.$mstNo[$i];
		// 		}
		// 	}
		// 	$where .= ')';
		// }
		if (!empty($param["maker_id"])) {

            $placeholders = [];
            foreach ($param["maker_id"] as $i => $name) {
                $ph = ":maker_id{$i}";
                $placeholders[] = $ph;
                $bindings[$ph] = [
                    'value' => $name,
                    'datatype' => \PDO::PARAM_INT,
                ];
            }
            // IN句を組み立て
			if ($where != "") $where .= " AND";
            $where .= ' MA.id IN (' . implode(',', $placeholders) . ')';    
		}

		// 商品名
		// if (!empty($param["product_name"])) {
		// 	if ($where != "") $where .= " AND";
		// 	$where .= " PR.product_name LIKE '%".$param["product_name"]."%'";
		// }
		if (!empty($param["product_name"])) {
			if ($where != "") $where .= " AND";
			$where .= " PR.product_name LIKE :product_name";
            $bindings[':product_name'] = [
                'value' => '%'.$param["product_name"].'%',
                'datatype' => \PDO::PARAM_STR,
            ];
		}

		// 保管場所
		// if (!empty($param["storage_place"])) {
		// 	if ($where != "") $where .= " AND";
		// 	$where .= " PR.storage_place LIKE '%".$param["storage_place"]."%'";
		// }
		if (!empty($param["storage_place"])) {
			if ($where != "") $where .= " AND";
			$where .= " PR.storage_place LIKE :storage_place";
            $bindings[':storage_place'] = [
                'value' => '%'.$param["storage_place"].'%',
                'datatype' => \PDO::PARAM_STR,
            ];
		}

		// 備考
		// if (!empty($param["remarks"])) {
		// 	if ($where != "") $where .= " AND";
		// 	$where .= " (PR.remarks LIKE '%".$param["remarks"]."%' OR PR.remarks2 LIKE '%".$param["remarks"]."%')";
		// }

		if (!empty($param["remarks"])) {
			if ($where != "") $where .= " AND";
			$where .= "(PR.remarks LIKE :remarks OR PR.remarks2 LIKE :remarks)";
            $bindings[':remarks'] = [
                'value' => '%'.$param["remarks"].'%',
                'datatype' => \PDO::PARAM_STR,
            ];
		}

        return [
            'where' => $where,
            'bindings' => $bindings ?? [],
        ];
	}
	/**
	 * sortnoからORDER BY句を作成する。将来的には番号ではなくもっとうまい方法でORDER BY句を渡したい。それまでの対応。
	 */
	private function convertSortStrFromSortNo($sortno) {
		$sortStr = "";
        $sortno = $sortno ?? 0;

		if ($sortno === 3) {
			$sortStr = " ORDER BY STA.product_name, STA.management_no";
		} else if ($sortno === 4) {
			$sortStr = " ORDER BY STA.product_name DESC, STA.management_no";
		} else if ($sortno === 5) {
			$sortStr = " ORDER BY STA.management_no";
		} else if ($sortno === 6) {
			$sortStr = " ORDER BY STA.management_no DESC";
		} else if ($sortno === 7) {
			$sortStr = " ORDER BY STA.category_name, STA.management_no";
		} else if ($sortno === 8) {
			$sortStr = " ORDER BY STA.category_name DESC, STA.management_no";
		} else if ($sortno === 9) {
			$sortStr = " ORDER BY STA.maker_name, STA.management_no";
		} else if ($sortno === 10) {
			$sortStr = " ORDER BY STA.maker_name DESC, STA.management_no";
		} else if ($sortno === 11) {
			$sortStr = " ORDER BY STA.quantity, STA.management_no";
		} else if ($sortno === 12) {
			$sortStr = " ORDER BY STA.quantity DESC, STA.management_no";
		} else if ($sortno === 13) {
			$sortStr = " ORDER BY STA.storage_place, STA.management_no";
		} else if ($sortno === 14) {
			$sortStr = " ORDER BY STA.storage_place DESC, STA.management_no";
		} else if ($sortno === 15) {
			$sortStr = " ORDER BY STA.wholesale_amount, STA.management_no";
		} else if ($sortno === 16) {
			$sortStr = " ORDER BY STA.wholesale_amount DESC, STA.management_no";
		} else if ($sortno === 17) {
			$sortStr = " ORDER BY STA.retail_amount, STA.management_no";
		} else if ($sortno === 18) {
			$sortStr = " ORDER BY STA.retail_amount DESC, STA.management_no";
		} else if ($sortno === 19) {
			$sortStr = " ORDER BY STA.SHOP_NAME, STA.product_name, STA.management_no";
		} else if ($sortno === 20) {
			$sortStr = " ORDER BY STA.SHOP_NAME DESC, STA.product_name, STA.management_no";
		}

		return $sortStr;
	}

}
