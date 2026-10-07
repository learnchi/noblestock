<?php
namespace Noblestock\DbLogic;


use Studiogau\Chandra\Database\Database;
use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Database\RecordNotFoundException;
use Studiogau\Chandra\Database\MultipleRecordsFoundException;
use Studiogau\Chandra\Auth\AuthService;
use Studiogau\Chandra\Support\Utility;
use Studiogau\Chandra\Auth\AuthException;
use Noblestock\Logic\LogicConst;
use Noblestock\Logic\MessageConst;
use Noblestock\Util\UtilCommon;

/**
 * ロジッククラス（ユーザーテーブル用）
 *
 * @author Studio GAU
 * @version 1.0
 */
class User {

    private const TABLE_NAME = 'users';
    private const USER_SELECT_SQL = <<<SQL
SELECT 
    id,
    login_id,
    password_hash, 
    user_name, 
    furigana,
    email, 
    authority,
    sort_order
FROM 
    users 
SQL;
    private const HISTORY_LIST_SQL =  <<<SQL2

SQL2;

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
	public function login($user_id, $password) {

		$data = null;

		// USER_IDからhashされたパスワードの取得
		$bindings = [
			':login_id' => ['value' => $user_id, 'datatype' => \PDO::PARAM_STR],
			// ':password_hash' => ['value' => password_hash($password, PASSWORD_DEFAULT), 'datatype' => \PDO::PARAM_STR],
		];
		$sql = "SELECT id, password_hash FROM users WHERE login_id=:login_id";
		$pwRet = $this->database->fetchOne($sql, $bindings);

		// パスワードの検証
		if ($pwRet && password_verify($password, $pwRet['password_hash'])) {
			$data = $this->select($pwRet['id']);
		} else {
			$this->logger->error(
                __METHOD__
                . ' op=user.login'
                . ' msg="password_verify failed" table=' . self::TABLE_NAME
                . ' detail=id=' . ($pwRet['id'] ?? 'null') . ' user_id=' . $user_id
            );
			throw new AuthException('login password was incorrect.');
		}

		return $data;
	}
	/**
	 * ユーザーを1人selectする。
	 */
	public function select($id) {

		// SQL生成
		$sql = self::USER_SELECT_SQL." WHERE id=:id";

		$data = null;
		$bindings = [
			':id' => ['value' => $id, 'datatype' => \PDO::PARAM_INT],
		];

		$data = $this->database->fetchOne($sql, $bindings);
		return $data;
	}
	/**
	 * idは$dataに入れない
	 * 呼び出す前にuser_idがuniqueであることを確認する
	 */
	public function insert($data) {

		// 入力チェック
		if (empty($data['login_id'])) {
  		    $this->logger->error(
                __METHOD__
                . ' op=user.insert'
                . ' msg="required parameter missing" table=' . self::TABLE_NAME
                . ' detail=login_id=' . ($data["login_id"] ?? 'null')
            );
            throw new \InvalidArgumentException('required parameter missing (login_id)');
		}

        $values = [];
		if (array_key_exists('login_id', $data)) {
			$values['login_id'] = ['value' => $data['login_id'], 'datatype' => \PDO::PARAM_STR];
		}
		if (array_key_exists('password_hash', $data)) {
			$values['password_hash'] = ['value' => password_hash($data['password_hash'], PASSWORD_DEFAULT), 'datatype' => \PDO::PARAM_STR];
		}
		if (array_key_exists('user_name', $data)) {
			$values['user_name'] = ['value' => $data['user_name'], 'datatype' => \PDO::PARAM_STR];
		}

		if (array_key_exists('furigana', $data)) {
			$values['furigana'] = ['value' => $data['furigana'], 'datatype' => \PDO::PARAM_STR];
		}
		if (array_key_exists('email', $data)) {
			$values['email'] = ['value' => $data['email'], 'datatype' => \PDO::PARAM_STR];
		}
		if (array_key_exists('authority', $data)) {
			$values['authority'] = ['value' => $data['authority'], 'datatype' => \PDO::PARAM_STR];
		}
		if (array_key_exists('sort_order', $data)) {
			$values['sort_order'] = ['value' => $data['sort_order'], 'datatype' => \PDO::PARAM_INT];
		}

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
	 * キー項目はidのみとする
	 * user_idの重複チェックを事前にすること。
	 */
    public function update($data) { 
		// 入力チェック
		if (empty($data['id'])) {
  		    $this->logger->error(
                __METHOD__
                . ' op=user.update'
                . ' msg="required parameter missing" table=' . self::TABLE_NAME
                . ' detail=id=' . ($data["id"] ?? 'null')
            );
            throw new \InvalidArgumentException('required parameter missing (id)');
		}

        $values = [];
		if (array_key_exists('login_id', $data) && $data['login_id'] !== "") {
			$values['login_id'] = ['value' => $data['login_id'], 'datatype' => \PDO::PARAM_STR];
		}
		if (array_key_exists('password_hash', $data) && $data['password_hash'] !== "") {
			$values['password_hash'] = ['value' => password_hash($data['password_hash'], PASSWORD_DEFAULT), 'datatype' => \PDO::PARAM_STR];
		}
		if (array_key_exists('user_name', $data)) {
			$values['user_name'] = ['value' => $data['user_name'], 'datatype' => \PDO::PARAM_STR];
		}
		if (array_key_exists('furigana', $data)) {
			$values['furigana'] = ['value' => $data['furigana'], 'datatype' => \PDO::PARAM_STR];
		}
		if (array_key_exists('email', $data)) {
			$values['email'] = ['value' => $data['email'], 'datatype' => \PDO::PARAM_STR];
		}
		if (array_key_exists('authority', $data)) {
			$values['authority'] = ['value' => $data['authority'], 'datatype' => \PDO::PARAM_STR];
		}
		if (array_key_exists('sort_order', $data)) {
			$values['sort_order'] = ['value' => $data['sort_order'], 'datatype' => \PDO::PARAM_INT];
		}
		
        $conditions = [];
		if (array_key_exists('id', $data)) {
			$conditions['id'] = ['value' => $data['id'], 'datatype' => \PDO::PARAM_INT];
		}

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

    public function delete($id = null, $user_id = null) {

		// 入力チェック
		if (empty($id) && empty($user_id)) {
  		    $this->logger->error(
                __METHOD__
                . ' op=user.delete'
                . ' msg="required parameter missing" table=' . self::TABLE_NAME
                . ' detail=id=' . ($id.", user_id:".$user_id ?? 'null')
            );
			throw new \InvalidArgumentException('required parameter missing (id or user_id)');
		}

        $conditions = [];
		if (!empty($id)) {
			$conditions['id'] = ['value' => $id, 'datatype' => \PDO::PARAM_INT];
		}
		if (!empty($user_id)) {
			$conditions['login_id'] = ['value' => $user_id, 'datatype' => \PDO::PARAM_STR];
		}

		// 削除
		$affected  = $this->database->delete(self::TABLE_NAME, $conditions);

		// 1行だけのはず
		if ($affected !== 1) {
			throw new \RuntimeException('product delete returned '.$affected);
		}
		
        return $affected ;       

    }
    public function deleteAll() {
		$affected  = $this->database->delete(self::TABLE_NAME);
		// // 1行だけのはず
		// if ($affected !== 1) {
		//     throw new RuntimeException('stock delete returned '.$affected);
		// }

		return $affected ;  
    }
	/**
     * 一括更新
	 */
	public function bulkChange($data) {

		$rtnlist = array();

		// トランザクション開始
		$pdoConn = $this->database->getConnection();
		$pdoConn->begin();
		$hasFail = false;    // 失敗行がある場合true

        // 全件削除
        $this->deleteAll();

		try {
			foreach ($data ?? [] as $i => $row) {

				$status = '失敗';
                $insRtn = 0;
				if (!empty($row["login_id"]) && !empty($row["password_hash"]) && !empty($row["user_name"])) {
					if (Utility::checkAlphanumeric($row["login_id"], 3, 16)
					 && UtilCommon::isValidPassword($row["password_hash"])) {
						$status = '登録';  // 登録
						// TODO user_idが登録済でないことを確認する

						// 登録処理
						$insertUserData = [
                            // 'id' => $row["id"],
                            'login_id' => $row["login_id"],   
                            'password_hash' => $row["password_hash"],
                            'user_name' => $row["user_name"],
                            'furigana' => $row["furigana"],
                            'email' => $row["email"],
                            'authority' => $row["authority"],
                            'sort_order' => $row["sort_order"],
						];
						$insRtn = $this->insert($insertUserData);
					}
				}

                if ($insRtn !== 1) {
                    throw new \RuntimeException(__METHOD__ . " row no: ".$i." user_id: ". $row["login_id"]. " returned ".$insRtn);
                }
                $rtnlist[] = array("status" => $status, 
					'login_id' => $row["login_id"],   
					'user_name' => $row["user_name"],
					'furigana' => $row["furigana"],
					'email' => $row["email"],
					'authority' => $row["authority"],
					'sort_order' => $row["sort_order"],
				);

			}    // end of foreach ($mergedProData)

			// コミット
			$pdoConn->commit();

		} catch (\Throwable $e){
			// ロールバック
			$pdoConn->rollback();
			$hasFail = true;
			// エラーログ出力
            $this->logger->error(
                __METHOD__
                . ' op=user.bulkChange msg="transaction failed and rolled back"'
                . ' table=' . self::TABLE_NAME
                . ' ex=' . get_class($e)
                . ' detail=' . $e->getMessage()
            );
			$rtnlist[] = array("status" => $status, "errMsg" => $e->getMessage());
		}

		if (empty($rtnlist)) {
            $this->logger->error(
				__METHOD__
				. ' op='
				. ' msg="excel has no data" table=' . self::TABLE_NAME
			);
            return array("status" => "90007", "errMsg" => MessageConst::MSG_VAL_FILE_011, "lists" => $rtnlist);
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
	public function list() {
		$sql = self::USER_SELECT_SQL." ORDER BY sort_order";
		return $this->database->fetchList($sql);
		
	}
	public function changePassword($user_id, $currentPlain, $newPlain) {
		// 入力チェック
		if (empty($user_id)) {
  		    $this->logger->error(
                __METHOD__
                . ' op=user.changePassword'
                . ' msg="required parameter missing" table=' . self::TABLE_NAME
                . ' detail=user_id=' . ($user_id ?? 'null')
            );
            throw new \InvalidArgumentException('required parameter missing (user_id)');
		}

		$updRtn = 0;
		// currentで通るかどうかチェック
		$user =$this->login($user_id, $currentPlain);

		// 更新
		$updateUserData = [
			'id' => $user['id'],
			'password_hash' => $newPlain,
		];
		$updRtn = $this->update($updateUserData);

		return $updRtn;

	}
	public function selectMaxSortOrder() {
		$sql = "SELECT MAX(sort_order) AS max_sort_order FROM users";
		$rtn = $this->database->fetchOne($sql);
		return $rtn['max_sort_order'];
	}

	/**
	 * login_idに対応するユーザーのidを返却する。
	 * 存在しない場合はnullを返却する。
	 * $excludeIdに、検索対象から除外するidを指定できる。
	 */
	public function getIdByUserId($user_id, $excludeId = null): ?int {
		$bindings = [
			':login_id' => ['value' => $user_id, 'datatype' => \PDO::PARAM_STR],
		];

		$checkCntSql = 'SELECT id FROM users WHERE login_id = :login_id';

		if ($excludeId !== null) {
			$checkCntSql .= ' AND id <> :exclude_id';
			$bindings[':exclude_id'] = ['value' => $excludeId, 'datatype' => \PDO::PARAM_INT];
		}

		try {
			$row = $this->database->fetchOne($checkCntSql, $bindings);
			return (int) $row['id'];
		} catch (RecordNotFoundException $e) {
			// 許容されるエラー
			return null;
		}
	}

	/**
	 * =========================================
	 * PRIVATE METHODS
	 * =========================================
	 */

}
