<?php
namespace Noblestock\DbLogic;


use Studiogau\Chandra\Database\Database;
use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Support\Utility;
use Studiogau\Chandra\Database\RecordNotFoundException;
use Studiogau\Chandra\Auth\AuthService;
use Noblestock\Logic\BarcodeGenerator;
use Noblestock\Logic\LogicConst;

/**
 * ロジッククラス（履歴テーブル用）
 *
 * @author Studio GAU
 * @version 1.0
 */
class History {

    private const TABLE_NAME = 'histories';
    private const HISTORY_SELECT_SQL = <<<SQL
SELECT
    HIS.id      AS id,
    HIS.history_yy      AS history_yy,
    HIS.history_mm      AS history_mm,
    HIS.history_dd      AS history_dd,
    HIS.history_kbn      AS history_kbn,
    HIS.management_no   AS management_no,
    HIS.branch_no       AS branch_no,
    HIS.category_name   AS category_name,
    HIS.maker_name      AS maker_name,
    HIS.product_name    AS product_name,
    HIS.location_name   AS location_name,
    HIS.quantity        AS quantity,
    HIS.stock_in        AS stock_in,
    HIS.move_stock      AS move_stock,
    HIS.location_stock  AS location_stock,
    HIS.stock           AS stock,
    HIS.del_flg    AS del_flg,
    HIS.created_at     AS created_at,
    HIS.created_by       AS created_by,
    USC.user_name       AS CREATE_USER_NAME,
    HIS.updated_at     AS updated_at,
    HIS.updated_by       AS updated_by,
    US.user_name        AS user_name
FROM
    histories HIS
    LEFT JOIN users US
        ON HIS.updated_by = US.login_id
    LEFT JOIN users USC
        ON HIS.created_by = USC.login_id
WHERE
    HIS.id = :id
SQL;
    private const HISTORY_LIST_SQL =  <<<SQL2
SELECT
    HIS.id        AS id,
    HIS.history_yy        AS history_yy,
    HIS.history_mm        AS history_mm,
    HIS.history_dd        AS history_dd,
    HIS.history_kbn        AS history_kbn,
    HIS.management_no     AS management_no,
    HIS.branch_no         AS branch_no,
    HIS.location_name     AS location_name,
    HIS.category_name     AS category_name,
    HIS.maker_name        AS maker_name,
    HIS.product_name      AS product_name,
    HIS.quantity          AS quantity,
    HIS.stock_in          AS stock_in,
    HIS.move_stock        AS move_stock,
    HIS.location_stock    AS location_stock,
    HIS.stock             AS stock,
    HIS.del_flg      AS del_flg,
    HIS.created_at       AS created_at,
    HIS.created_by         AS created_by,
    USC.user_name         AS CREATE_USER_NAME,
    HIS.updated_at       AS updated_at,
    HIS.updated_by         AS updated_by,
    US.user_name          AS user_name
FROM
    histories HIS
    LEFT JOIN users US
        ON HIS.updated_by = US.login_id
    LEFT JOIN users USC
        ON HIS.created_by = USC.login_id
SQL2;
    private const HISTORY_LIST4ZAIKO_SQL =  <<<SQL3
WITH filtered_his AS (
    SELECT *
    FROM histories
{0}
)
SELECT
    HIS.management_no,
    HIS.branch_no,
    GB_HIS.category_name,
    GB_HIS.maker_name,
    GB_HIS.product_name,
    SUM(HIS.quantity) AS ZAIKO_OUT,
    SUM(HIS.stock_in) AS ZAIKO_IN,
    SUM(HIS.move_stock) AS ZAIKO_MOVE,
    GB_HIS.stock,
    GB_HIS.created_at,
    GB_HIS.updated_at
FROM
    filtered_his HIS
    INNER JOIN (
        SELECT
            HI.management_no,
            HI.branch_no,
            HI.category_name,
            HI.maker_name,
            HI.product_name,
            HI.stock,
            HI.created_at,
            HI.updated_at
        FROM
            filtered_his HI
            INNER JOIN (
                SELECT
                    HI_NEWEST.management_no,
                    HI_NEWEST.branch_no,
                    MAX(HI_NEWEST.id) AS id
                FROM
                    filtered_his HI_NEWEST
                GROUP BY
                    HI_NEWEST.management_no,
                    HI_NEWEST.branch_no
            ) AS NEWEST
                ON NEWEST.management_no = HI.management_no
                AND NEWEST.branch_no = HI.branch_no
                AND NEWEST.id = HI.id
        GROUP BY
            HI.management_no,
            HI.branch_no
    ) AS GB_HIS
        ON GB_HIS.management_no = HIS.management_no
        AND GB_HIS.branch_no = HIS.branch_no
GROUP BY
    HIS.management_no,
    HIS.branch_no
SQL3;
    private const HISTORY_COMPARESTOCK_SQL =  <<<SQL4
SELECT
    HIS.stock AS HIS_STOCK,
    SUM(ST.quantity) AS PR_STOCK
FROM
    histories HIS
    INNER JOIN stocks ST
        ON ST.management_no = HIS.management_no
WHERE
    HIS.history_kbn != 9
    AND HIS.management_no = :management_no
    AND HIS.branch_no = :branch_no
    AND HIS.id = (
        SELECT
            MAX(HI.id) AS id
        FROM
            histories HI
        WHERE
            HI.history_kbn != 9
            AND HI.management_no = HIS.management_no
            AND HI.branch_no = HIS.branch_no
    )
SQL4;
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
	public function select($id) {
        // 入力チェック
        if (empty($id)) {
            $this->logger->error(
                __METHOD__
                . ' op=history.select msg="Required parameter missing: id" table=' . self::TABLE_NAME
                . ' id=' . ($id ?? '(missing)')
            );
            throw new \InvalidArgumentException('Required parameter missing: id');
        }

		$data = null;
        $bindings = [
            ':id' => ['value' => $id, 'datatype' => \PDO::PARAM_STR],
        ];
		// SQL生成
		$sql = self::HISTORY_SELECT_SQL;
		$data = $this->database->fetchOne($sql, $bindings);

        // 集計関数が入っているので0件でもレコードがかえってきてしまう。
        // キー項目がnullなら期待通りではないと判断。
        if (empty($data["id"])) {
            throw new RecordNotFoundException('fetchOne: found no records at table ' . self::TABLE_NAME . ' by selecting id=' . $id);
        }
		
		return $data;
	}

