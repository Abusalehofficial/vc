<?php

$title .= $languageArray["account.title"];

userSecureHeader();

 

$api_key = $user["apikey"];
if ($settings["user_api_key"] == 2) {
  $api_key = preg_replace("/(^.|.$)(*SKIP)(*F)|(.)/", "*", $api_key);
}




if (route(1) == "newapikey") {
  $conn->beginTransaction();
  $insert = $conn->prepare("INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ");
  $insert = $insert->execute(array("c_id" => $user["client_id"], "action" => "API Key changed", "ip" => GetIP(), "date" => date("Y-m-d H:i:s")));
  $apikey = CreateApiKey(["email" => $user["email"], "username" => $user["username"]]);
  $update = $conn->prepare("UPDATE clients SET apikey=:key WHERE client_id=:id ");
  $update = $update->execute(array("id" => $user["client_id"], "key" => $apikey));
  if ($update && $insert) :
    $conn->commit();
    $success    = 1;
    $successText = "New API key : $apikey";
    $api_key = $apikey;
    if ($settings["user_api_key"] == 2) {
      $api_key = preg_replace("/(^.|.$)(*SKIP)(*F)|(.)/", "*", $api_key);
    } else {
    }
  else :
    $conn->rollBack();
    $error    = 1;
    $errorText = "Unsuccessfull Attempt , Try again later!";
  endif;
}


elseif( route(1) == "change_email" && $_POST ){
    $new_email = filter_var($_POST['new_email'], FILTER_VALIDATE_EMAIL); // Validate email format

    if (!$new_email) {
        // Invalid email format
        $error = 1;
        $errorText = "Invalid email address provided.";
    } else {
        // Check if email already exists in the database
        $check = $conn->prepare("SELECT COUNT(*) FROM clients WHERE email=:email");
        $check->execute(['email' => $new_email]);
        if ($check->fetchColumn() > 0) {
            // Email already in use
            $error = 1;
            $errorText = "This email is already in use.";
        } else {
            // Get old email before changing
            $old_email = $user["email"];
            
            // Update email in the database
            $update = $conn->prepare("UPDATE clients SET email=:email WHERE client_id=:id");
            $update_success = $update->execute(['email' => $new_email, 'id' => $user["client_id"]]);

            if ($update_success) {
                // Log the change in client report
                $log = $conn->prepare("INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date");
                $log->execute([
                    "c_id" => $user["client_id"],
                    "action" => "User email has been changed to $new_email",
                    "ip" => GetIP(),
                    "date" => date("Y-m-d H:i:s")
                ]);

                // Send a notification email to the new email address
                $site_name = $settings["site_name"];
                $from = $settings["smtp_user"];
                $fromName = $settings["site_seo"];
                $subject = "Your Email Address Has Been Updated";
                $htmlContent = "
                    <p>Dear {$user['username']},</p>
                    <p>This is to inform you that your email address associated with your account has been successfully updated.</p>
                    <p>If you made this change, no further action is required.</p>
                    <p>If you did not make this change, please contact our support team immediately to secure your account.</p>
                    <p>Thank you for using {$site_name}.</p>
                    <p>Best regards,<br>{$site_name} Team</p>";

                $headers = "MIME-Version: 1.0" . "\r\n";
                $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
                $headers .= "From: {$fromName} <{$from}>" . "\r\n";

                if (!mail($new_email, $subject, $htmlContent, $headers)) {
                    // Failed to send email to the new address
                    $error = 1;
                    $errorText = "Email change was successful, but we were unable to send the notification to your new email.";
                }

                // Send a notification email to the old email address
                $subjectOld = "Important: Your Email Address Has Been Changed";
                $htmlContentOld = "
                    <p>Dear {$user['username']},</p>
                    <p>This is to inform you that the email address associated with your account has been updated to a new email address.</p>
                    <p>If you made this change, no further action is required.</p>
                    <p>If you did not make this change, please contact our support team immediately to secure your account.</p>
                    <p>Thank you for using {$site_name}.</p>
                    <p>Best regards,<br>{$site_name} Team</p>";

                $headersOld = "MIME-Version: 1.0" . "\r\n";
                $headersOld .= "Content-type:text/html;charset=UTF-8" . "\r\n";
                $headersOld .= "From: {$fromName} <{$from}>" . "\r\n";

                if (mail($old_email, $subjectOld, $htmlContentOld, $headersOld)) {
                    // Successfully sent to old email
                    $success = 1;
                    $successText = "Your email has been updated successfully, and a notification has been sent to both your new and old email addresses.";
                } else {
                    // Failed to send email to the old address
                    $success = 1; // Email change was successful
                    $successText = "Your email has been updated successfully, but we were unable to send a notification to your old email.";
                }

            } else {
                // Database update failed
                $error = 1;
                $errorText = "Unable to update email. Please try again later.";
            }
        }
    }
}

elseif (route(1) == "change_lang" && $_POST) {
  $lang     = $_POST["lang"];
  $update = $conn->prepare("UPDATE clients SET lang=:lang WHERE client_id=:id ");
  $update = $update->execute(array("id" => $user["client_id"], "lang" => $lang));
  header("Location:" . site_url('account'));
} elseif (route(1) == "timezone" && $_POST) {
  $timezone = $_POST["timezone"];
  $update   = $conn->prepare("UPDATE clients SET timezone=:timezone WHERE client_id=:id ");
  $update   = $update->execute(array("id" => $user["client_id"], "timezone" => $timezone));
  header("Location:" . site_url('account'));
} elseif (route(0) == "account" && $_POST) {

  $pass     = $_POST["current_password"];
  $new_pass = $_POST["password"];
  $new_again = $_POST["confirm_password"];

  if (!userdata_check('password', md5(sha1(md5($pass))))) {
    $error    = 1;
    $errorText = $languageArray["error.account.password.notmach"];
  } elseif (strlen($new_pass) < 8) {
    $error    = 1;
    $errorText = $languageArray["error.account.password.length"];
  } elseif ($new_pass != $new_again) {
    $error    = 1;
    $errorText = $languageArray["error.account.passwords.notmach"];
  } else {
    $conn->beginTransaction();
    $insert = $conn->prepare("INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ");
    $insert = $insert->execute(array("c_id" => $user["client_id"], "action" => "User password has been changed from accounts page.", "ip" => GetIP(), "date" => date("Y-m-d H:i:s")));
    $update = $conn->prepare("UPDATE clients SET password=:pass WHERE client_id=:id ");
    $update = $update->execute(array("id" => $user["client_id"], "pass" => md5(sha1(md5($new_pass)))));
    if ($update && $insert) :
      $_SESSION["msmbilisim_userpass"]       = md5(sha1(md5($new_pass)));


      $conn->commit();
      $success    = 1;
      $successText = $languageArray["error.account.password.success"];

    else :
      $conn->rollBack();
      $error    = 1;
      $errorText = $languageArray["error.account.password.fail"];
    endif;
  }
}
