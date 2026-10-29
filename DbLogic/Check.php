<?php
namespace Noblestock\DbLogic;


use Studiogau\Chandra\Database\Database;
use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Database\RecordNotFoundException;
use Studiogau\Chandra\Database\MultipleRecordsFoundException;
use Studiogau\Chandra\Auth\AuthService;
use Noblestock\Logic\BarcodeGenerator;
use Noblestock\Logic\LogicConst;

/**
 * ロジッククラス（チェックテーブル用）
 *
 * @author Studio GAU
 * @version 1.0
 */
class Check {

    private const TABLE_NAME = 'checks';
    private const CHECK_SELECT_CHECKCOUNT_SQL = <<<SQL
SELECT 
    check_count 
FROM 
    checks 
WHERE 
    login_id = :user_id AND 
    location_id = :location_no AND 
    management_no = :management_no
SQL;

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
	public function selectCheckcount($user_id, $location_no, $management_no) {

        // 入力チェック
        if (empty($user_id)) {
            $this->logger->error(
                __METHOD__
                . ' op=check.selectCheckcount msg="Required parameter missing: user_id" table=' . self::TABLE_NAME
                . ' user_id=' . ($user_id ?? '(missing)')
            );
            throw new \InvalidArgumentException('Required parameter missing: user_id');
        }
        if (empty($location_no) || !is_numeric((string)$location_no)) {
            $this->logger->error(
                __METHOD__
                . ' op=check.selectCheckcount msg="Invalid parameter: location_no" table=' . self::TABLE_NAME
                . ' location_no=' . ($location_no === null ? '(missing)' : (string)$location_no)
            );
            throw new \InvalidArgumentException('Invalid parameter: location_no');
        }

        if (empty($management_no)) {
            $this->logger->error(
                __METHOD__
                . ' op=check.selectCheckcount msg="Required parameter missing: management_no" table=' . self::TABLE_NAME
                . ' management_no=' . ($management_no ?? '(missing)')
            );
            throw new \InvalidArgumentException('Required parameter missing: management_no');
        }

        $data = null;
        $bindings = [
            ':user_id' => ['value' => $user_id, 'datatype' => \PDO::PARAM_STR],
            ':location_no' => ['value' => $location_no, 'datatype' => \PDO::PARAM_INT],
            ':management_no' => ['value' => $management_no, 'datatype' => \PDO::PARAM_STR],
        ];
        // SQL生成
        $sql = self::CHECK_SELECT_CHECKCOUNT_SQL;

        $data = $this->database->fetchOne($sql, $bindings);
        return $data['check_count'];
	}

    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     * @throws PDOException
     * 
     */
    public function delete($user_id, $location_no = null, $management_no = null) {
		// 入力チェック
        if (empty($user_id)) {
            $this->logger->error(
                __METHOD__
                . ' op=check.delete msg="Required parameter missing: user_id" table=' . self::TABLE_NAME
                . ' user_id=' . ($user_id ?? '(missing)')
            );
            throw new \InvalidArgumentException('Required parameter missing: user_id');
        }

        $conditions = [
            'login_id' => ['value' => $user_id, 'datatype' => \PDO::PARAM_INT],
        ];


        if ($location_no !== null) {
            $conditions['location_id'] = ['value' => (int)$location_no, 'datatype' => \PDO::PARAM_INT];
        }
        if ($management_no !== null) {
            $conditions['management_no'] = ['value' => $management_no, 'datatype' => \PDO::PARAM_STR];
        }

        return $this->database->delete(self::TABLE_NAME, $conditions);    

    }

    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     * @throws PDOException
     */
    public function update($data) {

		// 入力チェック
        if (!is_array($data)) {
            $this->logger->error(
                __METHOD__
                . ' op=check.update msg="Invalid parameter: data" table=' . self::TABLE_NAME
            );
            throw new \InvalidArgumentException('Invalid parameter: data');
        }

        if (empty($data['user_id'])) {
            $this->logger->error(
                __METHOD__
                . ' op=check.update msg="Required parameter missing: user_id" table=' . self::TABLE_NAME
                . ' user_id=' . (($data['user_id'] ?? null) ?? '(missing)')
            );
            throw new \InvalidArgumentException('Required parameter missing: user_id');
        }

        if (empty($data['location_no']) || !is_numeric($data['location_no'])) {
            $this->logger->error(
                __METHOD__
                . ' op=check.update msg="Invalid parameter: location_no" table=' . self::TABLE_NAME
                . ' location_no=' . (($data['location_no'] ?? null) === null ? '(missing)' : (string)$data['location_no'])
            );
            throw new \InvalidArgumentException('Invalid parameter: location_no');
        }

        if (empty($data['management_no']) || BarcodeGenerator::check((string)$data['management_no']) === 99) {
            $this->logger->error(
                __METHOD__
                . ' op=check.update msg="Invalid parameter: management_no" table=' . self::TABLE_NAME
                . ' management_no=' . (($data['management_no'] ?? null) === null ? '(missing)' : (string)$data['management_no'])
            );
            throw new \InvalidArgumentException('Invalid parameter: management_no');
        }

        $values =     [
		    'check_count' => ['value' => $data["check_count"], 'datatype' => \PDO::PARAM_INT],
		    'result_flg' => ['value' => $data["result_flg"], 'datatype' => \PDO::PARAM_INT],
        ];
        $conditions = [
            'login_id' => ['value' => $data['user_id'], 'datatype' => \PDO::PARAM_STR],
            'location_id' => ['value' => $data['location_no'], 'datatype' => \PDO::PARAM_INT],
            'management_no' => ['value' => $data['management_no'], 'datatype' => \PDO::PARAM_STR],
        ];
        
        $affected  = $this->database->update(self::TABLE_NAME, $values, $conditions);
        // 1行だけのはず
        if ($affected !== 1) {
            throw new \RuntimeException('Update affected rows mismatch (expected=1, actual=' . $affected . ')');
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
        if (empty($data['management_no']) || BarcodeGenerator::check((string)$data['management_no']) === 99) {
            $this->logger->error(
                __METHOD__
                . ' op=check.insert msg="Invalid parameter: management_no" table=' . self::TABLE_NAME
                . ' management_no=' . (($data['management_no'] ?? null) === null ? '(missing)' : (string)$data['management_no'])
            );
            throw new \InvalidArgumentException('Invalid parameter: management_no');
        }
        
        $values = [
            "login_id"              => ['value' => $data["user_id"],          'datatype' => \PDO::PARAM_STR],
            "location_id"          => ['value' => $data["location_no"],      'datatype' => \PDO::PARAM_INT],
            "management_no"        => ['value' => $data["management_no"],    'datatype' => \PDO::PARAM_STR],
            "check_count"          => ['value' => $data["check_count"],      'datatype' => \PDO::PARAM_INT],
            "result_flg"           => ['value' => $data["result_flg"],      'datatype' => \PDO::PARAM_INT],
        ];

        $affected = 0;
        $affected = $this->database->insert(self::TABLE_NAME, $values);
        // 1行だけのはず
        if ($affected !== 1) {
            throw new \RuntimeException('Insert affected rows mismatch (expected=1, actual=' . $affected . ')');
        }

        return $affected ;

    }

	/**
     * ロジック
	 * チェック数を加算（減算）または変更します
	 *
	 * @param string $user_id ユーザーID
	 * @param int $location_no 店舗NO
	 * @param string $management_no 管理番号
	 * @param int $up_count 加算数（減算数）または変更数
	 * @param bool $add_flag true：もともとの数に加減算する　false：上書きする
	 * @return
	 */
	public function change($user_id, $location_no, $management_no, $up_count = 0, $add_flag = false) {
        $rtn = false;
        
        // 入力チェック
        if (!is_numeric((string)$up_count)) {
            $this->logger->error(__METHOD__.' op=check.change msg="Invalid parameter: up_count" table=' . self::TABLE_NAME.' up_count=' . (string)$up_count);
            throw new \InvalidArgumentException('Invalid parameter: up_count');
        }
		if (!is_numeric($up_count)) {
            $this->logger->fatal(__METHOD__ . ' required parameter missing. up_count:'.$up_count);
            throw new \InvalidArgumentException('required parameter missing (up_count)');
		}
		if (!$add_flag) {
			// 変更の場合、マイナス値は不可
			if (!is_numeric($up_count) || $up_count < 0) {
            $this->logger->fatal(__METHOD__ . ' up_count cannot be negative. up_count: '.$up_count);
            throw new \InvalidArgumentException('up_count cannot be negative');
			}
		}

        // 同じコネクションで扱えるようにする
        $stock = new Stock($this->database);

        // チェック数取得
        $check_count = 0;
        try{
            $check_count = $this->selectCheckcount($user_id, $location_no, $management_no);
        } catch (RecordNotFoundException $e ){
            // 許容されるエラー
            // $check_count = 0;
        }

        // 在庫数取得
        $stock_count = 0;
        try{
            $stock_count = $stock->selectQuantity($management_no, $location_no);
        } catch (RecordNotFoundException $e ){
            // 保管場所への商品登録がない 許容されるエラー
            // $locStock = 0;
        }

        // 更新チェック数
        $update_count = $up_count;
        if ($add_flag) {
            // 加算（減算）
            $update_count = $check_count + $up_count;
            if ($update_count < 0) {
                $update_count = 0;
            }
        }

        // 結果フラグ
        $result_flg = 0;
        if ($update_count == $stock_count) {
            // 在庫数と更新チェック数が一致
            $result_flg = 1;
        }

        $actionType = '';  // 登録・更新・削除フラグ
        if ($check_count > 0) {
            // レコードあり
            if ($update_count == 0) {
                $actionType = 'delete';  // 削除
            } else {
                $actionType = 'update';  // 更新
            }
        } else {
            // レコードなし
            if ($update_count > 0) {
                $actionType = 'insert';  // 登録
            }
        }

        // トランザクション開始
        $pdoConn = $this->database->getConnection();
        $pdoConn->begin();

        try {
			if ($actionType === 'insert') {
				// 登録
                $data = [
                    "user_id"       => $user_id,
                    "location_no"   => $location_no,
                    "management_no" => $management_no,
                    "check_count"   => $update_count,
                    "result_flg"   => $result_flg,
                ];
                $insRtn = $this->insert($data);
                if ($insRtn !== 1) {
                    throw new \RuntimeException('Insert affected rows mismatch (expected=1, actual=' . $insRtn . ')');

                }
			} else if ($actionType === 'update') {
				// 更新
                $data = [
                    "user_id"       => $user_id,
                    "location_no"   => $location_no,
                    "management_no" => $management_no,
                    "check_count"   => $update_count,
                    "result_flg"   => $result_flg,
                ];
                $updateRtn = $this->update($data);
                if ($updateRtn !== 1) {
                    throw new \RuntimeException('Update affected rows mismatch (expected=1, actual=' . $updateRtn . ')');
                }
			} else if ($actionType === 'delete') {
				// 削除
                $delRtn = $this->delete($user_id, $location_no, $management_no);
                if ($delRtn !== 1) {
                    throw new \RuntimeException('Delete affected rows mismatch (expected=1, actual=' . $delRtn . ')');
                }
			}

            // コミット
            $pdoConn->commit();
            $rtn = true;

        } catch (\Throwable $e) {
            $pdoConn->rollback();
            $this->logger->error(__METHOD__.' op=check.change msg="transaction failed and rolled back" table=' . self::TABLE_NAME.' user_id=' . $user_id.' location_no=' . $location_no.' management_no=' . $management_no.' action=' . ($actionType !== '' ? $actionType : '(noop)').' ex=' . get_class($e).' detail=' . $e->getMessage());
            throw $e;
        }

        return $rtn;
	}
}
