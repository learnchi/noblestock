<?php
namespace Noblestock\DbLogic;

use Studiogau\Chandra\Database\Database;
use Studiogau\Chandra\Database\RecordNotFoundException;
use Studiogau\Chandra\Database\MultipleRecordsFoundException;
use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Support\Utility;
use Studiogau\Chandra\Auth\AuthService;
use Noblestock\DbLogic\Stock;
use Noblestock\DbLogic\History;
use Noblestock\DbLogic\UserRepository;
use Noblestock\Logic\BarcodeGenerator;
use Noblestock\Logic\LogicConst;
use Noblestock\Logic\MessageConst;

/**
 * ロジッククラス（商品テーブル用）
 *
 * @author Studio GAU
 * @version 1.0
 */
class Product {

    private const TABLE_NAME = 'products';

	private const PRODUCT_LIST_SELECT_SQL = <<<SQL
SELECT
    PR.management_no AS management_no,
    PR.category_id AS category_id,
    CA.category_name AS category_name,
    PR.maker_id AS maker_id,
    MA.maker_name AS maker_name,
    PR.product_name AS product_name,
    PR.wholesale_amount AS wholesale_amount,
    PR.retail_amount AS retail_amount,
    PR.sell_amount AS sell_amount,
    IFNULL(SUM(ST.quantity), 0) AS quantity,
    PR.unit_id AS unit_id,
    UN.unit_name AS unit_name,
    PR.storage_place AS storage_place,
    PR.image_file AS image_file,
    PR.remarks AS remarks,
    PR.remarks2 AS remarks2
FROM products PR
LEFT JOIN categories CA ON PR.category_id = CA.id
LEFT JOIN makers MA ON PR.maker_id = MA.id
LEFT JOIN units UN ON PR.unit_id = UN.id
LEFT JOIN stocks ST ON PR.management_no = ST.management_no
LEFT JOIN locations LO ON ST.location_id = LO.id
SQL;


	private const PRODUCT_SELECT_SQL = <<<SQL
SELECT
    PR.management_no      AS management_no,
    PR.category_id        AS category_id,
    CA.category_name      AS category_name,
    PR.maker_id           AS maker_id,
    MA.maker_name         AS maker_name,
    PR.product_name       AS product_name,
    PR.wholesale_amount          AS wholesale_amount,
    PR.retail_amount        AS retail_amount,
    PR.sell_amount         AS sell_amount,
    IFNULL(SUM(ST.quantity), 0) AS quantity,
    PR.unit_id           AS unit_id,
    UN.unit_name          AS unit_name,
    PR.storage_place      AS storage_place,
    PR.image_file         AS image_file,
    PR.remarks            AS remarks,
    PR.remarks2           AS remarks2
FROM
    products PR
    LEFT JOIN categories CA ON PR.category_id = CA.id
    LEFT JOIN makers MA ON PR.maker_id = MA.id
    LEFT JOIN units UN ON PR.unit_id = UN.id
    LEFT JOIN stocks ST ON PR.management_no = ST.management_no
    LEFT JOIN locations LO ON ST.location_id = LO.id
WHERE 
	PR.management_no = :management_no
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
	public function select($management_no) {

		$data = null;
        $bindings = [
            ':management_no' => ['value' => $management_no, 'datatype' => \PDO::PARAM_STR],
        ];
		// SQL生成
		$sql = self::PRODUCT_SELECT_SQL;
		$data = $this->database->fetchOne($sql, $bindings);

        // 集計関数が入っているので0件でもレコードがかえってきてしまう。
        // キー項目がnullなら期待通りではないと判断。
        if (empty($data["management_no"])) {
            $data = null;
            throw new RecordNotFoundException('fetchOne: found no records at table '.self::TABLE_NAME." by selecting management_no:". $management_no);
        }
		
		return $data;
	}

