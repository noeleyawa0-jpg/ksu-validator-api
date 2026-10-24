<?php
// Student credential delivery is server-side; SMTP secrets never enter Flutter.
use PHPMailer\PHPMailer\PHPMailer;

function account_mailer(): PHPMailer {
    foreach ([__DIR__ . '/vendor/autoload.php', __DIR__ . '/../vendor/autoload.php'] as $autoload) {
        if (is_file($autoload)) { require_once $autoload; break; }
    }
    if (!class_exists(PHPMailer::class)) {
        throw new RuntimeException('Email dependency is not installed.');
    }
    foreach (['SMTP_HOST', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'SMTP_FROM_EMAIL'] as $key) {
        if (trim((string)getenv($key)) === '') throw new RuntimeException('Email sender is not configured.');
    }
    $from = (string)getenv('SMTP_FROM_EMAIL');
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid sender email configuration.');
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = (string)getenv('SMTP_HOST');
    $mail->SMTPAuth = true;
    $mail->Username = (string)getenv('SMTP_USERNAME');
    $mail->Password = (string)getenv('SMTP_PASSWORD');
    $encryption = strtolower((string)(getenv('SMTP_ENCRYPTION') ?: 'tls'));
    if (!in_array($encryption, ['tls', 'ssl'], true)) throw new RuntimeException('SMTP requires TLS or SSL.');
    $mail->SMTPSecure = $encryption === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = (int)(getenv('SMTP_PORT') ?: ($encryption === 'ssl' ? 465 : 587));
    $mail->Timeout = 10;
    $mail->Timelimit = 10;
    $mail->SMTPDebug = 0;
    $mail->CharSet = 'UTF-8';
    $mail->setFrom($from, (string)(getenv('SMTP_FROM_NAME') ?: 'KSU Subject Validator'));
    // Optional custom CA supports school-managed TLS certificates without disabling verification.
    if (getenv('SMTP_CA_FILE')) {
        $mail->SMTPOptions = ['ssl' => ['cafile' => getenv('SMTP_CA_FILE'), 'verify_peer' => true, 'verify_peer_name' => true]];
    }
    return $mail;
}

function prepare_account_email(string $email, string $name, string $username, string $password, string $role = 'student'): PHPMailer {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('A valid student email is required.');
    $mail = account_mailer();
    $mail->addAddress($email, $name);
    $mail->Subject = 'Your KSU Subject Validator login details';
    $mail->isHTML(false);
    $roleLabel = $role === 'chairperson' ? 'Chair' : 'Student';
    $mail->Body = "Hello {$name},\n\nYour KSU Subject Validator account has been created.\n\n"
        . "Username / Login ID: {$username}\nTemporary password: {$password}\n\n"
        . "Open KSU Subject Validator, select {$roleLabel}, and sign in using these details.\n"
        . "Keep your login details private.\n\nKSU Subject Validator";
    return $mail;
}
