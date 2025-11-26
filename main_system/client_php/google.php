<?php

if ($settings["google_login"] == 0) {
    require PATH . '/main_system/client_php/404.php';
}

userSecureHeader();



function sir($username)
{
    global $conn;

    $row = $conn->prepare("SELECT * FROM clients WHERE username=:username");
    $row->execute(array("username" => $username));
    $row = $row->fetch(PDO::FETCH_ASSOC);
    return $row['client_id'];
}

function generateRandomUsernamePassword($usernameLength = 8, $passwordLength = 12)
{
    $usernameChars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $passwordChars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+-={}[]|:;"<>,.?/';

    $username = '';
    $password = '';

    for ($i = 0; $i < $usernameLength; $i++) {
        $username .= $usernameChars[rand(0, strlen($usernameChars) - 1)];
    }

    for ($i = 0; $i < $passwordLength; $i++) {
        $password .= $passwordChars[rand(0, strlen($passwordChars) - 1)];
    }

    return array('username' => $username, 'password' => $password);
}

function generateApiKeys()
{
    $length = 32;
    $bytes = openssl_random_pseudo_bytes($length, $strong);
    $hex = bin2hex($bytes);
    return $hex;
}

function convertEmailToUsername($email)
{
    $username = strstr($email, '@', true);

    $username = str_replace('.', '', $username);

    return $username;
}

$api_url = $cdn_base_url . 'google/agoogle';

$data = [
    "gsecret" => $settings["gkey"],
    "gkey" => $settings["gsecret"],
    "site_url" => site_url("google"),
];

if (isset($_GET['code'])) {
    $code = urldecode($_GET['code']);
    $data["code"] = $code;
}

$ch = curl_init();

curl_setopt($ch, CURLOPT_URL, $api_url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    echo "cURL Error: " . curl_error($ch);
} else {
    $decoded_response = json_decode($response, true);
}

curl_close($ch);