	/**
	 * バーコード選択出力 product_barcode_list.php で使用
	 */
	public function selectMngNos($management_no_list) {
		$rtnList = [];
		if (empty($management_no_list)) {
			throw new \InvalidArgumentException('required parameter missing (management_no_list)');
			// return $rtnList;
		}
		// SQL
		$sql = self::PRODUCT_SELECT_SQL;
		$pdo = $this->database->getConnection()->getPdo();
		$stmt = $pdo->prepare($sql);
		
		foreach ($management_no_list as $i => $mngNo) {
			try {
				$stmt->bindValue(':management_no', $mngNo, \PDO::PARAM_STR);
				$stmt->execute();
				$prd = $stmt->fetch(\PDO::FETCH_ASSOC);
				$hasMore = ($stmt->fetch(\PDO::FETCH_ASSOC) !== false);
				if ($hasMore) {
					throw new MultipleRecordsFoundException('query returned more than one row.');
				}
				if (!empty($prd["management_no"])) {
					$rtnList[] = array(
						"management_no" => $prd['management_no'],
						"category_name" => $prd['category_name'],
						"maker_name" => $prd['maker_name'],
						"product_name" => $prd['product_name'],
						"image_file" => $prd['image_file'],
						"wholesale_amount" => $prd['wholesale_amount'],
						"retail_amount" => $prd['retail_amount'],
						"OUT_NUM" => "1"
					);
				}
			// } catch (RecordNotFoundException $e){
			// 	// 許容されるエラー
			} finally {
				$stmt->closeCursor();
			}
		}
		return $rtnList;
	}

