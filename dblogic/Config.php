<?php
namespace Noblestock\DbLogic;


// Bring in Composer autoload so Database/Logger classes can be resolved when this file is loaded directly.
require_once __DIR__ . '/../vendor/autoload.php';

use Studiogau\Chandra\Database\Database;
use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Auth\AuthService;
use Noblestock\Logic\LogicConst;

/**
 * ロジッククラス（設定テーブル用）
 *
 * @author Studio GAU
 * @version 1.0
 */
class Config {

    private const TABLE_NAME = 'configs';

	private const CONFIG_LIST_SELECT_SQL = <<<SQL
SELECT config_key, value_int, value_str, config_description FROM configs
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
    public function update($data) {

        if (!is_array($data)) {
            $this->logger->error(
                __METHOD__
                . ' op=config.update msg="Invalid parameter: data" table=' . self::TABLE_NAME
            );
            throw new \InvalidArgumentException('Invalid parameter: data');
        }

        // 必須: config_key
        if (empty($data['config_key'])) {
            $this->logger->error(
                __METHOD__
                . ' op=config.update msg="Required parameter missing: config_key" table=' . self::TABLE_NAME
                . ' config_key=' . (($data['config_key'] ?? null) ?? '(missing)')
            );
            throw new \InvalidArgumentException('Required parameter missing: config_key');
        }

        // value_int / value_str はnullOK

        $values =     [
		    'value_int' => ['value' => $data["value_int"], 'datatype' => \PDO::PARAM_INT],
		    'value_str' => ['value' => $data["value_str"], 'datatype' => \PDO::PARAM_STR],
            // updated_at / updated_by は自動で追加される
        ];
        $conditions = [
		    'config_key' => ['value' => $data["config_key"], 'datatype' => \PDO::PARAM_STR],
        ];
        
        $affected = 0;
		
        $affected  = $this->database->update(self::TABLE_NAME, $values, $conditions);
        // 1行だけのはず
        if ($affected !== 1) {
            throw new \RuntimeException('Update affected rows mismatch (expected=1, actual=' . $affected . ')');
        }

        return $affected ;
    }
    
    /**
     * 一覧取得
     *
     * - ここでは例外を握らない（呼び出し元でまとめてログ）
     */
	public function list() {

		$sql = self::CONFIG_LIST_SELECT_SQL;
        return $this->database->fetchList($sql);
		
	}
}