    public function insert($data) {

        // 枝番
		$branceNo = 1;
        
        if (empty($branceNo)) {    // 履歴クリア時の対処
            $branceNo = 1;
        } else if ($data["branch_no"] === 0) {    // BRANCH_NOが0の場合はmax値取得
            $branchNoBindings = [
                ':management_no' => ['value' => $data["management_no"], 'datatype' => \PDO::PARAM_STR],
            ];
            $branchNoSql = 'SELECT MAX(branch_no) AS MAX_BRANCH_NO FROM '.self::TABLE_NAME.' WHERE management_no = :management_no';
            $branchNoData = $this->database->fetchOne($branchNoSql, $branchNoBindings);
            if (empty($branchNoData["MAX_BRANCH_NO"])) {
                // throw new RuntimeException('fetchOne: query returned no rows.');
                // $branceNo = 1;
            } else {
                $branceNo = $branchNoData["MAX_BRANCH_NO"];
            }
            if ($data["history_kbn"] == 2) {
                // 商品登録の場合は枝番加算
                $branceNo++;
            }
        }

        $values = [
            'history_yy'		 => ['value' => date('Y'), 'datatype' => \PDO::PARAM_INT],
            'history_mm'		 => ['value' => date('n'), 'datatype' => \PDO::PARAM_INT],
            'history_dd'		 => ['value' => date('j'), 'datatype' => \PDO::PARAM_INT],
            'history_kbn'		 => ['value' => $data["history_kbn"], 'datatype' => \PDO::PARAM_INT],
            'management_no'		 => ['value' => $data["management_no"], 'datatype' => \PDO::PARAM_STR],
            'branch_no'			 => ['value' => $branceNo, 'datatype' => \PDO::PARAM_INT],    // MANAGEMENT_NOごとにインクリメント
            'category_name'		 => ['value' => $data["category_name"], 'datatype' => \PDO::PARAM_STR],
            'maker_name'		 => ['value' => $data["maker_name"], 'datatype' => \PDO::PARAM_STR],
            'product_name'		 => ['value' => $data["product_name"], 'datatype' => \PDO::PARAM_STR],
            'location_name'		 => ['value' => $data["location_name"], 'datatype' => \PDO::PARAM_STR],
            'quantity'			 => ['value' => $data["quantity"], 'datatype' => \PDO::PARAM_INT],
            'stock_in'			 => ['value' => $data["stock_in"], 'datatype' => \PDO::PARAM_INT],
            'move_stock'		 => ['value' => $data["move_stock"], 'datatype' => \PDO::PARAM_INT],
            'location_stock'	 => ['value' => $data["location_stock"], 'datatype' => \PDO::PARAM_INT],
            'stock'				 => ['value' => $data["stock"], 'datatype' => \PDO::PARAM_INT],
        ];

        $affected = $this->database->insert(self::TABLE_NAME, $values);
        // 1行だけのはず
        if ($affected !== 1) {
            throw new \RuntimeException('Insert affected rows mismatch (expected=1, actual=' . $affected . ')');
        }

        return $affected ;

    }
    /**
     * 更新
     * $data 更新内容
     * $management_no WHERE条件
     * 複数行の更新可能
     */
    public function updateByManagementNo($data, $management_no) {

		// 入力チェック
        if (empty($management_no) || BarcodeGenerator::check((string)$management_no) === 99) {
            $this->logger->error(
                __METHOD__
                . ' op=history.updateByManagementNo msg="Invalid parameter: management_no" table=' . self::TABLE_NAME
                . ' management_no=' . ($management_no === null ? '(missing)' : (string)$management_no)
            );
            throw new \InvalidArgumentException('Invalid parameter: management_no');
        }

        $conditions = [
            'management_no' => ['value' => $management_no, 'datatype' => \PDO::PARAM_STR],
        ];

        $values = [];
        // if (array_key_exists('id', $data)) { $values['id'] = ['value' => $data['id'], 'datatype' => \PDO::PARAM_INT]; }
        // if (array_key_exists('history_yy', $data)) { $values['history_yy'] = ['value' => $data['history_yy'], 'datatype' => \PDO::PARAM_INT]; }
        // if (array_key_exists('history_mm', $data)) { $values['history_mm'] = ['value' => $data['history_mm'], 'datatype' => \PDO::PARAM_INT]; }
        // if (array_key_exists('history_dd', $data)) { $values['history_dd'] = ['value' => $data['history_dd'], 'datatype' => \PDO::PARAM_INT]; }
        if (array_key_exists('history_kbn', $data)) { $values['history_kbn'] = ['value' => $data['history_kbn'], 'datatype' => \PDO::PARAM_INT]; }
        // if (array_key_exists('management_no', $data)) { $values['management_no'] = ['value' => $data['management_no'], 'datatype' => \PDO::PARAM_STR]; }
        if (array_key_exists('branch_no', $data)) { $values['branch_no'] = ['value' => $data['branch_no'], 'datatype' => \PDO::PARAM_INT]; }
        if (array_key_exists('category_name', $data)) { $values['category_name'] = ['value' => $data['category_name'], 'datatype' => \PDO::PARAM_STR]; }
        if (array_key_exists('maker_name', $data)) { $values['maker_name'] = ['value' => $data['maker_name'], 'datatype' => \PDO::PARAM_STR]; }
        if (array_key_exists('product_name', $data)) { $values['product_name'] = ['value' => $data['product_name'], 'datatype' => \PDO::PARAM_STR]; }
        if (array_key_exists('location_name', $data)) { $values['location_name'] = ['value' => $data['location_name'], 'datatype' => \PDO::PARAM_STR]; }
        if (array_key_exists('quantity', $data)) { $values['quantity'] = ['value' => $data['quantity'], 'datatype' => \PDO::PARAM_INT]; }
        if (array_key_exists('stock_in', $data)) { $values['stock_in'] = ['value' => $data['stock_in'], 'datatype' => \PDO::PARAM_INT]; }
        if (array_key_exists('move_stock', $data)) { $values['move_stock'] = ['value' => $data['move_stock'], 'datatype' => \PDO::PARAM_INT]; }
        if (array_key_exists('location_stock', $data)) { $values['location_stock'] = ['value' => $data['location_stock'], 'datatype' => \PDO::PARAM_INT]; }
        if (array_key_exists('stock', $data)) { $values['stock'] = ['value' => $data['stock'], 'datatype' => \PDO::PARAM_INT]; }
        if (array_key_exists('del_flg', $data)) { $values['del_flg'] = ['value' => $data['del_flg'], 'datatype' => \PDO::PARAM_INT]; }
        // updated_at / updated_by は Database::update() 側で自動付与される

        if (empty($values)) {
            $this->logger->error(
                __METHOD__
                . ' op=history.updateByManagementNo msg="No update columns specified" table=' . self::TABLE_NAME
                . ' management_no=' . (string)$management_no
            );
            throw new \InvalidArgumentException('No update columns specified');
        }

        return $this->database->update(self::TABLE_NAME, $values, $conditions);

    }
    /**
     * 更新
     * $data 更新内容
     * $id WHERE条件
     * 複数行の更新不可
     */
    public function updateByHistoryNo($data, $id) {

    
		// 入力チェック
        if (empty($id)) {
            $this->logger->error(
                __METHOD__
                . ' op=history.updateByHistoryNo msg="Required parameter missing: id" table=' . self::TABLE_NAME
                . ' id=' . ($id ?? '(missing)')
            );
            throw new \InvalidArgumentException('Required parameter missing: id');
        }

        $conditions = [
            'id' => ['value' => $id, 'datatype' => \PDO::PARAM_INT],
        ];

        $values = [];
        // if (array_key_exists('id', $data)) { $values['id'] = ['value' => $data['id'], 'datatype' => \PDO::PARAM_INT]; }
        // if (array_key_exists('history_yy', $data)) { $values['history_yy'] = ['value' => $data['history_yy'], 'datatype' => \PDO::PARAM_INT]; }
        // if (array_key_exists('history_mm', $data)) { $values['history_mm'] = ['value' => $data['history_mm'], 'datatype' => \PDO::PARAM_INT]; }
        // if (array_key_exists('history_dd', $data)) { $values['history_dd'] = ['value' => $data['history_dd'], 'datatype' => \PDO::PARAM_INT]; }
        if (array_key_exists('history_kbn', $data)) { $values['history_kbn'] = ['value' => $data['history_kbn'], 'datatype' => \PDO::PARAM_INT]; }
        // if (array_key_exists('management_no', $data)) { $values['management_no'] = ['value' => $data['management_no'], 'datatype' => \PDO::PARAM_STR]; }
        if (array_key_exists('branch_no', $data)) { $values['branch_no'] = ['value' => $data['branch_no'], 'datatype' => \PDO::PARAM_INT]; }
        if (array_key_exists('category_name', $data)) { $values['category_name'] = ['value' => $data['category_name'], 'datatype' => \PDO::PARAM_STR]; }
        if (array_key_exists('maker_name', $data)) { $values['maker_name'] = ['value' => $data['maker_name'], 'datatype' => \PDO::PARAM_STR]; }
        if (array_key_exists('product_name', $data)) { $values['product_name'] = ['value' => $data['product_name'], 'datatype' => \PDO::PARAM_STR]; }
        if (array_key_exists('location_name', $data)) { $values['location_name'] = ['value' => $data['location_name'], 'datatype' => \PDO::PARAM_STR]; }
        if (array_key_exists('quantity', $data)) { $values['quantity'] = ['value' => $data['quantity'], 'datatype' => \PDO::PARAM_INT]; }
        if (array_key_exists('stock_in', $data)) { $values['stock_in'] = ['value' => $data['stock_in'], 'datatype' => \PDO::PARAM_INT]; }
        if (array_key_exists('move_stock', $data)) { $values['move_stock'] = ['value' => $data['move_stock'], 'datatype' => \PDO::PARAM_INT]; }
        if (array_key_exists('location_stock', $data)) { $values['location_stock'] = ['value' => $data['location_stock'], 'datatype' => \PDO::PARAM_INT]; }
        if (array_key_exists('stock', $data)) { $values['stock'] = ['value' => $data['stock'], 'datatype' => \PDO::PARAM_INT]; }
        if (array_key_exists('del_flg', $data)) { $values['del_flg'] = ['value' => $data['del_flg'], 'datatype' => \PDO::PARAM_INT]; }
        // updated_at / updated_by は Database::update() 側で自動付与される


        if (empty($values)) {
            $this->logger->error(
                __METHOD__
                . ' op=history.updateByHistoryNo msg="No update columns specified" table=' . self::TABLE_NAME
                . ' id=' . (string)$id
            );
            throw new \InvalidArgumentException('No update columns specified');
        }

        $pdo = $this->database->getConnection();
        $pdo->begin();
        try{
            $affected  = $this->database->update(self::TABLE_NAME, $values, $conditions);
            // 1行だけのはず
            if ($affected !== 1) {
                throw new \RuntimeException('Update affected rows mismatch (expected=1, actual=' . $affected . ')');
            }
            $pdo->commit();
            
            return $affected ;
        } catch (\Throwable $e) {
			$pdo->rollback();

            $this->logger->error(
                __METHOD__
                . ' op=history.updateByHistoryNo msg="transaction failed and rolled back" table=' . self::TABLE_NAME
                . ' id=' . (string)$id
                . ' ex=' . get_class($e)
                . ' detail=' . $e->getMessage()
            );
            throw $e;
        }

    }

