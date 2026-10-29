<?php
namespace Noblestock\DbLogic;

use Studiogau\Chandra\Database\Database;
use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Database\RecordNotFoundException;
use Studiogau\Chandra\Database\MultipleRecordsFoundException;
use Noblestock\DbLogic\Product;
use Noblestock\DbLogic\UserRepository;
use Studiogau\Chandra\Auth\AuthService;
use Noblestock\Logic\BarcodeGenerator;
use Noblestock\Logic\LogicConst;
use Noblestock\Logic\MessageConst;

/**
 * ロジッククラス（在庫テーブル用）
 *
 * @author Studio GAU
 * @version 1.0
 */
class Stock {

    private const TABLE_NAME = 'stocks';

	private const STOCK_SELECT_SQL = <<<SQL
SELECT 
    ST.management_no AS management_no, 
    ST.location_id AS location_id, 
    LO.location_name AS location_name, 
    ST.quantity AS quantity, 
    ST.remarks AS remarks 
FROM 
    stocks ST, locations LO 
WHERE 
    ST.location_id = LO.id AND 
    ST.management_no = :management_no
SQL;
    private const STOCK_SELECT_QUATITY_SQL = <<<SQL2
SELECT 
    quantity 
FROM 
    stocks 
WHERE 
    management_no = :management_no AND 
    location_id = :location_no
SQL2;
    private const STOCK_SELECT_SUMQUATITY_SQL = <<<SQL3
SELECT 
    SUM(quantity) AS SUM_QUANTITY 
FROM 
    stocks 
WHERE 
    management_no = :management_no 
GROUP BY 
    management_no 
SQL3;
    private const STOCK_SELECT_CHECK_SQL = <<< SQL4
SELECT
    ST.management_no AS management_no,
    CA.category_name AS category_name,
    MA.maker_name AS maker_name,
    PR.product_name AS product_name,
    IFNULL(ST.quantity, 0) AS quantity,
    IFNULL(CK.check_count, 0) AS check_count,
    IFNULL(CK.result_flg, 0) AS result_flg
FROM
    stocks ST
    LEFT JOIN products PR
        ON ST.management_no = PR.management_no
    LEFT JOIN categories CA
        ON PR.category_id = CA.id
    LEFT JOIN makers MA
        ON PR.maker_id = MA.id
    LEFT JOIN checks CK
        ON ST.management_no = CK.management_no
        AND ST.location_id = CK.location_id
        AND CK.login_id = :user_id
WHERE
    ST.location_id = :location_no
    AND ST.management_no = :management_no 
SQL4;
    private const STOCK_SELECT_COUNT_SQL = <<< SQL5
SELECT 
    COUNT(ST.management_no) AS stcnt 
FROM 
    stocks ST 
WHERE 
    ST.location_id = :location_no
SQL5;
    private const STOCK_SELECT_LIST_SQL = <<< SQL6
SELECT
    ST.management_no AS management_no,
    CA.category_name AS category_name,
    MA.maker_name AS maker_name,
    PR.product_name AS product_name,
    IFNULL(ST.quantity, 0) AS quantity,
    IFNULL(CK.check_count, 0) AS check_count,
    IFNULL(CK.result_flg, 0) AS result_flg
FROM
    stocks ST
    LEFT JOIN products PR
        ON ST.management_no = PR.management_no
    LEFT JOIN categories CA
        ON PR.category_id = CA.id
    LEFT JOIN makers MA
        ON PR.maker_id = MA.id
    LEFT JOIN checks CK
        ON ST.management_no = CK.management_no
        AND ST.location_id = CK.location_id
        AND CK.login_id = :user_id
WHERE
    ST.location_id = :location_no
SQL6;
	private Database $database;
    private Logger $logger;

	public function __construct(?Database $database = null, ?Logger $logger = null) {

        $this->logger = $logger ?? Logger::createDefault(dirname(__DIR__, 1));

        // Databaseクラスのインスタンスは設定ファイルを指定して生成
		$this->database = $database ?? Database::fromConfiguredSource(dirname(__DIR__, 1) . LogicConst::DB_CONFIG_PATH, $this->logger);

        // 監査項目用 userId を設定（取れなければ Database 側の SYSTEM のまま）
        
        $auth = new AuthService(new UserRepository(), $this->logger);
        $userId = $auth->getCurrentUser()?->getLoginId();
        if (!empty($userId)) {
            $this->database->setCurrentUserId($userId);
        }
	}