	/**
	 * バーコード一括出力 barcode_bulk_list.php で使用
	 */
	public function selectMngNoSeqs($management_no_seq_list) {
		$rtnList = [];
		if (empty($management_no_seq_list)) {
			return $rtnList;
		}
		// SQL
		$sql = self::PRODUCT_SELECT_SQL;
		$pdo = $this->database->getConnection()->getPdo();
		$stmt = $pdo->prepare($sql);
		
		foreach ($management_no_seq_list as $i => $wk) {
			$mngNo = $wk['management_no'];
			$seqNo = $wk['SEQ'];
			try {
				$stmt->bindValue(':management_no', $mngNo, \PDO::PARAM_STR);
				$stmt->execute();
				$prd = $stmt->fetch(\PDO::FETCH_ASSOC);
				$hasMore = ($stmt->fetch(\PDO::FETCH_ASSOC) !== false);
				if ($hasMore) {
					throw new MultipleRecordsFoundException('query returned more than one row.');
				}
				if (!empty($prd["management_no"])) {
					$rtnList[] = array(
						"management_no" => $prd['management_no'],
						"category_name" => $prd['category_name'],
						"maker_name" => $prd['maker_name'],
						"product_name" => $prd['product_name'],
						"image_file" => $prd['image_file'],
						"wholesale_amount" => $prd['wholesale_amount'],
						"retail_amount" => $prd['retail_amount'],
						"SEQ" => $seqNo
					);
				} else {
					$rtnList[] = array(
						"management_no" => $mngNo, 
						"category_name" => "", 
						"maker_name" => "", 
						"product_name" => "", 
						"image_file" => "", 
						"wholesale_amount" => "", 
						"retail_amount" => "", 
						"SEQ" => $seqNo
					);
				}
			} catch (RecordNotFoundException $e){
				// 許容されるエラー
			} finally {
				$stmt->closeCursor();
			}
		}
		return $rtnList;
	}
	public function insert($data) {

		// 入力チェック
		if (empty($data["management_no"]) || BarcodeGenerator::check($data["management_no"]) === 99) {
		    $this->logger->error(__METHOD__.' op=produuct.insert msg="required parameter missing" table=' . self::TABLE_NAME.' detail=management_no=' . ($data["management_no"] ?? 'null'));
            throw new \InvalidArgumentException('required parameter missing (management_no)');
		}


		// 画像ファイル名チェック
		if (!Utility::checkImageName($data["image_file"])) {
		    // $this->logger->info(__METHOD__ . '画像ファイル名:['.$data["image_file"].'] 空欄に変更します。');
			$data["image_file"] = "";
		}

			// // 管理番号存在チェック
			// $isExists = $this->isExistsByManagementNo($data["management_no"]);
			// if ($isExists) {
			// 	$this->logger->error(__METHOD__ . ' products.management_no['.$data["management_no"].'] has multiple records.');
			// 	throw new MultipleRecordsFoundException(' products.management_no['.$data["management_no"].'] has multiple records.');
			// }

			$values = [
				"management_no"        => ['value' => $data["management_no"],    'datatype' => \PDO::PARAM_STR],
				"category_id"          => ['value' => $data["category_id"],      'datatype' => \PDO::PARAM_INT],
				"maker_id"             => ['value' => $data["maker_id"],         'datatype' => \PDO::PARAM_INT],
				"product_name"         => ['value' => $data["product_name"],     'datatype' => \PDO::PARAM_STR],
				"wholesale_amount"            => ['value' => $data["wholesale_amount"],        'datatype' => \PDO::PARAM_INT],
				"retail_amount"          => ['value' => $data["retail_amount"],      'datatype' => \PDO::PARAM_INT],
				"sell_amount"           => ['value' => $data["sell_amount"],       'datatype' => \PDO::PARAM_INT],
				"quantity"             => ['value' => $data["quantity"],         'datatype' => \PDO::PARAM_INT],
				"unit_id"             => ['value' => $data["unit_id"],         'datatype' => \PDO::PARAM_INT],
				"storage_place"       => ['value' => $data["storage_place"],    'datatype' => \PDO::PARAM_STR],
				"image_file"          => ['value' => $data["image_file"],       'datatype' => \PDO::PARAM_STR],
				"remarks"             => ['value' => $data["remarks"] ,         'datatype' => \PDO::PARAM_STR],
				"remarks2"            => ['value' => $data["remarks2"],         'datatype' => \PDO::PARAM_STR],
			];

		$startedHere = false;
        $pdoConn = $this->database->getConnection();
        try{

			if (!$pdoConn->getPdo()->inTransaction()) {
				$pdoConn->begin();
				$startedHere = true;
			}

            $affected = $this->database->insert(self::TABLE_NAME, $values);
            // 1行だけのはず
            if ($affected !== 1) {
                throw new MultipleRecordsFoundException('product insert returned '.$affected);
            }
			$history = new History($this->database);
			$historyData = [
				'history_kbn'		 => 2,  // 商品登録
				'management_no'		 => $data["management_no"],
				'branch_no'			 => 0,    // BRANCH_NOが0の場合はmax値取得
				'category_name'		 => $data["category_name"],
				'maker_name'		 => $data["maker_name"],
				'product_name'		 => $data["product_name"],
				'location_name'		 => '',
				'quantity'			 => 0,
				'stock_in'			 => 0,
				'move_stock'		 => 0,
				'location_stock'	 => 0,
				'stock'				 => 0
			];
			$historyAffected = $history->insert($historyData);

            // 1行だけのはず
            if ($historyAffected !== 1) {
                throw new MultipleRecordsFoundException('history insert returned '.$historyAffected);
            }

			if ($startedHere) {
				$pdoConn->commit();
			}

        } catch (\Throwable $e) {
			if ($startedHere && $pdoConn->getPdo()->inTransaction()) {
				$pdoConn->rollback();
			}
            throw $e;
        }

        return $affected ;
    }
    public function update($data) {

		// 入力チェック
		if (empty($data["management_no"]) || BarcodeGenerator::check($data["management_no"]) === 99) {
			$this->logger->error(__METHOD__.' op=product.update msg="invalid management_no" table=' . self::TABLE_NAME.' detail=management_no=' . ($data['management_no'] ?? 'l'));
			throw new \InvalidArgumentException('MANAGEMENT_NO値不正: '.$data["management_no"]);
		}


		// 画像ファイル名チェック
		if (!Utility::checkImageName($data["image_file"])) {
		    // $this->logger->info(__METHOD__ . '画像ファイル名:['.$data["image_file"].'] 空欄に変更します。');
			$data["image_file"] = "";
		}

        $values = [
			'category_id' => ['value' => $data["category_id"], 'datatype' => \PDO::PARAM_INT],
			'maker_id' => ['value' => $data["maker_id"], 'datatype' => \PDO::PARAM_INT],
			'product_name' => ['value' => $data["product_name"], 'datatype' => \PDO::PARAM_STR],
			'wholesale_amount' => ['value' => $data["wholesale_amount"], 'datatype' => \PDO::PARAM_INT],
			'retail_amount' => ['value' => $data["retail_amount"], 'datatype' => \PDO::PARAM_INT],
			'sell_amount' => ['value' => $data["sell_amount"], 'datatype' => \PDO::PARAM_INT],
			'quantity' => ['value' => $data["quantity"], 'datatype' => \PDO::PARAM_INT],
			'unit_id' => ['value' => $data["unit_id"], 'datatype' => \PDO::PARAM_INT],
			'storage_place' => ['value' => $data["storage_place"], 'datatype' => \PDO::PARAM_STR],
			'image_file' => ['value' => $data["image_file"], 'datatype' => \PDO::PARAM_STR],
			'remarks' => ['value' => $data["remarks"] , 'datatype' => \PDO::PARAM_STR],
			'remarks2' => ['value' => $data["remarks2"], 'datatype' => \PDO::PARAM_STR],
			// updated_at / updated_by は自動で追加される
        ];
        $conditions = [
            'management_no' => ['value' => $data['management_no'], 'datatype' => \PDO::PARAM_STR],
        ];
        
        $affected = 0;
		
		$startedHere = false;
        $pdoConn = $this->database->getConnection();
        try{
			if (!$pdoConn->getPdo()->inTransaction()) {
				$pdoConn->begin();
				$startedHere = true;
			}
            $affected  = $this->database->update(self::TABLE_NAME, $values, $conditions);
            // 1行だけのはず
            if ($affected !== 1) {
                throw new \RuntimeException('product update returned '.$affected);
            }

			$history = new History($this->database);
			$historyData = [
				'history_kbn'		 => 3,    // 商品更新
				'management_no'		 => $data["management_no"],
				'branch_no'			 => 0,    // BRANCH_NOが0の場合はmax値取得
				'category_name'		 => $data["category_name"],
				'maker_name'		 => $data["maker_name"],
				'product_name'		 => $data["product_name"],
				'location_name'		 => '',
				'quantity'			 => 0,
				'stock_in'			 => 0,
				'move_stock'		 => 0,
				'location_stock'	 => 0,
				'stock'				 => $data["quantity"]
			];
			$history->insert($historyData);
            // // 1行だけのはず
            // if ($historyAffected !== 1) {
            //     throw new RuntimeException('history update returned '.$historyAffected);
            // }
			if ($startedHere) {
				$pdoConn->commit();
			}

        } catch (\Throwable $e) {
			if ($startedHere && $pdoConn->getPdo()->inTransaction()) {
				$pdoConn->rollback();
			}
            throw $e;
        }

        return $affected ;
    }
    