if (isset($decoded_response["error"])) {
    header("Location: /");
    exit;
}
if ($_GET['code']) {
    $name = $decoded_response["name"];
    $email = $decoded_response["email"];

    if (userdata_check("email", $email)) {
        $row = $conn->prepare("SELECT * FROM clients WHERE email=:email");
        $row->execute(array("email" => $email));
        $row = $row->fetch(PDO::FETCH_ASSOC);
        $access = json_decode($row["access"], true);

        $logged_ip = GetIP();
        $browser_info = $_SERVER["HTTP_USER_AGENT"];
        $user_token = encrypt_user_key($row, $browser_info, $logged_ip);
        $user_id = encryptIt($row["username"]);

        $cookie_set_user = setcookie("_user", $user_id, time() + 604800, '/', '', false, true); // 604800 seconds = 7 days
        $cookie_set_token = setcookie("_user_token", $user_token, time() + 604800, '/', '', false, true); // 604800 seconds = 7 days

        if ($cookie_set_user && $cookie_set_token) {
            $_SESSION["msmbilisim_userlogin"] = 1;
            $_SESSION["msmbilisim_userid"] = $row["client_id"];
            $_SESSION["msmbilisim_userpass"] = $row["password"];

            header('Location:' . site_url(''));
            
            $insert = $conn->prepare("INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ");
            $insert->execute(array(
                "c_id" => $row["client_id"], 
                "action" => "Login with google.", 
                "ip" => GetIP(), 
                "date" => date("Y-m-d H:i:s")
            ));
            
            $update = $conn->prepare("UPDATE clients SET login_date=:date, login_ip=:ip WHERE client_id=:c_id ");
            $update->execute(array(
                "c_id" => $row["client_id"], 
                "date" => date("Y.m.d H:i:s"), 
                "ip" => GetIP()
            ));
            exit;
        }  
    } else {
        $credentials = generateRandomUsernamePassword();
        $username = convertEmailToUsername($email);
        $pass = $credentials['password'];
        $ref_code = $_SESSION['ref_code'];
       if (userdata_check("email", $email)) { 
            header('Location:' . site_url('')); 
        } else { 
            
            
               $allowed_payment_methods = $conn->prepare("SELECT id FROM payment_methods WHERE allow_new_users=:allow_new_users");
    $allowed_payment_methods->execute(array("allow_new_users" => 2));
    $allowed_payment_methods  = $allowed_payment_methods->fetchAll(PDO::FETCH_ASSOC);

 
    $allowed_arrray = array();
    foreach ($allowed_payment_methods as $key => $value) {
      $allowed_arrray[] =  $value["id"];
    }

     


    $allowed_arrray = implode(",", $allowed_arrray);

            $apikey = generateApiKeys(); 
            $ref_code =  substr(bin2hex(random_bytes(18)), 5, 6); 
            $conn->beginTransaction(); 
            $insert = $conn->prepare("INSERT INTO clients SET  
                name=:name, 
                username=:username, 
                email=:email, 
                password=:password, 
                register_date=:date, 
                apikey=:key, 
                timezone=:timezone, 
                ref_code=:ref_code,
                user_pref_curr=:user_pref_curr,
            allowed_payments_methods=:allowed_payments_methods 

            "); 
            $insert = $insert->execute(array( 
                "name" => $name, 
                "username" => $username, 
                "email" => $email, 
                "password" => md5(sha1(md5($pass))), 
                "date" => date("Y.m.d H:i:s"), 
                'key' => $apikey, 
                "timezone" => $settings["default_timezone"], 
                "ref_code" => $ref_code,
                "user_pref_curr" => $settings["site_currency"],
                "allowed_payments_methods" => $allowed_arrray
                
            )); 
            if ($insert) { 
                $conn->commit(); 
                $client_id = sir($username); 
                
                
                // Welcome email
    if ($settings["alert_welcomemail"] == 2) {
        $template = $settings["welcome_email_temp"];
        $template = json_decode($template, true);
        $email_to_send = $email; // naya registered user ka email
        sendEmailWithTemplate($template["subject"], $template["message"], $email_to_send);
    }
                
                
  $row = $conn->prepare("SELECT * FROM clients WHERE username=:username");
$row->execute(array("username" => $username));
$row = $row->fetch(PDO::FETCH_ASSOC);

$logged_ip = GetIP();
$browser_info = $_SERVER["HTTP_USER_AGENT"];
$user_token = encrypt_user_key($row, $browser_info, $logged_ip);
$user_id =  encryptIt($row["username"]);
 $cookie_set_user = setcookie("_user", $user_id, time() + 604800, '/', '', false, true); // 604800 seconds = 7 days
        $cookie_set_token = setcookie("_user_token", $user_token, time() + 604800, '/', '', false, true); // 604800 seconds = 7 days

 if ($cookie_set_user && $cookie_set_token) {
            $_SESSION["msmbilisim_userlogin"] = 1;
            $_SESSION["msmbilisim_userid"] = $row["client_id"];
            $_SESSION["msmbilisim_userpass"] = $row["password"];

            header('Location:' . site_url(''));
            
            $insert = $conn->prepare("INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ");
            $insert->execute(array(
                "c_id" => $row["client_id"], 
                "action" => "Login with google.", 
                "ip" => GetIP(), 
                "date" => date("Y-m-d H:i:s")
            ));
            
            $update = $conn->prepare("UPDATE clients SET login_date=:date, login_ip=:ip WHERE client_id=:c_id ");
            $update->execute(array(
                "c_id" => $row["client_id"], 
                "date" => date("Y.m.d H:i:s"), 
                "ip" => GetIP()
            ));
            exit;
        }  
                 
             } else { 
                $conn->rollBack(); 
                header('Location:' . site_url('')); 
            } 
        } 
    } 
    
    
} else {
    header("Location: " . $decoded_response["url"]);
    exit;
}

?>