    /**
     * @throws InvalidArgumentException
     * @throws RecordNotFoundException
     * @throws MultipleRecordsFoundException
     * @throws PDOException
     * @throws RuntimeException
     */
	public function select($management_no, $location_no) {

		// SQL生成
		$sql = self::STOCK_SELECT_SQL.' AND ST.location_id = :location_no';
        $bindings = [
            ':management_no' => ['value' => $management_no, 'datatype' => \PDO::PARAM_STR],
            ':location_no' => ['value' => $location_no, 'datatype' => \PDO::PARAM_INT],
        ];
        return $this->database->fetchOne($sql, $bindings);
	}

    /**
     * 店舗ごとの在庫数を文字列で取得する
     */
	public function getStockPerLocation($management_no) {

		$locationStock = "";
        $bindings = [
            ':management_no' => ['value' => $management_no, 'datatype' => \PDO::PARAM_STR],
        ];
		// SQL生成
		$sql = self::STOCK_SELECT_SQL.' ORDER BY ST.location_id';
        $stockData = $this->database->fetchList($sql, $bindings);
        foreach ($stockData ?? [] as $i => $wk) {
            if ($i > 0) {
                $locationStock .= ",";
            }
            // $locationStock .= $wk['location_name']."：".$wk['quantity'];
            $locationStock .= $wk['location_name']."[".$wk['quantity']."]";
        }
        return $locationStock;
	}
    /**
     * 在庫チェック（product_check.php）で使用。
     * 
     * @throws InvalidArgumentException
     * @throws RecordNotFoundException
     * @throws MultipleRecordsFoundException
     * @throws PDOException
     * @throws RuntimeException
     */
	public function select4Check($user_id, $location_no, $management_no) {

		$data = null;
        $bindings = [
            ':user_id' => ['value' => $user_id, 'datatype' => \PDO::PARAM_STR],
            ':location_no' => ['value' => $location_no, 'datatype' => \PDO::PARAM_INT],
            ':management_no' => ['value' => $management_no, 'datatype' => \PDO::PARAM_STR],
        ];
		// SQL生成
		$sql = self::STOCK_SELECT_CHECK_SQL;
        $data = $this->database->fetchOne($sql, $bindings);
        return $data;
	}
    /**
     * @throws InvalidArgumentException
     * @throws RecordNotFoundException
     * @throws MultipleRecordsFoundException
     * @throws PDOException
     * @throws RuntimeException
     */
	public function selectQuantity($management_no, $location_no) {

		$data = null;
        $bindings = [
            ':management_no' => ['value' => $management_no, 'datatype' => \PDO::PARAM_STR],
            ':location_no' => ['value' => $location_no, 'datatype' => \PDO::PARAM_INT],
        ];
		// SQL生成
		$sql = self::STOCK_SELECT_QUATITY_SQL;
        $data = $this->database->fetchOne($sql, $bindings);
        return $data['quantity'];
	}
    /**
     * @throws InvalidArgumentException
     * @throws RecordNotFoundException
     * @throws MultipleRecordsFoundException
     * @throws PDOException
     * @throws RuntimeException
     */
	public function selectSumQuantity($management_no) {

		$data = null;
        $bindings = [
            ':management_no' => ['value' => $management_no, 'datatype' => \PDO::PARAM_STR],
        ];
		// SQL生成
		$sql = self::STOCK_SELECT_SUMQUATITY_SQL;
        $data = $this->database->fetchOne($sql, $bindings);
        
        // 集計関数が入っているので0件でもレコードがかえってきてしまう。
        // キー項目がnullなら期待通りではないと判断。
        if (empty($data["SUM_QUANTITY"])) {
            $data = null;
            throw new RecordNotFoundException('fetchOne: found no records at table '.self::TABLE_NAME." by selecting management_no:". $management_no);
        }

        return $data['SUM_QUANTITY'];
	}
    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     * @throws PDOException
     */
    public function delete($management_no, $location_no = null) {
		// 入力チェック
		if (empty($management_no)) {
		    $this->logger->fatal(__METHOD__ . ' required parameter missing. management_no:'.$management_no);
            throw new \InvalidArgumentException('required parameter missing (management_no)');
		}

        if (is_null($location_no)) {
            $conditions = [
                'management_no' => ['value' => $management_no, 'datatype' => \PDO::PARAM_STR],
            ];
        } else {
            $conditions = [
                'management_no' => ['value' => $management_no, 'datatype' => \PDO::PARAM_STR],
                'location_id' => ['value' => $location_no, 'datatype' => \PDO::PARAM_STR],
            ];
        }
        
        $affected  = $this->database->delete(self::TABLE_NAME, $conditions);
        // // 1行だけのはず
        // if ($affected !== 1) {
        //     throw new RuntimeException('stock delete returned '.$affected);
        // }

        return $affected ;   
    }

    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     * @throws PDOException
     */
    public function update($data) {

		// 入力チェック
		if (empty($data["management_no"]) || BarcodeGenerator::check($data["management_no"]) === 99) {
		    $this->logger->fatal(__METHOD__ . ' required parameter missing. management_no:'.$data["management_no"]);
            throw new \InvalidArgumentException('required parameter missing (management_no)');
		}
		if (empty($data["location_id"])) {
		    $this->logger->fatal(__METHOD__ . ' required parameter missing. location_id:'.$data["location_id"]);
            throw new \InvalidArgumentException('required parameter missing (location_id)');
		}


        $values =     [
		    'quantity' => ['value' => $data["quantity"], 'datatype' => \PDO::PARAM_INT],
        ];
        $conditions = [
            'management_no' => ['value' => $data['management_no'], 'datatype' => \PDO::PARAM_STR],
            'location_id' => ['value' => $data['location_id'], 'datatype' => \PDO::PARAM_INT],
        ];
        $affected  = $this->database->update(self::TABLE_NAME, $values, $conditions);
        // 1行だけのはず
        if ($affected !== 1) {
            throw new \RuntimeException('product update returned '.$affected);
        }

        return $affected ;
    }

    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     * @throws PDOException
     */
    public function insert($data) {

		// 入力チェック
		if (empty($data["management_no"]) || BarcodeGenerator::check($data["management_no"]) === 99) {
		    $this->logger->fatal(__METHOD__ . ' required parameter missing. management_no:'.$data["management_no"]);
            throw new \InvalidArgumentException('required parameter missing (management_no)');
		}


        $values = [
            "management_no"        => ['value' => $data["management_no"],    'datatype' => \PDO::PARAM_STR],
            "location_id"          => ['value' => $data["location_id"],      'datatype' => \PDO::PARAM_INT],
            "quantity"             => ['value' => $data["quantity"],         'datatype' => \PDO::PARAM_INT],
        ];

        $affected = 0;
        $affected = $this->database->insert(self::TABLE_NAME, $values);
        // 1行だけのはず
        if ($affected !== 1) {
            throw new \RuntimeException('stock insert returned '.$affected);
        }

        return $affected ;

    }

