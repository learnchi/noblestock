<?php
namespace Noblestock\DbLogic;

require_once __DIR__ . '/../vendor/autoload.php';

use Studiogau\Chandra\Database\Database;
use Studiogau\Chandra\Database\MultipleRecordsFoundException;
use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Support\Utility;
use Studiogau\Chandra\Auth\AuthService;
use Noblestock\Logic\LogicConst;
use Noblestock\Logic\MessageConst;

/**
 * ロジッククラス（店舗テーブル用）
 *
 * @author Studio GAU
 * @version 1.0
 */
class Location {

    private const TABLE_NAME = 'locations';

	private const LOCATION_LIST_SELECT_SQL = <<<SQL
SELECT id, location_name, remarks FROM locations
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
	public function list() {
		$sql = self::LOCATION_LIST_SELECT_SQL;
		return $this->database->fetchList($sql);
	}
	public function insert($data) {

		// 入力チェック
        if (empty($data['id'])) {
            $this->logger->error(__METHOD__.' op=location.insert'.' msg="required parameter missing" table=' . self::TABLE_NAME.' detail=id=' . ($data['id'] ?? 'null'));
            throw new \InvalidArgumentException('required parameter missing (id)');
        }

        $values = [];
		if (array_key_exists('id', $data)) {
			$values['id'] = ['value' => $data['id'], 'datatype' => \PDO::PARAM_INT];
		}
		if (array_key_exists('location_name', $data)) {
			$values['location_name'] = ['value' => $data['location_name'], 'datatype' => \PDO::PARAM_STR];
		}
		if (array_key_exists('remarks', $data)) {
			$values['remarks'] = ['value' => $data['remarks'], 'datatype' => \PDO::PARAM_STR];
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
                throw new MultipleRecordsFoundException('insert returned ' . $affected);
            }

			if ($startedHere) {
				$pdoConn->commit();
			}
        } catch (\Throwable $e) {
			if ($startedHere && $pdoConn->getPdo()->inTransaction()) {
				$pdoConn->rollback();
			}
            $this->logger->error(__METHOD__.' op=location.insert'.' msg="insert failed" table=' . self::TABLE_NAME.' ex=' . get_class($e).' detail=' . $e->getMessage() );
            throw $e;
        }

        return $affected ;
    }
	/**
	 * Excelファイルを読込み、店舗マスタ（locations）にレコードを登録／更新します
	 *
	 * @param array $excelData Excelファイル抽出データ
	 * @return array 登録結果
	 */
	public function bulkChange($excelData) {

		$rtnlist = array();

		try {
            $delRtn = $this->database->delete(self::TABLE_NAME);
        } catch (\Throwable $e) {
            $this->logger->error(__METHOD__.' op=location.bulkChange msg="delete all failed" table=' . self::TABLE_NAME.' ex=' . get_class($e).' detail=' . $e->getMessage());
            return ["status" => "90007", "errMsg" => MessageConst::MSG_SYS_MASTER_001, "lists" => []];
        }

		// トランザクション開始
		$pdoConn = $this->database->getConnection();
		$pdoConn->begin();
		$hasFail = false;    // 失敗行がある場合true
		try {
			foreach ($excelData ?? [] as $i => $row) {
				$status = '失敗';
                $insRtn = 0;

				if ($row["status"] === "AAA") {    // チェック結果OK→A、NG→Eがカラムの数だけ並ぶ
                    $status = '登録';
                    $insertData = [
                        'id' => $row["id"],   
                        'location_name' => $row["location_name"],
                        'remarks' => $row["remarks"],
                    ];
                    $insRtn = $this->insert($insertData);
				}

                if ($insRtn !== 1) {
                    throw new \RuntimeException(__METHOD__ . " row no: ".$i." id: ". $row["id"]. " returned ".$insRtn);
                }
                $rtnlist[] = array("status" => $status, 
					'id' => $row["id"],   
					'location_name' => $row["location_name"],
					'remarks' => $row["remarks"],
				);

			}    // end of foreach ($mergedProData)

			// コミット
			$pdoConn->commit();

		} catch (\Throwable $e){
			// ロールバック
			$pdoConn->rollback();
			// エラーログ出力
            $this->logger->error(__METHOD__.' op=location.bulkChange msg="transaction failed and rolled back"'.' table=' . self::TABLE_NAME.' ex=' . get_class($e).' detail=' . $e->getMessage());
			$rtnlist[] = array("status" => $status, "errMsg" => $e->getMessage());
			$hasFail = true;
		}

		if (empty($rtnlist)) {
            $this->logger->error(__METHOD__ . ' op=location.bulkChange msg="excel contains no data"');
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
	 * Excelファイル抽出データのチェックを行います
	 *
	 * @param array $excelData Excelファイル抽出データ
	 * @return array チェック結果
	 */
	public function checkExcel($excelData) {

		$rtnlist = [];

		if (!empty($excelData)) {
			// 重複チェック
			$dupNo = Utility::checkDuplicate($excelData, "id");
			$dupName = Utility::checkDuplicate($excelData, "location_name");

            foreach ($excelData as $i => $masterData) {

				// チェック結果
				$ststr = "";

				// Noチェック
				if (empty($masterData["id"]) || !is_numeric($masterData["id"])) {
                    $ststr .= "E";
                } else if (!empty($dupNo) && in_array($masterData['id'], $dupNo, true) ) {
                    $ststr .= "E";
				} else {
					$ststr .= "A";
				}

				// Nameチェック
				if (empty($masterData["location_name"])) {
                    $ststr .= "E";
                } else if (!empty($dupName) && in_array($masterData['location_name'], $dupName, true) ) {
                    $ststr .= "E";
				} else {
					$ststr .= "A";
				}

                // remarksチェック
                $ststr .= "A";

				$rtnlist[] = [
                    "status" => $ststr,  // チェック結果OK→A、NG→Eがカラムの数だけ並ぶ
                    "id" => $masterData["id"], 
                    "location_name" => $masterData["location_name"], 
                    "remarks" => $masterData["remarks"]
                ];
			}
		}

		return $rtnlist;
	}
}