	/**
	 * Where句を付与した状態で行数をカウントする。
     * 実績一覧で使用
	 * @return int
	 */
	public function count4Zaiko($param = []) {
		// WHERE句
        $search = $this->makeWhere($param);
        // 値の取り出し
        $where = $search['where'];
        $bindings = $search['bindings'];


		// SQL生成
		$sql = Utility::replaceStr(self::HISTORY_LIST4ZAIKO_SQL, $where);

        // 在庫
        if (!empty($param["SEARCH_KBN"])) {
            $having = $this->makeHaving($param["SEARCH_KBN"]);
            $sql .= $having;
        }
        $countSql = Utility::replaceStr('SELECT COUNT(*) AS MNG_CNT FROM ({0}) AS HIS_CNT', $sql);
        return $this->database->fetchCount($countSql, $bindings);
	}
    /**
     * 
     * 実績一覧で使用
     */
	public function list4Zaiko($param, $sortno, $limit = 0, $page = 1, $count = 0) {

		// WHERE句
        $search = $this->makeWhere($param);
        // 値の取り出し
        $where = $search['where'];
        $bindings = $search['bindings'];

		// ORDER BY句
		$sortStr = self::convertSortStr4Zaiko($sortno);

		// SQL生成
        $sql = Utility::replaceStr(self::HISTORY_LIST4ZAIKO_SQL, $where);

        // 在庫
        if (!empty($param["SEARCH_KBN"])) {
            $having = $this->makeHaving($param["SEARCH_KBN"]);
            $sql .= $having;
        }

        $sql = $sql.$sortStr;

		// LIMIT句生成
		if ($limit > 0) {
			$lst = $page * $limit - $limit;
			if ($count >= ($lst + 1)) {
				$sql .= " LIMIT ".$lst.", ".$limit;
			} else {
                $this->logger->error(
                    __METHOD__
                    . ' op=history.list4Zaiko msg="Invalid pagination: limit/page/count" table=' . self::TABLE_NAME
                    . ' limit=' . (int)$limit
                    . ' page=' . (int)$page
                    . ' count=' . (int)$count
                    . ' offset=' . (int)$lst
                );
                throw new \InvalidArgumentException('Invalid pagination');
			}
		}
		return $this->database->fetchList($sql,$bindings);
		
	}

