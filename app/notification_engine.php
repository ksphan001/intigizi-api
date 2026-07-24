<?php
// File: app/notification_engine.php
// PENJELASAN: Diperbarui untuk menggunakan URL logo absolut untuk memastikan gambar selalu tampil di email.

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/vendor/autoload.php';

function send_direct_email($recipient_email, $recipient_name, $title, $message) {
    if (empty($recipient_email)) {
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $_ENV['MAIL_HOST'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['MAIL_USERNAME'];
        $mail->Password   = $_ENV['MAIL_PASSWORD'];
        $mail->SMTPSecure = $_ENV['MAIL_ENCRYPTION'];
        $mail->Port       = $_ENV['MAIL_PORT'];
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($_ENV['MAIL_FROM_ADDRESS'], 'GIZINOW PLATFORM');
        $mail->addAddress($recipient_email, $recipient_name);

        $mail->isHTML(true);
        $mail->Subject = "GIZINOW PLATFORM - " . $title;
        
        // --- PERBAIKAN: Menggunakan URL absolut yang sudah disediakan ---
        $logo_url = 'https://mbg.taskora.id/uploads/gizinow-logo.png';

        $email_body = <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>$title</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 20px;">
    <table align="center" border="0" cellpadding="0" cellspacing="0" width="600" style="border-collapse: collapse; background-color: #ffffff; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.1);">
        <tr>
            <td align="center" style="padding: 20px 0; border-bottom: 1px solid #eeeeee;">
                <img src="$logo_url" alt="GiziNow Logo" style="height: 40px; display: block;">
            </td>
        </tr>
        <tr>
            <td style="padding: 40px 30px;">
                <h1 style="color: #1A335A; font-size: 24px;">$title</h1>
                <p style="color: #555555; font-size: 16px; line-height: 1.5;">
                    Yth. <strong>$recipient_name</strong>,
                </p>
                <div style="color: #555555; font-size: 16px; line-height: 1.5;">
                    $message
                </div>
                <p style="color: #555555; font-size: 16px; line-height: 1.5; margin-top: 30px;">
                    Terima kasih,<br>
                    <strong>Tim GiziNow</strong>
                </p>
            </td>
        </tr>
        <tr>
            <td align="center" style="padding: 20px 30px; background-color: #f9f9f9; border-top: 1px solid #eeeeee;">
                <p style="color: #999999; font-size: 12px; margin: 0;">
                    Ini adalah email otomatis. Mohon untuk tidak membalas email ini.
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

        $mail->Body = $email_body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer Direct Send Error: {$mail->ErrorInfo}");
        return false;
    }
}

function send_notification($conn, $org_id, $user_id, $title, $message, $link = null) {
    if ($link && substr($link, 0, 1) === '/' && substr($link, 0, 5) !== '/app/') {
        $link = '/app' . $link;
    }

    $sql_insert = "INSERT INTO notifications (organization_id, user_id, title, message, link) VALUES (?, ?, ?, ?, ?)";
    $stmt_insert = $conn->prepare($sql_insert);
    $stmt_insert->bind_param("iisss", $org_id, $user_id, $title, $message, $link);
    $stmt_insert->execute();
    $stmt_insert->close();

    $sql_user = "SELECT full_name, email FROM users WHERE id = ? AND (organization_id = ? OR organization_id IS NULL) LIMIT 1";
    $stmt_user = $conn->prepare($sql_user);
    $stmt_user->bind_param("ii", $user_id, $org_id);
    $stmt_user->execute();
    $user_result = $stmt_user->get_result();
    
    if ($user_result->num_rows > 0) {
        $user = $user_result->fetch_assoc();
        
        $email_message = $message;
        if ($link) {
            $allowed_origins_list = explode(',', $_ENV['ALLOWED_ORIGINS'] ?? '');
            $full_link = rtrim($allowed_origins_list[0], '/') . $link;
            $email_message .= "<br><br>Anda bisa melihat detailnya dengan mengklik tautan berikut: <a href='{$full_link}'>Lihat Detail</a>";
        }
        send_direct_email($user['email'], $user['full_name'], $title, $email_message);
    }
    $stmt_user->close();
}

