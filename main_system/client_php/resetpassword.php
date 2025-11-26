<?php
$title .= $languageArray["resetpassword.title"];

if ($_SESSION["msmbilisim_userlogin"] == 1 || $username["client_type"] == 1 || $settings["resetpass_page"] == 1) {
    Header("Location:" . site_url());
    exit;
}

$resetType = array();
if ($settings["resetpass_sms"] == 2) :
    $resetType[] = ["type" => "sms", "name" => $languageArray["resetpassword.type.sms"]];
endif;
if ($settings["resetpass_email"] == 2) :
    $resetType[] = ["type" => "email", "name" => $languageArray["resetpassword.type.email"]];
endif;

if ($_POST) :
    $captcha = $_POST['g-recaptcha-response'] ?? '';
    $googlesecret = $settings["recaptcha_secret"];
    $captcha_control = file_get_contents("https://www.google.com/recaptcha/api/siteverify?secret=$googlesecret&response=" . $captcha . "&remoteip=" . $_SERVER['REMOTE_ADDR']);
    $captcha_control = json_decode($captcha_control);
    
    $username = trim($_POST["user"] ?? '');
    $type = "email";
    
    $userData = $conn->prepare("SELECT * FROM clients WHERE username = :username OR email = :email");
    $userData->execute(array("username" => $username, "email" => $username));
    
    if (empty($username)) :
        $error = 1;
        $errorText = $languageArray["error.resetpassword.user.empty"];
    elseif (!$userData->rowCount()) :
        $error = 1;
        $errorText = $languageArray["error.resetpassword.user.notmatch"];
    elseif ($settings["recaptcha"] == 2 && $captcha_control->success == false) :
        $error = 1;
        $errorText = $languageArray["error.resetpassword.recaptcha"];
    else :
        $userData = $userData->fetch(PDO::FETCH_ASSOC);
        
        // Generate secure random password
        $comb = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
        $pass = array();
        $combLen = strlen($comb) - 1;
        for ($i = 0; $i < 8; $i++) {
            $n = rand(0, $combLen);
            $pass[] = $comb[$n];
        }
        $pass = implode($pass);
        
        // Update password
        $update = $conn->prepare("UPDATE clients SET password = :pass WHERE client_id = :id");
        $update->execute(array(
            "id" => $userData["client_id"], 
            "pass" => md5(sha1(md5($pass)))
        ));
        
        // Log action
        $main_report = "A new password is requested by " . $userData["username"] . " from forget-password page.";
        $insert2 = $conn->prepare("INSERT INTO client_report SET client_id = :c_id, report_type = :report_type, action = :action, report_ip = :ip, report_date = :date");
        $insert2->execute(array(
            "c_id" => $userData["client_id"], 
            "report_type" => 3, 
            "action" => $main_report, 
            "ip" => GetIP(), 
            "date" => date("Y-m-d H:i:s")
        ));
        
        // ============================================
        // SEND EMAIL USING PHP mail() FUNCTION
        // ============================================
        
        $to = $userData["email"];
        $subject = "Reset Password - " . $settings['site_name'];
        
        // HTML Email Body
        $message = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; background: #f9f9f9; }
                .content { background: white; padding: 30px; border-radius: 10px; }
                .header { text-align: center; margin-bottom: 20px; }
                .password-box { background: #f0f0f0; padding: 15px; border-radius: 5px; text-align: center; margin: 20px 0; }
                .password { font-size: 24px; font-weight: bold; color: #3b82f6; letter-spacing: 2px; }
                .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='content'>
                    <div class='header'>
                        <h2 style='color: #3b82f6;'>Password Reset Request</h2>
                    </div>
                    <p>Hello <strong>" . htmlspecialchars($userData['username']) . "</strong>,</p>
                    <p>You requested a password reset for your " . htmlspecialchars($settings['site_name']) . " account.</p>
                    <div class='password-box'>
                        <p style='margin: 0; font-size: 14px; color: #666;'>Your New Password:</p>
                        <p class='password'>" . htmlspecialchars($pass) . "</p>
                    </div>
                    <p><strong>Important:</strong> Please use this password to log in, then change it immediately to something you can remember.</p>
                    <p>If you did not request this password reset, please contact our support team immediately.</p>
                    <p>Best Regards,<br><strong>" . htmlspecialchars($settings['site_name']) . " Support Team</strong></p>
                </div>
                <div class='footer'>
                    <p>This is an automated email. Please do not reply to this message.</p>
                    <p>&copy; " . date('Y') . " " . htmlspecialchars($settings['site_name']) . ". All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        // Plain text alternative
        $plain_message = "Hello " . $userData['username'] . ",\n\n";
        $plain_message .= "You requested a password reset for your " . $settings['site_name'] . " account.\n\n";
        $plain_message .= "Your new password is: " . $pass . "\n\n";
        $plain_message .= "Please use it once and then change it to what you can remember.\n\n";
        $plain_message .= "Best Regards,\n";
        $plain_message .= $settings['site_name'] . " Support Team";
        
        // Email headers
        $headers = array();
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "Content-Type: text/html; charset=UTF-8";
        $headers[] = "From: " . ($settings['site_name'] ?? 'No Reply') . " <noreply@" . $_SERVER['HTTP_HOST'] . ">";
        $headers[] = "Reply-To: noreply@" . $_SERVER['HTTP_HOST'];
        $headers[] = "X-Mailer: PHP/" . phpversion();
        $headers[] = "X-Priority: 1"; // High priority
        
        // Send email
        $send = mail($to, $subject, $message, implode("\r\n", $headers));
        
        // Log email attempt
        if ($send) {
            error_log("Password reset email sent to: " . $to);
        } else {
            error_log("Failed to send password reset email to: " . $to);
        }
        
        if ($send) :
            $success = 1;
            $successText = $languageArray["error.resetpassword.success"];
            echo '<script>setTimeout(function(){window.location="' . site_url() . '"},2000)</script>';
        else :
            $error = 1;
            $errorText = $languageArray["error.resetpassword.fail"];
        endif;
    endif;
endif;
?>