	/**
	 * Where句を付与した状態で行数をカウントする。
     * 履歴一覧で使用
	 * @return int
	 */
	public function count($param = []) {

		// WHERE句
        $search = $this->makeWhere($param);
        // 値の取り出し
        $where = $search['where'];
        $bindings = $search['bindings'];

		// SQL生成
		$sql = Utility::replaceStr('SELECT COUNT(id) AS HNO_CNT FROM ({0}) AS CIS',self::HISTORY_LIST_SQL.$where);
        return $this->database->fetchCount($sql, $bindings);
	}
    /**
     * 
     * 履歴一覧で使用
     */
	public function list($param, $sortno, $limit = 0, $page = 1, $count = 0) {

		// WHERE句
        $search = $this->makeWhere($param);
        // 値の取り出し
        $where = $search['where'];
        $bindings = $search['bindings'];

		// ORDER BY句
		$sortStr = self::convertSortStr($sortno);

		// SQL生成
        $sql = self::HISTORY_LIST_SQL.$where;
        $sql = $sql.$sortStr;

		// LIMIT句生成
		if ($limit > 0) {
			$lst = $page * $limit - $limit;
			if ($count >= ($lst + 1)) {
				$sql .= " LIMIT ".$lst.", ".$limit;
			} else {
                $this->logger->error(
                    __METHOD__
                    . ' op=history.list msg="Invalid pagination: limit/page/count" table=' . self::TABLE_NAME
                    . ' limit=' . (int)$limit
                    . ' page=' . (int)$page
                    . ' count=' . (int)$count
                    . ' offset=' . (int)$lst
                );
                throw new \InvalidArgumentException('Invalid pagination');
			}
		}

		return $this->database->fetchList($sql,$bindings);
		
	}
    public function compareStock($management_no, $branch_no) {
        // 入力チェック
        if (empty($management_no)) {
            $this->logger->error(
                __METHOD__
                . ' op=history.compareStock msg="Required parameter missing: management_no" table=' . self::TABLE_NAME
                . ' management_no=' . ($management_no ?? '(missing)')
            );
            throw new \InvalidArgumentException('Required parameter missing: management_no');
        }
        if (empty($branch_no)) {
            $this->logger->error(
                __METHOD__
                . ' op=history.compareStock msg="Required parameter missing: branch_no" table=' . self::TABLE_NAME
                . ' branch_no=' . ($branch_no ?? '(missing)')
            );
            throw new \InvalidArgumentException('Required parameter missing: branch_no');
        }

        $bindings = [
            ':management_no' => ['value' => $management_no, 'datatype' => \PDO::PARAM_STR],
            ':branch_no' => ['value' => $branch_no, 'datatype' => \PDO::PARAM_STR],
        ];
        // SQL生成
        $sql = self::HISTORY_COMPARESTOCK_SQL;
        return $this->database->fetchOne($sql, $bindings);
    }