    /**
     * 商品削除処理
     * 削除対象取得→商品削除→在庫レコード削除→履歴登録→履歴テーブルの該当商品データを論理削除
     */
    public function delete($management_no) {

        // 同じコネクションで扱えるようにする
        $stock = new Stock($this->database);
        $history = new History($this->database);

        $pdoConn = $this->database->getConnection();

        // 削除対象取得
        $productData = self::select($management_no);

		// 入力チェック
		if (empty($management_no)) {
			$this->logger->error(__METHOD__.' op=product.delete msg="required parameter missing" table=' . self::TABLE_NAME.' detail=management_no=' . ($management_no ?? 'null'));
		    $this->logger->fatal(__METHOD__ . ' required parameter missing. management_no:'.$management_no);
            throw new \InvalidArgumentException('required parameter missing (management_no)');
		}

        $conditions = [
            'management_no' => ['value' => $management_no, 'datatype' => \PDO::PARAM_STR],
        ];

        $affected = 0;
        $pdoConn->begin();
        try{
            // 商品削除
            $productAffected  = $this->database->delete(self::TABLE_NAME, $conditions);

            // 1行だけのはず
            if ($productAffected !== 1) {
                throw new \RuntimeException('product delete returned '.$productAffected);
            }
            
            // 在庫レコード削除
            $stockAffected = $stock->delete($management_no, null);

            // // 1行だけのはず
            // if ($stockAffected !== 1) {
            //     throw new RuntimeException('stock delete returned '.$stockAffected);
            // }

			// エラー
			if ($stockAffected < 0) {
				throw new \RuntimeException('stock delete returned '.$stockAffected);
			}

            // 履歴登録
            $historyData = [
                'history_kbn'		 => 4,    // 商品削除
                'management_no'		 => $productData["management_no"],
                'branch_no'			 => 0,    // BRANCH_NOが0の場合はmax値取得
                'category_name'		 => $productData["category_name"],
                'maker_name'		 => $productData["maker_name"],
                'product_name'		 => $productData["product_name"],
                'location_name'		 => '',
                'quantity'			 => 0,
                'stock_in'			 => 0,
                'move_stock'		 => 0,
                'location_stock'	 => 0,
                'stock'				 => $productData["quantity"]
            ];
            $historyInsertAffected = $history->insert($historyData);
            // // 1行だけのはず
            // if ($historyInsertAffected !== 1) {
            //     throw new RuntimeException('history insert returned '.$historyInsertAffected);
            // }

            // 履歴テーブルの該当商品データを論理削除
            $delHisData = [
				'del_flg'       => 1,
			];
            $historyUpdateAffected = $history->updateByManagementNo($delHisData, $productData["management_no"]);
            
            // // 1行だけのはず
            // if ($historyUpdateAffected !== 1) {
            //     throw new RuntimeException('history update returned '.$historyUpdateAffected);
            // }

			// エラー
			if ($historyUpdateAffected < 0) {
				throw new \RuntimeException('history update returned '.$historyUpdateAffected);
			}

            $pdoConn->commit();
            $affected = $productAffected;

        } catch (\Throwable $e) {
            $pdoConn->rollback();
            throw $e;
        }

        return $affected ;       

    }

