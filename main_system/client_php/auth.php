<?php

if (!route(1)) {
    $route[1] = "login";
}

$title .= $settings["site_seo"];

userSecureHeader();

if ($route[1] == "login" && $_POST) {

    $username       = $_POST["username"];
    $username = strip_tags($username);
    $username = filter_var($username, FILTER_SANITIZE_STRING);
    $pass           = $_POST["password"];
    $captcha        = $_POST['g-recaptcha-response'];
    $remember       = $_POST["remember"];
    $googlesecret   = $settings["recaptcha_secret"];
 
    if (!empty($googlesecret) && $settings["recaptcha"] == 2) {
        $captcha_control = file_get_contents("https://www.google.com/recaptcha/api/siteverify?secret=$googlesecret&response=" . $captcha . "&remoteip=" . $_SERVER['REMOTE_ADDR']);
        $captcha_control = json_decode($captcha_control);
    }





    if ($settings["recaptcha"] == 2 && $captcha_control->success == false && $_SESSION["recaptcha"]) {
        $error      = 1;
        $errorText  = $languageArray["error.signin.recaptcha"];
        if ($settings["recaptcha"] == 2) {
            $_SESSION["recaptcha"]  = true;
        }
    } elseif (!userdata_check("username", $username) && !userdata_check("email", $username)) {

        $error      = 1;
        $errorText  = $languageArray["error.signin.username"];
        if ($settings["recaptcha"] == 2) {
            $_SESSION["recaptcha"]  = true;
        }
    } elseif (!userlogin_check($username, $pass)) {
        $error      = 1;
        $errorText  = $languageArray["error.signin.notmatch"];
        if ($settings["recaptcha"] == 2) {
            $_SESSION["recaptcha"]  = true;
        }
    } elseif (countRow(["table" => "clients", "where" => ["username" => $username, "client_type" => 1]])) {
        $error      = 1;
        $errorText  = $languageArray["error.signin.deactive"];
        if ($settings["recaptcha"] == 2) {
            $_SESSION["recaptcha"]  = true;
        }
    } elseif (countRow(["table" => "clients", "where" => ["email" => $username, "client_type" => 1]])) {
        $error      = 1;
        $errorText  = $languageArray["error.signin.deactive"];
        if ($settings["recaptcha"] == 2) {
            $_SESSION["recaptcha"]  = true;
        }
    } else {




        if (strpos($username, "@") !== false) {
            // email
            $row    = $conn->prepare("SELECT * FROM clients WHERE email=:username && password=:password ");
            $row->execute(array("username" => $username, "password" => md5(sha1(md5($pass)))));
        } else {
            // username
            $row    = $conn->prepare("SELECT * FROM clients WHERE username=:username && password=:password ");
            $row->execute(array("username" => $username, "password" => md5(sha1(md5($pass)))));
        }

        $row    = $row->fetch(PDO::FETCH_ASSOC);



    //    if ($remember) :
    //        setUserCookies($row);
    //    endif;
    

        setUserCookies($row);
        
        
        

        $_SESSION["msmbilisim_userlogin"] = 1;

        $insert = $conn->prepare("INSERT INTO client_report SET client_id=:c_id, report_type=:report_type , action=:action, report_ip=:ip, report_date=:date ");
        $insert->execute(array("c_id" => $row["client_id"], "report_type" => 2, "action" => "User Logged in.", "ip" => GetIP(), "date" => date("Y-m-d H:i:s")));
        $update = $conn->prepare("UPDATE clients SET login_date=:date, login_ip=:ip WHERE client_id=:c_id ");
        $update->execute(array("c_id" => $row["client_id"], "date" => date("Y.m.d H:i:s"), "ip" => GetIP()));


        $_SESSION["msmbilisim_userid"]         = $row["client_id"];
        $_SESSION["msmbilisim_userpass"]       = md5(sha1(md5($pass)));
        $_SESSION["recaptcha"]                = false;


       $_SESSION['otp_login'] = true; // Default case

// Ensure OTP is disabled by default
$settings['otp_login'] = 0;

$user = $conn->prepare('SELECT user2FA FROM clients WHERE client_id=:id');
$user->execute(['id' => $_SESSION['msmbilisim_userid']]);
$user = $user->fetch(PDO::FETCH_ASSOC);

// Force OTP enable only if user2FA is 1
if (!empty($user) && $user['user2FA'] == 1) { 
    $settings['otp_login'] = 2;
}

// If OTP is necessary, force redirect
if ($settings['otp_login'] == 2) { 
    $_SESSION['otp_login'] = false;
    $_SESSION['otp_logins'] = 342;
    
    $last_login_ip = $row['login_ip'];
    loginOtp($row);
    
    header('Location: ' . site_url('otp_auth'));
    exit;
}

else {
            $user = $conn->prepare("SELECT * FROM clients WHERE client_id=:id");
            $user->execute(array("id" => $_SESSION["msmbilisim_userid"]));
            $user = $user->fetch(PDO::FETCH_ASSOC);
            $user['auth']     = $_SESSION["msmbilisim_userlogin"];
            Header("Location:" . site_url());
        }



    }
}
