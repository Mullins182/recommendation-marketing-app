<?php
declare(strict_types=1);

namespace App;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;
use PDO;

class Mailer {
    private PHPMailer $mail;
    private PDO $pdo;
    private string $smtpDebugBuffer = '';

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->mail = new PHPMailer(true);
        $this->mail->isSMTP();
        $this->mail->Host       = $_ENV['MAIL_HOST'];
        $this->mail->Port       = (int)$_ENV['MAIL_PORT'];
        $this->mail->SMTPAuth   = true;
        $this->mail->Username   = $_ENV['MAIL_USERNAME'];
        $this->mail->Password   = $_ENV['MAIL_PASSWORD'];
        $this->mail->SMTPSecure = $_ENV['MAIL_ENCRYPTION'] ?? 'tls';

        // Fail-safe: From = Username, wenn From leer/abweichend
        $from = $_ENV['MAIL_FROM_ADDRESS'] ?? '';
        if (!$from || strcasecmp($from, $_ENV['MAIL_USERNAME']) !== 0) {
            $from = $_ENV['MAIL_USERNAME'];
        }
        $this->mail->setFrom($from, $_ENV['MAIL_FROM_NAME'] ?? 'No-Reply');

        $this->mail->isHTML(true);
        $this->mail->CharSet = 'UTF-8';

        // Optionales SMTP-Debug in APP_ENV=development
        if (($_ENV['APP_ENV'] ?? '') === 'development') {
            $this->mail->SMTPDebug = 2; // 0=aus, 2=kompakt
            $this->mail->Debugoutput = function($str, $level) {
                $this->smtpDebugBuffer .= "[$level] $str\n";
            };
        }
    }

    public function send(string $toEmail, string $subject, string $html, ?string $attachmentPath = null, ?string $attachmentName = null): bool {
        try {
            $this->smtpDebugBuffer = '';
            $this->mail->clearAllRecipients();
            $this->mail->clearAttachments();

            $this->mail->addAddress($toEmail);
            $this->mail->Subject = $subject;
            $this->mail->Body    = $html;

            if ($attachmentPath && is_file($attachmentPath)) {
                $this->mail->addAttachment($attachmentPath, $attachmentName ?? basename($attachmentPath));
            }

            $ok = $this->mail->send(); // bool
            $this->logMail($toEmail, $subject, (int)$ok, $ok ? null : ($this->smtpDebugBuffer ?: 'send() returned false'));
            return $ok;
        } catch (MailException $e) {
            $msg = $e->getMessage();
            if ($this->smtpDebugBuffer) $msg .= "\n" . $this->smtpDebugBuffer;
            $this->logMail($toEmail, $subject, 0, $msg);
            return false;
        }
    }

    private function logMail(string $to, string $subject, int $success, ?string $error): void {
    // user_id zum Empfänger ermitteln (normalize + HMAC wie in der Registrierung)
        $userId = null;
        $key = $_ENV['EMAIL_HASH_KEY'] ?? '';
        if ($key !== '') {
            $normalized = trim(strtolower($to));
            $fingerprint = hash_hmac('sha256', $normalized, $key);
            $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email_hash = ? LIMIT 1');
            $stmt->execute([$fingerprint]);
         $uid = $stmt->fetchColumn();
         if ($uid !== false) {
            $userId = (int)$uid;
            }
    }

        $stmt = $this->pdo->prepare(
            'INSERT INTO mail_log (to_user_id, subject, success, error) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $subject, $success, $error]);
    }
}