	/**
	 * Where条件を付与せずに行カウント
	 * @return int
	 */
	public function countAll($location_no) {

		// 入力チェック
		if (empty($location_no)) {
		    $this->logger->fatal(__METHOD__ . ' required parameter missing. location_no:'.$location_no);
            throw new \InvalidArgumentException('required parameter missing (location_no)');
		}
        
		// SQL生成
        $bindings = [
            ':location_no' => ['value' => $location_no, 'datatype' => \PDO::PARAM_INT],
        ];
		$sql = self::STOCK_SELECT_COUNT_SQL;

        $rows = $this->database->fetchCount($sql, $bindings);
        return $rows;
	}
	public function list($user_id, $location_no, $sortno = 5, $limit = 0, $page = 1, $count = 0) {
		// 入力チェック
		if (empty($location_no)) {
		    $this->logger->fatal(__METHOD__ . ' required parameter missing. location_no:'.$location_no);
            throw new \InvalidArgumentException('required parameter missing (location_no)');
		}

		// SQL生成
		$sql = self::STOCK_SELECT_LIST_SQL;

        // WHERE句からバインド生成
        $bindings = [
            ':user_id' => ['value' => $user_id, 'datatype' => \PDO::PARAM_STR],
            ':location_no' => ['value' => $location_no, 'datatype' => \PDO::PARAM_INT],
            // ':management_no' => ['value' => $management_no, 'datatype' => \PDO::PARAM_STR],
        ];

		// ORDER BY句生成
		$sortStr = self::convertSortStrFromSortNo($sortno);
		$sql.=$sortStr;

		// LIMIT句生成
		if ($limit > 0) {
			$lst = $page * $limit - $limit;
			if ($count >= ($lst + 1)) {
				$sql .= " LIMIT ".$lst.", ".$limit;
			} else {
				$this->logger->error(__METHOD__ . ' limit is illegal: '.$lst);
                throw new \InvalidArgumentException('limit is illegal: '.$lst);
			}
		}

		return $this->database->fetchList($sql, $bindings);
		
	}
	/**
     * 入庫、出庫、移動共通のロジック
	 */
	public function change($proData, $locData, $addFlg ) {

        // 同一管理番号でマージ（入出庫数は加算）
        $mergedProData = array();
        foreach ($proData ?? [] as $row) {
            $key = $row['management_no'];
            if (!isset($mergedProData[$key])) {
                // 初回 → 新規登録
                $mergedProData[$key] = $row;
            } else {
                // 2回目以降 → STOCK_COUNTを加算しつつ、その他の項目も上書き（後勝ち）
                $mergedProData[$key]['STOCK_COUNT'] += $row['STOCK_COUNT'];
                // STOCK_COUNT以外も「後勝ち」になるように上書き
                foreach ($row as $col => $val) {
                    if ($col !== 'STOCK_COUNT') {
                        $mergedProData[$key][$col] = $val;
                    }
                }
            }
        }
        $mergedProData = array_values($mergedProData);

		$rtnlist = array();
        // 同じコネクションで扱えるようにする
        $history = new History($this->database);

        foreach ($mergedProData ?? [] as $i => $wk) {

            // 商品在庫数取得  TODO: ここ1回だけにできる？
            try{
                $proStock = $this->selectSumQuantity($wk['management_no']);
            } catch (RecordNotFoundException $e) {
                $proStock = 0;
            }

            // トランザクション開始
            $pdoConn = $this->database->getConnection();
            $pdoConn->begin();

            try {

                if ($addFlg === 1 || $addFlg === 2) {    // 入庫、出庫
                    $result =  $this->changeImpl($wk,  $locData[0]['location_id'], $locData[0]['location_name'], $addFlg, $history);
                } else {    // 移動
                    //保管場所変更（From）
                    $resultFrom =  $this->changeImpl($wk,  $locData[0]['location_id'], $locData[0]['location_name'], 4, $history);

                    $statusFrom = $resultFrom["status"];
                    $listsFrom = $resultFrom["lists"];
                    
                    if ($statusFrom === "成功") {

                        //保管場所変更（To）
                        $resultTo =  $this->changeImpl($wk,  $locData[1]['location_id'], $locData[1]['location_name'], 3, $history);

                        $statusTo = $resultTo["status"];
                        $listsTo = $resultTo["lists"];
                        $listsTo['LOCATION_STOCK_FROM'] = $listsFrom['location_stock'];
                        $listsTo['LOCATION_NAME_FROM'] = $listsFrom['location_name'];
                        $result = array("status" => $statusTo, "lists" => $listsTo);

                    } else {

                        $listsFrom['LOCATION_STOCK_FROM'] = $wk['location_stock'];
                        $listsFrom['LOCATION_NAME_FROM'] = $locData[0]['location_name'];
                        $listsFrom['location_stock'] = $wk['LOCATION_STOCK_TO'];
                        $listsFrom['location_name'] = $locData[1]['location_name'];
                        $result = array("status" => $statusFrom, "lists" => $listsFrom);
                    }
                }
                // コミット
                $pdoConn->commit();
                

            } catch (\Throwable $e){
                // ロールバック
                $pdoConn->rollback();

                // エラーログ出力
                $this->logger->error(__METHOD__.' op=stock.change msg="transaction failed and rolled back" table=' . self::TABLE_NAME.' ex=' . get_class($e).' detail=' . $e->getMessage());

                $result = array("status" => "失敗", "lists" => $wk);
            }
            
            $rtnlist[] = $result;
        }    // end of foreach ($mergedProData)

        

        if (empty($rtnlist)) {
            $this->logger->error(__METHOD__ . ' op=stock.bulkChange msg="excel contains no data"');
            return array("status" => "90007", "errMsg" => MessageConst::MSG_VAL_FILE_011, "lists" => []);            
        }  
        if (in_array('失敗', array_column($rtnlist, 'status'), true)) {
            // エラーログ出力
            $this->logger->error(__METHOD__ . ' op=stock.bulkChange msg="error occured."');
            return array("status" => "90001", "errMsg" => MessageConst::MSG_SYS_MASTER_002, "lists" => $rtnlist);
        }
		return array("status" => "00000", "errMsg" => "", "lists" => $rtnlist);
	}

