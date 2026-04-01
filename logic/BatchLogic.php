<?php
namespace Noblestock\Logic;

require_once(__DIR__ . '/../vendor/autoload.php');

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use Noblestock\DbLogic\Product;
use Noblestock\DbLogic\User;
use Studiogau\Chandra\Config\MailConfig;
use Studiogau\Chandra\Database\RecordNotFoundException;
use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Support\Utility;

/**
 * 在庫数閾値通知メール送信バッチ.
 * @author Studio GAU
 */
final class BatchLogic
{
    private Logger $logger;

    /**
     * ロガー初期化.
     */
    public function __construct(?Logger $logger = null)
    {
        $this->logger = $logger ?? Logger::createDefault(dirname(__DIR__, 1));
    }

    /**
     * 在庫数閾値通知メールを送信する.
     */
    public static function run(string $managementNo): void
    {
        // バッチ停止設定時は何もしない
        if (!LogicConst::BATCH_SW) {
            return;
        }

        $logic = new self();
        $filename = basename(__FILE__, '.php');
        $logic->logger->info($filename . " 起動 引数：" . $managementNo);

        try {
            // 管理番号単位で通知メール送信を実行する
            $logic->sendLowStockMail($managementNo);
        } catch (\Throwable $e) {
            // バッチ失敗は呼び出し元へ投げず fatal ログへ集約する
            $logic->logger->fatal(MessageConst::MSG_SYS_COMMON_900 . ": " . $filename . ": " . $e->getMessage());
        }
    }

    /**
     * 在庫数閾値通知メール送信.
     */
    private function sendLowStockMail(string $managementNo): void
    {
        $filename = basename(__FILE__, '.php');

        // 管理番号必須
        if ($managementNo === '') {
            $this->logger->error(__METHOD__ . ' required parameter missing. management_no:' . $managementNo);
            throw new \InvalidArgumentException('required parameter missing (management_no)');
        }

        // 商品情報取得
        $product = new Product();
        try {
            $productData = $product->select($managementNo);
        } catch (RecordNotFoundException $e) {
            throw new RecordNotFoundException($filename . ": 商品情報なし 管理番号：" . $managementNo . " " . $e->getMessage());
        }

        // 通知先となるユーザーのメールアドレス一覧取得
        $user = new User();
        $retuser = $user->list();

        $mailTo = "";
        foreach ($retuser as $wk) {
            if (filter_var($wk["email"], FILTER_VALIDATE_EMAIL)) {
                $mailTo = $mailTo . "," . $wk["email"];
            }
        }
        if ($mailTo === "") {
            throw new RecordNotFoundException(
                $filename . ": メールアドレスなし 管理番号：" . $productData['management_no'] . " 在庫数：" . $productData['quantity']
            );
        }

        $mailTo = substr($mailTo, 1);

        // メール停止設定時はここで終了
        if (!LogicConst::MAIL_SW) {
            return;
        }

        $mailConfig = MailConfig::fromConfiguredSource(dirname(__DIR__, 1) . LogicConst::MAIL_CONFIG_PATH);

        $mail = new PHPMailer(true);
        try {
            // SMTP 設定
            $mail->CharSet = 'UTF-8';
            $mail->Encoding = 'base64';

            $mail->isSMTP();
            $mail->Host = $mailConfig->getSmtpHost();
            $mail->Port = $mailConfig->getSmtpPort();
            $mail->SMTPAuth = $mailConfig->isSmtpAuth();
            $mail->Username = $mailConfig->getSmtpUser();
            $mail->Password = $mailConfig->getSmtpPass();
            $mail->SMTPSecure = $mailConfig->getSmtpSecure();

            // 送信先設定
            $mail->setFrom($mailConfig->getMailFrom(), '在庫通知システム');
            $mail->addAddress($mailTo);

            // 件名・本文組み立て
            $mail->Subject = LogicConst::MAIL_SUB;
            $body = Utility::replaceStr(
                LogicConst::MAIL_MSG,
                $productData['management_no'],
                $productData['category_name'],
                $productData['maker_name'],
                $productData['product_name'],
                $productData['quantity']
            ) . LogicConst::MAIL_FTR;

            $mail->Body = $body;

            if (LogicConst::MAIL_PRESEND) {
                // ★ 送信せず MIME だけ生成してログへ出す
                $mail->preSend();
                // ★ 送信されるメールの中身を確認出力
                $rawMail = $mail->getSentMIMEMessage();
                $this->logger->debug($rawMail);
            } else {
                // 実送信
                $mail->send();
            }

            $this->logger->info(
                $filename . ": メール送信成功 メールアドレス：" . $mailTo
                . " 管理番号：" . $productData['management_no']
                . " 在庫数：" . $productData['quantity']
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                $filename . ": 低在庫メール送信失敗 管理番号："
                . $productData['management_no']
                . " 在庫数：" . $productData['quantity']
                . " " . $e->getMessage()
            );
        }
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    // 直接 CLI 実行された場合だけ、従来どおりバッチ入口として動作させる
    date_default_timezone_set('Asia/Tokyo');
    set_time_limit(1800);

    $managementNo = (string)($_SERVER['argv'][1] ?? '');
    BatchLogic::run($managementNo);
}
?>