	/**
	 * 履歴区分名取得処理.
	 * @param int $value 履歴区分
	 * @return string 履歴区分名
	 */
	public static function getKbnName($value) {
		$historyName = null;
		if (!isset($value) || !is_numeric($value)) {
			return $historyName;
		}
		switch ($value) {
			case 0:
				$historyName = "入庫";
				break;
			case 1:
				$historyName = "出庫";
				break;
			case 2:
				$historyName = "商品登録";
				break;
			case 3:
				$historyName = "商品更新";
				break;
			case 4:
				$historyName = "商品削除";
				break;
			case 5:
				$historyName = "移動From";
				break;
			case 6:
				$historyName = "移動To";
				break;
			case 7:
				$historyName = "在庫変更";
				break;
			case 9:
				$historyName = "履歴削除";
				break;
		}
		return $historyName;
	}
	/**
	 * =========================================
	 * PRIVATE METHODS
	 * =========================================
	 */
	/**
     * 検索条件（WHERE句とバインド用配列）を生成する関数
     *
     * @param string $management_no 検索対象の管理番号（部分一致検索）
     * @return array{
     *     where: string,
     *     bindings: array<string, array{value: mixed, datatype: int}>
     * }
	 */
	private function makeWhere($param = []) {

		$where = "";

		// history_kbn
		// 0：入庫
		// 1：出庫
		// 2：商品登録
		// 3：商品更新
		// 4：商品削除
		// 5：店舗移動From
		// 6：店舗移動To
		// 7：在庫数変更
		// 9：履歴削除

    
		if (!empty($param["history_kbn"])) {    // 指定されている場合
        } else {    // 指定されていない場合は削除以外
            $param["history_kbn"] = [0,1,2,3,4,5,6,7];
        }

        $placeholders = [];
        foreach ($param["history_kbn"] as $i => $name) {
            $ph = ":histry_kbn{$i}";
            $placeholders[] = $ph;
            $bindings[$ph] = [
                'value' => $name,
                'datatype' => \PDO::PARAM_INT,
            ];
        }
        // IN句を組み立て
		$where = " WHERE history_kbn IN(" . implode(',', $placeholders) . ")";

        // ユーザー名(CREATE_USER_NAME)は履歴一覧で使用
		if (!empty($param["user_name"])) {
            $placeholders = [];
            foreach ($param["user_name"] as $i => $name) {
                $ph = ":user_name{$i}";
                $placeholders[] = $ph;
                $bindings[$ph] = [
                    'value' => $name,
                    'datatype' => \PDO::PARAM_STR,
                ];
            }
            // IN句を組み立て
            $where .= ' AND USC.user_name IN (' . implode(',', $placeholders) . ')';    
		}

		if (!empty($param["management_no"])) {
			$where .= " AND management_no = :management_no";
            $bindings[':management_no'] = [
                'value' => $param["management_no"],
                'datatype' => \PDO::PARAM_STR,
            ];
		}

		if (!empty($param["branch_no"])) {
			$where .= " AND branch_no = :branch_no";
            $bindings[':branch_no'] = [
                'value' => $param["branch_no"],
                'datatype' => \PDO::PARAM_INT,
            ];
		}

		if (!empty($param["location_name"])) {

            $placeholders = [];
            foreach ($param["location_name"] as $i => $name) {
                $ph = ":location_name{$i}";
                $placeholders[] = $ph;
                $bindings[$ph] = [
                    'value' => $name,
                    'datatype' => \PDO::PARAM_STR,
                ];
            }
            // IN句を組み立て
            $where .= ' AND location_name IN (' . implode(',', $placeholders) . ')';        
		}

		if (!empty($param["category_name"])) {
            $placeholders = [];
            foreach ($param["category_name"] as $i => $name) {
                $ph = ":category_name{$i}";
                $placeholders[] = $ph;
                $bindings[$ph] = [
                    'value' => $name,
                    'datatype' => \PDO::PARAM_STR,
                ];
            }
            // IN句を組み立て
            $where .= ' AND category_name IN (' . implode(',', $placeholders) . ')';    
		}
		if (!empty($param["maker_name"])) {

            $placeholders = [];
            foreach ($param["maker_name"] as $i => $name) {
                $ph = ":maker_name{$i}";
                $placeholders[] = $ph;
                $bindings[$ph] = [
                    'value' => $name,
                    'datatype' => \PDO::PARAM_STR,
                ];
            }
            // IN句を組み立て
            $where .= ' AND maker_name IN (' . implode(',', $placeholders) . ')';    
		}
		if (!empty($param["product_name"])) {
			$where .= " AND product_name LIKE :product_name";
            $bindings[':product_name'] = [
                'value' => '%'.$param["product_name"].'%',
                'datatype' => \PDO::PARAM_STR,
            ];
		}

        if (!empty($param["DATE_FROM"])) {
            if (
                ($dateFrom = \DateTime::createFromFormat('Y-m-d', $param["DATE_FROM"])) &&
                $dateFrom->format('Y-m-d') === $param["DATE_FROM"]  // 厳密チェック
            ) {
                // 'Y-m-d' → 'Ymd' 形式に変換
                $formattedDate = $dateFrom->format('Ymd');

                // WHERE句
                $where .= " AND CONCAT(history_yy, LPAD(history_mm, 2, '0'), LPAD(history_dd, 2, '0')) >= :date_from";

                // バインド条件
                $bindings[':date_from'] = [
                    'value' => $formattedDate,
                    'datatype' => \PDO::PARAM_INT,
                ];
            } else {
                // エラー処理

                $this->logger->error(
                    __METHOD__
                    . ' op=history.makeWhere msg="Invalid date format: DATE_FROM" table=' . self::TABLE_NAME
                    . ' date_from=' . $param['DATE_FROM']
                );
                throw new \InvalidArgumentException('Invalid date format: DATE_FROM');
            }
        }

        if (!empty($param["DATE_TO"])) {

            if (
                ($dateFrom = \DateTime::createFromFormat('Y-m-d', $param["DATE_TO"])) &&
                $dateFrom->format('Y-m-d') === $param["DATE_TO"]  // 厳密チェック
            ) {
                // 'Y-m-d' → 'Ymd' 形式に変換
                $formattedDate = $dateFrom->format('Ymd');

                // WHERE句
                $where .= " AND CONCAT(history_yy, LPAD(history_mm, 2, '0'), LPAD(history_dd, 2, '0')) <= :date_to";

                // バインド条件
                $bindings[':date_to'] = [
                    'value' => $formattedDate,
                    'datatype' => \PDO::PARAM_INT,
                ];
            } else {
                // エラー処理
                $this->logger->error(
                    __METHOD__
                    . ' op=history.makeWhere msg="Invalid date format: DATE_TO" table=' . self::TABLE_NAME
                    . ' date_to=' . $param['DATE_TO']
                );
                throw new \InvalidArgumentException('Invalid date format:DATE_TO');

            }
        }

        return [
            'where' => $where,
            'bindings' => $bindings,
        ];
	}
	/**
	 * sortnoからORDER BY句を作成する
	 */
	private function convertSortStr4Zaiko($sortno) {
		$sortStr = "";
        $sortno = (int)($sortno ?? 0);

		if ($sortno === 1) {
			$sortStr = " ORDER BY management_no, branch_no";
		} else if ($sortno === 2) {
			$sortStr = " ORDER BY management_no DESC, branch_no";
		} else if ($sortno === 5) {
			$sortStr = " ORDER BY category_name, management_no, branch_no";
		} else if ($sortno === 6) {
			$sortStr = " ORDER BY category_name DESC, management_no, branch_no";
		} else if ($sortno === 7) {
			$sortStr = " ORDER BY maker_name, management_no, branch_no";
		} else if ($sortno === 8) {
			$sortStr = " ORDER BY maker_name DESC, management_no, branch_no";
		} else if ($sortno === 9) {
			$sortStr = " ORDER BY product_name, management_no, branch_no";
		} else if ($sortno === 10) {
			$sortStr = " ORDER BY product_name DESC, management_no, branch_no";
		} else if ($sortno === 11) {
			$sortStr = " ORDER BY ZAIKO_IN, management_no, branch_no";
		} else if ($sortno === 12) {
			$sortStr = " ORDER BY ZAIKO_IN DESC, management_no, branch_no";
		} else if ($sortno === 13) {
			$sortStr = " ORDER BY ZAIKO_OUT, management_no, branch_no";
		} else if ($sortno === 14) {
			$sortStr = " ORDER BY ZAIKO_OUT DESC, management_no, branch_no";
		} else if ($sortno === 15) {
			$sortStr = " ORDER BY ZAIKO_MOVE, management_no, branch_no";
		} else if ($sortno === 16) {
			$sortStr = " ORDER BY ZAIKO_MOVE DESC, management_no, branch_no";
		} else if ($sortno === 17) {
			$sortStr = " ORDER BY stock, management_no, branch_no";
		} else if ($sortno === 18) {
			$sortStr = " ORDER BY stock DESC, management_no, branch_no";
		}

		return $sortStr;
	}

