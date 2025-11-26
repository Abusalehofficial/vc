<?php

 
$title .= "Add Funds";

userSecureHeader();

if ($_GET["error"]) :
    $error = 1;
    $errorText = urldecode($_GET["error"]);
endif;
if ($_GET["success"]) :
    $success = 1;
    $successText = urldecode($_GET["success"]);
endif;


if (route(1) && is_numeric(route(1))) :
    $page = route(1);
else :
    $page = 1;
endif;
 

 
$paymentsList = $conn->prepare("SELECT * FROM payment_methods WHERE method_type=:type && method_status=2 && id!=:id6  ORDER BY method_line ASC ");
$paymentsList->execute(array("type" => 2, "id6" => 6));
$paymentsList = $paymentsList->fetchAll(PDO::FETCH_ASSOC);



foreach ($paymentsList as $index => $payment) {
    if (in_array($payment["id"], explode(",", $user["allowed_payments_methods"]))) {
        $extra = json_decode($payment["method_extras"], true);
        $methodList[$index]["method_name"] = $extra["name"];
        $methodList[$index]["id"] = $payment["id"];
        $methodList[$index]["type"] = $payment["method_automatic"] == 2 ? "automatic" : "manual";
    }
}



if ($_POST["action"] == "getFields") {


    $id = $_POST["selectedMethod"];

    $methodData = $conn->prepare("SELECT * FROM payment_methods WHERE method_type=:type && method_status=2 && id=:id");
    $methodData->execute(array("id" => $id, "type" => 2));
    $methodData = $methodData->fetch(PDO::FETCH_ASSOC);

    $configuration = json_decode($methodData["method_configuration"], true);

    $extras = json_decode($methodData["method_extras"], true);
    $instruction = $extras["instruction"];

    $html = '';



    $current_theme = $settings["site_theme"];
    if ($current_theme == "premuim-wixo") :
        $labelClass = "fla";
        $wrapperDiv = "fga mb-4";
        $inputwrapperDiv = "fg";
        $inputClass = "fg-control";
    else :
        $labelClass = "control-label";
        $wrapperDiv = "form-group";
        $inputwrapperDiv = "";
        $inputClass = "form-control";
    endif;



    if ($configuration["image"]) :
        $inputConfig  = $configuration["imageConfig"];
        $html = $html . '<center><img id="' . $inputConfig[0]["id"] . '" class="' . $inputConfig[0]["class"] . '" alt="' . $extras["merchant_key"] . '" width="' . $extras["width"] . '%" src=' . $extras["merchant_key"] . '></center>';
    endif;

    // p(json_encode(["buttons" => $imageConfig]));

    if (!empty($instruction) && $instruction != "<p><br></p>" && !($methodData["method_automatic"] == 1 && $extras["method_show"] == 2)) {
        $html = $html . '<div class="form-group">
        <label class="' . $labelClass . '">Instructions</label>
        ' . $instruction . '</div>';
    }


    if ($methodData["method_automatic"] == 2) :


        $input = $configuration["input"];
        $inputConfig  = $configuration["inputConfig"];

        foreach ($inputConfig as $input) :

            if (!empty($input["label"])) :
                $html = $html . '<div class="' . $wrapperDiv . '"><label class="' . $labelClass . '">' . $input["label"] . '</label>';
            endif;


            $html = $html . '<div class="' . $inputwrapperDiv . '"><input class="' . $inputClass . ' ' . $input["class"] . '" type="' . $input["type"] . '"
step="0.01"





        id="' . $input["id"] . '" placeholder="' . $input["placeholder"] . '" value="' . $input["value"] . '"
        name="' . $input["name"] . '" ';
            $readonly = $input["readonly"] == "true" ? "readonly" : " ";
            $html = $html . $readonly . ' /></div></div>';
        endforeach;



        $button = $configuration["button"];
        $buttonConfig  = $configuration["buttonConfig"];

        $buttonHtml = '';
        foreach ($buttonConfig as $button) :

            $label = $button["label"];
            $addFundsLabel = $languageArray[$label] ? $languageArray[$label] : "Add Funds";

            $settings["site_theme"] == "simplyfy" ? $button["class"] = "btn btn-big-primary btn-block" : $button["class"];

            $buttonHtml =  $buttonHtml . '<button class="' . $button["class"] . '" id="' . $button["id"] . '" type="' . $button["type"] . '">' . $addFundsLabel . '</button>';
        endforeach;
    endif;

    if ($methodData["method_automatic"] == 1 && $extras["method_show"] == 2) :

        ///////////////////////// Amount Input ///////////////////////////////////

               // $html = '<div class="fga mb-4"><label class="fla">Amount</label><div class="fg"><input class="fg-control payment-amount-class" type="number" id="payment-amount-id" placeholder="Amount" value="payment_amount" name="payment_amount"></div></div>';

        
        $html = $html . '<div class="' . $inputwrapperDiv . '"><label class="fla">Amount</label><input class="' . $inputClass . ' ' . $input["class"] . '" type="' . $input["type"] . '"
        id="payment-amount-id" placeholder="' . $input["placeholder"] . '" value="' . $input["value"] . '"
        name="payment_amount" ';
            $readonly = $input["readonly"] == "true" ? "readonly" : " ";
            $html = $html . $readonly . ' /></div></div>';

        ///////////////////////// Add Funds Button ///////////////////////////////

        $button["class"] = "btn btn-primary btn-block";

        $settings["site_theme"] == "simplyfy" ? $button["class"] = "btn btn-big-primary btn-block" : $button["class"];
        $settings["site_theme"] == "premuim-wixo" ? $button["class"] = "btn btn-primary btn-block" : $button["class"];
        $buttonHtml =  '<button type="submit" class="' . $button["class"] . '" id="' . $button["id"] . '">' . $extras["button_text"] . '</button>';


    endif;


    //SHOW AMOUNT + BONUS BEFORE BUTTON
    $paymentBonus = $conn->prepare("SELECT * FROM payments_bonus WHERE bonus_type=:bonus_type && bonus_method=:bonus_method");
    $paymentBonus->execute(array("bonus_method" => $id, "bonus_type" => 2));
    $paymentBonus = $paymentBonus->fetch(PDO::FETCH_ASSOC);
    if ($settings["site_theme"] == "premuim-wixo" && !($methodData["method_automatic"] == 1 && $extras["method_show"] == 1)) {
        $html .= '<div class="fga mb-4"><label class="fla">After Bonus</label><div class="fg"><div class="fg-icon"><i class="far fa-regular fa-gift"></i></div><input class="fg-control payment-bonus-class" type="number" id="payment-bonus-amount-id" readonly  name="payment_bonus_amount"></div></div><input type="hidden" id="bonusPercent" value="' . $paymentBonus["bonus_amount"] . '"><input type="hidden" id="bonusFrom" value="' . $paymentBonus["bonus_from"] . '">';

        $html .= '<script>

		$("#payment-amount-id").keyup(function(e){
			 e.preventDefault();
			 var amount = $(this).val();
			 var bonusPer = $("#bonusPercent").val();
			 var bonusMin = $("#bonusFrom").val();
			 if(amount >= bonusMin){
				var totalAmt = (bonusPer * amount)/100;
				totalAmt = parseFloat(totalAmt) + parseFloat(amount);
				$("#payment-bonus-amount-id").val(totalAmt);
			 }else{
                $("#payment-bonus-amount-id").val(amount);
             }
		});
		</script>';
    }


    $data = [
        "inputs" => $html,
        "buttons" => $buttonHtml,
    ];

    $data = json_encode($data);

    echo $data;
    exit();
}


function _removeFunds($amount, $token)
{

    global $conn, $user, $settings;
    $update = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:client_id ');
    $update = $update->execute(['client_id' => $user["client_id"], 'balance' => $user["balance"] - $amount]);

    $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, client_balance=:client_balance, payment_amount=:amount, payment_privatecode=:code, payment_status=:payment_status ,payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
    $insert = $insert->execute(array("c_id" => $user['client_id'], "client_balance" => $user["balance"], "amount" => (0 - $amount), "code" => "", "method" => 36, "payment_status" => 3, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => "Funds transferred $token"));



    $user["balance"] = $user["balance"] - $amount;

    $old_bal =    getCurrencySymbol($settings["site_currency"]) . ($user["balance"] + $amount);
    $new_bal =    getCurrencySymbol($settings["site_currency"]) . $user["balance"];

    $message = "Token : $token , User has asked to withdraw funds and "  .  getCurrencySymbol($settings["site_currency"]) . $amount . " has been deducted already now. Old Balance/New Balance : $old_bal/$new_bal";

    insertCReport($user["client_id"], 1, $message);
    return $update;
}

function _makeTicket($amount, $w_type, $types, $token, $username)
{
    global $conn, $user, $settings;
    $type = $types[$w_type - 1];

    $insert = $conn->prepare("INSERT INTO tickets SET client_id=:c_id, subject=:subject, time=:time, lastupdate_time=:last_time ");
    $insert = $insert->execute(array("c_id" => $user["client_id"], "subject" => "Withdraw Funds $token", "time" => date("Y.m.d H:i:s"), "last_time" => date("Y.m.d H:i:s")));
    if ($insert) {
        $ticket_id = $conn->lastInsertId();
    }

    $t_message = "Hello Team</br>I want to withdraw my funds by method : $type for amount of " . getCurrencySymbol($settings["site_currency"]) . "$amount</br>Unique Token : $token</br>";

    if ($w_type == 1) :
        $t_message .= "Destination : Preferred Payment Method";
    else :
        $t_message .= "Username : $username";
    endif;

    $t_message .= "</br>Please complete the process asap.</br>Thank you.";


    $insert2 = $conn->prepare("INSERT INTO ticket_reply SET ticket_id=:t_id, client_id=:client_id ,  message=:message, time=:time ");
    $insert2 = $insert2->execute(array("t_id" => $ticket_id, "client_id" => $user["client_id"], "message" => $t_message, "time" => date("Y.m.d H:i:s")));

    return [$insert2, $ticket_id];
}

function _addFundsToUser($userData, $amount, $token)
{

    global $conn, $user, $settings;
    $update = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:client_id ');
    $update = $update->execute(['client_id' => $userData["client_id"], 'balance' => $userData["balance"] + $amount]);


    $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, client_balance=:client_balance, payment_amount=:amount, payment_privatecode=:code, payment_status=:payment_status ,payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
    $insert = $insert->execute(array("c_id" => $userData['client_id'], "client_balance" => $userData["balance"], "amount" => $amount, "code" => "", "method" => 36, "payment_status" => 3, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => "Funds transferred $token"));


    $old_bal =    getCurrencySymbol($settings["site_currency"]) . $userData["balance"];
    $new_bal =    getCurrencySymbol($settings["site_currency"]) .  ($userData["balance"] + $amount);

    $message = "Token : $token , User has recieved funds from " . $user["username"] . " and " .    getCurrencySymbol($settings["site_currency"]) . $amount . " has been added now. Old Balance/New Balance : $old_bal/$new_bal";

    insertCReport($userData["client_id"], 1, $message);
    return $update;
}



if ($_POST && route(1) == "withdraw") :


    $w_type = $_POST["withdraw"]["type"];
    $w_amount = $_POST["withdraw"]["amount"];
    $w_username = trim($_POST["withdraw"]["username"]);


    $userData = $conn->prepare("SELECT * FROM clients WHERE username=:username");
    $userData->execute(array("username" => $w_username));
    $userData = $userData->fetch(PDO::FETCH_ASSOC);

    $token = "#" . generateRandomString(10);
    $withdraw_types = ["Withdraw To Payment", "Peer to Peer", "Withdraw to CheapSMMJunior.com"];


    if ($w_amount <= 0) :
        $error = 1;
        $errorText = 'Amount must be more than zero to withdraw';
    elseif ($w_amount > $user["balance"]) :
        $error = 1;
        $errorText = 'Amount must be less than or equal to current balance';
    elseif (!in_array($w_type, [1, 2, 3])) :
        $error = 1;
        $errorText = 'Withdrawl method is not supported';
    elseif ($userData["client_id"] == $user["client_id"]) :
        $error = 1;
        $errorText = 'Source and destination user must not be the same';
    elseif ($w_type != 1 && empty($_POST["withdraw"]["username"])) :
        $error = 1;
        $errorText = 'Username must not be empty';
    elseif ($w_type == 2 &&  empty($userData)) :
        $error = 1;
        $errorText = $w_username . ' is not a valid username';
    else :

        if ($w_type == 1) :
            //withdraw to payment  

            $amountRemoved = _removeFunds($w_amount, $token);
            if ($amountRemoved) :
                [$status, $ticketID] =  _makeTicket($w_amount, $w_type, $withdraw_types, $token, $w_username);
            endif;


        elseif ($w_type == 2) :
            //peer to peer
            $amountRemoved = _removeFunds($w_amount, $token);
            if ($amountRemoved) :
                $status =  _addFundsToUser($userData, $w_amount, $token);
            endif;


        elseif ($w_type == 3) :
            //withdrwal to cheapsmmjunior
            $amountRemoved = _removeFunds($w_amount, $token);
            if ($amountRemoved) :
                [$status, $ticketID] =  _makeTicket($w_amount, $w_type, $withdraw_types, $token, $w_username);
            endif;
        endif;

        if ($status == 1) :
            $success = 1;
            if ($w_type == 2) :
                $successText = 'Transfer Completed!';
            else :
                $successText = 'Withdrawl Initiated , Please wait.....';
                echo '<script>setTimeout(function(){
                    window.location="' . site_url('tickets/') . $ticketID . '"
                },2000);</script>';
            endif;
        else :
            $error = 1;
            $errorText = 'Something went wrong!';
        endif;

    endif;

endif;


$clid = $user['client_id'];

$searchh = "WHERE payments.client_id=$clid && payments.payment_status='3' ";


$count = $conn->prepare("SELECT COUNT(payment_id) as count FROM payments INNER JOIN payment_methods ON payment_methods.id=payments.payment_method INNER JOIN clients ON clients.client_id=payments.client_id $searchh ORDER BY payments.payment_id DESC");
$count->execute(array());
$count = $count->fetch(PDO::FETCH_ASSOC);
$count = $count["count"];

$to             = 20;
$pageCount      = ceil($count / $to);
if ($page > $pageCount) : $page = 1;
endif;
$where          = ($page * $to) - $to;
$paginationArr  = ["count" => $pageCount, "current" => $page, "next" => $page + 1, "previous" => $page - 1];


$transaction_logs = $conn->prepare("SELECT * FROM payments INNER JOIN payment_methods ON payment_methods.id=payments.payment_method INNER JOIN clients ON clients.client_id=payments.client_id $searchh ORDER BY payments.payment_id DESC LIMIT $where,$to");
$transaction_logs->execute(array());
$transaction_logs = $transaction_logs->fetchAll(PDO::FETCH_ASSOC);

for ($i = 0; $i < count($transaction_logs); $i++) {
    $method_extras = json_decode($transaction_logs[$i]["method_extras"], true);
    $transaction_logs[$i]["method_name"] = $method_extras["name"];
}


if ($_POST && $_POST["payment_bank"]) :
    foreach ($_POST as $key => $value) :
        $_SESSION["data"][$key] = $value;
    endforeach;
    $bank = $_POST["payment_bank"];
    $amount = $_POST["payment_bank_amount"];
    $gonderen = $_POST["payment_gonderen"];
    $method_id = 6;
    $extras = json_encode($_POST);



    if (open_bankpayment($user["client_id"]) >= 2) {
        unset($_SESSION["data"]);
        $error = 1;
        $errorText = 'You have 2 payment notifications pending approval, you cannot create new notifications.';
    } elseif (empty($bank)) {
        $error = 1;
        $errorText = 'Please select a valid bank account.';
    } elseif (!is_numeric($amount)) {
        $error = 1;
        $errorText = 'Please enter a valid amount.';
    } elseif (empty($gonderen)) {
        $error = 1;
        $errorText = 'Please enter a valid sender name.';
    } else {
        $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_method=:method, payment_create_date=:date, payment_ip=:ip, payment_extra=:extras, payment_bank=:bank ");
        $insert->execute(array("c_id" => $user["client_id"], "amount" => $amount, "method" => $method_id, "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extras" => $extras, "bank" => $bank));
        if ($insert) {
            unset($_SESSION["data"]);
            $success = 1;
            $successText = 'Your payment notification has been received.';
            if ($settings["alert_newbankpayment"] == 2) :
                if ($settings["alert_type"] == 3) :
                    $sendmail = 1;
                    $sendsms = 1;
                elseif ($settings["alert_type"] == 2) :
                    $sendmail = 1;
                    $sendsms = 0;
                elseif ($settings["alert_type"] == 1) :
                    $sendmail = 0;
                    $sendsms = 1;
                endif;
                if ($sendsms) :
                    SMSUser($settings["admin_telephone"], "New payment request created on your site and ID is: #" . $conn->lastInsertId());
                endif;
                if ($sendmail) :
                    sendMail(["subject" => "New payment request", "body" => "New payment request created on your site and ID is: #" . $conn->lastInsertId(), "mail" => $settings["admin_mail"]]);
                endif;
            endif;
        } else {
            $error = 1;
            $errorText = 'An error occurred while alert sending, please try again later..';
        }
    }
