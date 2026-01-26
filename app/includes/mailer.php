<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php'; // se qui hai config/env

/**
 * Invia email via SMTP (TurboSMTP) con PHPMailer.
 * Richiede: composer require phpmailer/phpmailer
 */
function send_mail_smtp(array $cfg, string $to, string $subject, string $textBody, ?string $htmlBody = null): bool
{
  // Lazy-load PHPMailer (composer)
  require_once __DIR__ . '/../../vendor/autoload.php';

  $mail = new PHPMailer\PHPMailer\PHPMailer(true);

  try {
    $mail->isSMTP();
    $mail->Host       = (string)$cfg['host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = (string)$cfg['username'];
    $mail->Password   = (string)$cfg['password'];
    $mail->Port       = (int)$cfg['port'];
    $mail->SMTPSecure = (string)$cfg['encryption']; // 'tls' o 'ssl'

    $fromEmail = (string)$cfg['from_email'];
    $fromName  = (string)($cfg['from_name'] ?? 'EasyRace');

    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress($to);

    $mail->Subject = $subject;

    if ($htmlBody !== null && $htmlBody !== '') {
      $mail->isHTML(true);
      $mail->Body    = $htmlBody;
      $mail->AltBody = $textBody;
    } else {
      $mail->isHTML(false);
      $mail->Body = $textBody;
    }

    return $mail->send();
  } catch (Throwable $e) {
    // se hai un logger, logga qui
    return false;
  }
}
