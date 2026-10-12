<?php
declare(strict_types=1);

// PHPMailer (vendored under config/PHPMailer/src)
require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

function sendMail(string $to, string $name, string $subject, string $htmlBody): bool
{
    $smtpHost = defined('SMTP_HOST') ? SMTP_HOST : (getenv('GILDED_SPOON_SMTP_HOST') ?: 'smtp.gmail.com');
    $smtpPort = (int)(defined('SMTP_PORT') ? SMTP_PORT : (getenv('GILDED_SPOON_SMTP_PORT') ?: 587));
    $smtpUser = defined('SMTP_USER') ? SMTP_USER : (getenv('GILDED_SPOON_SMTP_USER') ?: '');
    $smtpPassword = defined('SMTP_PASSWORD') ? SMTP_PASSWORD : (getenv('GILDED_SPOON_SMTP_PASSWORD') ?: '');
    $smtpSecure = strtolower((string)(defined('SMTP_SECURE') ? SMTP_SECURE : (getenv('GILDED_SPOON_SMTP_SECURE') ?: 'tls')));

    // From details shown to recipients
    $smtpFromEmail = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : (getenv('GILDED_SPOON_SMTP_FROM_EMAIL') ?: $smtpUser);
    $smtpFromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : (getenv('GILDED_SPOON_SMTP_FROM_NAME') ?: 'La Elena Fishyalan Restaurant');

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUser;
        $mail->Password = $smtpPassword;
        $mail->SMTPSecure = $smtpSecure === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $smtpPort;

        $mail->SMTPDebug = 0;
        $mail->Timeout = 15;

        $mail->setFrom($smtpFromEmail, $smtpFromName);
        $mail->addAddress($to, $name);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);

        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('Mail send failed: ' . $e->getMessage());
        return false;
    }
}

?>