    // 入庫ロジック
    public function add($proData, $locData) {
        return $this->change($proData, $locData, 1 );
    }
    // 出庫
    public function reduce($proData, $locData) {
        return $this->change($proData, $locData, 2 );
    }
    // 移動
    public function transfer($proData, $locData) {
        return $this->change($proData, $locData, 3 );
    }
	/**
     * 一括更新
	 */
	public function bulkChange($data) {

		$rtnlist = array();
        // // 同じコネクションで扱えるようにする
        $product = new Product($this->database);
        $history = new History($this->database);

		// トランザクション開始
		$pdoConn = $this->database->getConnection();
		$pdoConn->begin();
		$hasFail = false;    // 失敗行がある場合true
		try {
			foreach ($data ?? [] as $row) {


				$status = '失敗';
				if ( empty($row["management_no"]) || BarcodeGenerator::check($row["management_no"]) === 99 || empty($row["location_id"])) {
                    $rtnlist[] = array("status" => $status, "management_no" => $row["management_no"], "location_id" => $row["location_id"], "location_name" => $row["location_name"], "quantity" => $row["quantity"]);
				} else {
                    // 商品情報取得
                    $param = [
                        "management_no" => $row['management_no'],
                    ];
                    $productCnt = 0;
                    try {
                        $product_list = $product->list($param, 5, 0, 1, 1);
                        $productInfo = $product_list[0] ?? null;
                        if (empty($productInfo['management_no'])) {
                            // $productCnt = 0;
                        } else {
                            $productCnt = count($productInfo ?? []);
                        }
                    } catch (RecordNotFoundException $e) { 
                        // $productCnt = 0;
                    }
                    
                    if ($productCnt > 0) {
                        // 存在チェック
                        $isExists = $this->isExists($row['management_no'], $row['location_id']);

                        if ($isExists) {
                            if ($row["quantity"] == 0) {
                                // 削除
                                $delRtn = $this->delete($row['management_no'], $row['location_id']);
                                if ($delRtn === 1) {
                                    $status = "削除";
                                }
                            } else {
                                // 更新
                                $param = [
                                    "quantity" => $row['quantity'],
                                    "management_no" => $row['management_no'],
                                    "location_id" => $row['location_id'], 
                                ];
                                $updateRtn = $this->update($param);
                                if ($updateRtn === 1) {
                                    $status = "更新";
                                }
                            }
                        } else if ($row["quantity"] == 0) {
                            // 操作なし
                            $status = "操作なし";
                        } else {
                            // 登録
                            $param = [
                                "quantity" => $row['quantity'],
                                "management_no" => $row['management_no'],
                                "location_id" => $row['location_id'], 
                            ];
                            $insRtn = $this->insert($param);
                            if ($insRtn === 1) {
                                $status = "登録";
                            }
                        }

                        if ($status !== "失敗" && $status !== "操作なし") {
                            
                            // 商品在庫数取得
                            try{
                                $proStock = $this->selectSumQuantity($row['management_no']);
                            } catch (RecordNotFoundException $e) {
                                $proStock = 0;
                            }

                            $historyData = [
                                "history_kbn" => 7,  // 在庫数変更
                                'management_no'		 => $row["management_no"],
                                'branch_no'			 => 0,    // BRANCH_NOが0の場合はmax値取得
                                'category_name'		 => $productInfo["category_name"],
                                'maker_name'		 => $productInfo["maker_name"],
                                'product_name'		 => $productInfo["product_name"],
                                "location_name"      => $row["location_name"],
                                'quantity'			 => 0,
                                'stock_in'			 => 0,
                                'move_stock'		 => 0,
                                "location_stock"     => $row["quantity"],
                                "stock"              => $proStock,
                            ];
                            $historyAffected = $history->insert($historyData);
                            // 1行だけのはず
                            if ($historyAffected !== 1) {
                                throw new MultipleRecordsFoundException('history insert returned '.$historyAffected);
                            }
                        }
                    }
                    $rtnlist[] = array("status" => $status, "management_no" => $row["management_no"], "location_id" => $row["location_id"], "location_name" => $row["location_name"], "quantity" => $row["quantity"]);

				}

			}    // end of foreach
			// コミット
			$pdoConn->commit();

		} catch (\Throwable $e){
			// エラーログ出力
            $this->logger->error(
                __METHOD__.' op=stock.bulkChange msg="transaction failed and rolled back"'.' table=' . self::TABLE_NAME.' ex=' . get_class($e).' detail=' . $e->getMessage()
            );
			// ロールバック
			$pdoConn->rollback();
			$rtnlist[] = array("status" => $status, "errMsg" => $e->getMessage());
			$hasFail = true;
		}

		if (empty($rtnlist)) {
            $this->logger->error(__METHOD__ . ' op=stock.bulkChange msg="excel contains no data"');
            return array("status" => "90007", "errMsg" => MessageConst::MSG_VAL_FILE_011, "lists" => $rtnlist);
		} 
        if (array_unique(array_column($rtnlist, 'status')) === ['失敗']) {
            $this->logger->error(__METHOD__ . ' op=stock.bulkChange msg="error occured."');
            return array("status" => "90001", "errMsg" => MessageConst::MSG_SYS_MASTER_002, "lists" => $rtnlist);
        }
         if ($hasFail) {
			// rtnlistの処理結果を失敗にする
			foreach ($rtnlist as $i => $item) {
				if ($item['status'] !== '失敗') {
					$rtnlist[$i]['status'] .= '（失敗）';
				}
			}

            return array("status" => "90001", "errMsg" => MessageConst::MSG_SYS_MASTER_002, "lists" => $rtnlist);
        }

		return array("status" => "00000", "lists" => $rtnlist);
	}
	public function isExists($management_no, $location_no) {

        $bindings = [
            ":management_no" => ['value' => $management_no, 'datatype' => \PDO::PARAM_STR],
            ":location_id" => ['value' => $location_no, 'datatype' => \PDO::PARAM_INT],
        ];
		$checkCntSql = "SELECT COUNT(management_no) AS MNG_CNT FROM stocks WHERE management_no = :management_no AND location_id = :location_id";
		
        $rows = $this->database->fetchCount($checkCntSql, $bindings);
        if ($rows === 1) {
            return true;
        } else {
            return false;
        }
	}
	/**
	 * =========================================
	 * PRIVATE METHODS
	 * =========================================
	 */