	/**
	 * Where条件を付与せずに行カウント
	 * @return int
	 */
	public function countAll() {

		// SQL生成

		$sql = self::PRODUCT_LIST_SELECT_SQL. " GROUP BY PR.management_no";
		$sql = Utility::replaceStr(
			'SELECT COUNT(STA.management_no) AS PRCNT FROM({0}) AS STA',
			$sql,
		);

		return $this->database->fetchCount($sql);
		
	}
	public function isExistsByManagementNo($management_no) {
		// 管理番号存在チェック
		$bindings = [
			':management_no' => ['value' => $management_no, 'datatype' => \PDO::PARAM_STR],
		];
		$checkCntSql = 'SELECT COUNT(management_no) AS PRCNT FROM products  WHERE management_no = :management_no';

		$rows = $this->database->fetchCount($checkCntSql, $bindings);
		if ($rows === 1) {
			return true;
		} else {
			return false;
		}
	}
	/**
	 * Where句を付与した状態で行数をカウントする。
	 * @return int
	 */
	public function count($param = []) {

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

		$sql .= " GROUP BY PR.management_no";
		$sql = Utility::replaceStr(
			'SELECT COUNT(STA.management_no) AS PRCNT FROM({0}) AS STA',
			$sql,
		);

		// 在庫有無
		$stockFlg = $param['STOCK_FLG'] ?? null;
		if ($stockFlg == 1) {
			$sql .= " WHERE STA.quantity > 0";
		} else if ($stockFlg == 2) {
			$sql .= " WHERE STA.quantity = 0";
		}

		return $this->database->fetchCount($sql, $bindings);

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

		$sql .= " GROUP BY PR.management_no";
		$sql = Utility::replaceStr(
			'SELECT STA.* FROM ({0}) AS STA',
			$sql,
		);
		// 在庫有無
		$stockFlg = $param['STOCK_FLG'] ?? null;
		if ($stockFlg == 1) {
			$sql .= " WHERE STA.quantity > 0";
		} else if ($stockFlg == 2) {
			$sql .= " WHERE STA.quantity = 0";
		}

		// ORDER BY句生成
		$sortStr = self::convertSortStrFromSortNo($sortno);
		$sql.=$sortStr;

		// LIMIT句生成
		if ($limit > 0) {
			$lst = $page * $limit - $limit;
			if ($count >= ($lst + 1)) {
				$sql .= " LIMIT ".$lst.", ".$limit;
			} else {

				$this->logger->error(__METHOD__.' op=product.list msg="limit is illegal" table=' . self::TABLE_NAME.' detail=lst=' . $lst);
				$this->logger->error(__METHOD__ . ' limit is illegal: '.$lst);
                throw new \InvalidArgumentException('limit is illegal: '.$lst);
			}
		}

		return $this->database->fetchList($sql, $bindings);
		
	}
	/**
     * 一括更新
	 */
	public function bulkChange($data) {

		$rtnlist = array();
        // 同じコネクションで扱えるようにする
        $stock = new Stock($this->database);
        // $history = new History($this->database);

		// トランザクション開始
		$pdoConn = $this->database->getConnection();
		$pdoConn->begin();
		$hasFail = false;    // 失敗行がある場合true
		try {
			foreach ($data ?? [] as $row) {
				$status = '失敗';
				if (empty($row["management_no"]) || BarcodeGenerator::check($row["management_no"]) === 99 || empty($row["product_name"])) {

					$rtnlist[] = array("status" => $status, "management_no" => $row["management_no"], "product_name" => $row["product_name"]);
				} else {
							
					$stockRtn = 0;

					// 管理番号存在チェック
					$isExists = $this->isExistsByManagementNo($row["management_no"]);
					if ($isExists) {
						$status = '更新';  // 更新

						// 商品在庫数取得
						try{
							$proStock = $stock->selectSumQuantity($row['management_no']);
						} catch (RecordNotFoundException $e ){
							// 保管場所への商品登録がない
							$proStock = 0;
						}

						$updateStockData = [
							'category_id' => $row["category_id"],
							'category_name' => $row["category_name"],
							'maker_id' => $row["maker_id"], 
							'maker_name' => $row["maker_name"], 
							'product_name' => $row["product_name"], 
							'wholesale_amount' => $row["wholesale_amount"], 
							'retail_amount' => $row["retail_amount"],
							'sell_amount' => $row["sell_amount"], 
							'quantity' => $proStock, 
							'unit_id' => $row["unit_id"], 
							'storage_place' => $row["storage_place"], 
							'image_file' => $row["image_file"], 
							'remarks' => $row["remarks"] , 
							'remarks2' => $row["remarks2"], 
							'management_no' => $row["management_no"],
						];
						$stockRtn = $this->update($updateStockData);
						
					} else {
						$status = '登録';  // 登録
						// 商品情報登録処理
						$insertStockData = [
							'management_no' => $row["management_no"],
							'category_id' => $row["category_id"],
							'category_name' => $row["category_name"],
							'maker_id' => $row["maker_id"], 
							'maker_name' => $row["maker_name"], 
							'product_name' => $row["product_name"], 
							'wholesale_amount' => $row["wholesale_amount"], 
							'retail_amount' => $row["retail_amount"], 
							'sell_amount' => $row["sell_amount"], 
							'quantity' => $row["quantity"], 
							'unit_id' => $row["unit_id"], 
							'storage_place' => $row["storage_place"],
							'image_file' => $row["image_file"], 
							'remarks' => $row["remarks"] , 
							'remarks2' => $row["remarks2"], 
						];
						$stockRtn = $this->insert($insertStockData);
					}

					if ($stockRtn !== 1) {
						throw new \RuntimeException(__METHOD__ . ' returned '.$stockRtn);
					}
					$rtnlist[] = array("status" => $status, "management_no" => $row["management_no"], "product_name" => $row["product_name"]);
				}

			}    // end of foreach ($mergedProData)

			// コミット
			$pdoConn->commit();

		} catch (\Throwable $e){
			// エラーログ出力
            $this->logger->error(__METHOD__.' op=product.bulkChange msg="transaction failed and rolled back" table=' . self::TABLE_NAME.' ex=' . get_class($e).' detail=' . $e->getMessage());
			// ロールバック
			$pdoConn->rollback();
			$rtnlist[] = array("status" => $status, "errMsg" => $e->getMessage());
			$hasFail = true;
		}

		if (empty($rtnlist)) {
            $this->logger->error(
                __METHOD__.' op=product.bulkChange msg="excel contains no data" table=' . self::TABLE_NAME);
            return array("status" => "90007", "errMsg" => MessageConst::MSG_VAL_FILE_011, "lists" => []);
		} 
		if (array_unique(array_column($rtnlist, 'status')) === ['失敗']) {
			// ステータスが"失敗"しかない
			return array("status" => "90006", "errMsg" => MessageConst::MSG_VAL_FILE_015, "lists" => $rtnlist);
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
		// }

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