elseif ($_POST && $_POST["payment_type"]) :

    foreach ($_POST as $key => $value) :
        $_SESSION["data"][$key] = $value;
    endforeach;
    $method_id = $_POST["payment_type"];
    $amount = $_POST["payment_amount"];
    if ($_POST["paytmqr_orderid"] != "") {

        $paytmqr_orderid = $_POST["paytmqr_orderid"];
        $paytmqr_orderid = str_replace(' ', '', $paytmqr_orderid);
    }

    if ($_POST["phonepeqr_orderid"] != "") {

        $phonepeqr_orderid = $_POST["phonepeqr_orderid"];
        $phonepeqr_orderid = str_replace(' ', '', $phonepeqr_orderid);
    }

    if ($_POST["easypaise_orderid"] != "") {

        $easypaise_orderid = $_POST["easypaise_orderid"];
        $easypaise_orderid = str_replace(' ', '', $easypaise_orderid);
    }


    if ($_POST["jazzcash_orderid"] != "") {

        $jazzcash_orderid = $_POST["jazzcash_orderid"];
        $jazzcash_orderid = str_replace(' ', '', $jazzcash_orderid);
    }


    $extras = json_encode($_POST);
    $method = $conn->prepare("SELECT * FROM payment_methods WHERE id=:id ");
    $method->execute(array("id" => $method_id));
    $method = $method->fetch(PDO::FETCH_ASSOC);
    $extra = json_decode($method["method_extras"], true);



    $paymentCode = createPaymentCode();

    $amount_fee = ($amount + ($amount * $extra["fee"] / 100));
    if (empty($method_id)) {
        $error = 1;
        $errorText = 'Please select a valid payment method.';
    } elseif (!is_numeric($amount)) {
        $error = 1;
        $errorText = 'Please enter a valid amount.';
    } elseif ($amount < $method["method_min"]) {
        $error = 1;
        $errorText = 'Minimum payment amount ' . $settings["csymbol"] . $method["method_min"];
    } elseif ($amount > $method["method_max"] && $method["method_max"] != 0) {
        $error = 1;
        $errorText = 'Maximum payment amount ' . $settings["csymbol"] . $method["method_max"];
    } else {



        $currentcur = json_decode($settings["currency_conversion_data"], true);

        if (isset($currentcur["error"])) {
            if (defined($getcur . '_')) {
                constant($getcur . '_');
            } else {
                die('There\'s a problem with currency. Please contact with admin.');
            }
        }

        //TODO : write code for phonepe payments

        if ($method["method_automatic"] == 1 && $extra["method_show"] == 2) :

            // Manual Link payment methods
            header("Location:" . $extra["link"]);

        elseif ($method_id == 1):

        $amount = htmlentities($_POST["payment_amount"]);
        $extras = json_encode($_POST);
        $method = $conn->prepare("SELECT * FROM payment_methods WHERE id=:id ");
        $method->execute(array("id" => $method_id));
        $method = $method->fetch(PDO::FETCH_ASSOC);
        $extra = json_decode($method["method_extras"], true);

        $payment_amount = ($amount + ($amount * $extra["fee"] / 100));

        
        require_once "lib/paypal/autoload.php";

        $clientId = $extra['clientId']; 
        $clientSecret = $extra['clientSecret'];

       
            $environment = new PayPalCheckoutSdk\Core\ProductionEnvironment($clientId, $clientSecret);
            
        $client = new PayPalCheckoutSdk\Core\PayPalHttpClient($environment);

        $request = new PayPalCheckoutSdk\Orders\OrdersCreateRequest();
        $request->prefer('return=representation');
        $items = [
            [
                "reference_id" => "Client " . $user['client_id'],
                "amount" => [
                    "value" => "$payment_amount",
                    "currency_code" => "USD",
                ],
            ],
        ];

        $cancel_url = site_url('addfunds');
        $return_url = site_url('payment/paypal');
        $request->body = [
            "intent" => "CAPTURE",
            "purchase_units" => $items,
            "application_context" => [
                "cancel_url" => "$cancel_url",
                "return_url" => "$return_url",
            ],
        ];

        try {
            $response = $client->execute($request);
            $paymentCode = strval($response->result->id);
            $icid = $paymentCode;
            $getcur = $extra['currency'];
            $lastcur = 1;
            $tc_amount = str_replace(',', '.', $amount_fee);
            $params = array(
                'sid' => $icid,
                'mode' => 'paypal',
                'li_0_name' => 'Add Balance',
                'li_0_price' => number_format($tc_amount * $lastcur, 2, '.', ''),
            );

            $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
            $insert->execute(array("c_id" => $user['client_id'], "amount" => $amount, "code" => time()+rand(999,9999), "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $icid));
            $success = 1;

            foreach ($response->result->links as $link) {
                if ($link->rel == "approve") {
                    header('location:' . $link->href);
                }

            }
        } catch (Throwable $ex) {
            header('location:'. site_url('addfunds'));
        }

        exit();
            
            
            elseif ($method_id == 32) :
            unset($_SESSION["data"]);
            $pp_amount_fee = str_replace(',', '.', $amount_fee);
            $icid = md5(rand(1, 999999));
            $getcur = 'USD';
            if ($getcur != $settings["site_currency"]) :
                $getamo = convertCurrencyUpdated($getcur, $settings["site_currency"], $amount);
                $payment_extraa = json_encode(array('currency' => $getcur, 'amount' => $pp_amount_fee));
            else :
                $getamo = $pp_amount_fee;
                $payment_extraa = json_encode([]);
            endif;


            $jsondata = json_encode(array('c' => $getcur, 'a' => $pp_amount_fee));


            $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra, data=:data , payment_extraa=:payment_extraa");
            $insert->execute(array("c_id" => $user['client_id'], "amount" => $getamo, "code" => $paymentCode, "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $icid, "data" => $jsondata, "payment_extraa" => $payment_extraa));
            if ($insert) :
                $success = 1;
                $successText = "Your payment was initiated successfully, you are being redirected..";
                $payment_url = site_url('lib/pay/paypal-payment.php?hash=' . $icid);
            else :
                $error = 1;
                $errorText = "There was an error starting your payment, please try again later..";
            endif;
            
            
            
        // elseif ($method_id == 2) :
        //     unset($_SESSION["data"]);
        //     $icid = md5(rand(1, 999999));
        //     $getcur = $extra['currency'];
        //     //  $lastcur = isset($currentcur->error) ? defined($getcur . '_') ? constant($getcur . '_') : die('There\'s a problem with currency. Please contact with admin.') : $currentcur->rates->$getcur;
        //     $_SESSION["developerity_userid"] = $user["client_id"];
        //     $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, client_balance=:client_balance, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
        //     $insert = $insert->execute(array("c_id" => $user['client_id'], "amount" => $amount,  "client_balance" => $user["balance"], "code" => $paymentCode, "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $icid));
        //     if ($insert) :
        //         $success = 1;
        //         $successText = "Your payment was initiated successfully, you are being redirected..";
        //         $payment_url = site_url('lib/stripe/index.php');
        //     else :
        //         $error = 1;
        //         $errorText = "There was an error starting your payment, please try again later..";
        //     endif;
        elseif ($method_id == 2) :

            $icid = md5(rand(1, 999999));
            $orderId = "ST" . strval(strtotime(date("Y-m-d H:i:s")) + rand(100, 1000000));
            $getcur = empty($extra['currency']) ? "USD" : $extra['currency'];
            $ptm_amount = str_replace(',', '.', $amount_fee);
            if ($getcur != $settings["site_currency"]) :
                $converted_amount = convertCurrencyUpdated($getcur, $settings["site_currency"], $amount);
                $payment_extraa = json_encode(array('currency' => $getcur, 'amount' => $ptm_amount));
            else :
                $converted_amount = $ptm_amount;
                $payment_extraa = json_encode([]);
            endif;


            Stripe::setApiKey($extra["stripe_secret_key"]);

            try {
                $checkout_session = \Stripe\Checkout\Session::create([
                    'success_url' => URL . "/payment/stripe",
                    'cancel_url' => URL . "/payment/stripe",
                    'client_reference_id' => $icid,
                    'customer_email' => $user["email"],
                    'payment_method_types' => ['card'],
                    'line_items' => [[
                        'name' => 'Add Funds ' . $user["username"],
                        'images' => ["https://picsum.photos/300/300?random=4"],
                        'quantity' => 100,
                        'amount' => round($converted_amount),
                        'currency' => $getcur
                    ]]
                ]);



                $payment_code = generatePaymentCode($transactionId, $converted_amount, 43);

                $_SESSION["payment"][$orderId] = $checkout_session;

                $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, client_balance=:client_balance, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra , payment_extraa=:conversion");
                $insert = $insert->execute(array("c_id" => $user['client_id'], "amount" => $converted_amount,  "client_balance" => $user["balance"], "code" => $payment_code, "method" => $method_id, "mode" => "Otomatik", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $orderId, "conversion" => $payment_extraa));


                if ($insert) :
                    $success = 1;
                    $successText = "Your payment was initiated successfully, you are being redirected..";

                else :
                    $error = 1;
                    $errorText = "Something went wrong. Contact your administrator.";
                endif;



                $payment_url = $checkout_session["url"];
            } catch (\Throwable $th) {
                $error = 1;
                $errorText = "Payment method not configured!";
            }

        elseif ($method_id == 8) :
            $getcur = $extra['currency'];
            //  $lastcur = isset($currentcur->error) ? defined($getcur . '_') ? constant($getcur . '_') : die('There\'s a problem with currency. Please contact with admin.') : $currentcur->rates->$getcur;
            // Create a new API wrapper instance
            $cps_api = new CoinpaymentsAPI($extra["coinpayments_private_key"], $extra["coinpayments_public_key"], 'json');

            // This would be the price for the product or service that you're selling
            $cp_amount = str_replace(',', '.', $amount_fee);
            $cp_amount = number_format($cp_amount * $lastcur, 2, '.', '');

            // The currency for the amount above (original price)
            $currency1 = $extra['currency'];

            // Litecoin Testnet is a no value currency for testing
            // The currency the buyer will be sending equal to amount of $currency1
            $currency2 = $extra["coinpayments_currency"];

            // Enter buyer email below
            $buyer_email = $user["email"];

            // Set a custom address to send the funds to.
            // Will override the settings on the Coin Acceptance Settings page
            $address = '';

            // Enter a buyer name for later reference
            $buyer_name = $user["name"];

            // Enter additional transaction details
            $item_name = 'Add Balance';
            $item_number = $cp_amount;
            $custom = 'Express order';
            $invoice = 'addbalancetosmm001';
            $ipn_url = site_url('payment/coinpayments');


            // Make call to API to create the transaction
            try {
                $transaction_response = $cps_api->CreateComplexTransaction($cp_amount, $currency1, $currency2, $buyer_email, $address, $buyer_name, $item_name, $item_number, $invoice, $custom, $ipn_url);
            } catch (Exception $e) {
                echo 'Error: ' . $e->getMessage();
                exit();
            }

            if ($transaction_response['error'] == 'ok') :
                unset($_SESSION["data"]);
                $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
                $insert->execute(array("c_id" => $user['client_id'], "amount" => $amount, "code" => $paymentCode, "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $transaction_response['result']['txn_id']));
                $success = 1;
                $successText = "Your payment was initiated successfully, you are being redirected..";
                $payment_url = $transaction_response['result']['checkout_url'];
            else :
                $error = 1;
                $errorText = "There was an error starting your payment, please try again later..";
            endif;
        elseif ($method_id == 9) :
            require_once(FILES_BASE . "/vendor/2checkout/2checkout-php/lib/Twocheckout.php");

            Twocheckout::privateKey($extra['private_key']);
            Twocheckout::sellerId($extra['seller_id']);

            // If you want to turn off SSL verification (Please don't do this in your production environment)
            Twocheckout::verifySSL(false);  // this is set to true by default

            // To use your sandbox account set sandbox to true
            Twocheckout::sandbox(false);

            // All methods return an Array by default or you can set the format to 'json' to get a JSON response.
            Twocheckout::format('json');

            $icid = md5(rand(1, 999999));
            $getcur = $extra['currency'];
            //  $lastcur = isset($currentcur->error) ? defined($getcur . '_') ? constant($getcur . '_') : die('There\'s a problem with currency. Please contact with admin.') : $currentcur->rates->$getcur;
            $tc_amount = str_replace(',', '.', $amount_fee);
            $params = array(
                'sid' => $icid,
                'mode' => '2CO',
                'li_0_name' => 'Add Balance',
                'li_0_price' => number_format($tc_amount * $lastcur, 2, '.', '')
            );

            unset($_SESSION["data"]);
            $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
            $insert->execute(array("c_id" => $user['client_id'], "amount" => $amount, "code" => $paymentCode, "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $icid));
            $success = 1;
            $successText = "Your payment was initiated successfully, you are being redirected..";
            Twocheckout_Charge::form($params, 'auto');
        elseif ($method_id == 11) :
            $mollie = new MollieApiClient();
            $mollie->setApiKey($extra['live_api_key']);

            $icid = md5(rand(1, 999999));
            $getcur = $extra['currency'];
            //  $lastcur = isset($currentcur->error) ? defined($getcur . '_') ? constant($getcur . '_') : die('There\'s a problem with currency. Please contact with admin.') : $currentcur->rates->$getcur;
            $ml_amount = str_replace(',', '.', $amount_fee);
            $payment = $mollie->payments->create([
                "amount" => [
                    "currency" => $extra['currency'],
                    "value" => number_format($ml_amount * $lastcur, 2, '.', '')
                ],
                "description" => $user["email"],
                "redirectUrl" => site_url(),
                "webhookUrl" => site_url('payment/mollie'),
                "metadata" => [
                    "order_id" => $icid,
                ],
            ]);

            unset($_SESSION["data"]);
            $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
            $insert->execute(array("c_id" => $user['client_id'], "amount" => $amount, "code" => $paymentCode, "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $icid));
            $success = 1;
            $successText = "Your payment was initiated successfully, you are being redirected..";
            $payment_url = $payment->getCheckoutUrl();
        elseif ($method_id == 12) : //paytm 

            require FILES_BASE . '/lib/encdec_paytm.php';


            $checkSum = "";
            $paramList = array();

            $icid = md5(rand(1, 999999));
            $getcur = $extra['currency'];
            $ptm_amount = str_replace(',', '.', $amount_fee);

            if ($getcur != $settings["site_currency"]) :
                $getamo = convertCurrencyUpdated($getcur, $settings["site_currency"], $amount);
                $payment_extraa = json_encode(array('currency' => $getcur, 'amount' => $ptm_amount));
            else :
                $getamo = $ptm_amount;
                $payment_extraa = json_encode([]);

            endif;


            $paramList["MID"] = $extra['merchant_mid'];
            $paramList["ORDER_ID"] = $icid;
            $paramList["CUST_ID"] = $user['client_id'];
            $paramList["EMAIL"] = $user['email'];
            $paramList["INDUSTRY_TYPE_ID"] = "Retail";
            $paramList["CHANNEL_ID"] = "WEB";
            $paramList["TXN_AMOUNT"] = number_format($ptm_amount, 2, '.', '');
            $paramList["WEBSITE"] = $extra['merchant_website'];
            $paramList["CALLBACK_URL"] = site_url('payment/paytm');

            $checkSum = getChecksumFromArray($paramList, $extra['merchant_key']);




            unset($_SESSION["data"]);
            $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, client_balance=:client_balance, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra  , payment_extraa=:payment_extraa");
            $insert->execute(array("c_id" => $user['client_id'], "amount" => $getamo,  "client_balance" => $user["balance"], "code" => $paymentCode, "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $icid, "payment_extraa" => $payment_extraa));
            $success = 1;
            $successText = "Your payment was initiated successfully, you are being redirected..";
            echo '<form method="post" action="https://securegw.paytm.in/theia/processTransaction" name="f1">
                    <table border="1">
                        <tbody>';
            foreach ($paramList as $name => $value) {
                echo '<input type="hidden" name="' . $name . '" value="' . $value . '">';
            }
            echo '<input type="hidden" name="CHECKSUMHASH" value="' . $checkSum . '">
                        </tbody>
                    </table>
                    <script type="text/javascript">
                        document.f1.submit();
                    </script>
                </form>';
        elseif ($method_id == 13) :
            unset($_SESSION["data"]);
            $im_amount_fee = str_replace(',', '.', $amount_fee);
            $icid = md5(rand(1, 999999));
            $getcur = $extra['currency'];
            if ($getcur != $settings["site_currency"]) :
                $getamo = convertCurrencyUpdated($getcur, $settings["site_currency"], $amount);
                $payment_extraa = json_encode(array('currency' => $getcur, 'amount' => $im_amount_fee));
            else :
                $getamo = $im_amount_fee;
                $payment_extraa = json_encode([]);
            endif;
            $jsondata = json_encode(array('c' => $getcur, 'a' => $getamo));

            $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra, data=:data , payment_extraa=:payment_extraa");
            $insert->execute(array("c_id" => $user['client_id'], "amount" => $getamo, "code" => $paymentCode, "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $icid, "data" => $jsondata, "payment_extraa" => $payment_extraa));
            if ($insert) :
                $success = 1;
                $successText = "Your payment was initiated successfully, you are being redirected..";
                $payment_url = site_url('lib/pay/instamojo-payment.php?hash=' . $icid);
            else :
                $error = 1;
                $errorText = "There was an error starting your payment, please try again later..";
            endif;
        elseif ($method_id == 23) :

            $amount = (float)$amount;
            $amount = number_format($amount, 2);

            $client_id = $extra['usd'];
            $getcur = "USD";


            $getamo = convertCurrencyUpdated($getcur, $settings["site_currency"], $amount);
                $payment_extraa = json_encode(array('currency' => $getcur, 'amount' => $amount));
                
            $conversion = json_encode(array(
                "currency" => "USD",
                "amount" => $getamo,
            ));

            // $users = session('user_current_info');
            $order_id = strtotime('NOW');
            $perfectmoney = array(
                'PAYEE_ACCOUNT'     => $client_id,
                'PAYEE_NAME'         => $extra['merchant_website'],
                'PAYMENT_UNITS'     => "USD",
                'STATUS_URL'         => site_url('payment/perfectmoney'),
                'PAYMENT_URL'         => site_url('payment/perfectmoney'),
                'NOPAYMENT_URL'     => site_url('payment/perfectmoney'),
                'BAGGAGE_FIELDS'     => 'IDENT',
                'ORDER_NUM'         => $order_id,
                'PAYMENT_ID'         => strtotime('NOW'),
                'CUST_NUM'             => "USERID" . rand(10000, 99999999),
                'memo'                 => "Balance recharge - " .  $user['email'],
                'hash' => 'sfdsfsdfsdf'

            );
            $tnx_id = $perfectmoney['PAYMENT_ID'];

            $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, client_balance=:client_balance ,payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra , payment_extraa=:payment_extraa");
            $insert->execute(array("c_id" => $user['client_id'], "amount" => $getamo, "client_balance" => $user["balance"], "code" => $paymentCode, "method" => $method_id, "mode" => "Otomatik", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $tnx_id, "payment_extraa" => $payment_extraa));
            $success = 1;
            $successText = "Your payment was initiated successfully, you are being redirected..";



            echo '<div class="dimmer active" style="min-height: 400px;">
                  <div class="loader"></div>
                  <div class="dimmer-content">
                    <center><h2>Please do not refresh this page</h2></center>
                    <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" style="margin:auto;background:#fff;display:block;" width="200px" height="200px" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid">
                      <circle cx="50" cy="50" r="32" stroke-width="8" stroke="#e15b64" stroke-dasharray="50.26548245743669 50.26548245743669" fill="none" stroke-linecap="round">
                        <animateTransform attributeName="transform" type="rotate" dur="1s" repeatCount="indefinite" keyTimes="0;1" values="0 50 50;360 50 50"></animateTransform>
                      </circle>
                      <circle cx="50" cy="50" r="23" stroke-width="8" stroke="#f8b26a" stroke-dasharray="36.12831551628262 36.12831551628262" stroke-dashoffset="36.12831551628262" fill="none" stroke-linecap="round">
                        <animateTransform attributeName="transform" type="rotate" dur="1s" repeatCount="indefinite" keyTimes="0;1" values="0 50 50;-360 50 50"></animateTransform>
                      </circle>
                    </svg>
                    <form method="post" action="https://perfectmoney.is/api/step1.asp" id="redirection_form">
                      <input type="hidden" name="PAYMENT_AMOUNT" value="' . $amount . '">
                      <input type="hidden" name="PAYEE_ACCOUNT" value="' . $perfectmoney["PAYEE_ACCOUNT"] . '">
                      <input type="hidden" name="PAYEE_NAME" value="' . $perfectmoney["PAYEE_NAME"] . '">
                      <input type="hidden" name="PAYMENT_UNITS" value="' . $perfectmoney["PAYMENT_UNITS"] . '">
                      <input type="hidden" name="STATUS_URL" value="' . $perfectmoney["STATUS_URL"] . '">
                      <input type="hidden" name="PAYMENT_URL" value="' . $perfectmoney["PAYMENT_URL"] . '">
                      <input type="hidden" name="NOPAYMENT_URL" value="' . $perfectmoney["NOPAYMENT_URL"] . '">
                      <input type="hidden" name="BAGGAGE_FIELDS" value="' . $perfectmoney["BAGGAGE_FIELDS"] . '">
                      <input type="hidden" name="ORDER_NUM" value="' . $perfectmoney["ORDER_NUM"] . '">
                      <input type="hidden" name="CUST_NUM" value="' . $perfectmoney["CUST_NUM"] . '">
                      <input type="hidden" name="PAYMENT_ID" value="' . $perfectmoney["PAYMENT_ID"] . '>
                      <input type="hidden" name="PAYMENT_URL_METHOD" value="POST">
                      <input type="hidden" name="NOPAYMENT_URL_METHOD" value="POST">
                      <input type="hidden" name="SUGGESTED_MEMO" value="' . $perfectmoney["memo"] . '">
                      <script type="text/javascript">
                        document.getElementById("redirection_form").submit();
                      </script>
                    </form>
                  </div>
                </div>';
                
                
  
     elseif ($method_id == 49) : // primepayments.io 

            $amount = (float)$amount;
            $amount = number_format($amount, 2);
            


            $getcur = empty($extra["currency"]) ? "RUB" : $extra["currency"];

            if ($amount < 50) :
                $error = 1;
                $errorText = "Minimum 50 required";
            else :


                if ($getcur != $settings["site_currency"]) :
                    $getamo = convertCurrencyUpdated($getcur, $settings["site_currency"], $amount);
                    $payment_extraa = json_encode(array('currency' => $getcur, 'amount' => $amount));
                else :
                    $getamo = $amount;
                    $payment_extraa = json_encode([]);
                endif;



                $tnx_id = "pp_" . strtotime(date("Y-m-d H:i:s")) . rand(50, 500);

                $primepayments = array(
                    "project" => $extra["project_id"],
                    "sum" => $amount,
                    "currency" => $getcur,
                    "innerID" => $tnx_id,
                    "comment" => "Balance Recharge (User : " . $user["username"] . ")",
                    "email" => $user["email"],

                );
                
                // if($user["username"] == "nikdeveloper"):
                //         p($primepayments);
                //     endif;

                $action = "initPayment";

                $signature = md5($extra["secret1"] . $action . $primepayments["project"] . $primepayments["sum"] . $primepayments["currency"] . $primepayments["innerID"] . $primepayments["email"]);
                $primepayments["sign"] = $signature;
                $primepayments["action"] = $action;

                $url = 'https://pay.primepayments.io/API/v2/';
                $response = sendCurlRequest($url, "POST", $primepayments, array('Content-Type: application/x-www-form-urlencoded'));
 

                $response = json_decode($response, true);


                if (!empty($response) && $response["status"] == "OK") :
                    $payment_response = json_encode([[
                        "time" => date("Y-m-d H:i:s"),
                        "response" => $response,
                    ]]);

                    $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, client_balance=:client_balance ,payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra , payment_extraa=:payment_extraa , payment_response=:payment_response");
                    $insert = $insert->execute(array("c_id" => $user['client_id'], "amount" => $getamo, "client_balance" => $user["balance"], "code" => $paymentCode, "method" => $method_id, "mode" => "Otomatik", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $tnx_id, "payment_extraa" => $payment_extraa, "payment_response" => $payment_response));

                    if ($insert) :
                        $success = 1;
                        $successText = "Your payment was initiated successfully, you are being redirected..";
                        $payment_url = $response["result"];
                    else :
                        $error = 1;
                        $errorText = "Something went wrong with your payment, please try again later!";
                    endif;
                else :
                    $error = 1;
                    $errorText = "Something went wrong with your payment gateway";
                endif;

            endif;
        elseif ($method_id == 43) : //swiftpay ph

            $env = "PROD"; //PROD OR SANDBOX
            $orderId = "SP" . strval(strtotime(date("Y-m-d H:i:s")) + rand(100, 1000000));
            $amount = (float)$amount;
            $currency = "PHP";
            $fee = $extra["fee"];

            if ($currency != $settings["site_currency"]) :
                $converted_amount = convertCurrencyUpdated($currency, $settings["site_currency"], $amount);
                $payment_extraa = json_encode(array('currency' => $currency, 'amount' => $amount));
            endif;



            if ($env == "SANDBOX") {
                $url = "api.pay.sandbox.live.swiftpay.ph";
                $secretKey = "9ACFB8E3F6454E748496457974CF51A8";
                $accessKey = "33A317C1B39A4D369463037B0CA938C5";
            } else {
                $url = "api.pay.live.swiftpay.ph";
                $secretKey = $extra["Secret Key"];
                $accessKey = $extra["Access Key"];
            }

            $params = [
                "x_access_key" => $accessKey,
                "x_amount" => number_format($amount, 2, '.', ','),
                "x_currency" => $currency,
                "x_reference_no" => $orderId
            ];

            $message = "";
            foreach ($params as $key => $value) {
                $message .= $key . $value;
            }

            $signature = hash_hmac('sha256', $message, $secretKey);

            $params["signature"] = $signature;

            $checkOutUrl = "https://" . $url . "/api/bootstrap?";
            $i = 1;
            $max = 6;
            foreach ($params as $key => $value) {
                if ($i != 1 && $i != $max) {
                    $checkOutUrl .= "&";
                }
                $checkOutUrl .= $key . "=" . $value;
                $i++;
            }

            $payment_code = generatePaymentCode($transactionId, $converted_amount, 43);

            $_SESSION["payment"][$orderId] = $params;

            $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, client_balance=:client_balance, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra , payment_extraa=:conversion");
            $insert = $insert->execute(array("c_id" => $user['client_id'], "amount" => $converted_amount,  "client_balance" => $user["balance"], "code" => $payment_code, "method" => $method_id, "mode" => "Otomatik", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $orderId, "conversion" => $payment_extraa));
            $success = 1;
            $successText = "Your payment was initiated successfully, you are being redirected..";

            if ($insert) :
                $success = 1;
                $successText = "Your payment was initiated successfully, you are being redirected..";
                $payment_url = $checkOutUrl;
            else :
                $error = 1;
                $errorText = "Something went wrong. Contact your administrator.";
            endif;




        elseif ($method_id == 42) : // gcash automatic

            $amount = (float)$amount;
            $amount = number_format($amount, 2, '.', '');
            $getcur = 'PHP';
            if ($getcur != $settings["site_currency"]) :
                $getamo = convertCurrencyUpdated($getcur, $settings["site_currency"], $amount);
                $payment_extraa = json_encode(array('currency' => $getcur, 'amount' => $amount));
            endif;



            $chargeData = array(
                'x-public-key' => $extra['Api Key'],
                'amount' => $amount,
                'expiry' => 1,
                'description' => 'Add funds ' . $user["username"],
                'fee' => $extra['fee'],
                'customername' => $user["name"],
                'customermobile' => $user["telephone"],
                'customeremail' => $user["email"],
                'redirectsuccessurl' => site_url('payment/gcash_auto'),
                'redirectfailurl' => site_url('payment/gcash_auto'),
                'webhooksuccessurl' => site_url('payment/gcash_auto'),
                'webhookfailurl' => site_url('payment/gcash_auto'),
            );


            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://g.payx.ph/payment_request',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $chargeData,
            ));
            $response = curl_exec($curl);
            curl_close($curl);

            $response = json_decode($response, true);

            if ($response["data"]) :
                $payment_response = [
                    'time' => date('Y-m-d H:i:s'),
                    'response' => $response["data"],
                ];


                $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, client_balance=:client_balance, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra , payment_extraa=:conversion , payment_response=:payment_response");
                $insert->execute(array("c_id" => $user['client_id'], "amount" => $getamo,  "client_balance" => $user["balance"], "code" => $paymentCode, "method" => $method_id, "mode" => "Otomatik", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $response["data"]["hash"], "conversion" => $payment_extraa, "payment_response" => json_encode([$payment_response])));
                $success = 1;
                $successText = "Your payment was initiated successfully, you are being redirected..";

                header("Location: " . $response["data"]["checkouturl"]);
            else :
                $error = 1;
                $errorText = "Something went wrong. Contact your administrator.";
            endif;



        elseif ($method_id == 46) : // cashfree automatic

            $amount = (float)$amount;
            $amount = number_format($amount, 2, '.', '');
            $getcur =  empty($extra['currency']) ? "INR" : $extra['currency'];
            if ($getcur != $settings["site_currency"]) :
                $getamo = convertCurrencyUpdated($getcur, $settings["site_currency"], $amount);
                $payment_extraa = json_encode(array('currency' => $getcur, 'amount' => $amount));
            else :
                $getamo = $amount;
                $payment_extraa = json_encode([]);
            endif;
            $secret_key = $extra["secret_key"];

            $params = [
                'appId' =>  $extra["app_Id"],
                'orderId' =>  "CF_" . strval(strtotime(date("Y-m-d H:i:s")) + rand(50000, 5000000) * rand(2, 5)),
                'orderAmount' => $amount,
                'orderCurrency' => $getcur,
                'orderNote' => 'Balance Recharge (' . $user["username"] . ')',
                'customerName' => $user["username"],
                'customerEmail' => $user["email"],
                'customerPhone' => $user["telephone"],
                'returnUrl' => site_url('payment/cashfree'),
                'notifyUrl' => site_url('payment/cashfree'),
            ];


            ksort($params);
            $signatureData = "";
            foreach ($params as $key => $value) {
                $signatureData .= $key . $value;
            }
            $signature = hash_hmac('sha256', $signatureData, $secret_key, true);
            $signature = base64_encode($signature);
            $mode = "PROD";
            if ($mode == "PROD") {
                $url = "https://www.cashfree.com/checkout/post/submit";
            } else {
                $url = "https://test.cashfree.com/billpay/checkout/post/submit";
            }

            $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, client_balance=:client_balance, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra , payment_extraa=:conversion");
            $insert =  $insert->execute(array("c_id" => $user['client_id'], "amount" => $getamo,  "client_balance" => $user["balance"], "code" => $paymentCode, "method" => $method_id, "mode" => "Otomatik", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $params["orderId"], "conversion" => $payment_extraa));

            if ($insert) :
                echo '<form id="redirection_form" action="' . $url . '" name="frm1" method="post">
            <input type="hidden" name="signature" value="' . $signature . '"/>
            <input type="hidden" name="orderNote" value="' . $params["orderNote"] . '"/>
            <input type="hidden" name="orderCurrency" value="' . $params["orderCurrency"] . '"/>
            <input type="hidden" name="customerName" value="' . $params["customerName"] . '"/>
            <input type="hidden" name="customerEmail" value="' . $params["customerEmail"] . '"/>
            <input type="hidden" name="customerPhone" value="' . $params["customerPhone"] . '"/>
            <input type="hidden" name="orderAmount" value="' . $params["orderAmount"] . '"/>
            <input type ="hidden" name="notifyUrl" value="' . $params["notifyUrl"] . '"/>
            <input type ="hidden" name="returnUrl" value="' . $params["returnUrl"] . '"/>
            <input type="hidden" name="appId" value="' . $params["appId"] . '"/>
            <input type="hidden" name="orderId" value="' . $params["orderId"] . '"/>
            </form>
            <script type="text/javascript">
            setTimeout(function(){
                document.getElementById("redirection_form").submit();
            },1000);
              </script>';
                $success = 1;
                $successText = "Your payment was initiated successfully, you are being redirected..";

            else :
                $error = 1;
                $errorText = "Something went wrong. Contact your administrator.";
            endif;
            
        elseif ($method_id == 50) : // bananapay
 

            $amount = (float)$amount;
            $amount = number_format($amount, 2, '.', '');
            $getcur =  empty($extra['currency']) ? "PHP" : $extra['currency'];
            if ($getcur != $settings["site_currency"]) :
                $getamo = convertCurrencyUpdated($getcur, $settings["site_currency"], $amount);
                $payment_extraa = json_encode(array('currency' => $getcur, 'amount' => $amount));
            else :
                $getamo = $amount;
                $payment_extraa = json_encode([]);
            endif;



            $url = "https://pay.bananapay.cn/phl/api/v3.0/Cashier.Payment.JsapiPay";

            $pay_way = "gcashpay";


            $access_key = $extra['access_key'];
            $sign_key = $extra['sign_key'];
            $notify_key = $extra['notify_key'];

            $order_id = "BP_" . strval(strtotime(date("Y-m-d H:i:s")) + rand(50000, 5000000) * rand(2, 5));

            $params = [
                'access_key' => ($access_key),
                'pay_way' =>  $pay_way,
                'out_trade_no' => $order_id,
                'tname' => 'Balance Recharge (' . $user["username"] . ')',
                'tprice' => $amount,
                'fee_type' => "PHP",
                'notify_url' => site_url('payment/bananapay'),
                'cancel_jump_url' => site_url('payment/bananapay'),
                'jump_url' => site_url('payment/bananapay'),
                'ts' => strtotime(date("Y-m-d H:i:s")),
            ];

            $signString = "access_key=" . $params["access_key"] . "&cancel_jump_url=" . $params["notify_url"] . "&fee_type=" . $params["fee_type"] . "&jump_url=" . $params["notify_url"] . "&notify_url=" . $params["notify_url"] . "&out_trade_no=" . $params["out_trade_no"] . "&pay_way=" . $params["pay_way"] . "&tname=" . $params["tname"] . "&tprice=" . $params["tprice"] . "&ts=" . $params["ts"] . "&key=" . $sign_key . "";

            $sign = md5($signString);

            $params["sign"] =  strtoupper($sign);

            $curl = curl_init($url);
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

            $headers = array(
                "Content-Type: application/json",
            );
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

            $data = json_encode($params);

            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

            // Enable SSL verification
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);

            $resp = curl_exec($curl);
            curl_close($curl);

            $response = json_decode($resp, true);

            $responseData = $response["results"];

            $payment_response = json_encode([[
                "time" => date("Y-m-d H:i:s"),
                "response" => $responseData,
            ]]);


            $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, client_balance=:client_balance, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra , payment_extraa=:conversion , payment_response=:payment_response");
            $insert =  $insert->execute(array("c_id" => $user['client_id'], "amount" => $getamo,  "client_balance" => $user["balance"], "code" => $paymentCode, "method" => $method_id, "mode" => "Otomatik", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $order_id, "conversion" => $payment_extraa , "payment_response" => $payment_response));

            if ($insert && $response["message"] == "success") :

                $payment_url = $responseData["api_url"];

                $success = 1;
                $successText = "Your payment was initiated successfully, you are being redirected..";


            else :
                $error = 1;
                $errorText = "Something went wrong. Contact your administrator.";
            endif;
    
     elseif ($method_id == 51) : // toyyibPay


            $amount = (float)$amount;
            $amount = number_format($amount, 2, '.', '');
            $getcur = empty($extra['currency']) ? "MYR" : $extra['currency'];

            if ($getcur != $settings["site_currency"]) :
                $getamo = convertCurrencyUpdated($getcur, $settings["site_currency"], $amount);
                $payment_extraa = json_encode(array('currency' => $getcur, 'amount' => $amount));
            else :
                $getamo = $amount;
                $payment_extraa = json_encode([]);
            endif;

            $baseUrl = "https://toyyibpay.com/";

            $url = $baseUrl . "index.php/api/createBill";

            $orderId = "TP_" . strval(strtotime(date("Y-m-d H:i:s")) + rand(50000, 5000000) * rand(2, 5));
$user["telephone"]="22212221111";

            $billTo = !empty($user['name']) ? $user['name'] : 'Guest User'; // Fallback if name is empty

            $params = array(
                'userSecretKey' => $extra["user_secret"],
                'categoryCode' => $extra["category_code"],
                'billName' => $user["username"],
                'billDescription' => 'Balance Recharge (' . $user["username"] . ')',
                'billPriceSetting' => 1,
                'billPayorInfo' => 1,
                'billAmount' => $amount * 100,
                'billReturnUrl' => site_url('payment/toyyibPay'),
                'billCallbackUrl' => site_url('payment/toyyibPay'),
                'billExternalReferenceNo' => $orderId,
                'billTo' => $billTo,
                'billEmail' => $user["email"],
                'billPhone' => preg_replace('/[^0-9]/', '', str_replace(' ', '', $user["telephone"])),
                'billPaymentChannel' => $extra["billPaymentChannel"],
            );
 
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $params);

              $result = curl_exec($curl);
            $info = curl_getinfo($curl);
            curl_close($curl);
            $result = json_decode($result, true);

            if (empty($result[0]["BillCode"])) :

                $error = 1;
                $errorText = "Something went wrong. Contact your administrator.";

            else :

                 $result[0]["Amount"] = $amount;

                $payment_response = json_encode([[
                    "time" => date("Y-m-d H:i:s"),
                    "response" => $result,
                ]]);

                $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, client_balance=:client_balance, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra , payment_extraa=:conversion , payment_response=:payment_response");
                $insert =  $insert->execute(array("c_id" => $user['client_id'], "amount" => $getamo,  "client_balance" => $user["balance"], "code" => "", "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $orderId, "conversion" => $payment_extraa , "payment_response" => $payment_response));

                if ($insert) :
                    $payment_url = $baseUrl .  $result[0]["BillCode"];
                    $success = 1;
                    $successText = "Your payment was initiated successfully, you are being redirected..";
                else :
                    $error = 1;
                    $errorText = "Something went wrong. Contact your administrator.";
                endif;
            endif;


        elseif ($method_id == 98) : // enot.io

            //method_get - enot
            if (empty($extra['currency'])) :
                $extra['currency'] = 'RUB';
            endif;

            $amount = (float)$amount;
            $amount = number_format($amount, 2, '.', '');
            $getcur = $extra["currency"];

            if ($getcur != $settings["site_currency"]) :
                $getamo = convertCurrencyUpdated($getcur, $settings["site_currency"], $amount);
                $payment_extraa = json_encode(array('currency' => $getcur, 'amount' => $amount));
            else :
                $getamo = $amount;
                $payment_extraa = json_encode([]);
            endif;


            $merchant_id    = $extra['merchant_id']; // Store ID                  
            $secret_key    = $extra['secret_key']; // Secret Key          
            $additional_secret_key    = $extra['additional_secret_key'];
            $orderId = "ENOT_" . strval(strtotime(date("Y-m-d H:i:s")) + rand(50000, 500000000) * rand(2, 10));

            $hash = md5($merchant_id . ':' . $amount . ':' . $secret_key . ':' . $orderId); //Generate key  

            $paymentCode = generatePaymentCode($orderId, $amount, $merchant_id, "encrypt");

            $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, client_balance=:client_balance, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra , payment_extraa=:conversion");
            $insert =  $insert->execute(array("c_id" => $user['client_id'], "amount" => $getamo,  "client_balance" => $user["balance"], "code" => $paymentCode, "method" => $method_id, "mode" => "Otomatik", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $orderId, "conversion" => $payment_extraa));

            //res https://enot.io/en/_/pay/check?PAYEE_ACCOUNT=U21541377&PAYMENT_AMOUNT=0.01&PAYMENT_UNITS=USD&PAYMENT_BATCH_NUM=501601165&PAYMENT_ID=133789869&SUGGESTED_MEMO=https//enot.io/_/pay/check&V2_HASH=93F6413F105E0AA8208F349EF928A428&TIMESTAMPGMT=1673930190&PAYER_ACCOUNT=U34727775&sign=07e827a1ec76a30aa9c115e077578a8d

            if ($insert) :
                $username = $user["username"];
                $successUrl = site_url('payment/enot');
                $failUrl = site_url('payment/enot');
                $customFields = array([
                    "order_id" => $orderId,
                    "paymentCode" => $paymentCode,
                ]);
                echo "<form method=\"get\" id=\"redirection_form\" action=\"https://enot.io/pay\">
                    <input type='hidden' name='m' value='$merchant_id'>
                    <input type='hidden' name='oa' value='$amount'>
                    <input type='hidden' name='o' value='$orderId'>
                    <input type='hidden' name='s' value='$hash'>
                    <input type='hidden' name='cr' value='$getcur'>
                    <input type='hidden' name='c' value='Balance Recharge ($username)'>
                    <input type='hidden' name='cf' value='$customFields'>
                    <input type='hidden' name='success_url' value='$successUrl'>
                    <input type='hidden' name='fail_url' value='$failUrl'>
                    </form>
                <script type=\"text/javascript\">
                setTimeout(function(){
                document.getElementById(\"redirection_form\").submit();
                },1000);
              </script>";
                $success = 1;
                $successText = "Your payment was initiated successfully, you are being redirected..";

            else :
                $error = 1;
                $errorText = "Something went wrong. Contact your administrator.";
            endif;


        elseif ($method_id == 47) : // bux.ph automatic



            if (empty($extra['currency'])) :
                $extra['currency'] = 'PHP';
            endif;

            $amount = (float)$amount;
            $amount = number_format($amount, 2, '.', '');
            $getcur = $extra["currency"];

            if ($getcur != $settings["site_currency"]) :
                $getamo = convertCurrencyUpdated($getcur, $settings["site_currency"], $amount);
                $payment_extraa = json_encode(array('currency' => $getcur, 'amount' => $amount));
            else :
                $getamo = $amount;
                $payment_extraa = json_encode([]);
            endif;

            if ($amount < 50) :
                header("Location: " . site_url("addfunds?error=Minimum 50 PHP required"));
            endif;

            $bux = new Bux([
                'app_key' => $extra["api_key"],
                'client_id' => $extra['client_id'],
                'client_secret' => $extra['client_secret']
            ]);

            $orderId = "BUX_" . strval(strtotime(date("Y-m-d H:i:s")) + rand(50000, 5000000) * rand(2, 5));


            $params =  [
                'amount' => $amount,
                'description' => 'Balance Recharge (' . $user["username"] . ')',
                'order_id' => $orderId,
                'email' => $user["email"],
                'phone' => $user["telephone"],
                'name' => $user["username"],
                'expiry' => '6',
                'fee' => empty($extra["fee"]) ? 0 : $extra["fee"],
                'notification_url' => $bux->muteDefaultWCIPN(site_url('payment/buxph')),
                'redirect_url' => site_url('payment/buxph'),
            ];


            // Create Request Payment
            $payment_request = $bux->paymentRequest($params);


            // checkout URL for the payee
            $checkout_url = $bux->checkoutUrl($payment_request['uid']);

            // // get payment info
            // $payment_info = $bux->getPaymentInfo($payment_request['uid']);
            $server_response = array([
                'time' => date("Y-m-d H:i:s"),
                'response' => $payment_request
            ]);

            if ($payment_request["status"] == "success") :
                $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, client_balance=:client_balance, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra , payment_extraa=:conversion , payment_response=:payment_response");
                $insert->execute(array("c_id" => $user['client_id'], "amount" => $getamo,  "client_balance" => $user["balance"], "code" => $paymentCode, "method" => $method_id, "mode" => "Otomatik", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "payment_response" => json_encode($server_response), "conversion" => $payment_extraa, "extra" => $orderId));
                $success = 1;
                $successText = "Your payment was initiated successfully, you are being redirected..";

                echo "<script>
                    setTimeout(function(){
                        window.location.href = '$checkout_url';
                    },1000);
                </script>";

            else :
                $error = 1;
                $errorText = "Something went wrong. Contact your administrator.";
            endif;


        elseif ($method_id == 41) : // Coinbase-commerce

            if (empty($extra['currency'])) :
                $extra['currency'] = 'USD';
            endif;


            $amount = (float)$amount;
            $amount = number_format($amount, 2, '.', '');
            $getcur = $extra["currency"];
            if ($getcur != $settings["site_currency"]) :
                $getamo = convertCurrencyUpdated($getcur, $settings["site_currency"], $amount);
                $payment_extraa = json_encode(array('currency' => $getcur, 'amount' => $amount));
            else :
                $getamo = $amount;
                $payment_extraa = json_encode([]);
            endif;



            $chargeData = [
                'name' => 'Balance recharge ' . $user["username"],
                'description' => 'Balance recharge '  . $user["username"],
                'local_price' => [
                    'amount' => $amount,
                    'currency' => $extra["currency"],
                ],
                'pricing_type' => 'fixed_price',
                'redirect_url' => site_url('addfunds'),
            ];

            $api_key = $extra["Api Key"];


            $apiClientObj = ApiClient::init($api_key);

            $apiClientObj->setTimeout(3);
            try {
                $chargeData = Charge::create($chargeData);

                $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, client_balance=:client_balance, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra , payment_extraa=:conversion");
                $insert->execute(array("c_id" => $user['client_id'], "amount" => $getamo,  "client_balance" => $user["balance"], "code" => $paymentCode, "method" => $method_id, "mode" => "Otomatik", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $chargeData->code, "conversion" => $payment_extraa));
                $success = 1;
                $successText = "Your payment was initiated successfully, you are being redirected..";

                echo '<form method="get" id="redirection_form" action="' . $chargeData->hosted_url . '">
                </form>
                <script type="text/javascript">
                document.getElementById("redirection_form").submit();
              </script>';
            } catch (\Throwable $th) {
                $error = 1;
                $errorText = "Something went wrong. Contact your administrator.";
            }

        elseif ($method_id == 35) :
 
            if (empty($extra['currency'])) :
                $extra['currency'] = 'USD';
            endif;

            $amount = (float)$amount;
            $order_id = strtotime('NOW') . rand(10000, 99999999);

            $m_shop = $extra['merchant_id'];
            $m_orderid = $order_id;
            $m_amount = number_format($amount, 2, '.', '');
            $m_curr = $extra['currency'];
            $m_desc = base64_encode('Balance recharge (' . $user["username"] . ')');
            $m_key = $extra['secret_key'];
            $getcur = $extra['currency'];

 

            if ($getcur != $settings["site_currency"]) :
                $getamo = convertCurrencyUpdated($getcur, $settings["site_currency"], $amount);
                $payment_extraa = json_encode(array('currency' => $getcur, 'amount' => $amount));
            else :
                $getamo = $amount;
                $payment_extraa = json_encode([]);
            endif;


            // $m_orderid = "12345";
            // $m_amount = number_format(50, 2, '.', '');
            // $m_desc = base64_encode('Test payment №12345');


            $arHash = array(
                $m_shop,
                $m_orderid,
                $m_amount,
                $m_curr,
                $m_desc
            );


            $arHash[] = $m_key;


            $sign = strtoupper(hash('sha256', implode(':', $arHash)));




            $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, client_balance=:client_balance,  payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra , payment_extraa=:payment_extraa");
            $insert->execute(array("c_id" => $user['client_id'],  "client_balance" => $user["balance"], "amount" => $getamo, "code" => $paymentCode, "method" => $method_id, "mode" => "Otomatik", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $m_orderid, "payment_extraa" => $payment_extraa));
            $success = 1;
            $successText = "Your payment was initiated successfully, you are being redirected..";



            echo '<form method="post" id="redirection_form" action="https://payeer.com/merchant/">
                <input type="hidden" name="m_shop" value=" ' . $m_shop . '">
                <input type="hidden" name="m_orderid" value=" ' . $m_orderid . '">
                <input type="hidden" name="m_amount" value=" ' . $m_amount . '">
                <input type="hidden" name="m_curr" value=" ' . $m_curr . '">
                <input type="hidden" name="m_desc" value=" ' . $m_desc . '">
                <input type="hidden" name="m_sign" value=" ' . $sign . '">
                <input type="submit" name="m_process" value="send" />
                </form>
                <script type="text/javascript">
                document.getElementById("redirection_form").submit();
              </script>';
              
              
              
   elseif ($method_id == 9090):
             $uniqueOrderId = mt_rand();

              $payment_amount = $amount;
              
                    $method = $conn->prepare("SELECT * FROM payment_methods WHERE id=:id ");
        $method->execute(array("id" => $method_id));
        $method = $method->fetch(PDO::FETCH_ASSOC);
        $extra = json_decode($method["method_extras"], true);
        
        $amount = $amount - ($amount * $extra["fee"] / 100);
              
        $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
        $insert->execute(array("c_id" => $user['client_id'], "amount" => $amount, "code" => $paymentCode, "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $uniqueOrderId));
 

        $channel = $extra['channel'];
        $secret = $extra['secret'];
        $website = $extra['websiteUrl'];
         

        $headers = array(
            "Content-Type: application/json",
            "channel: $channel",
            "secret: $secret",
            "websiteurl: $website"
        );
        
        $post_vals = array(
            'amount' => $payment_amount,
            'currency'=> 'USD',
            'invoice' => 'Payment ' . $uniqueOrderId,
            'externalId' => $uniqueOrderId,
            'successCallbackUrl' =>  site_url('callback/wish')."?payment_id=".$uniqueOrderId,
            'failureCallbackUrl' =>site_url('callback/wish')."?payment_id=".$uniqueOrderId,
            'successRedirectUrl' => site_url('addfunds'),
            'failureRedirectUrl'=> site_url('addfunds')
        );
        
      
                 $url = 'https://whish.money/itel-service/api/payment/whish';
        
$data = json_encode($post_vals);
 

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $result = @curl_exec($ch);
        echo $result;
        if (curl_errno($ch)) {
            die("PAYTR IFRAME connection error. err:" . curl_error($ch));
        }
        curl_close($ch);
        $result = json_decode($result,1);
        
    
        if (@$result['data']['collectUrl']) {
            header("Location:" . $result['data']['collectUrl']);
        }
        exit;
           
 
 
 elseif($method_id == 3354):
                               include_once('callback/OAuth.php');

 $m_orderid = md5(rand(1,999999));

               $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
        $insert->execute(array("c_id" => $user['client_id'], "amount" => $amount, "code" => $paymentCode, "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $m_orderid));

 $token = $params = NULL;

$consumer_key = $extra["Consumer_Key"];//Register a merchant account on
                   //demo.pesapal.com and use the merchant key for testing.
                   //When you are ready to go live make sure you change the key to the live account
                   //registered on www.pesapal.com!
$consumer_secret = $extra["Consumer_Secret"];// Use the secret from your test
                   //account on demo.pesapal.com. When you are ready to go live make sure you 
                   //change the secret to the live account registered on www.pesapal.com!
$signature_method = new OAuthSignatureMethod_HMAC_SHA1();
$iframelink = 'https://www.pesapal.com/api/PostPesapalDirectOrderV4';//change  
//get form details
 $amount = number_format($amount, 2);//format amount to 2 decimal places

$desc =  "Balance recharge (" . $user["username"] . ")";
$type = "MERCHANT"; //default value = MERCHANT
$reference = $m_orderid;//unique order id of the transaction, generated by merchant
$first_name = $user["name"];
$last_name = $user["name"];
$email =  $user["email"];
$phonenumber = '';//ONE of email or phonenumber is required

$callback_url = site_url("callback/pesapal"); //redirect url, the page that will handle the response from pesapal.

// htmlentities.
$post_xml = '<?xml version="1.0" encoding="utf-8"?>';
$post_xml .= '<PesapalDirectOrderInfo ';
$post_xml .= 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" ';
$post_xml .= 'xmlns:xsd="http://www.w3.org/2001/XMLSchema" ';
$post_xml .= 'Amount="'.$amount.'" ';
$post_xml .= 'Description="'.$desc.'" ';
$post_xml .= 'Type="'.$type.'" ';
$post_xml .= 'Reference="'.$reference.'" ';
$post_xml .= 'FirstName="'.$first_name.'" ';
$post_xml .= 'LastName="'.$last_name.'" ';
$post_xml .= 'Email="'.$email.'" ';
$post_xml .= 'PhoneNumber="'.$phonenumber.'" ';
$post_xml .= 'xmlns="http://www.pesapal.com" />';
$post_xml = htmlentities($post_xml);

$consumer = new OAuthConsumer($consumer_key, $consumer_secret);

// Construct the OAuth Request URL & post transaction to pesapal
$signature_method = new OAuthSignatureMethod_HMAC_SHA1();
$iframe_src = OAuthRequest::from_consumer_and_token($consumer, $token, 'GET', $iframelink, $params);
$iframe_src -> set_parameter('oauth_callback', $callback_url);
$iframe_src -> set_parameter('pesapal_request_data', $post_xml);
$iframe_src -> sign_request($signature_method, $consumer, $token);

 header("Location: " .$iframe_src);
            exit;
                    elseif ($method_id == 399):
$amount = (double)$amount;
$email  = $user['email'];
		$curl = curl_init();
		curl_setopt_array($curl, array(
		  CURLOPT_URL => "https://api.paystack.co/transaction/initialize",
		  CURLOPT_RETURNTRANSFER => true,
		  CURLOPT_CUSTOMREQUEST => "POST",
		  CURLOPT_POSTFIELDS => json_encode([
		    'amount'       => $amount * 100, //the amount in kobo. This value is actually NGN 300
		    'email'        => $email,
		    'callback_url' => site_url('callback/paystack'), 
		  ]),
		  CURLOPT_HTTPHEADER => [
		    "authorization: Bearer ". $extra['secret_key'],  
		    "content-type: application/json",
		    "cache-control: no-cache"
		  ],
		));
		$response = curl_exec($curl);
		$err = curl_error($curl);

		
		$tranx = json_decode($response, true);
		 $insert                       = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
        $insert->execute(array("c_id" => $user['client_id'], "amount" => $amount, "code" => $paymentCode, "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $tranx['data']['reference']));

$paymet_url = $tranx['data']['authorization_url'];
    header("location:".$paymet_url);
                
                
					   

 elseif ($method_id == 5346):

    $orderId = md5(rand(1,999999));
    $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
    $insert->execute(array("c_id" => $user['client_id'], "amount" => $amount, "code" => $paymentCode, "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $orderId));
    
    $endpoint = "https://api.korapay.com/merchant/api/v1/charges/initialize";
    $secret_key = $extra["secret_key"];
    $amount = (double)$amount;
    $email = $user['email'];
    
    if (!$user['first_name']) {
        $user['first_name'] = "admin From Admin";
    }
    
    // Data for the request
    $data = array(
        'amount' => $amount,
        'currency' => 'NGN',
        'reference' => $orderId,
        'redirect_url' => site_url('callback/korapay'),
        'customer' => array(
            'email' => $user['email'],
            'name' => $user['first_name'],
        ),
    );
    
    // Convert the data to JSON format
    $json_data = json_encode($data);
    
    // Initialize cURL session
    $ch = curl_init($endpoint);
    
    // Set the cURL options
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $secret_key,
    ));
    
    $response = curl_exec($ch);
    
    // Check for cURL errors
    if (curl_errno($ch)) {
        echo 'cURL error: ' . curl_error($ch);
    }
    
    curl_close($ch);
    
    $result = json_decode($response, true);
    $checkOutURL = $result['data']['checkout_url'];
    
    header("location: $checkOutURL");
    exit;                      
                      
              
              elseif ($method_id == 1110):
           
         require 'lib/cryptomusgateway/vendor/autoload.php';
$orderId = md5(mt_rand() . time());
$callbackURL = site_url("callback/crypto");
  $apiKey = $extra['apiKey'];
    $merchantUuid = $extra['merchantUuid'];
 

     $amount = $amount;
    $currencyCode = "USDT";

    $data = [];
    $data['amount'] = (string)$amount;
    $data['currency'] = $currencyCode;
    $data['order_id'] = $orderId;
    
    $fee = $extra["fee"];
$amount_fee = $amount - ($amount * $fee/100);
$insert = $conn->prepare(
    "INSERT INTO payments SET
client_id=:client_id,
payment_amount=:amount,
payment_method=:method,
payment_mode=:mode,
payment_create_date=:date,
payment_ip=:ip,
payment_extra=:extra"
);

$insert->execute([
    "client_id" => $user["client_id"],
    "amount" => $amount_fee,
    "method" => $method_id,
    "mode" => "Auto",
    "date" => date("Y.m.d H:i:s"),
    "ip" => GetIP(),
    "extra" =>$data['order_id']
]);
    
    $data['url_return'] = site_url("addfunds");
    $data['url_callback'] = $callbackURL;
    $data['is_payment_multiple'] = true;
    $data['lifetime'] = '43200';
    $data['is_refresh'] = true;
    $data['whmcs_version'] = "1.0";
    $data['payer_email'] = isset($user["email"]) ?  $user["email"] : null;

   
    $payment = \Cryptomus\Api\Client::payment($apiKey, $merchantUuid);
    $paymentCreate = $payment->create($data);

   
    
      $redirectForm = $paymentCreate['url'];
      header("Location:".$redirectForm);
      
      
            elseif ($method_id == 6766):
        $tx_orderID = $_POST['utr_id'] ?? false;

if ($tx_orderID !== false) {
    $paymentCountStatus3 = countRow([
        'table' => 'payments',
        'where' => [
            'payment_method' => 6766,
            'payment_status' => 3,
            'payment_delivery' => 2,
            'payment_extra' => $tx_orderID
        ]
    ]);

    if ($paymentCountStatus3 > 0) {
        $tx_orderID = false;
    }

    if ($tx_orderID !== false) {
          $getcur = $method['method_currency'];

            if ($getcur != $settings["site_currency"]) :
                $amount_converted = convertCurrencyUpdated($getcur, $settings["site_currency"], $amount);
                $payment_extraa = json_encode(array('currency' => $getcur, 'amount' => $amount));
            else :
                $amount_converted = $amount;
            endif;

        
        
            require FILES_BASE . '/lib/bharat_lib.php';

        $sec_key = $extra["sec_key"];
        $apikey = $extra["apikey"];
        $bharatpeAPI = new BharatPeAPI($sec_key);
        $amount = $amount;
          if ($bharatpeAPI->initiateTransaction($tx_orderID, $apikey, $amount)) {
           
$insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
        $insert->execute(array("c_id" => $user['client_id'], "amount" => $amount_converted, "code" => $paymentCode, "method" => $method_id, "mode" => "Auto", "date" => date("Y-m-d H:i:s"), "ip" => GetIP(), "extra" => $tx_orderID));


 $do = bharatpe($tx_orderID);
            if ($do === true) {
                $success = 1;
                $successText = "Your Payment Has Been Added.";
                header("Refresh: 2");  

            } else {
                $error = 1;
                $errorText = "Transaction Already Exists";
            }
        } else {
            $error = 1;
            $errorText = "Transaction Not Found";
        }
    } else {
        $error = 1;
        $errorText = "Transaction Already Exists";
    }
} else {
    $error = 1;
    $errorText = "Invalid UTR";
}


elseif ($method_id == 667):
                 $vri=md5(rand(1,999999));
			     $pay_id = mt_rand(982538, 9825382937292);
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $nonce = '';
    for ($i = 1; $i <= 32; $i++) {
        $pos = mt_rand(0, strlen($chars) - 1);
        $char = $chars[$pos];
        $nonce .= $char;
    }
    $ch = curl_init();
    $timestamp = round(microtime(true) * 1000);
$fee = $extra["fee"];
$amount_fee = $amount - ($amount * $fee/100);
    // Request body
    $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
    $insert->execute(array("c_id" => $user['client_id'], "amount" => $amount_fee, "code" => $paymentCode, "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $pay_id));
    $_SESSION['TID'] = $pay_id;
    $_SESSION['VERI'] = $vri;

    $request = array(
        "env" => array(
            "terminalType" => "WEB"
        ),
        "merchantTradeNo" => $pay_id,
        "orderAmount" => $amount,
        "currency" => "USDT",
"returnUrl" => site_url('callback/binance?suc=' . $vri), // Corrected comma placement
        "cancelUrl" => site_url('addfunds'),
         "goods" => array(
            "goodsType" => "02",
            "goodsCategory" => "0000",
            "referenceGoodsId" => "7876763A3B",
            "goodsName" => "Addfunds in $settings[site_name]",
            "goodsDetail" => "Addfunds in $settings[site_name]"
        )
    );

    $json_request = json_encode($request);
    $payload = $timestamp . "\n" . $nonce . "\n" . $json_request . "\n";
    $binance_pay_key = $extra['binance_pay_key'];
    $binance_pay_secret = $extra['binance_pay_secret']; // Removed extra double quote here
    $signature = strtoupper(hash_hmac('SHA512', $payload, $binance_pay_secret));
    $headers = array();
    $headers[] = "Content-Type: application/json";
    $headers[] = "BinancePay-Timestamp: $timestamp";
    $headers[] = "BinancePay-Nonce: $nonce";
    $headers[] = "BinancePay-Certificate-SN: $binance_pay_key";
    $headers[] = "BinancePay-Signature: $signature";

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_URL, "https://bpay.binanceapi.com/binancepay/openapi/v2/order");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_request);

    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        echo 'Error:' . curl_error($ch);
    }
    curl_close($ch);
    $response = json_decode($result, true);

    // Check if the response contains a redirect URL
    if ($response['status'] === 'SUCCESS') {
        // Check if the "checkoutUrl" is present in the data
        if (isset($response['data']['checkoutUrl']) && !empty($response['data']['checkoutUrl'])) {
            // Perform a header redirect to the checkout URL
            header("Location: " . $response['data']['checkoutUrl']);
            exit;
        } else {
            // Handle error or display response
            var_dump($response);
        }
    } else {
        // Handle error or display response
        var_dump($response);
    }
    
    
    
    elseif ($method_id == 3804): // binance order id
 


           $binance_order_id = isset($_POST["binance_order_id"]) 
    ? preg_replace('/\D/', '', trim($_POST["binance_order_id"])) 
    : '';
            $api_key = $extra["binance_pay_key"];
            $secret_key = $extra["binance_pay_secret"];

            if (
                !countRow([
                    'table' => 'payments',
                    'where' => [
                        'payment_extra' => $binance_order_id,
                        'payment_method' => $method_id,
                        'payment_status' => 3,
                        'payment_delivery' => 2
                    ]
                ])
            ) {

               //  $timestamp = round(microtime(true) * 1000);
               
               // Binance ke server ka time le lo to avoid "Timestamp ahead" error
$time_ch = curl_init();
curl_setopt($time_ch, CURLOPT_URL, "https://api.binance.com/api/v3/time");
curl_setopt($time_ch, CURLOPT_RETURNTRANSFER, 1);
$server_time_response = curl_exec($time_ch);
curl_close($time_ch);

$server_time_data = json_decode($server_time_response, true);
$timestamp = $server_time_data['serverTime'] ?? round(microtime(true) * 1000);


                $startTime = $timestamp - (15 * 60 * 1000); // 15 minutes ago

                $endTime = $timestamp;
                $params = [
                    'timestamp' => $timestamp,
                    'startTime' => $startTime,
                    'endTime' => $endTime,
                    // Optional: 'limit' => 100
                ];

                $queryString = http_build_query($params);
                $signature = hash_hmac('sha256', $queryString, $secret_key);
                $url = "https://api.binance.com/sapi/v1/pay/transactions?$queryString&signature=$signature";

                $headers = [
                    'X-MBX-APIKEY: ' . $api_key,
                ];

               $ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$result = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if (curl_errno($ch)) {
    $error = 1;
    $errorText = 'cURL Error: ' . curl_error($ch);
    curl_close($ch);
    return;
}

curl_close($ch);
$response = json_decode($result, true);


                if ($response["success"] == 1) {

                    if (!empty($response["data"])) {
                        foreach ($response["data"] as $transaction) {
                            if ($transaction["orderId"] == $binance_order_id) {
                                $transactionAmount = $transaction["amount"];
                                $transactionCurrency = $transaction["currency"];


                                if ($transactionCurrency == "USDT") {
    $transactionCurrency = "USD";
    $transactionAmount = convertCurrencyUpdated($transactionCurrency, $settings["site_currency"], $transactionAmount);

    $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, client_balance=:client_balance, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
    $insert->execute(array(
        "c_id" => $user['client_id'],
        "amount" => $transactionAmount,
        "client_balance" => $user["balance"],
        "code" => $paymentCode,
        "method" => $method_id,
        "mode" => "Otomatik",
        "date" => date("Y-m-d H:i:s"),
        "ip" => GetIP(),
        "extra" => $transaction["orderId"]
    ));
    $insert_id = $conn->lastInsertId();

    $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_id=:id');
    $payment->execute(['id' => $insert_id]);
    $payment = $payment->fetch(PDO::FETCH_ASSOC);

    // BONUS LOGIC START
    $payment_bonus = $conn->prepare('SELECT * FROM payments_bonus WHERE bonus_method=:method && bonus_from<=:from ORDER BY bonus_from DESC LIMIT 1');
    $payment_bonus->execute([
        'method' => $method_id,
        'from' => $payment['payment_amount']
    ]);
    $payment_bonus = $payment_bonus->fetch(PDO::FETCH_ASSOC);

    if ($payment_bonus) {
        $amount = $payment['payment_amount'] + (($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100);
        $bonus_amount = ($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100;
    } else {
        $amount = $payment['payment_amount'];
        $bonus_amount = 0;
    }
    $final_balance = $payment['balance'] + $amount;
    // BONUS LOGIC END

    $conn->beginTransaction();

    $update = $conn->prepare('UPDATE payments SET client_balance=:balance, payment_amount=:amount, payment_status=:status, payment_response=:payment_response, payment_delivery=:delivery WHERE payment_id=:id');
    $update = $update->execute([
        'balance' => $payment['balance'],
        "amount" => $payment['payment_amount'],
        'status' => 3,
        'delivery' => 2,
        "payment_response" => json_encode($transaction),
        'id' => $payment['payment_id']
    ]);

    $balance = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id');
    $balance = $balance->execute([
        'id' => $payment['client_id'],
        'balance' => $final_balance
    ]);

    $insert = $conn->prepare('INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date');
    $insert25 = $conn->prepare("INSERT INTO payments SET client_id=:client_id , client_balance=:client_balance , payment_amount=:payment_amount , payment_method=:payment_method , payment_status=:status, payment_delivery=:delivery , payment_note=:payment_note , payment_create_date=:payment_create_date , payment_extra=:payment_extra , bonus=:bonus");

    if ($payment_bonus) {
        $insert25->execute(array(
            "client_id" => $payment['client_id'],
            "client_balance" => (($payment['balance'] + $amount) - $bonus_amount),
            "payment_amount" => $bonus_amount,
            "payment_method" => $method_id,
            'status' => 3,
            'delivery' => 2,
            "payment_note" => "Bonus added",
            "payment_create_date" => date('Y-m-d H:i:s'),
            "payment_extra" => "Bonus added for previous payment",
            "bonus" => 1
        ));
        $insert = $insert->execute([
            'c_id' => $payment['client_id'],
            'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'] . ' and included %' . $payment_bonus['bonus_amount'] . ' bonus.',
            'ip' => GetIP(),
            'date' => date('Y-m-d H:i:s')
        ]);
    } else {
        $insert = $insert->execute([
            'c_id' => $payment['client_id'],
            'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'],
            'ip' => GetIP(),
            'date' => date('Y-m-d H:i:s')
        ]);
    }

    if ($update && $balance) {
        afterPaymentDone($payment["username"], $amount, $method['method_name']);
        $conn->commit();
        echo 'OK';
        header("Location: " . site_url(""));
    } else {
        $conn->rollBack();
        echo 'NO';
    }
} else {
    $error = 1;
    $errorText = 'Invalid currency. Only USDT is supported. Contact administrator.';
}

                            }
                        }



                    } else {
                        $error = 1;
                        $errorText = 'No transactions found.';
                    }

                } else {
                    $error = 1;
                    $errorText = $response["msg"] ?? $response["message"] ?? "Unknown error";
                }
            } else {

                $error = 1;
                $errorText = 'Binance Order ID you entered is already used.';

            }
    
    
    
    elseif ($method_id == 123) : // ecpay automatic

$amount = (float)$amount;
$amount = number_format($amount, 2, '.', '');
$getcur = 'TWD'; // Default currency for ECPay

if ($getcur != $settings['site_currency']) :
    $getamo = convertCurrencyUpdated($getcur, $settings['site_currency'], $amount);
    $payment_extraa = json_encode(array('currency' => $getcur, 'amount' => $amount));
else :
    $getamo = $amount;
    $payment_extraa = json_encode(array('currency' => $getcur, 'amount' => $amount));
endif;

// Live credentials (ECPay official test provided)
$extra = array(
    'sandbox' => $extra['sandbox'], // live mode
    'MerchantID' =>  $extra['MerchantID'],
    'HashKey' =>  $extra['HashKey'],
    'HashIV' =>  $extra['HashIV'],
    'fee' =>  $extra['fee'],
    'fixed_fee' => 0
);

// Generate unique trade number
$merchantTradeNo = date("YmdHis") . rand(10000, 99999);

// ECPay parameters
$params = array(
    'MerchantID' => $extra['MerchantID'],
    'MerchantTradeNo' => $merchantTradeNo,
    'MerchantTradeDate' => date("Y/m/d H:i:s"),
    'PaymentType' => 'aio',
    'TotalAmount' => (int)$getamo + $extra['fixed_fee'],
    'TradeDesc' => 'Account Topup',
    'ItemName' => 'ATM',
    'ReturnURL' => site_url('callback/ecpay'),
    'OrderResultURL' => site_url('callback/ecpay'),
    'ClientBackURL' => site_url('callback/ecpay'),
    'ChoosePayment' => 'ATM',
    'EncryptType' => 1
);

// Generate check value
ksort($params);
$checkStr = '';
foreach ($params as $key => $value) {
    $checkStr .= $key . '=' . $value . '&';
}
$checkStr = rtrim($checkStr, '&');

$checkValue = strtoupper(hash('sha256', "HashKey={$extra['HashKey']}&$checkStr&HashIV={$extra['HashIV']}"));

// Build redirect form
$ecpay_url = 'https://payment.ecpay.com.tw/Cashier/AioCheckOut/V5'; // Live URL

$redirectForm = '<form id="ecpayForm" method="POST" action="'.$ecpay_url.'">';
foreach ($params as $key => $value) {
    $redirectForm .= '<input type="hidden" name="'.$key.'" value="'.$value.'">';
}
$redirectForm .= '<input type="hidden" name="CheckMacValue" value="'.$checkValue.'">';
$redirectForm .= '</form><script>document.getElementById("ecpayForm").submit();</script>';


$feePercentage = $extra['fee']; // e.g., 5 means 5%

$fee = ($getamo * $feePercentage) / 100;


// Insert transaction into database
$insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, client_balance=:client_balance, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra, payment_extraa=:conversion");
$insert->execute(array(
    "c_id" => $user['client_id'],
    "amount" => $getamo-$fee,
    "client_balance" => $user["balance"],
    "code" => $merchantTradeNo,
    "method" => $method_id,
    "mode" => "Automatic",
    "date" => date("Y.m.d H:i:s"),
    "ip" => GetIP(),
    "extra" => $merchantTradeNo,
    "conversion" => $payment_extraa
));

echo $redirectForm;
    
    
      elseif ($method_id == 5012):
         
            $merchant_uuid = $extra["merchant_uuid"];
            $payment_api_key = $extra["payment_api_key"];
            $order_id = "heleket_" . strtolower(generateRandomString(32));


            $data = [
                'amount' => (string) $amount_fee,
                'currency' => 'USD',
                'order_id' => $order_id,
                'url_return' => site_url("payment/heleket"),
                'url_callback' => site_url("addfunds"),
                'url_success' => site_url("payment/heleket"),
                'is_payment_multiple' => false,
                'from_referral_code' => 'w4pBRd',
                'additional_data' => json_encode(['order_id' => $order_id]),
            ];

            $body = json_encode($data, JSON_UNESCAPED_UNICODE);

            $headers = [
                'Accept: application/json',
                'Content-Type: application/json;charset=UTF-8',
                'Content-Length: ' . strlen($body),
                "merchant: $merchant_uuid",
                "sign: " . md5(base64_encode($body) . $payment_api_key),
                "Content-Type: application/json",
            ];

            $url = "https://api.heleket.com/v1/payment";

            $ch = curl_init();

curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true); // POST method
curl_setopt($ch, CURLOPT_POSTFIELDS, $body); // data array
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_TIMEOUT, 25);

$response = curl_exec($ch);
curl_close($ch);

$response = json_decode($response, true);

            $checkout_url = $response['result']['url'];

            if ($checkout_url) {

                $amount = convertCurrencyUpdated("USD", $settings['site_currency'], $amount);

                $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, client_balance=:client_balance, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra  , payment_extraa=:payment_extraa");
                $insert->execute(array("c_id" => $user['client_id'], "amount" => $amount, "client_balance" => $user["balance"], "code" => $paymentCode, "method" => $method_id, "mode" => "Auto", "date" => date("Y-m-d H:i:s"), "ip" => GetIP(), "extra" => $order_id, "payment_extraa" => json_encode($response)));
                header("Location: $checkout_url");
                exit();

            } else {
                $error = 1;
                $errorText = $response['message'];
            }

    
    
    elseif ($method_id == 2269):
 $orderId   = round(microtime(true) * 1000); // ✅ externalId
$callbackURL = site_url("callback/fapshi") . '?externalId=' . $orderId;
 $fapshi = new Fapshi($extra['api_user'],$extra['api_key']);

$payment = array(
    'amount' => (int)$amount,
    'email' =>$user['email'],
    'externalId' => $orderId,
    'userId' => $user['client_id'],
    'redirectUrl' =>$callbackURL,
    'message' => 'Addfunds in smm',
    // 'cardOnly' => true
);

$resp = $fapshi->initiate_pay($payment);
$resps= json_encode($resp, JSON_UNESCAPED_SLASHES);
 if (isset($resp['link']) && isset($resp['transId'])) {
    $link = $resp['link'];
    $transId = $resp['transId'];
     // Display the transaction ID
  // Request body
  
    
    $fee = $extra["fee"];
$amount_fee = $amount - ($amount * $fee/100);
    $insert = $conn->prepare(
        "INSERT INTO payments SET
        client_id=:client_id,
        payment_amount=:amount,
        payment_method=:method,
        payment_mode=:mode,
        payment_create_date=:date,
        payment_ip=:ip,
        payment_extra=:extra,
        payment_note=:note"
    );

    $insert->execute([
        "client_id" => $user["client_id"],
        "amount"    => $amount_fee,
        "method"    => $method_id,
        "mode"      => "Automatic",
        "date"      => date("Y.m.d H:i:s"),
        "ip"        => GetIP(),
        "extra"     => $orderId,  // externalId
        "note"      => $transId   // transId
    ]);

    
    // Redirect to the payment link
    header("Location: $link");
    exit;
} else {
    // Handle the case where the response does not contain the link or transId
    echo $resps;
}
    
     elseif ($method_id == 987):

$stamp = date("Ymdhis");
       $ip = $_SERVER['REMOTE_ADDR'];
       $sku = "$stamp-$ip";
       $sku = str_replace(".", "", "$sku");
       $sku = str_replace("-", "", "$sku");
       $sku = str_replace(":", "", "$sku");
       $sku = substr($sku, 0,15);

        $token =$extra['token'] ;
        $backgroundUrl         = site_url('callback/gbprimepay');;


        $order_id                     = $sku;
       
        $insert                       = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
        $insert->execute(array("c_id" => $user['client_id'], "amount" => $amount, "code" => $paymentCode, "method" => $method_id, "mode" => "", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $order_id));


        echo '
<form action="https://api.gbprimepay.com/v3/qrcode" method="POST" id="pay"> 
              <input type="hidden" name="token" value="'.$token.'">
  <input type="hidden" name="referenceNo"   value="' .$order_id .'">
  <input type="hidden" name="backgroundUrl" value="' .$backgroundUrl .'">
            <input type="hidden" name="amount"      value="' . $amount . '"/>


            


              <script type="text/javascript">
                document.getElementById("pay").submit();
              </script>
            </form>
          </div>
        </div>';
        
        
        
        elseif ($method_id == 990):
   $m_orderid = md5(rand(1,999)).time;

$secretKey = $extra["secretKey"];
$orderId =$m_orderid;
$callbackURL = site_url("callback/flutterwave");

$insert = $conn->prepare(
    "INSERT INTO payments SET
client_id=:client_id,
payment_amount=:amount,
payment_method=:method,
payment_mode=:mode,
payment_create_date=:date,
payment_ip=:ip,
payment_extra=:extra"
);

$insert->execute([
    "client_id" => $user["client_id"],
    "amount" => $amount,
    "method" => $method_id,
    "mode" => "Automatic",
    "date" => date("Y.m.d H:i:s"),
    "ip" => GetIP(),
    "extra" => $orderId
]);


$url = "https://api.flutterwave.com/v3/payments";

$postData = [
    'tx_ref' => $orderId,
    'amount' => $amount,
    'currency' => $extra["currency"],
    'payment_options' => 'card, ussd, mobilemoneyghana, banktransfer',
    'redirect_url' => $callbackURL,
    'customer' => [
        'email' => $user["email"],
        'name' => $user["name"]
    ],
    'meta' => [
        'price' => $amount
    ],
    'customizations' => [
        'title' => 'Balance Recharge (' . $user["username"] . ')',
        'description' => ''
    ]
];

$postData = json_encode($postData);

$headers = [
    'Authorization: Bearer ' . $secretKey . '',
    'Content-Type: application/json'
];

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS => $postData,
    CURLOPT_HTTPHEADER => $headers,
]);

$gatewayResponse = curl_exec($curl);
curl_close($curl);
$gatewayResponses = $gatewayResponse;

$gatewayResponse = json_decode($gatewayResponse, 1);

if ($gatewayResponse["status"] == "success") {
    $paymentUrl = $gatewayResponse["data"]["link"];

header("Location: $paymentUrl");

} else {
 $error = 1;
                $errorText = $gatewayResponses;
    
    
}



elseif($method_id == 6969):
              $fee = $extra["fee"];
                
  
                
      
                
                
                $amount_fee   = $amount-($amount*$fee/100);
         
$profile_key = $extra["profile_key"];
        $transaction_id = time();


    $my_payment_url = "https://raksmeypay.com/payment/request/" . $extra["profile_id"];

$success_url = site_url("callback/raksmeypay?transaction_id=" . $transaction_id);

    
    $pushback_url = "";
    $hash = sha1($profile_key . $transaction_id . $amount . $success_url . $pushback_url);
               $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
        $insert->execute(array("c_id" => $user['client_id'], "amount" => $amount_fee, "code" => $paymentCode, "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $transaction_id));
        
                
    $parameters = [
        "transaction_id" => $transaction_id,
        "amount" => $amount,
        "success_url" => $success_url,
         "hash" => $hash
    ];
    $queryString = http_build_query($parameters);
    $payment_link_url = $my_payment_url."?".$queryString;
             $url = $payment_link_url;
              header("Location:" .  $payment_link_url);


elseif ($method_id == 6981):
    
     $amount_converted = $amount;
     
		function get_apps_auth_token($merchantid, $secret, $amount, $basketid) {

    $token_url = "https://ipg1.apps.net.pk/Ecommerce/api/Transaction/GetAccessToken?MERCHANT_ID=%s&SECURED_KEY=%s&TXNAMT=%s&BASKET_ID=%s&CURRENCY_CODE=PKR";
    $token_url = sprintf($token_url, $merchantid, $secret, $amount, $basketid);
    
    $response = curl_request($token_url);
    $response_decode = json_decode($response);

    if (isset($response_decode->ACCESS_TOKEN)) {
        return $response_decode->ACCESS_TOKEN;
    }

    return;
}


function curl_request($url, $data_string = '') {

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'application/json; charset=utf-8    '
    ));
	curl_setopt($ch,CURLOPT_USERAGENT,'WHMCS-PayFast Plugin-PHP-CURL');
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}	   
$uniqueOrderId = time() . mt_rand();
$fee = $extra["fee"];
$amount_fee = $amount_converted - ($amount_converted * $fee/100);
    // Request body
    $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
    $insert->execute(array("c_id" => $user['client_id'], "amount" => $amount_fee, "code" => $paymentCode, "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $uniqueOrderId));
    $_SESSION['TID'] = $uniqueOrderId;
     $merchantId = $extra['merchantId'];
    $securedKey =$extra['securedKey'];

    $phonenumber = $user['telephone'];

    if ($phonenumber == '') {
        $phonenumber = '920000000000';
    }

    // System Parameters
    $whmcsVersion = '3.0';

        $url = 'https://ipg1.apps.net.pk/Ecommerce/api/Transaction/PostTransaction';

   
    $callback = site_url('callback/payfast');

    $signature = md5($merchantId . $securedKey . $amount);
$params['merchant_name']=$extra['merchant_name'];
    $token = get_apps_auth_token($merchantId, $securedKey, $amount, $uniqueOrderId);
$params['description']="Add IN Panel";
$htmlOutput = '<form id="paymentForm" action="' . $url . '" method="post">
<input type="hidden" name="MERCHANT_ID" value="' . $merchantId . '">
<input type="hidden" name="MERCHANT_NAME" value="' . $params['merchant_name'] . '">
<input type="hidden" name="TOKEN" value="' . $token . '">
<input type="hidden" name="PROCCODE" value="00">
<input type="hidden" name="APP_PLUGIN" value="WHMCS">
<input type="hidden" name="TXNAMT" value="' . $amount . '">
<input type="hidden" name="CUSTOMER_MOBILE_NO" value="' . $phonenumber . '">
<input type="hidden" name="CUSTOMER_EMAIL_ADDRESS" value="' . $user['email'] . '">
<input type="hidden" name="SIGNATURE" value="' . $signature . '">
<input type="hidden" name="VERSION" value="FSP1.0-' . $whmcsVersion . '">
<input type="hidden" name="TXNDESC" value="' . $params['description'] . '">
<input type="hidden" name="CURRENCY_CODE" value="PKR">
<input type="hidden" name="SUCCESS_URL" value="' . $callback . '">
<input type="hidden" name="FAILURE_URL" value="' . $callback . '">
<input type="hidden" name="BASKET_ID" value="' . $uniqueOrderId . '">
<input type="hidden" name="ORDER_DATE" value="' . date('Y-m-d H:i:s', time()) . '">
<input type="hidden" name="CHECKOUT_URL" value="' . $callback . '">
 </form>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        document.querySelector("#paymentForm").submit();
    });
</script>';
    
    echo $htmlOutput;
    
    
   

    elseif ($method_id == 6672) :
$apiKey = $extra['api_key'];
$host = parse_url(trim($extra['api_url']),  PHP_URL_HOST);
$apiUrl = "https://{$host}/api/checkout-v2";

$final_amount = $amount * $extra['exchange_rate'];
$txnid = substr(hash('sha256', mt_rand() . microtime()), 0, 20);

$posted = [
	'full_name' => isset($user['first_name']) ? $user['first_name'] : 'John Doe',
	'email' => $user['email'],
	'amount' => $final_amount,
	'metadata' => [
		'user_id' => $user['client_id'],
		'txnid' => $txnid
	],
	'redirect_url' => site_url('callback/uddoktapay'),
	'return_type' => 'GET',
	'cancel_url' => site_url('addfunds'),
	'webhook_url' => site_url('callback/uddoktapay')
];

$curl = curl_init();
curl_setopt_array($curl, [
	CURLOPT_URL => $apiUrl,
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_ENCODING => "",
	CURLOPT_MAXREDIRS => 10,
	CURLOPT_TIMEOUT => 30,
	CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
	CURLOPT_CUSTOMREQUEST => "POST",
	CURLOPT_POSTFIELDS => json_encode($posted),
	CURLOPT_HTTPHEADER => [
		"RT-UDDOKTAPAY-API-KEY: " . $apiKey,
		"accept: application/json",
		"content-type: application/json"
	],
]);

$response = curl_exec($curl);
$err = curl_error($curl);

curl_close($curl);

if ($err) {
	echo "cURL Error #:" . $err;
	exit();
}

$result = json_decode($response, true);
if ($result['status']) {
	$order_id = $txnid;
	$insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
	$insert->execute(array("c_id" => $user['client_id'], "amount" => $amount, "code" => $paymentCode, "method" => $method_id, "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $order_id));
	if ($insert) {
		$payment_url = $result['payment_url'];
	}
} else {
	echo $result['message'];
	exit();
}

// Redirects to Uddoktapay
echo '<div class="dimmer active" style="min-height: 400px;">
	<div class="loader"></div>
	<div class="dimmer-content">
		<center>
			<h2>Please do not refresh this page</h2>
		</center>
		<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" style="margin:auto;background:#fff;display:block;" width="200px" height="200px" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid">
			<circle cx="50" cy="50" r="32" stroke-width="8" stroke="#e15b64" stroke-dasharray="50.26548245743669 50.26548245743669" fill="none" stroke-linecap="round">
				<animateTransform attributeName="transform" type="rotate" dur="1s" repeatCount="indefinite" keyTimes="0;1" values="0 50 50;360 50 50"></animateTransform>
			</circle>
			<circle cx="50" cy="50" r="23" stroke-width="8" stroke="#f8b26a" stroke-dasharray="36.12831551628262 36.12831551628262" stroke-dashoffset="36.12831551628262" fill="none" stroke-linecap="round">
				<animateTransform attributeName="transform" type="rotate" dur="1s" repeatCount="indefinite" keyTimes="0;1" values="0 50 50;-360 50 50"></animateTransform>
			</circle>
		</svg>
		<form action="' . $payment_url . '" method="get" name="uddoktapayForm" id="pay">
			<script type="text/javascript">
				document.getElementById("pay").submit();
			</script>
		</form>
	</div>
</div>'; 
         
         
         
         elseif ($method_id == 899):
              $amount = htmlentities($_POST["payment_amount"]);


     
   
  $abusaleh =  md5(rand(1,999999));
$_SESSION['TXN'] = $abusaleh;
        
        $insert                       = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
        $insert->execute(array("c_id" => $user['client_id'], "amount" => $amount, "code" => $paymentCode, "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $abusaleh));

 
           
           $email = $user['email'];
 
$key = $extra['public_key'];
$amount_in_cents = $amount * 100;
echo '<script src="https://checkout.squadco.com/widget/squad.min.js"></script>';
echo '<script>
    function SquadPay() {
        const squadInstance = new squad({
            onClose: () => console.log("Widget closed"),
            onLoad: () => console.log("Widget loaded successfully"),
            onSuccess: () => console.log(`Linked successfully`),
            key: "' . $key . '",
            email: "' . $email . '",
            amount: ' . $amount_in_cents . ',
            currency_code: "NGN"
        });

        squadInstance.setup();
        squadInstance.open();
    }

    // Auto-submit the form when the page loads
    document.addEventListener("DOMContentLoaded", function() {
        SquadPay();
    });
</script>';
         


        elseif ($method_id == 30) :

            $pm_amount_fee = str_replace(',', '.', $amount_fee);
            //  $key="gtKFFx";
            //  $salt="wia56q6O";
            $key = $extra["merchant_key"];
            $salt = $extra["salt_key"];

            $icid1 = md5(rand(1, 999999));
            $code = md5(rand(1, 999999));
            $productinfo = "Balance Recharge " . $user["username"];
            $firstname = $user["name"];
            $lastname = " ";
            $email = $user["email"];
            $number = $user["telephone"];
            $udf5 = "mno";

            $action = 'https://secure.payu.in/_payment';
            //$action = 'https://test.payu.in/_payment';

            $html = '';

            function getCallbackUrl()
            {
                $protocol = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off') || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
                $uri = str_replace('/index.php', '/', $_SERVER['REQUEST_URI']);
                return site_url('payment/payumoneyV2');
            }

            $hash = $key . '|' . $icid1 . '|' . $pm_amount_fee . '|' . $productinfo . '|' . $firstname . '|' . $email . '|||||' . $udf5 . '||||||' . $salt;
            $hash = hash('sha512', $hash);

            $_SESSION['salt'] = $salt; //save salt in session to use during Hash validation in response


            $html = '<form action="' . $action . '" id="payment_form_submit" method="post">
                    <input type="hidden" id="udf5" name="udf5" value="' . $udf5 . '" />
                    <input type="hidden" id="surl" name="surl" value="' . getCallbackUrl() . '" />
                    <input type="hidden" id="furl" name="furl" value="' . getCallbackUrl() . '" />
                    <input type="hidden" id="curl" name="curl" value="' . getCallbackUrl() . '" />
                    <input type="hidden" id="key" name="key" value="' . $key . '" />
                    <input type="hidden" id="txnid" name="txnid" value="' . $icid1 . '" />
                    <input type="hidden" id="amount" name="amount" value="' . $pm_amount_fee . '" />
                    <input type="hidden" id="productinfo" name="productinfo" value="' . $productinfo . '" />
                    <input type="hidden" id="firstname" name="firstname" value="' . $firstname . '" />
                    <input type="hidden" id="Lastname" name="Lastname" value="' . $lastname . '" />
                    <input type="hidden" id="Zipcode" name="Zipcode" value="97223" />
                    <input type="hidden" id="email" name="email" value="' . $email . '" />
                    <input type="hidden" id="phone" name="phone" value="' . $number . '" />
                    <input type="hidden" id="address1" name="address1" value="3234 Godfrey Street Tigard, OR 97223" />
                    <input type="hidden" id="address2" name="address2" value="Lorem ipsum dolor sit ame" />
                    <input type="hidden" id="city" name="city" value="Tigard" />
                    <input type="hidden" id="state" name="state" value="FL" />
                    <input type="hidden" id="country" name="country" value="United States" />
                     <input type="hidden" id="hash" name="hash" value="' . $hash . '" />
                    </form>
        
                    <script type="text/javascript">
                    setTimeout(function(){
                        document.getElementById("payment_form_submit").submit();	
                    }, 10);
                        
                    
                    </script>';

            $getcur = "INR";
            if ($getcur != $settings["site_currency"]) :
                $getamo = convertCurrencyUpdated($getcur, $settings["site_currency"], $amount);
                $payment_extraa = json_encode(array('currency' => $getcur, 'amount' => $pm_amount_fee));
            else :
                $getamo = $pm_amount_fee;
                $payment_extraa = json_encode([]);
            endif;


            $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, client_balance=:client_balance,payment_amount=:payment_amount,  payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra , payment_extraa=:payment_extraa");
            $insert->execute(array("c_id" => $user['client_id'],  "client_balance" => $user["balance"],  "payment_amount" => $getamo,  "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $icid1, "payment_extraa" => $payment_extraa));



            if ($insert) :
                $success = 1;
                $successText = "Your payment was initiated successfully, you are being redirected..";
                echo $html;
            else :
                $error = 1;
                $errorText = "There was an error starting your payment, please try again later..";
            endif;



        elseif ($method_id == 31) : //phonepe qr
            unset($_SESSION["data"]);
            $transactionId = $phonepeqr_orderid;
            $getcur = $method['method_currency'];

            if ($getcur != $settings["site_currency"]) :
                $amount_converted = convertCurrencyUpdated($getcur, $settings["site_currency"], $amount);
                $payment_extraa = json_encode(array('currency' => $getcur, 'amount' => $amount));
            else :
                $amount_converted = $amount;
                $payment_extraa = '';
            endif;


            $requestParamList = array("email" => $extra['phonepe_email'], "password" => $extra["phonepe_email_pass"],  "transactionId" => $transactionId, "amount" => $amount, "method_id" => 31);
            if (
                !countRow(['table' => 'payments', 'where' => ['client_id' => $user['client_id'], 'payment_method' => 31, 'payment_status' => 3, 'payment_delivery' => 2, 'payment_extra' => $transactionId]]) &&
                !countRow(['table' => 'payments', 'where' => ['payment_extra' => $transactionId]])
            ) {



                $responseParamList = getEmailTxnStatus($requestParamList);
                $payment_token  = storePaymentSession($responseParamList);


                if (!empty($responseParamList) && $responseParamList["status"] == "success") :

                    $payment_code = generatePaymentCode($transactionId, $amount_converted, 31);


                    $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, client_balance=:client_balance ,payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra , payment_extraa=:payment_extraa");
                    $insert->execute(array("c_id" => $user['client_id'], "amount" => $amount_converted, "code" => $payment_code,  "client_balance" => $user["balance"], "method" => $method_id, "mode" => "Otomatik", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $transactionId, "payment_extraa" => $payment_extraa));



                    if ($insert) :
                        $success = 1;
                        $successText = "Your payment was initiated successfully, you are being redirected..";

                        echo '<form method="post" action="' . site_url('payment/phonepeQr') . '" name="f1">
                                 <input type="hidden" name="transactionId" value="' . $transactionId . '"> 
                                 <input type="hidden" name="amount" value="' . $amount . '"> 
                                 <input type="hidden" name="payment_token" value="' . $payment_token . '"> 
                                     
                            		<script type="text/javascript">
                            			document.f1.submit();
                            		</script>
                            	</form>';

                    else :
                        $error = 1;
                        $errorText = "Something went wrong!";
                    endif;
                else :
                    $error = 1;
                    $errorText = "Invalid Transaction!";
                endif;
            } else {
                $error = 1;
                $errorText = "This transaction id is already used";
            }

        elseif ($method_id == 44) : //easypaise

            unset($_SESSION["data"]);
            $transactionId = $easypaise_orderid;
            $getcur = $method['method_currency'];


 
                $amount_converted = $amount;
                      $payment_extraa = json_encode(array('currency' => $getcur, 'amount' => $amount));
    


            $requestParamList = array("email" => $extra['email'], "password" => $extra["password"],  "transactionId" => $transactionId, "amount" => $amount, "method_id" => 44);


            if (
                !countRow(['table' => 'payments', 'where' => ['client_id' => $user['client_id'], 'payment_method' => 44, 'payment_status' => 3, 'payment_delivery' => 2, 'payment_extra' => $transactionId]]) &&
                !countRow(['table' => 'payments', 'where' => ['payment_extra' => $transactionId]])
            ) {



                $responseParamList = getEmailTxnStatus($requestParamList);
                $payment_token  = storePaymentSession($responseParamList);


                if (!empty($responseParamList) && $responseParamList["status"] == "success") :

                    $payment_code = generatePaymentCode($transactionId, $amount_converted, 44);

                    $payment_response =  json_encode([[
                        "date" => date("Y-m-d H:i:s"),
                        "response" => $responseParamList["body"],
                    ]]);


                    $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, client_balance=:client_balance ,payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra , payment_extraa=:payment_extraa , payment_response=:payment_response");
                    $insert =  $insert->execute(array("c_id" => $user['client_id'], "amount" => $amount_converted, "code" => $payment_code,  "client_balance" => $user["balance"], "method" => $method_id, "mode" => "Otomatik", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $transactionId, "payment_extraa" => $payment_extraa, "payment_response" => $payment_response));

                    $success = 1;
                    $successText = "Your payment was initiated successfully, you are being redirected..";

                    echo '<form method="post" action="' . site_url('payment/easypaise') . '" name="f1">
                                 <input type="hidden" name="transactionId" value="' . $transactionId . '"> 
                                 <input type="hidden" name="amount" value="' . $amount . '"> 
                                 <input type="hidden" name="payment_token" value="' . $payment_token . '"> 
                                     
                            		<script type="text/javascript">
                            			document.f1.submit();
                            		</script>
                            	</form>';
                else :
                    $error = 1;
                    $errorText = "Invalid Transaction!";
                endif;
            } else {
                $error = 1;
                $errorText = "This transaction id is already used";
            }
            
            
             elseif ($method_id == 2399) : //nayapay
  
 require FILES_BASE . '/lib/nayapay.php';


        elseif ($method_id == 45) : //jazzcash

            unset($_SESSION["data"]);
            $transactionId = $jazzcash_orderid;
            $getcur = $method['method_currency'];

             $amount_converted = $amount;
                      $payment_extraa = json_encode(array('currency' => $getcur, 'amount' => $amount));



            $requestParamList = array("email" => $extra['email'], "password" => $extra["password"],  "transactionId" => $transactionId, "amount" => $amount, "method_id" => 45);


            if (
                !countRow(['table' => 'payments', 'where' => ['client_id' => $user['client_id'], 'payment_method' => 45, 'payment_status' => 3, 'payment_delivery' => 2, 'payment_extra' => $transactionId]]) &&
                !countRow(['table' => 'payments', 'where' => ['payment_extra' => $transactionId]])
            ) {

                $responseParamList = getEmailTxnStatus($requestParamList);
                $payment_token  = storePaymentSession($responseParamList);


                if (!empty($responseParamList) && $responseParamList["status"] == "success") :

                    $payment_code = generatePaymentCode($transactionId, $amount_converted, 45);

                    $payment_response =  json_encode([[
                        "date" => date("Y-m-d H:i:s"),
                        "response" => $responseParamList["body"],
                    ]]);


                    $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, client_balance=:client_balance ,payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra , payment_extraa=:payment_extraa , payment_response=:payment_response");
                    $insert =  $insert->execute(array("c_id" => $user['client_id'], "amount" => $amount_converted, "code" => $payment_code,  "client_balance" => $user["balance"], "method" => $method_id, "mode" => "Otomatik", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $transactionId, "payment_extraa" => $payment_extraa, "payment_response" => $payment_response));

                    $success = 1;
                    $successText = "Your payment was initiated successfully, you are being redirected..";

                    echo '<form method="post" action="' . site_url('payment/jazzcash') . '" name="f1">
                                     <input type="hidden" name="transactionId" value="' . $transactionId . '"> 
                                     <input type="hidden" name="amount" value="' . $amount . '"> 
                                     <input type="hidden" name="payment_token" value="' . $payment_token . '"> 
                                         
                                        <script type="text/javascript">
                                            document.f1.submit();
                                        </script>
                                    </form>';
                else :
                    $error = 1;
                    $errorText = "Invalid Transaction!";
                endif;
            } else {
                $error = 1;
                $errorText = "This transaction id is already used";
            }


elseif ($method_id == 309):         
 
                    $icid = time();
    
 
                  $paramList = array();
                    
                      $paramList["id"] = $icid;
                     $paramList["amount"] = $amount;
                     $default_amount=$amount;
                        $paramList["name"] = $settings['site_name'];
                                         $paramList["upi_id"] =$extra['upi'];

                     $paramList["callback_url"] = site_url('callback/upi');

                    $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount,  payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
                    $insert->execute(array("c_id" => $user['client_id'], "amount" => $default_amount, "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $icid));
                     
                                                     
                     
                    echo '<form method="post" action="/upi" name="f1">
                    		<table border="1">
                    			<tbody>';
                    			foreach($paramList as $name => $value) {
                    				echo '<input type="hidden" name="' .$name.'" value="' .$value .'">';
                    			}
                    			echo '                                                
                    			</tbody>
                    		</table>
                    		<script type="text/javascript">
                    			document.f1.submit();
                    		</script>
                    	</form>';
                    	
                    	
                    	elseif ($method_id == 9094) :
function generateUniqueCode($length = 16) {
    // Generate a random unique string
    return substr(bin2hex(random_bytes($length / 2)), 0, $length);
}

function sendRequestToApi($apiUrl, $params) {
    // Generate the signature dynamically if mandatory parameters exist
    if (isset($params['key'], $params['unique_code'], $params['service'], $params['amount'], $params['valid_time'])) {
        $params['signature'] = md5(
            $params['key'] . 
            $params['unique_code'] . 
            $params['service'] . 
            $params['amount'] . 
            $params['valid_time'] . 
            'NewTransaction'
        );
    } else {
        return ['success' => false, 'message' => 'Missing mandatory parameters for signature'];
    }

    // Initialize cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded'
    ]);

    // Execute cURL
    $response = curl_exec($ch);

    // Check for cURL errors
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['success' => false, 'message' => $error];
    }

    curl_close($ch);

    // Parse and return the response
    $decodedResponse = json_decode($response, true);

    return $decodedResponse ?: ['success' => false, 'message' => 'Invalid JSON response from API'];
}

// Example usage
$apiUrl = 'https://api.paydisini.co.id/v1/'; // API endpoint URL

// Dynamically generate a unique code
$uniqueCode = generateUniqueCode(16); // Generate a 16-character unique string

$params = [
    'key' => $extra['key'],  // Mandatory
    'request' => 'new',                          // Mandatory
    'unique_code' => $uniqueCode,                // Mandatory
    'service' => '11',                           // Mandatory
    'amount' => $amount,                            // Example amount
    'note' => 'Payment for Order #' . $uniqueCode, // Mandatory
    'valid_time' => 3600,                        // Mandatory
    'type_fee' => 1,
    'return_url'  => site_url('addfunds')
    // Mandatory
];

$response = sendRequestToApi($apiUrl, $params);

// Check the response and redirect if successful
if (isset($response['success']) && $response['success'] == 1) {
    $checkoutUrl = $response['data']['checkout_url']; // Extract the checkout URL
       $payment_extraa = json_encode(array('currency' => "IDR", 'amount' => $amount));
            $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, client_balance=:client_balance ,payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra , payment_extraa=:payment_extraa");
            $insert->execute(array("c_id" => $user['client_id'], "amount" => $amount, "client_balance" => $user["balance"], "code" => $paymentCode, "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $uniqueCode, "payment_extraa" => $payment_extraa));
 
    // Redirect to the checkout URL
    header("Location: $checkoutUrl");
    exit;
} else {
    // Handle the error case
    echo "Error: " . ($response['message'] ?? 'Unknown error occurred.');
}
    
                    	
                    	
        elseif ($method_id == 14) : //paytm qr


            require FILES_BASE . '/lib/paytm/encdec_paytm.php';


            $icid = $paytmqr_orderid;
            //$icid = "ORDS57382437";

            $TXN_AMOUNT = $amount;

            $responseParamList = array();

            $requestParamList = array();

            $requestParamList = array("MID" => $extra['merchant_mid'], "ORDERID" => $icid);


            if (
                !countRow(['table' => 'payments', 'where' => ['client_id' => $user['client_id'], 'payment_method' => 14, 'payment_status' => 3, 'payment_delivery' => 2, 'payment_extra' => $icid]]) &&
                !countRow(['table' => 'payments', 'where' => ['payment_extra' => $icid]])
            ) {
                $responseParamList = getTxnStatusNew($requestParamList);
                if ($amount == $responseParamList["TXNAMOUNT"]) {


                    $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, client_balance=:client_balance ,payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
                    $insert =  $insert->execute(array("c_id" => $user['client_id'], "amount" => $amount, "code" => $paymentCode,  "client_balance" => $user["balance"], "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $icid));


                    $success = 1;
                    $successText = "Your payment was initiated successfully, you are being redirected..";

                    echo '<form method="post" action="' . site_url('payment/paytmqr') . '" name="f1">
                            		<table border="1">
                            			<tbody>';
                    foreach ($requestParamList as $name => $value) {
                        echo '<input type="hidden" name="' . $name . '" value="' . $value . '">';
                    }
                    echo '</tbody>
                            			</table>
                            		<script type="text/javascript">
                            			document.f1.submit();
                            		</script>
                            	</form>';
                } else {
                    $error = 1;
                    $errorText = "Amount is invalid";
                }
            } else {
                $error = 1;
                $errorText = "This transaction id is already used";
            }


        elseif ($method_id == 140) : //PaymentApproval

        $icid = $paytmqr_orderid;

            $TXN_AMOUNT = $amount;
                    $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, client_balance=:client_balance ,payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
                    $insert =  $insert->execute(array("c_id" => $user['client_id'], "amount" => $amount, "code" => $paymentCode,  "client_balance" => $user["balance"], "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $icid));


                    $success = 1;
                    $successText = "Your Request has been sent to admin Please wait..";

        
        elseif ($method_id == 15) :
            unset($_SESSION["data"]);
                   $TXN_AMOUNT = $amount;

      $apiKey = $extra['api_key'];
$apiSecret =  $extra['api_secret_key'];
$amount = $amount*100; // ₹100 (पैसे में)
$currency =  $extra['currency'];
$site_name=$settings['site_name'];
 
   $firstname = $user["name"];
            $email = $user["email"];
// Create New Order
$receipt = 'order_rcpt_'.time();
$data = [
    'amount' => $amount,
    'currency' => $currency,
    'receipt' => $receipt,
    'payment_capture' => 1
];

$ch = curl_init('https://api.razorpay.com/v1/orders');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_USERPWD, "$apiKey:$apiSecret");
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) die('Order creation failed');
$order = json_decode($response, true);
    $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, client_balance=:client_balance ,payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
                    $insert =  $insert->execute(array("c_id" => $user['client_id'], "amount" => $TXN_AMOUNT, "code" => $paymentCode,  "client_balance" => $user["balance"], "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $order['id']));

// Output HTML/JS
echo '<!DOCTYPE html>
<html>
<head>
    <title>Razorpay Payment</title>
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
</head>
<body>
 
<script>
document.addEventListener("DOMContentLoaded", function() {
    var options = {
        key: "'.htmlspecialchars($apiKey).'",
        amount: "'.htmlspecialchars($amount).'",
        currency: "'.htmlspecialchars($currency).'",
        name: "'.htmlspecialchars($site_name).'",
        order_id: "'.htmlspecialchars($order['id']).'",
        handler: function(response) {
            fetch("callback/razorpay", {
                method: "POST",
                headers: {"Content-Type": "application/json"},
                body: JSON.stringify(response)
            })
            .then(r => r.json())
            .then(data => {
                if(data.success) {
                     window.location.href = "/addfunds"; // Redirect after success
                } else {
                 }
            });
        }, 
        prefill: {
            name: "'.htmlspecialchars($firstname).'",
            email: "'.htmlspecialchars($email).'",
            contact: "9999999999"
        }
    };
    
    var rzp = new Razorpay(options);
    rzp.open();
});
</script>

</body>
</html>';    
            
            
        elseif ($method_id == 16) :
            unset($_SESSION["data"]);
            $ic_amount_fee = str_replace(',', '.', $amount_fee);
            $icid = md5(rand(1, 999999));
            $getcur = $extra['currency'];
            //  $lastcur = isset($currentcur->error) ? defined($getcur . '_') ? constant($getcur . '_') : die('There\'s a problem with currency. Please contact with admin.') : $currentcur->rates->$getcur;
            $getamo = number_format($ic_amount_fee * $lastcur, 2, '.', '');
            $jsondata = json_encode(array('c' => $getcur, 'a' => $getamo));

            $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra, data=:data");
            $insert->execute(array("c_id" => $user['client_id'], "amount" => $amount, "code" => $paymentCode, "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $icid, "data" => $jsondata));
            if ($insert) :
                $success = 1;
                $successText = "Your payment was initiated successfully, you are being redirected..";
                $payment_url = site_url('lib/pay/iyzico-payment.php?hash=' . $icid);
            else :
                $error = 1;
                $errorText = "There was an error starting your payment, please try again later..";
            endif;
        elseif ($method_id == 17) :
            unset($_SESSION["data"]);
            $ae_amount_fee = str_replace(',', '.', $amount_fee);
            $icid = md5(rand(1, 999999));
            $getcur = $extra['currency'];
            //  $lastcur = isset($currentcur->error) ? defined($getcur . '_') ? constant($getcur . '_') : die('There\'s a problem with currency. Please contact with admin.') : $currentcur->rates->$getcur;
            $getamo = number_format($ae_amount_fee * $lastcur, 2, '.', '');
            $jsondata = json_encode(array('c' => $getcur, 'a' => $getamo));

            $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra, data=:data");
            $insert->execute(array("c_id" => $user['client_id'], "amount" => $amount, "code" => $paymentCode, "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $icid, "data" => $jsondata));
            if ($insert) :
                $success = 1;
                $successText = "Your payment was initiated successfully, you are being redirected..";
                $payment_url = site_url('lib/pay/authorize-net-payment.php?hash=' . $icid);
            else :
                $error = 1;
                $errorText = "There was an error starting your payment, please try again later..";
            endif;
        
        elseif($method_id == 18):
                $access_token = $extra["access_token"];
                $is_demo = $extra["is_demo"];
                $dollar_rate = $extra["dollar_rate"];
                $pay_amount = $amount *  $dollar_rate;
      $icid1 = md5(rand(1, 999999)).time();          
    $curl = curl_init();
    $preferenceData = [
        'items' => [
            [
                'id' => $icid1,
                'title' => 'Add funds',
                'description' => 'Add funds from '.$user['email'],
                'quantity' => 1,
                'currency_id' => 'BRL',
                'unit_price' => $pay_amount
            ]
        ],
        'payer' => [
            'email' => $user['email'],
        ],
        'back_urls' => [
            'success' =>site_url('payment/mercadopago'),
            'pending' => '',
            'failure' => site_url('addfunds'),
        ],
        'notification_url' => site_url('addfunds'),
        'auto_return' =>  'approved',
    ];
    $httpHeader = [
        "Content-Type: application/json",
    ];

   
    $url = "https://api.mercadopago.com/checkout/preferences?access_token=" . $access_token;
    $opts = [
        CURLOPT_URL             => $url,
        CURLOPT_CUSTOMREQUEST   => "POST",
        CURLOPT_POSTFIELDS      => json_encode($preferenceData, true),
        CURLOPT_HTTP_VERSION    => CURL_HTTP_VERSION_1_1,
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_TIMEOUT         => 30,
        CURLOPT_HTTPHEADER      => $httpHeader
    ];
    curl_setopt_array($curl, $opts);
    $response = curl_exec($curl);
    $result = json_decode($response,true);
    $err = curl_error($curl);
    curl_close($curl);

    if (@$result['init_point']) {
           $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
        $insert->execute(array("c_id" => $user['client_id'], "amount" => $amount, "code" => $paymentCode, "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $icid1));
        $_SESSION['tids']=$icid1;
        $url = $result['init_point'];
        header("Location:" .  $url);
        exit;
    } 
          header("Location:" .  site_url('addfunds'));
             exit; 
      
      
        elseif ($method_id == 19) :




            unset($_SESSION["data"]);
            $pm_amount_fee = str_replace(',', '.', $amount_fee);


            $merchant_key = $extra["merchant_key"];
            $salt_key = $extra["salt_key"];
            $icid1 = md5(rand(1, 999999));
            $productinfo = "Digital-Services";
            $firstname = $user["name"];
            $email = $user["email"];
            $udf1 = "abc";
            $udf2 = "def";
            $udf3 = "ghi";
            $udf4 = "jkl";
            $udf5 = "mno";

            $hash = $merchant_key . '|' . $icid1 . '|' . $pm_amount_fee . '|' . $productinfo . '|' .
                $productinfo . '|' . $firstname . '|' . $email . '|' .  $udf1 . '|' . $udf2 . '|' . $udf3 . '|' .
                $udf4 . '|' . $udf5 . '||||||' .  $salt_key;
            $icid = hash('sha512', $hash);

            // exit();

            $getcur = $extra['currency'];
            if ($getcur != $settings["site_currency"]) :
                $getamo = convertCurrencyUpdated($getcur, $settings["site_currency"], $amount);
                $payment_extraa = json_encode(array('currency' => $getcur, 'amount' => $ptm_amount));
            else :
                $getamo = $ptm_amount;
                $payment_extraa = json_encode([]);
            endif;

            $fd = json_encode(array('c' => $getcur, 'a' => $getamo));

            $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id,client_balance=:client_balance, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra, payment_extraa=:payment_extraaa");
            $insert->execute(array("c_id" => $user['client_id'], "amount" => $getamo,  "client_balance" => $user["balance"],  "code" => $paymentCode, "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $icid, "payment_extraaa" => $fd));
            if ($insert) :
                $success = 1;
                $successText = "Your payment was initiated successfully, you are being redirected..";
                $payment_url = site_url('lib/pay/payumoney-payment.php?hash=' . $icid);
            else :
                $error = 1;
                $errorText = "There was an error starting your payment, please try again later..";
            endif;
            
            
             elseif ($method_id == 120):
    
// Assuming these variables are dynamically assigned
$merchant_key = $extra['apikey']; // API key from the $extra array
$currency_code = 'BRL';
$min_pix_value = 3.00; // Minimum PIX payment value
$total_pix_value = $amount; // Dynamically assigned amount
        	$orderId= uniqid(); 
// Check if the total value meets the minimum requirement
if ($total_pix_value >= $min_pix_value) {
    // Invoice details (replace dynamic values with your logic)
    $invoice = [
        "invoice_id" => $orderId, // Unique invoice ID
        "invoice_description" => "Descrição da fatura " . uniqid(), // Dynamic description
        "total" => number_format($total_pix_value, 2, '.', ''), // Ensure proper decimal format
        "devedor" => $user['username'], // Customer name
        "email" => $user['email'], // Customer email
        "cpf_cnpj" => "64597420061", // Example CPF/CNPJ
        "notification_url" => site_url('callback/expaybrasil'), // Dynamic callback URL
        "telefone" => "87981662211", // Example phone number
        "items" => [
            [
                "name" => "Produto", // Example product name
                "price" => number_format($total_pix_value, 2, '.', ''), // Product price
                "description" => "Descrição", // Product description
                "qty" => "1" // Quantity
            ]
        ]
    ];

    // Encode the invoice as JSON
    $invoice_json = json_encode($invoice);

    // Initialize cURL session
    $ch = curl_init();

    // Set cURL options
    curl_setopt($ch, CURLOPT_URL, "https://expaybrasil.com/en/purchase/link");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        "merchant_key" => $merchant_key,
        "currency_code" => $currency_code,
        "invoice" => $invoice_json
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "accept: application/json",
        "content-type: application/x-www-form-urlencoded"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Execute the request
     $response = curl_exec($ch);

    // Check for errors
    if (curl_errno($ch)) {
        echo "cURL Error: " . curl_error($ch);
    } else {
        // Decode the JSON response
        $response_data = json_decode($response, true);

        // Check if the request was successful
        if (isset($response_data['pix_request']['result']) && $response_data['pix_request']['result'] === true) {

        $insert = $conn->prepare(
    "INSERT INTO payments SET
client_id=:client_id,
payment_amount=:amount,
payment_method=:method,
payment_mode=:mode,
payment_create_date=:date,
payment_ip=:ip,
payment_extra=:extra"
);

$insert->execute([
    "client_id" => $user["client_id"],
    "amount" => $amount,
    "method" => $method_id,
    "mode" => "Automatic",
    "date" => date("Y.m.d H:i:s"),
    "ip" => GetIP(),
    "extra" => $orderId
]);

            // Extract QR code and URLs
            $qrcode_base64 = $response_data['pix_request']['pix_code']['qrcode_base64'] ?? null;
            
    $qrcode_base64emv = $response_data['pix_request']['pix_code']['emv'] ?? null;
            

            // Display QR Code or redirect to URL
            if ($qrcode_base64) {
     echo '
    <!-- The Modal -->
    <div id="qrModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h3>Scan the QR Code to Complete Payment</h3>
            <!-- Display the QR Code -->
            <img src="data:image/png;base64,' . $qrcode_base64 . '" alt="QR Code" class="qr-code">
            <p></p>
            <div class="form-group">
                <textarea id="qr-code-value" readonly="" class="form-control">' . $qrcode_base64emv . '</textarea>
            </div>

            <button type="button" class="btn btn-primary" id="qr-code-copy-button"><span class="fas fa-clone"></span> Copy</button>
        </div>
    </div>

    <style>
        /* Modal Overlay */
        .modal {
            display: none; /* Hidden by default */
            position: fixed;
            z-index: 1; /* Sit on top */
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.4); /* Black with opacity */
            justify-content: center;
            align-items: center;
        }

        /* Modal Content */
        .modal-content {
            background-color: white;
            margin: auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 600px;
            box-sizing: border-box;
            border-radius: 10px;
            position: relative;
        }

        /* Close Button Style */
        .close {
            position: absolute;
            top: 10px;
            right: 10px;
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .close:hover,
        .close:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }

        /* QR Code Image */
        .qr-code {
            width: 100%;
            height: auto;
            max-width: 300px;
            margin: 20px auto;
        }

        /* Responsive Design */
        @media screen and (max-width: 600px) {
            .modal-content {
                width: 95%;
                padding: 10px;
            }
        }

        /* Button Styling */
        .btn-primary {
            background-color: #007bff;
            border: none;
            color: white;
            padding: 10px 20px;
            text-align: center;
            text-decoration: none;
            display: inline-block;
            font-size: 16px;
            cursor: pointer;
            border-radius: 5px;
        }

        .btn-primary:hover {
            background-color: #0056b3;
        }
    </style>

    <script>
        // Get the modal
        var modal = document.getElementById("qrModal");

        // Open the Modal automatically
        window.onload = function() {
            modal.style.display = "block";
        }

        // Close the Modal
        function closeModal() {
            modal.style.display = "none";
        }

        // Copy to Clipboard functionality
        document.getElementById("qr-code-copy-button").addEventListener("click", function() {
            var qrCodeText = document.getElementById("qr-code-value");

            qrCodeText.select();
            qrCodeText.setSelectionRange(0, 99999); // For mobile devices

            // Copy the text inside the textarea
            document.execCommand("copy");

            // Alert the user
            alert("QR Code copied to clipboard!");
        });
    </script>
';

            } else {
                echo "No QR Code or URL available for this transaction.";
            }
        } else {
            // If the request was not successful
            echo "Transaction failed: " . ($response_data['pix_request']['success_message'] ?? 'Unknown error');
        }
    }

    // Close cURL session
    curl_close($ch);
} else {
    // Reject PIX payment if below minimum value
    echo "PIX payment cannot be processed. Total Value: R$ " . number_format($total_pix_value, 2, ',', '.') .
        " is less than the minimum required value of R$ " . number_format($min_pix_value, 2, ',', '.');
}
            
             elseif ($method_id == 8826) :

$va = $extra['va']; //get on iPaymu dashboard
$apikey = $extra['apikey'];  //get on iPaymu dashboard

//   $url = 'https://sandbox.ipaymu.com/api/v2/payment'; // for development mode
$url = 'https://my.ipaymu.com/api/v2/payment'; // for production mode

$method = 'POST'; //method
$orderId = md5(mt_rand() . time());
$asa = "Addfunds in $settings[site_name]";
//Request Body//
$body['product']    = array($asa);
$body['qty']        = array('1');
$body['price']      = array($amount*1);
$body['returnUrl']  = site_url('addfunds');
$body['cancelUrl']  = site_url('addfunds');
$body['notifyUrl']  = site_url('callback/ipaymu');
$body['referenceId'] = $orderId; //your reference id
//End Request Body//

//Generate Signature
// *Don't change this
$jsonBody = json_encode($body, JSON_UNESCAPED_SLASHES);
$requestBody = strtolower(hash('sha256', $jsonBody));
$stringToSign = strtoupper($method) . ':' . $va . ':' . $requestBody . ':' . $apikey;
$signature = hash_hmac('sha256', $stringToSign, $apikey);
$timestamp = date('YmdHis');
//End Generate Signature

$ch = curl_init($url);

$headers = array(
    'Accept: application/json',
    'Content-Type: application/json',
    'va: ' . $va,
    'signature: ' . $signature,
    'timestamp: ' . $timestamp
);

curl_setopt($ch, CURLOPT_HEADER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_POST, count($body));
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

$err = curl_error($ch);
$ret = curl_exec($ch);
curl_close($ch);


if ($err) {
    echo $err;
} else {

    //Response
    $ret = json_decode($ret, true); // Decode as array
    if ($ret['Status'] == 200) {
        $sessionId = $ret['Data']['SessionID'];
        
        $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
    $insert->execute(array("c_id" => $user['client_id'], "amount" => $amount, "code" => $paymentCode, "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $sessionId));
        
        $url = $ret['Data']['Url'];
        header('Location:' . $url);
    } else {
        
    }
    //End Response
}

            
            
        elseif ($method_id == 20) :
            unset($_SESSION["data"]);
            $rp_amount_fee = str_replace(',', '.', $amount_fee);
            $icid = md5(rand(1, 999999));
            $getcur = $extra['currency'];
            //  $lastcur = isset($currentcur->error) ? defined($getcur . '_') ? constant($getcur . '_') : die('There\'s a problem with currency. Please contact with admin.') : $currentcur->rates->$getcur;
            $getamo = number_format($rp_amount_fee * $lastcur, 2, '.', '');
            $jsondata = json_encode(array('c' => $getcur, 'a' => $getamo));

            $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra, data=:data");
            $insert->execute(array("c_id" => $user['client_id'], "amount" => $amount, "code" => $paymentCode, "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $icid, "data" => $jsondata));
            if ($insert) :
                $success = 1;
                $successText = "Your payment was initiated successfully, you are being redirected..";
                $payment_url = site_url('lib/pay/ravepay-payment.php?hash=' . $icid);
            else :
                $error = 1;
                $errorText = "There was an error starting your payment, please try again later..";
            endif;
        elseif ($method_id == 21) :
            unset($_SESSION["data"]);
            $ps_amount_fee = str_replace(',', '.', $amount_fee);
            $icid = md5(rand(1, 999999));
            $getcur = $extra['currency'];
            //  $lastcur = isset($currentcur->error) ? defined($getcur . '_') ? constant($getcur . '_') : die('There\'s a problem with currency. Please contact with admin.') : $currentcur->rates->$getcur;
            $getamo = number_format($ps_amount_fee * $lastcur, 2, '.', '');
            $jsondata = json_encode(array('c' => $getcur, 'a' => $getamo));

            $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra, data=:data");
            $insert->execute(array("c_id" => $user['client_id'], "amount" => $amount, "code" => $paymentCode, "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $icid, "data" => $jsondata));
            if ($insert) :
                $success = 1;
                $successText = "Your payment was initiated successfully, you are being redirected..";
                $payment_url = site_url('lib/pay/pagseguro-payment.php?hash=' . $icid);
            else :
                $error = 1;
                $errorText = "There was an error starting your payment, please try again later..";
            endif;

        elseif ($method_id == 22) : //cashmaal
            unset($_SESSION["data"]);

            if (empty($extra['currency'])) :
                $extra['currency'] = 'USD';
            endif;


            $icid = md5(rand(1, 999999));
            $getcur = $extra['currency'];
            $lastcur = 1;
            $ptm_amount = str_replace(',', '.', $amount_fee);

            $paramList = array();

            $paramList["amount"] = number_format($ptm_amount * $lastcur, 2, '.', '');
            $paramList["currency"] = $getcur;
            $paramList["succes_url"] = site_url('payment/cashmaal');
            $paramList["cancel_url"] = site_url('payment/cashmaal');
            $paramList["client_email"] = $user['email'];
            $paramList["web_id"] = $extra['web_id'];
            $paramList["order_id"] = $icid;
            unset($_SESSION["data"]);
            if ($extra['web_id'] == '') :
                $error = 1;
                $errorText = "Admin has not configured this gateway yet..";
            else :
                //haina
                $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
                $insert->execute(array("c_id" => $user['client_id'], "amount" => $amount, "code" => $paymentCode, "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $icid));
                if ($insert) :
                    $success = 1;
                    $successText = "Your payment was initiated successfully, you are being redirected..";
                    echo '<form method="post" action="https://www.cashmaal.com/Pay/" name="f2">
                        <table border="1">
                            <tbody>
                             <input type="hidden" name="pay_method" value="">';
                    foreach ($paramList as $name => $value) {
                        echo '<input type="hidden" name="' . $name . '" value="' . $value . '">';
                    }
                    echo '<input type="hidden" name="addi_info" value="eg. John Domain renewal payment">';
                    echo '<center>Redirecting...<input type="hidden" name="Submit" value="Pay With Cash-Maal"></center>
                            </tbody>
                        </table>
                        <script type="text/javascript">
                            document.f2.submit();
                        </script>
                        </form>';

                else :
                    $error = 1;
                    $errorText = "There was an error starting your payment, please try again later..";

                endif;

            endif;

        elseif ($method_id == 7) :
            $merchant_id = $extra["merchant_id"];
            $merchant_key = $extra["merchant_key"];
            $merchant_salt = $extra["merchant_salt"];
            $email = $user["email"];
            $payment_amount = $amount_fee * 100;
            $merchant_oid = rand(9999, 9999999);
            $user_name = $user["name"];
            $user_address = "Belirtilmemiş";
            $user_phone = $user["telephone"];
            $payment_type = "eft";
            $user_ip = GetIP();
            $timeout_limit = "360";
            $debug_on = 1;
            $test_mode = 0;
            $no_installment = 0;
            $max_installment = 0;
            $hash_str = $merchant_id . $user_ip . $merchant_oid . $email . $payment_amount . $payment_type . $test_mode;
            $paytr_token = base64_encode(hash_hmac('sha256', $hash_str . $merchant_salt, $merchant_key, true));
            $post_vals = array('merchant_id' => $merchant_id, 'user_ip' => $user_ip, 'merchant_oid' => $merchant_oid, 'email' => $email, 'payment_amount' => $payment_amount, 'payment_type' => $payment_type, 'paytr_token' => $paytr_token, 'debug_on' => $debug_on, 'timeout_limit' => $timeout_limit, 'test_mode' => $test_mode);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://www.paytr.com/odeme/api/get-token");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_vals);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            $result = @curl_exec($ch);
            if (curl_errno($ch)) die("PAYTR IFRAME connection error. err:" . curl_error($ch));
            curl_close($ch);
            $result = json_decode($result, 1);
            if ($result['status'] == 'success') :
                unset($_SESSION["data"]);
                $token = $result['token'];
                $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
                $insert->execute(array("c_id" => $user["client_id"], "amount" => $amount, "code" => $paymentCode, "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $merchant_oid));
                $success = 1;
                $successText = "Your payment was initiated successfully, you are being redirected..";
                $payment_url = "https://www.paytr.com/odeme/api/" . $token;
            else :
                $error = 1;
                $errorText = "There was an error starting your payment, please try again later..";
            endif;
            
            
        elseif ($method_id == 4) :
            $getcur = $extra['currency'];
                     $merchant_id = $extra["merchant_id"];
            $merchant_key = $extra["merchant_key"];
            $merchant_salt = $extra["merchant_salt"];
            $email = $user["email"];
             $payment_amount = $amount*100;
            $merchant_oid = rand(9999, 9999999);
            $user_name = "sshshs";
            $user_address = "Belirtilmemiş";
            $user_phone = "119282382882";
            $currency = $extra['currency'];
            $merchant_ok_url = URL;
            $merchant_fail_url = URL;
            $amounts=$amount;
            $amount=$amount*100;
            $user_basket = base64_encode(json_encode(array(array($amount . " " . $currency . " Bakiye", $amount, 1))));
            $user_ip = GetIP();
            $timeout_limit = "360";
            $debug_on = 1;
            $test_mode = 0;
            $no_installment = 0;
            $max_installment = 0;
            $hash_str = $merchant_id . $user_ip . $merchant_oid . $email . $payment_amount . $user_basket . $no_installment . $max_installment . $currency . $test_mode;
            $paytr_token = base64_encode(hash_hmac('sha256', $hash_str . $merchant_salt, $merchant_key, true));
            $post_vals = array('merchant_id' => $merchant_id, 'user_ip' => $user_ip, 'merchant_oid' => $merchant_oid, 'email' => $email, 'payment_amount' => $payment_amount, 'paytr_token' => $paytr_token, 'user_basket' => $user_basket, 'debug_on' => $debug_on, 'no_installment' => $no_installment, 'max_installment' => $max_installment, 'user_name' => $user_name, 'user_address' => $user_address, 'user_phone' => $user_phone, 'merchant_ok_url' => $merchant_ok_url, 'merchant_fail_url' => $merchant_fail_url, 'timeout_limit' => $timeout_limit, 'currency' => $currency, 'test_mode' => $test_mode);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://www.paytr.com/odeme/api/get-token");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_vals);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
              $result = @curl_exec($ch);
            if (curl_errno($ch)) die("PAYTR IFRAME connection error. err:" . curl_error($ch));
            curl_close($ch);
            $result = json_decode($result, 1);
              if ($result['status'] == 'success') :
                unset($_SESSION["data"]);
                $token = $result['token'];
                $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
                $insert->execute(array("c_id" => $user["client_id"], "amount" => $amounts, "code" => $paymentCode, "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $merchant_oid));
                $success = 1;
                $successText = "Your payment was initiated successfully, you are being redirected..";
                $payment_url = "https://www.paytr.com/odeme/guvenli/" . $token;
                                header("Location: " . $payment_url);
                                exit;

            else :
                $error = 1;
                $errorText = "There was an error starting your payment, please try again later..";
            endif;
            
            
        elseif ($method_id == 5) :
            $getcur = $extra['currency'];
            //  $lastcur = isset($currentcur->error) ? defined($getcur . '_') ? constant($getcur . '_') : die('There\'s a problem with currency. Please contact with admin.') : $currentcur->rates->$getcur;
            $payment_types = "";
            foreach ($extra["payment_type"] as $i => $v) {
                $payment_types .= $v . ",";
            }
            $amount_fee = $amount_fee * 100;
            $amount_fee = number_format($amount_fee * $lastcur, 2, '.', '');
            $payment_types = substr($payment_types, 0, -1);
            $hashOlustur = base64_encode(hash_hmac('sha256', $user["email"] . "|" . $user["email"] . "|" . $user['client_id'] . $extra['apiKey'], $extra['apiSecret'], true));
            $postData = array('apiKey' => $extra['apiKey'], 'hash' => $hashOlustur, 'returnData' => $user["email"], 'userEmail' => $user["email"], 'userIPAddress' => GetIP(), 'userID' => $user["client_id"], 'proApi' => TRUE, 'productData' => [
                "name" => $amount . $settings['currency'] . " Tutarında Bakiye (" . $paymentCode . ")", "amount" => $amount_fee, "extraData" => $paymentCode, "paymentChannel" => $payment_types,
                "commissionType" => $extra["commissionType"]
            ]);
            $curl = curl_init();
            curl_setopt_array($curl, array(CURLOPT_URL => "http://api.paywant.com/gateway.php", CURLOPT_RETURNTRANSFER => true, CURLOPT_ENCODING => "", CURLOPT_MAXREDIRS => 10, CURLOPT_TIMEOUT => 30, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, CURLOPT_CUSTOMREQUEST => "POST", CURLOPT_POSTFIELDS => http_build_query($postData),));
            $response = curl_exec($curl);
            $err = curl_error($curl);
            if (!$err) :
                $jsonDecode = json_decode($response, false);
                if ($jsonDecode->Status == 100) :
                    if (!strpos($jsonDecode->Message, "https")) $jsonDecode->Message = str_replace("http", "https", $jsonDecode->Message);
                    unset($_SESSION["data"]);
                    $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip ");
                    $insert->execute(array("c_id" => $user["client_id"], "amount" => $amount, "code" => $paymentCode, "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP()));
                    $success = 1;
                    $successText = "Your payment was initiated successfully, you are being redirected..";
                    $payment_url = $jsonDecode->Message;
                else :
                    //echo $response; // Dönen hatanın ne olduğunu bastır
                    $error = 1;
                    $errorText = "There was an error starting your payment, please try again later.." . $response;
                endif;
            else :
                $error = 1;
                $errorText = "There was an error starting your payment, please try again later..";
            endif;
        elseif ($method_id == 3) :
            if ($extra["processing_fee"]) :
                $amount_fee = $amount_fee + "0.49";
            endif;
            $paymentCode=md5(40).time();
              $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_extra=:payment_extra, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip ");
                $insert->execute(array("c_id" => $user['client_id'], "amount" => $amount, "payment_extra" => $paymentCode, "method" => $method_id, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP()));
            $getcur = $extra['currency'];
          $form_data = [
    "website_index" => $extra["website_index"],
    "apikey" => $extra["apiKey"],
    "apisecret" => $extra["apiSecret"],
    "item_name" => "Bakiye Ekleme",
    "order_id" => $paymentCode,
    "buyer_name" => $user["name"],
    "buyer_surname" => " ",
    "buyer_email" => $user["email"],
    "buyer_phone" => $user["telephone"],
    "city" => "NA",
    "billing_address" => "NA",
    "ucret" => $amount_fee
];

// Convert array to object
$data_object = json_decode(json_encode($form_data));
$form_html = generate_shopier_form($data_object);

// Output the form (HTML) returned by the function
            
             if ($_SESSION["data"]["payment_shopier"] == true) :
                $success = 1;
                $successText = "Your payment was initiated successfully, you are being redirected..";
                 
               
          echo $form_html;

            else :
                $error = 1;
                $errorText = "There was an error starting your payment, please try again later..";
            endif;
        endif;
    }
endif;
if ($payment_url) :
    echo '<script>setInterval(function(){window.location.href="' . $payment_url . '"},1000)</script>';
endif;


 