    /**
     * changeメソッドから呼び出される共通のプライベートメソッド
     * $productData = $wk
     * $location_no = $locData[0|1]['location_id']
     * $location_name = $locData[0|1]['location_name']
     * $addFlg 1:入庫 2:出庫 3:保管場所変更（To）4:保管場所変更（From）
     * $history 同じコネクションの履歴テーブル
     */
    private function changeImpl($productData, $location_no, $location_name, $addFlg, $history) {

        $status = "失敗";
        // $productData = $wk;
        $productData['location_name'] = $location_name;

        $wk_amt = $productData['STOCK_COUNT'];
        // 出庫時は在庫数をマイナスにする
        if ($addFlg === 2 || $addFlg === 4) $wk_amt = -1 * $wk_amt;

        // 保管場所在庫数取得  存在チェックも兼ねる
        $locStock = 0;
        $actionType = 'update';  // 更新
        try{
            $locStock = $this->selectQuantity($productData['management_no'], $location_no);
        } catch (RecordNotFoundException $e ){
            // 保管場所への商品登録がない
            // $locStock = 0;
            $actionType = 'insert';  // 登録
        } catch (MultipleRecordsFoundException $e) {
            // 許容されるエラー
            // $locStock = 0;
            $actionType = 'delete';  // 削除
        }
        // if ($locStock > 0) {
        //     $actionType = 'update';  // 更新
        // }

        // 変更後保管場所在庫数
        $addLocStock = $locStock + $wk_amt;

        // 商品在庫数取得  TODO: できれば削除したい
        try{
            $proStock = $this->selectSumQuantity($productData['management_no']);
        } catch (RecordNotFoundException $e) {
            $proStock = 0;
        }

        // 変更後商品在庫数
        $addProStock = $proStock + $wk_amt;

        // 在庫数テーブル登録／更新／削除
        if ($addLocStock >= 0) {
            /////////////////////////////////////// 在庫数テーブル登録／更新／削除 start
            $rtnup = false;

            if ($actionType === 'update') {  // 更新

                $data = [
                    "quantity" => $addLocStock,
                    "management_no" => $productData['management_no'],
                    "location_id" => $location_no, 
                ];
                $updateRtn = $this->update($data);
                if ($updateRtn === 1) {
                    $rtnup = true;
                }
            
            } else if ($actionType === 'delete' ) {  // 削除
                $delRtn = $this->delete($productData['management_no'], $location_no);
                if ($delRtn === 1) {
                    $rtnup = true;
                }
            } else {    // $actionType = 'insert';  // 登録
                $data = [
                    "management_no" => $productData['management_no'],
                    "location_id" => $location_no,
                    "quantity" => $addLocStock,
                ];
                $insRtn = $this->insert($data);
                if ($insRtn === 1) {
                    $rtnup = true;
                }
            }

            /////////////////////////////////////// 在庫数テーブル登録／更新／削除 end
            if ($rtnup) {

                $productData['quantity'] = $addProStock;
                $productData['location_stock'] = $addLocStock;

                // 履歴登録
                $historyKbn = null;
                $stockIn = 0;
                $quantity = 0;
                $moveStock = 0;
                if ($addFlg === 1) {
                    // 入庫
                    $historyKbn = 0;
                    $stockIn = $productData['STOCK_COUNT'];
                } else if ($addFlg === 2) {
                    // 出庫
                    $historyKbn = 1;
                    $quantity = $productData['STOCK_COUNT'];
                } else if ($addFlg === 3) {
                    // 保管場所変更（To）
                    $historyKbn = 6;
                    $moveStock = $productData['STOCK_COUNT'];
                } else if ($addFlg === 4) {
                    // 保管場所変更（From）
                    $historyKbn = 5;
                    $moveStock = $productData['STOCK_COUNT'];
                }

                // 履歴登録
                $historyData = [
                    "history_kbn" => $historyKbn,
                    "management_no" => $productData['management_no'],
                    "category_name" => $productData['category_name'],
                    "maker_name" => $productData['maker_name'],
                    "product_name" => $productData['product_name'],
                    "location_name" => $productData['location_name'],
                    "quantity" => $quantity,
                    "stock_in" => $stockIn,
                    "move_stock" => $moveStock,
                    "location_stock" => $productData['location_stock'],
                    "stock" => $productData['quantity'],
                    'branch_no'			 => 0,    // BRANCH_NOが0の場合はmax値取得
                ];
                $historyInsertAffected = $history->insert($historyData);

                if ($historyInsertAffected > 0) {
                    $status = "成功";
                }
            } else {    // 在庫数テーブル登録／更新／削除 失敗
                $addLocStock = 0;
                $addProStock = 0;
                $productData['location_name'] = "";
                // $status = "失敗";
                // return
            }
        } else {    // $addLocStock < 0
            // 在庫数不足
            $addLocStock = $locStock;
            $addProStock = $proStock;
        }

        return array("status" => $status, "lists" => $productData);
    }
	/**
	 * sortnoからORDER BY句を作成する。将来的には番号ではなくもっとうまい方法でORDER BY句を渡したい。それまでの対応。
	 */
	private function convertSortStrFromSortNo($sortno) {
		$sortStr = "";
        $sortno = $sortno ?? 0;

		if ($sortno === 1) {
			$sortStr = " ORDER BY ST.management_no";
		} else if ($sortno === 2) {
			$sortStr = " ORDER BY ST.management_no DESC";
		} else if ($sortno === 3) {
			$sortStr = " ORDER BY CA.category_name, ST.management_no";
		} else if ($sortno === 4) {
			$sortStr = " ORDER BY CA.category_name DESC, ST.management_no";
		} else if ($sortno === 5) {
			$sortStr = " ORDER BY MA.maker_name, ST.management_no";
		} else if ($sortno === 6) {
			$sortStr = " ORDER BY MA.maker_name DESC, ST.management_no";
		} else if ($sortno === 7) {
			$sortStr = " ORDER BY PR.product_name, ST.management_no";
		} else if ($sortno === 8) {
			$sortStr = " ORDER BY PR.product_name DESC, ST.management_no";
		}

		return $sortStr;
	}
}
