<?php

if (!route(1)) {
  $route[1] = "signup";
}



$title .= $languageArray["signup.title"];

userSecureHeader();



if ($_SESSION["msmbilisim_userlogin"] == 1  || $user["client_type"] == 1 || $settings["register_page"] == 1) {
  Header("Location:" . site_url());
} elseif ($route[1] == "signup" && $_POST) {
  foreach ($_POST as $key => $value) {
    $_SESSION["data"][$key]  = $value;
  }

  $name           = $_POST["name"];
  $name = strip_tags($name);
  $name = filter_var($name, FILTER_SANITIZE_STRING);
  $email          = $_POST["email"];
  $email = strip_tags($email);
  $email = filter_var($email, FILTER_SANITIZE_EMAIL);
  $username       = $_POST["username"];
  $username = strip_tags($username);
  $username = filter_var($username, FILTER_SANITIZE_STRING);
  $phone          = $_POST["telephone"];
  $phone = strip_tags($phone);
  $pass           = $_POST["password"];
  $pass_again     = $_POST["password_again"];
  $terms          = $_POST["terms"];
  $captcha        = $_POST['g-recaptcha-response'];
  $googlesecret   = $settings["recaptcha_secret"];
   
 
  if (!empty($googlesecret) && $settings["recaptcha"] == 2) {
    $captcha_control = file_get_contents("https://www.google.com/recaptcha/api/siteverify?secret=$googlesecret&response=" . $captcha . "&remoteip=" . $_SERVER['REMOTE_ADDR']);
    $captcha_control = json_decode($captcha_control);
}


  $ref_code =  substr(bin2hex(random_bytes(18)), 5, 6);

  $email_verification_code =  bin2hex(random_bytes(18));


  if ($captcha && $settings["recaptcha"] == 2 && $captcha_control->success == false) {
    $error      = 1;
    $errorText  = $languageArray["error.signup.recaptcha"];
  } elseif (empty($name) && $settings["register_name"] == 2) {
    $error      = 1;
    $errorText  = $languageArray["error.signup.name"];
  } elseif (!email_check($email)) {
    $error      = 1;
    $errorText  = $languageArray["error.signup.email"];
  } elseif (userdata_check("email", $email)) {
    $error      = 1;
    $errorText  = $languageArray["error.signup.email.used"];
  } elseif (!username_check($username)) {
    $error      = 1;
    $errorText  = $languageArray["error.signup.username"];
  } elseif (userdata_check("username", $username)) {
    $error      = 1;
    $errorText  = $languageArray["error.signup.username.used"];
  } elseif (empty($phone) && $settings["register_whatsapp"] == 2) {
    $error      = 1;
    $errorText  = $languageArray["error.signup.telephone"];
  } elseif (userdata_check("telephone", $phone) && $settings["register_whatsapp"] == 2) {
    $error      = 1;
    $errorText  = $languageArray["error.signup.telephone.used"];
  } elseif (strlen($pass) < 8) {
    $error      = 1;
    $errorText  = $languageArray["error.signup.password"];
  } elseif ($pass != $pass_again) {
    $error      = 1;
    $errorText  = $languageArray["error.signup.password.notmatch"];
  } elseif (!$terms) {
    $error      = 1;
    $errorText  = $languageArray["error.signup.terms"];
  } else {
    $apikey = CreateApiKey($_POST);
    $pref_curr = $_SESSION["guest_pref_curr"];
    $timezone = empty($settings["default_timezone"]) ? 9000 : $settings["default_timezone"];

    if (empty($pref_curr)) {
      $pref_curr = $settings["site_currency"];
    }

    $allowed_payment_methods = $conn->prepare("SELECT id FROM payment_methods WHERE allow_new_users=:allow_new_users");
    $allowed_payment_methods->execute(array("allow_new_users" => 2));
    $allowed_payment_methods  = $allowed_payment_methods->fetchAll(PDO::FETCH_ASSOC);

 
    $allowed_arrray = array();
    foreach ($allowed_payment_methods as $key => $value) {
      $allowed_arrray[] =  $value["id"];
    }

    $balance = 0;

    if ($settings["free_balance"] == 2) {
      $balance = $settings["free_amount"];
    }


    $allowed_arrray = implode(",", $allowed_arrray);

    $conn->beginTransaction();

    $insert = $conn->prepare("INSERT INTO clients SET name=:name, username=:username, email=:email, password=:pass, lang=:lang, telephone=:phone, register_date=:date, apikey=:key , balance=:balance , login_date=:login_date , login_ip=:login_ip , ref_code=:ref_code , user_pref_curr=:pref_curr , email_verification_code=:email_verification_code , allowed_payments_methods=:allowed_payments_methods , timezone=:timezone");

    $insert = $insert->execute(array("lang" => $selectedLang, "name" => $name, "username" => $username, "email" => $email, "pass" => md5(sha1(md5($pass))), "phone" => $phone, "date" => date("Y.m.d H:i:s"), "balance" => $balance, 'login_date' => date("Y.m.d H:i:s"), 'login_ip' => GetIP(), 'key' => $apikey, "ref_code" => $ref_code, "pref_curr" => $pref_curr, "email_verification_code" => $email_verification_code, "allowed_payments_methods" => $allowed_arrray, "timezone" => $timezone));
    
    

    if ($insert) : $client_id = $conn->lastInsertId();
    endif;
    $insert2 = $conn->prepare("INSERT INTO client_report SET client_id=:c_id, report_type=:report_type ,  action=:action, report_ip=:ip, report_date=:date ");
    $insert2 = $insert2->execute(array("c_id" => $client_id, "report_type" => 3, "action" => "
    User registered.", "ip" => GetIP(), "date" => date("Y-m-d H:i:s")));
// ✅ REPLACE REFERRAL BLOCK IN signup.php (after user insert)

if (!empty($_COOKIE['ref'])) {
    $refCode = trim((string)$_COOKIE['ref']);
    
    // Validate ref_code exists
    $refCheck = $conn->prepare("SELECT client_id FROM clients WHERE ref_code = :ref LIMIT 1");
    $refCheck->execute([':ref' => $refCode]);
    $refRow = $refCheck->fetch(PDO::FETCH_ASSOC);
    
    if ($refRow) {
        // Set referred_by for new user
        $updRef = $conn->prepare("UPDATE clients SET referred_by = :ref WHERE client_id = :cid");
        $updRef->execute([':ref' => $refCode, ':cid' => $client_id]);
        
        // Update affiliate stats
        $updStats = $conn->prepare("
            UPDATE affiliate_stats 
               SET total_signups = total_signups + 1,
                   updated_at = NOW()
             WHERE ref_code = :ref
        ");
        $updStats->execute([':ref' => $refCode]);
    }
}
    

    if ($settings["free_balance"] == 2) {
      $insert = $conn->prepare("INSERT INTO payments SET client_id=:client_id, client_balance=:client_balance, payment_amount=:payment_amount, payment_method=:payment_method, payment_status=:payment_status, payment_delivery=:payment_delivery, payment_mode=:payment_mode , payment_create_date=:payment_create_date , payment_ip=:payment_ip , bonus=:bonus");
      $insert = $insert->execute(array("client_id" => $client_id, "client_balance" => 0, "payment_amount" => $balance, "payment_method" => 36, "payment_status" => 3, "payment_delivery" => 2, "payment_mode" => "Otomatik", 'payment_create_date' => date("Y.m.d H:i:s"), 'payment_ip' => GetIP(), "bonus" => 1));

      $insert2 = $conn->prepare("INSERT INTO client_report SET client_id=:c_id, report_type=:report_type ,  action=:action, report_ip=:ip, report_date=:date ");
      $insert2 = $insert2->execute(array("c_id" => $client_id, "report_type" => 5, "action" => "
      Free funds added of amount $balance.", "ip" => GetIP(), "date" => date("Y-m-d H:i:s")));
    }



    if ($insert && $insert2) :
      $conn->commit();
      unset($_SESSION["data"]);
      $success    = 1;
      $successText = $languageArray["error.signup.success"];



      if ($_COOKIE['ref']) {
        $ref_by = $_COOKIE['ref'];
        $insert12 = $conn->prepare("UPDATE clients SET ref_by=:ref_by WHERE client_id=:c_id");
        $insert12->execute(array("c_id" => $client_id, "ref_by" => $ref_by));






        if (countRow(['table' => 'referral', 'where' => ['referral_code' => $ref_by]])) {

          $select = $conn->prepare("SELECT * FROM referral WHERE referral_code=:referral_code");
          $select->execute(array("referral_code" => $ref_by));
          $select  = $select->fetch(PDO::FETCH_ASSOC);



          //update signup value
          $update = $conn->prepare("UPDATE referral SET referral_sign_up=:referral_sign_up WHERE referral_code=:referral_code");
          $update = $update->execute(array("referral_code" => $ref_by, "referral_sign_up" => $select["referral_sign_up"] + 1));
        } else {
          //insert

          $clients  = $conn->prepare("SELECT * FROM clients WHERE ref_code=:ref_code ");
          $clients->execute(array("ref_code" => $ref_by));
          $clients  = $clients->fetch(PDO::FETCH_ASSOC);


          $insert = $conn->prepare("INSERT INTO referral SET referral_code=:referral_code");
          $insert->execute(array("referral_code" => $ref_by));

          $update = $conn->prepare("UPDATE referral SET referral_client_id=:referral_client_id , referral_sign_up=:referral_sign_up WHERE referral_code=:referral_code");
          $update = $update->execute(array("referral_client_id" => $clients["client_id"],  "referral_code" => $ref_by, "referral_sign_up" => 1));
        }
      }


      //send verification link if its on

      if ($settings["email_confirmation"] == 2) {


        $template = $settings["verification_email_temp"];
        $template = json_decode($template, true);

        $emailResponse = sendEmailWithTemplate($template["subject"], $template["message"], $email);
      }


      //Login after signup

      $row    = $conn->prepare("SELECT * FROM clients WHERE username=:username && password=:password ");
      $row->execute(array("username" => $username, "password" => md5(sha1(md5($pass)))));
      $row    = $row->fetch(PDO::FETCH_ASSOC);




      $_SESSION["otp_login"] = true; // in general case

      if ($settings["otp_login"] == 2) { // otp is neccesary to login

        $last_login_ip = $row["login_ip"];

        if ($last_login_ip != GetIP()) { // login from different ip
          $_SESSION["otp_login"] = false;
          loginOtp($row);
        }
      }


      $_SESSION["msmbilisim_userlogin"]      = 1;
      $_SESSION["msmbilisim_userid"]         = $row["client_id"];
      $_SESSION["msmbilisim_userpass"]       = md5(sha1(md5($pass)));
      $_SESSION["recaptcha"]                = false;
      $user = $row;
      setUserCookies($row);


      if ($settings["alert_welcomemail"] == 2) {

        $template = $settings["welcome_email_temp"];
        $template = json_decode($template, true);
        $email = $row["email"];
        sendEmailWithTemplate($template["subject"], $template["message"], $email);
      }



      header('Location:' . site_url(''));




    else :
      $conn->rollBack();
      $error      = 1;
      $errorText  = $languageArray["error.signup.fail"];
    endif;
  }
}
