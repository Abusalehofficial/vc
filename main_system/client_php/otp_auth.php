<?php






$title .= "Otp Verification";

if ($_POST['active']) {
    
    
if ($_SESSION["msmbilisim_userlogin"] != 1  || $user["client_type"] == 1) {
  header("Location:" . site_url('logout'));
}

    $update = $conn->prepare('UPDATE clients SET user2FA = :active WHERE client_id = :id');
$update->execute([
    'active' => $_POST['active'],
    'id' => $_SESSION['msmbilisim_userid']
]);

 header("Location:" . site_url(''));
exit;
    
}

if (route(0) != 'admin' && $_SESSION['otp_logins'] == 342){
    
 




if ($settings["otp_login"] == 0) {
  //no otp 
  header("Location:" . site_url('logout'));
} else {
  //users
}


if ($_COOKIE["u_password"]) {
  setcookie("u_password", "removed", strtotime('-7 days'), '/', null, null, true);
}



if ($_SESSION["msmbilisim_userlogin"] != 1  || $user["client_type"] == 1) {
  header("Location:" . site_url('logout'));
}

 

$_SESSION['otp_auth'] = true;


if (isset($_POST['otp'])) {

   $otp_posted = $_POST['otp'];
  $otp_posted = filter_var($otp_posted, FILTER_SANITIZE_NUMBER_INT);
  $client_id = $user["client_id"];
  $client_otp =  $user["login_otp"];


  if ($otp_posted == $client_otp) {

    $otp_sent_time = strtotime($user["otp_sent_time"]) + 900;

    $current_time = strtotime(date("Y-m-d H:i:s"));


    if ($current_time >  $otp_sent_time) {

      $error  = "Your otp has been expired, please resend it and then enter the
      new one";
    } else {
      $_SESSION["otp_login"] = true;

$_SESSION['otp_auth'] = false;

unset($_SESSION['otp_logins']);

      $insert = $conn->prepare("INSERT INTO client_report SET client_id=:c_id , report_type=:report_type , action=:action, report_ip=:ip, report_date=:date ");
      $insert->execute(array("c_id" => $user["client_id"], "report_type" => 2, "action" => "User Logged in through otp.", "ip" => GetIP(), "date" => date("Y-m-d H:i:s")));
      $update = $conn->prepare("UPDATE clients SET login_date=:date, login_ip=:ip WHERE client_id=:c_id ");
      $update->execute(array("c_id" => $user["client_id"], "date" => date("Y.m.d H:i:s"), "ip" => GetIP()));

      header("Location:" . site_url(''));
    }
  } else {
    $_SESSION["otp_login"] = false;
    //show error message
    $error  = "You have entered wrong OTP";
  }
}


//resend otp

if (isset($_GET["resend"])) {
  $user_email = $_GET["resend"];


  $otp = substr(number_format(time() * rand(), 0, '', ''), 0, 6);


  $body = '<div style="font-family: Helvetica,Arial,sans-serif;min-width:1000px;overflow:auto;line-height:2">
    <div style="margin:50px auto;width:70%;padding:20px 0">
       <div style="border-bottom:1px solid #eee">
      <a href="" style="font-size:1.4em;color: #00466a;text-decoration:none;font-weight:600">' .  $settings["site_seo"]  . '</a>
      </div>
    <p style="font-size:1.1em">Hi,</p>
      <p>Thank you for choosing ' .  $settings["site_seo"]  . '. Use the following OTP to complete your Sign In procedures. OTP is valid for 15 minutes</p>
    <h2 style="background: #00466a;margin: 0 auto;width: max-content;padding: 0 10px;color: #fff;border-radius: 4px;">' .  $otp  . '</h2>
    <p style="font-size:0.9em;">Regards,<br />' .  $settings["site_seo"]  . '</p>
      <hr style="border:none;border-top:1px solid #eee" />
    <div style="float:right;padding:8px 0;color:#aaa;font-size:0.8em;line-height:1;font-weight:300">
      <p> ' .  $settings["site_seo"]  . '</p>
     
    </div>
  </div>
    </div>';


  sendMail(["subject" => "Login OTP.", "body" => $body, "mail" => $user["email"]]);


  $update = $conn->prepare("UPDATE clients SET login_otp=:login_otp , otp_sent_time=:otp_sent_time WHERE client_id=:c_id ");
  $update->execute(array("c_id" => $user["client_id"], "login_otp" => $otp, "otp_sent_time" => date("Y-m-d H:i:s")));


  header("Location:" . site_url(''));
}
   
}
else    
{
    header("Location:" . site_url(''));
    exit;
    
}