	private function convertSortStr($sortno) {
		$sortStr = " ORDER BY id DESC";
        $sortno = (int)($sortno ?? 0);

		if ($sortno == 1) {
			$sortStr = " ORDER BY history_yy, history_mm, history_dd, id, created_at";
		} else if ($sortno == 2) {
			$sortStr = " ORDER BY history_yy DESC, history_mm DESC, history_dd DESC, id DESC, created_at";
		} else if ($sortno == 3) {
			$sortStr = " ORDER BY history_kbn";
		} else if ($sortno == 4) {
			$sortStr = " ORDER BY history_kbn DESC";
		} else if ($sortno == 5) {
			$sortStr = " ORDER BY product_name";
		} else if ($sortno == 6) {
			$sortStr = " ORDER BY product_name DESC";
		} else if ($sortno == 7) {
			$sortStr = " ORDER BY stock_in";
		} else if ($sortno == 8) {
			$sortStr = " ORDER BY stock_in DESC";
		} else if ($sortno == 9) {
			$sortStr = " ORDER BY quantity";
		} else if ($sortno == 10) {
			$sortStr = " ORDER BY quantity DESC";
		} else if ($sortno == 11) {
			$sortStr = " ORDER BY category_name";
		} else if ($sortno == 12) {
			$sortStr = " ORDER BY category_name DESC";
		} else if ($sortno == 13) {
			$sortStr = " ORDER BY location_stock";
		} else if ($sortno == 14) {
			$sortStr = " ORDER BY location_stock DESC";
		} else if ($sortno == 15) {
			$sortStr = " ORDER BY maker_name";
		} else if ($sortno == 16) {
			$sortStr = " ORDER BY maker_name DESC";
		} else if ($sortno == 17) {
			$sortStr = " ORDER BY USC.user_name";
		} else if ($sortno == 18) {
			$sortStr = " ORDER BY USC.user_name DESC";
		} else if ($sortno == 19) {
			$sortStr = " ORDER BY updated_at";
		} else if ($sortno == 20) {
			$sortStr = " ORDER BY updated_at DESC";
		} else if ($sortno == 21) {
			$sortStr = " ORDER BY management_no, branch_no";
		} else if ($sortno == 22) {
			$sortStr = " ORDER BY management_no DESC, branch_no";
		} else if ($sortno == 23) {
			$sortStr = " ORDER BY location_name";
		} else if ($sortno == 24) {
			$sortStr = " ORDER BY location_name DESC";
		} else if ($sortno == 25) {
			$sortStr = " ORDER BY move_stock";
		} else if ($sortno == 26) {
			$sortStr = " ORDER BY move_stock DESC";
		}

		return $sortStr;
	}
    private function makeHaving($search_kbn = 0) {

		$having = "";

        // 在庫
		if ($search_kbn == 1) {
			// 入庫あり 
			$having = " HAVING SUM(stock_in) > 0";
		} else if ($search_kbn == 2) {
			// 出庫あり
			$having = " HAVING SUM(quantity) > 0";
		} else if ($search_kbn == 3) {
			// 移動あり
			$having = " HAVING SUM(move_stock) > 0";
		} else if ($search_kbn == 4) {
			// 入庫・出庫あり
			$having = " HAVING (SUM(stock_in) > 0 OR SUM(quantity) > 0)";
		} else if ($search_kbn == 5) {
			// 入庫・出庫なし
			$having = " HAVING SUM(stock_in) = 0 AND SUM(quantity) = 0";
		} else if ($search_kbn == 6) {
			// 入庫・出庫・移動あり
			$having = " HAVING (SUM(stock_in) > 0 OR SUM(quantity) > 0 OR SUM(move_stock) > 0)";
		} else if ($search_kbn == 7) {
			// 入庫・出庫・移動なし
			$having = " HAVING SUM(stock_in) = 0 AND SUM(quantity) = 0 AND SUM(move_stock) = 0";
		}

        return $having;
    }
}
