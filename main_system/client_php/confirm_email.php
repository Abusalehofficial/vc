<?php







if ($settings["email_confirmation"]  == 1 || $user["email_confirmed"] == 2 ) {
    Header("Location:" . site_url(''));
}




$action = $_POST["action"];

$verification = $_GET["verification"];



if ($verification) {

    $client = $conn->prepare("SELECT * FROM clients WHERE email_verification_code=:email_verification_code");
    $client->execute(array("email_verification_code" => $verification));
    $client = $client->fetch(PDO::FETCH_ASSOC);


    if (!empty($client)) {

        $update = $conn->prepare("UPDATE clients SET email_confirmed=:email_confirmed WHERE email_verification_code=:code");
        $update->execute(array("email_confirmed" => 2, "code" => $verification));
        Header("Location:" . site_url(''));
    } else {
        Header("Location:" . site_url(''));
    }
}




if ($action == "resend_verification_link") {

    //resend otp to registered email

    // sendEmailVerificationLink($user["email"]);
    $email = $user["email"];
    $template = $settings["verification_email_temp"];
    $template = json_decode($template, true);

    $emailResponse = sendEmailWithTemplate($template["subject"], $template["message"], $email);

    if($emailResponse){
        $res["title"] = "Email has been sent again!";
        $res["icon"] = "success";
    }else{
        $res["title"] = "Unable to send email , Contact Administrator!";
        $res["icon"] = "error";
    }
   

    echo json_encode($res);
    exit();
}




if ($action == "change_email") {




    $current_email = $_POST["current_email"];
    $current_email = filter_var($current_email, FILTER_VALIDATE_EMAIL);
    $new_email = $_POST["new_email"];
    $new_email = filter_var($new_email, FILTER_VALIDATE_EMAIL);
    $current_password = $_POST["current_password"];




    if (empty($current_email)) {
        $res["title"] = "Current Email can't be empty!";
        $res["icon"] = "error";

        echo json_encode($res);
        exit();
    } elseif (empty($new_email)) {
        $res["title"] = "New Email can't be empty!";
        $res["icon"] = "error";

        echo json_encode($res);
        exit();
    } elseif (empty($current_password)) {
        $res["title"] = "Current Password can't be empty!";
        $res["icon"] = "error";

        echo json_encode($res);
        exit();
    } elseif ($current_email != $user["email"]) {
        $res["title"] = "Action Restricted!";
        $res["icon"] = "error";

        echo json_encode($res);
        exit();
    } elseif (countRow(['table' => 'clients', 'where' => ['email' => $new_email]])) {
        $res["title"] = "Email Already Exists!";
        $res["icon"] = "error";

        echo json_encode($res);
        exit();
    }


    //check whether current email and password are compatible or not

    $userLoginCheck = userlogin_check_email($current_email, $current_password);

    if ($userLoginCheck) {




        // update email of client
        $updateEmail = $conn->prepare("UPDATE clients SET email=:email WHERE email=:old_email");
        $updateEmail =  $updateEmail->execute(["old_email" => $current_email, "email" => $new_email]);

        if ($updateEmail) {
            //send new link to user

            sendEmailVerificationLink($user);

            $res["title"] = "Email Changed Successfully!";
            $res["icon"] = "success";


            echo json_encode($res);
            exit();
        } else {
            $res["title"] = "Some Error Occured! Try Again Later.";
            $res["icon"] = "error";

            echo json_encode($res);
            exit();
        }
    } else {
        //echo password uncorrect
        $res["title"] = "Password Incorrect.";
        $res["icon"] = "error";

        echo json_encode($res);
        exit();
    }
}
