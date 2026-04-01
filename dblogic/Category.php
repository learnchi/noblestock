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
 * ロジッククラス（カテゴリー用）
 *
 * @author Studio GAU
 * @version 1.0
 */
class Category {

    private const TABLE_NAME = 'categories';

	private const CATEGORY_LIST_SELECT_SQL = <<<SQL
SELECT id, category_name, remarks FROM categories
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
     * 一覧取得
     *
     * - ここでは例外を握らない（呼び出し元でまとめてログ）
     */
    public function list(): array
    {
		$sql = self::CATEGORY_LIST_SELECT_SQL;
		return $this->database->fetchList($sql);
	}

    /**
     * 1件登録
     *
     * - 引数不正は InvalidArgumentException（短い固定メッセージ）
     * - トランザクションは「ここで開始した場合のみ」commit/rollback
     * - 例外時のログは基本出さない（二重ログ防止）
     */
    public function insert(array $data): int
    {
		// 必須チェック id=0を許容する場合は、$data['id'] === null || $data['id'] === ''にする
		// auto incrementではないためチェック
        if (empty($data['id']) || !is_numeric($data['id'])) {
            $this->logger->error(__METHOD__.' op=category.insert'.' msg="required parameter missing" table=' . self::TABLE_NAME.' detail=id=' . ($data['id'] ?? 'null'));
            throw new \InvalidArgumentException('Required parameter missing: id');
        }

        $values = [];
        // id（必須）
        $values['id'] = ['value' => (int)$data['id'], 'datatype' => \PDO::PARAM_INT];

        // 任意項目
		if (array_key_exists('category_name', $data)) {
			$values['category_name'] = ['value' => $data['category_name'], 'datatype' => \PDO::PARAM_STR];
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
                throw new MultipleRecordsFoundException('Insert affected rows mismatch (expected=1, actual=' . $affected . ')');
            }

			if ($startedHere) {
				$pdoConn->commit();
			}

        } catch (\Throwable $e) {
			if ($startedHere && $pdoConn->getPdo()->inTransaction()) {
				$pdoConn->rollback();
			}
            $this->logger->error(__METHOD__.' op=category.insert'.' msg="insert failed" table=' . self::TABLE_NAME.' ex=' . get_class($e).' detail=' . $e->getMessage() );
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
    public function bulkChange(array $excelData): array
    {
		$rtnlist = array();

        // 全件削除
        try {
            $delRtn = $this->database->delete(self::TABLE_NAME);
        } catch (\Throwable $e) {
            $this->logger->error(__METHOD__.' op=category.bulkChange msg="delete all failed" table=' . self::TABLE_NAME.' ex=' . get_class($e).' detail=' . $e->getMessage());
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
                        'category_name' => $row["category_name"],
                        'remarks' => $row["remarks"],
                    ];
                    $insRtn = $this->insert($insertData);
				}

                if ($insRtn !== 1) {
                    throw new \RuntimeException(__METHOD__ . " row no: ".$i." id: ". $row["id"]. " returned ".$insRtn);
                }
                $rtnlist[] = [
                    "status" => $status,
                    'id' => $row["id"] ?? null,
                    'category_name' => $row["category_name"] ?? null,
                    'remarks' => $row["remarks"] ?? null,
                ];

			}    // end of foreach ($mergedProData)

			// コミット
			$pdoConn->commit();

		} catch (\Throwable $e){
			// ロールバック
            $pdoConn->rollback();

			// エラーログ出力
            $this->logger->error(__METHOD__.' op=cagegory.bulkChange msg="transaction failed and rolled back"'.' table=' . self::TABLE_NAME.' ex=' . get_class($e).' detail=' . $e->getMessage());

            $rtnlist[] = ["status" => "失敗", "errMsg" => $e->getMessage()];
            $hasFail = true;
		}

        if (empty($rtnlist)) {
            $this->logger->error(__METHOD__ . ' op=category.bulkChange msg="excel contains no data"');
            return ["status" => "90007", "errMsg" => MessageConst::MSG_VAL_FILE_011, "lists" => []];
        }

        if (array_unique(array_column($rtnlist, 'status')) === ['失敗']) {
			// ステータスが"失敗"しかない
            return ["status" => "90006", "errMsg" => MessageConst::MSG_VAL_FILE_015, "lists" => $rtnlist];
        }

        if ($hasFail) {
			// rtnlistの処理結果を失敗にする
            foreach ($rtnlist as $idx => $item) {
                if (($item['status'] ?? '') !== '失敗') {
                    $rtnlist[$idx]['status'] .= '（失敗）';
                }
            }
            return ["status" => "90001", "errMsg" => MessageConst::MSG_SYS_MASTER_002, "lists" => $rtnlist];
        }

        return ["status" => "00000", "lists" => $rtnlist];

	}

	/**
	 * Excelファイル抽出データのチェックを行います
	 *
	 * @param array $excelData Excelファイル抽出データ
	 * @return array チェック結果
	 */
    public function checkExcel(array $excelData): array
    {

		$rtnlist = [];

		if (!empty($excelData)) {
			// 重複チェック
			$dupNo = Utility::checkDuplicate($excelData, "id");
			$dupName = Utility::checkDuplicate($excelData, "category_name");

            foreach ($excelData as $i => $masterData) {

				// チェック結果
				$ststr = "";

				// idチェック
				if (empty($masterData['id']) || !is_numeric($masterData['id'])) {
                    $ststr .= "E";
                } else if (!empty($dupNo) && in_array($masterData['id'], $dupNo, true) ) {
                    $ststr .= "E";
				} else {
					$ststr .= "A";
				}

				// category_nameチェック
				if (empty($masterData["category_name"])) {
                    $ststr .= "E";
                } else if (!empty($dupName) && in_array($masterData['category_name'], $dupName, true) ) {
                    $ststr .= "E";
				} else {
					$ststr .= "A";
				}

                // remarksチェック（現状は常にOK）
                $ststr .= "A";

				$rtnlist[] = [
                    "status" => $ststr,  // チェック結果OK→A、NG→Eがカラムの数だけ並ぶ
                    "id" => $masterData["id"], 
                    "category_name" => $masterData["category_name"], 
                    "remarks" => $masterData["remarks"]
                ];
			}
		}

		return $rtnlist;
	}
}
