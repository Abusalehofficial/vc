<?php





$action = $_POST["action"];


if ($action == "phonepeQr") {



    $method = $conn->prepare('SELECT * FROM payment_methods WHERE id=:id');
    $method->execute(['id' => 31]);
    $method = $method->fetch(PDO::FETCH_ASSOC);
    $methodExtras = json_decode($method['method_extras'], true);


    $amount = $_POST["payment_amount"];

    if (empty($amount)) {

        $res["title"] = "Amount cannot be empty.";
        $res["icon"] = "error";

        echo json_encode($res);
        exit();
    } elseif ($methodExtras["min"] > $amount) {

        $res["title"] = "Amount must be greater than " . $methodExtras["min"];
        $res["icon"] = "error";

        echo json_encode($res);
        exit();
    } elseif ($methodExtras["max"] < $amount && $method["max"] != 0) {

        $res["title"] = "Amount must be less than " . $methodExtras["max"];
        $res["icon"] = "error";

        echo json_encode($res);
        exit();
    } else {
    }






    $amount_str = strval($amount);
    $transaction_id = $_POST["phonepeqr_orderid"];
    $transaction_id = strval($transaction_id);
    $method_id = 31;
    if (empty($transaction_id)) {
        $res["title"] = "Transaction id cannot be empty.";
        $res["icon"] = "error";

        echo json_encode($res);
        exit();
    }




    if (!function_exists('imap_open')) {
        $res["title"] = "Configuration issue , Contact Admin.";
        $res["icon"] = "error";

        echo json_encode($res);
        exit();
    } else {





        if (!countRow(['table' => 'payments', 'where' => [
            'payment_method' => 31,
            'payment_status' => 3, 'payment_delivery' => 2, 'payment_extra' => $transaction_id
        ]])) {


            $insertPayment = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
            $insertPayment->execute(array("c_id" => $user['client_id'], "amount" => $amount,  "method" => $method_id, "mode" => "Otomatik", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $transaction_id));





            /* Connecting Gmail server with IMAP */
            $connection = imap_open(
                '{imap.gmail.com:993/imap/ssl/novalidate-cert}INBOX',
                $methodExtras["phonepe_email"],
                $methodExtras["phonepe_email_pass"]
            );

            if (empty($connection)) {
                $res["title"] = "Configuration issue , Contact Admin.";
                $res["icon"] = "error";

                echo json_encode($res);
                exit();
            }


            $found = 0;

            /* Search Emails having the specified keyword in the email subject */
            $emailData = imap_search($connection, 'SUBJECT "Received ₹ ' . $amount . ' from "');





            if (!empty($emailData)) {
                rsort($emailData);

                foreach ($emailData as $emailIdent) {
                    $overview = imap_fetch_overview($connection, $emailIdent, 0);
                    $date = date("d F, Y", strtotime($overview[0]->date));

                    $from = $overview[0]->from;
                    $body =  imap_body($connection, $emailIdent);
                    $body =  strip_tags($body);


                    if (strpos($body, $transaction_id) !== false && strpos($body, $amount_str) !== false && strpos($from, "PhonePe") !== false && strpos($body, "Successful") !== false) {
                        $found = 1;
                        break;
                    } else {
                        $found = 0;
                    }
                }

                if ($found == 1) {

                    //Transaction Found.



                    $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra AND payments.payment_amount=:amount ORDER BY payment_id DESC LIMIT 1');
                    $payment->execute(['extra' => $transaction_id, 'amount' => $amount]);
                    $payment = $payment->fetch(PDO::FETCH_ASSOC);


                    $user = $conn->prepare("SELECT * FROM clients WHERE client_id=:client_id");
                    $user->execute(array("client_id" => $getfrompay['client_id']));
                    $user = $user->fetch(PDO::FETCH_ASSOC);


                    if ($settings['site_currency'] == "USD") {
                        $payment['payment_amount'] = $payment['payment_amount'] / $settings["dolar_charge"];
                    }

                    //referral

                    if ($user["ref_by"]) {
                        $reff = $conn->prepare("SELECT * FROM referral WHERE referral_code=:referral_code ");
                        $reff->execute(array("referral_code" => $user["ref_by"]));
                        $reff  = $reff->fetch(PDO::FETCH_ASSOC);

                        $newAmount = $payment['payment_amount'];

                        $update3 = $conn->prepare("UPDATE referral SET referral_totalFunds_byReffered=:referral_totalFunds_byReffered,
                        referral_total_commision=:referral_total_commision WHERE referral_code=:referral_code ");
                        $update3 = $update3->execute(array(
                            "referral_code" => $user["ref_by"],
                            "referral_totalFunds_byReffered" => round($reff["referral_totalFunds_byReffered"] + $newAmount, 2),
                            "referral_total_commision" => round($reff["referral_total_commision"] + (($settings["referral_commision"] / 100) * $newAmount), 2)
                        ));
                    }
                    //referral


                    $payment_bonus = $conn->prepare('SELECT * FROM payments_bonus WHERE bonus_method=:method && bonus_from<=:from ORDER BY bonus_from DESC LIMIT 1');
                    $payment_bonus->execute(['method' => $method['id'], 'from' => $payment['payment_amount']]);
                    $payment_bonus = $payment_bonus->fetch(PDO::FETCH_ASSOC);

                    if ($payment_bonus) {
                        $amount = $payment['payment_amount'] + (($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100);

                        $bonus_amount = ($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100;
                    } else {
                        $amount = $payment['payment_amount'];
                    }


                    $conn->beginTransaction();




                    if ($settings['site_currency'] == "USD") {
                        $amount = round($amount, 4);
                    } else {
                        $amount = round($amount, 2);
                    }

                    $payment_id = $payment['payment_id'];
                    $old_balance =  $payment['balance'];

                    $added_funds = $amount;

                    $final_balance =  $old_balance + $added_funds;

                    if ($settings["site_currency"] == "USD") {
                        $update = $conn->prepare('UPDATE payments SET client_balance=:balance, payment_amount=:payment_amount , payment_status=:status, payment_delivery=:delivery WHERE payment_id=:id ');
                        $update = $update->execute(['balance' => $payment['balance'], "payment_amount" =>  round($payment['payment_amount'], 4),  'status' => 3, 'delivery' => 2, 'id' => $payment['payment_id']]);
                    } else {
                        $update = $conn->prepare('UPDATE payments SET client_balance=:balance, payment_status=:status, payment_delivery=:delivery WHERE payment_id=:id ');
                        $update = $update->execute(['balance' => $payment['balance'], 'status' => 3, 'delivery' => 2, 'id' => $payment['payment_id']]);
                    }
                    $balance = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id ');
                    $balance = $balance->execute(['id' => $payment['client_id'], 'balance' => $payment['balance'] + $amount]);

                    $insert = $conn->prepare('INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ');

                    $insert25 = $conn->prepare("INSERT INTO payments SET client_id=:client_id , client_balance=:client_balance , payment_amount=:payment_amount , payment_method=:payment_method ,
                                            payment_status=:status, payment_delivery=:delivery , payment_note=:payment_note , payment_create_date=:payment_create_date , payment_extra=:payment_extra , bonus=:bonus");

                    $check = $conn->prepare('SELECT * FROM clients WHERE  client_id=:id');
                    $check->execute(['id' => $payment['client_id']]);
                    $check = $check->fetch(PDO::FETCH_ASSOC);

                    $username = $check["username"];

                    $user_balance_after_adding = $check['balance'];

                    $solved = "No";

                    if ($user_balance_after_adding == $final_balance) {
                        //do nothing
                    } else {
                        $update = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id ');
                        $update = $update->execute(['id' => $payment['client_id'], 'balance' => $final_balance]);

                        if ($update) {
                            $solved  = "yes";
                        }
                    }

                    $funds_difference = abs($final_balance - $user_balance_after_adding);

                    if ($final_balance != $user_balance_after_adding) {
                        if ($solved == "No") {
                            sendMail(["subject" => "Invalid Payment is added.", "body" => "<h3>Invalid payment added on this account </h3>
                                                    <p>Username : $username</p><p>Payment Method : PhonePe Qr</p><p>Payment ID : $payment_id </p><p>Funds Difference - $funds_difference </p><p>Solved : $solved </p>", "mail" => $settings["admin_mail"]]);
                        }
                        //notify admin
                    }

                    if ($payment_bonus) {
                        $insert25->execute(array(
                            "client_id" => $payment['client_id'], "client_balance" => (($payment['balance'] + $amount) - $bonus_amount),
                            "payment_amount" => $bonus_amount, "payment_method" =>  31, 'status' => 3, 'delivery' => 2, "payment_note" => "Bonus added", "payment_create_date" => date('Y-m-d H:i:s'), "payment_extra" => "Bonus added for previous payment",
                            "bonus" => 1
                        ));


                        $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'] . ' and included %' . $payment_bonus['bonus_amount'] . ' bonus , and Final balance 
                                              is ' . $final_balance  . ' ', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
                    } else {
                        $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'] . ' and Final balance 
                                              is ' . $final_balance  . ' ', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
                    }
                    if ($update && $balance) {

                        $conn->commit();


                        afterPaymentDone($payment["username"], $payment['payment_amount'], $method_name);

                        $res["title"] = 'Payment Added Succesfully!';
                        $res["icon"] = "success";

                        echo json_encode($res);
                        exit();
                    } else {
                        $conn->rollBack();

                        $res["title"] = 'Some Error Occured!';
                        $res["icon"] = "info";

                        echo json_encode($res);
                        exit();
                    }
                } else {
                    //Transaction Not Found.

                    $res["title"] = "Invalid Payment!";
                    $res["icon"] = "error";

                    echo json_encode($res);
                    exit();
                }
            } else {
                $res["title"] = "Invalid Payment!";
                $res["icon"] = "error";

                echo json_encode($res);
                exit();
            }
        } else {
            //Duplicate Transaction ID

            $res["title"] = "Transaction ID Already Used!";
            $res["icon"] = "error";


            echo json_encode($res);
            exit();
        }
    }
}






$method_name = route(1);
if (!countRow(['table' => 'payment_methods', 'where' => ['method_get' => $method_name]])) {
    header('Location:' . site_url());
    exit();
}










$method = $conn->prepare('SELECT * FROM payment_methods WHERE method_get=:get');
$method->execute(['get' => $method_name]);
$method = $method->fetch(PDO::FETCH_ASSOC);
$extras = json_decode($method['method_extras'], true);
if ($method_name == 'shopier') {

    $rawPostData = file_get_contents("php://input");
    
    // Get the raw POST data
 
// Define the file name
//$file = 'data.txt';

// Save the data into the file
//file_put_contents($file, $rawPostData);
parse_str($rawPostData, $postData);

 
if (isset($postData['status']) && $postData['status'] == 'success') {
    $transactionId = $postData['platform_order_id'];
    $order_id = $postData['platform_order_id'];
    
       
            $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:orderid ');
            $payment->execute(['orderid' => $order_id]);
            $payment = $payment->fetch(PDO::FETCH_ASSOC);
            $payment_bonus = $conn->prepare('SELECT * FROM payments_bonus WHERE bonus_method=:method && bonus_from<=:from ORDER BY bonus_from DESC LIMIT 1 ');
            $payment_bonus->execute(['method' => $method['id'], 'from' => $payment['payment_amount']]);
            $payment_bonus = $payment_bonus->fetch(PDO::FETCH_ASSOC);
            if ($payment_bonus) {
                $amount = $payment['payment_amount'] + (($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100);
            } else {
                $amount = $payment['payment_amount'];
            }
              $conn->beginTransaction();
            $update = $conn->prepare('UPDATE payments SET client_balance=:balance, payment_status=:status, payment_delivery=:delivery WHERE payment_id=:id ');
            $update = $update->execute(['balance' => $payment['balance'], 'status' => 3, 'delivery' => 2,'id' => $payment['payment_id']]);
            $balance = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id ');
            $balance = $balance->execute(['id' => $payment['client_id'], 'balance' => $payment['balance'] + $amount]);
            $insert = $conn->prepare('INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ');
            if ($payment_bonus) {
                $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'] . ' and included %' . $payment_bonus['bonus_amount'] . ' bonus.', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
            } else {
                $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'], 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
            }
            if ($update && $balance) {
                afterPaymentDone($payment["username"], $payment['payment_amount'], $method_name);
                $conn->commit();
            } else {
                $conn->rollBack();
            }
        
    } else {
        $update = $conn->prepare('UPDATE payments SET payment_status=:status, payment_delivery=:delivery WHERE payment_extra=:code  ');
        $update = $update->execute(['status' => 2, 'delivery' => 1, 'code' => $order_id]);
    }
    header('Location:' . site_url());
} else if ($method_name == 'paytr') {
    $post = $_POST;
    $order_id = $post['merchant_oid'];
    $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:orderid ');
    $payment->execute(['orderid' => $order_id]);
    $payment = $payment->fetch(PDO::FETCH_ASSOC);
    $method = $conn->prepare('SELECT * FROM payment_methods WHERE id=:id ');
    $method->execute(['id' => $payment['payment_method']]);
    $method = $method->fetch(PDO::FETCH_ASSOC);
    $extras = json_decode($method['method_extras'], true);
    $merchant_key = $extras['merchant_key'];
    $merchant_salt = $extras['merchant_salt'];
    $hash = base64_encode(hash_hmac('sha256', $post['merchant_oid'] . $merchant_salt . $post['status'] . $post['total_amount'], $merchant_key, true));
    if ($hash != $post['hash']) {
        die('PAYTR notification failed: bad hash');
     }
    if ($post['status'] == 'success') {
        if (countRow(['table' => 'payments', 'where' => ['payment_extra' => $order_id, 'payment_delivery' => 1]])) {
            $payment_bonus = $conn->prepare('SELECT * FROM payments_bonus WHERE bonus_method=:method && bonus_from<=:from ORDER BY bonus_from DESC LIMIT 1 ');
            $payment_bonus->execute(['method' => $method['id'], 'from' => $payment['payment_amount']]);
            $payment_bonus = $payment_bonus->fetch(PDO::FETCH_ASSOC);
            if ($payment_bonus) {
                $amount = $payment['payment_amount'] + (($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100);
            } else {
                $amount = $payment['payment_amount'];
            }
            $extra = $_POST;
            $extra = json_encode($extra);
            $conn->beginTransaction();
            $update = $conn->prepare('UPDATE payments SET client_balance=:balance, payment_status=:status, payment_delivery=:delivery WHERE payment_id=:id ');
            $update = $update->execute(['balance' => $payment['balance'], 'status' => 3, 'delivery' => 2, 'id' => $payment['payment_id']]);
            $balance = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id ');
            $balance = $balance->execute(['id' => $payment['client_id'], 'balance' => $payment['balance'] + $amount]);
            $insert = $conn->prepare('INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ');
            if ($payment_bonus) {
                $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'] . ' and included %' . $payment_bonus['bonus_amount'] . ' bonus.', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
            } else {
                $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'], 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
            }
            if ($update && $balance) {
                afterPaymentDone($payment["username"], $payment['payment_amount'], $method_name);
                $conn->commit();
                                 echo "OK";
die;


            } else {
                $conn->rollBack();
                                echo "OK";
die;

             }
        }
    } else {
        $update = $conn->prepare('UPDATE payments SET payment_status=:status, payment_delivery=:delivery WHERE payment_privatecode=:code  ');
        $update = $update->execute(['status' => 2, 'delivery' => 1, 'code' => $order_id]);
    }
} 






else if ($method_name == 'heleket') {
    $merchant_uuid = $extras["merchant_uuid"];
    $payment_api_key = $extras["payment_api_key"];

  

   $data = file_get_contents('php://input');
$data = json_decode($data, true);

    $sign = $data["sign"];
    unset($data["sign"]);

    $hash = md5(base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE)) . $payment_api_key);

    if (hash_equals($hash, $sign)) {
        $txid = $data['txid'];
        $amountfromheleket = $data['payment_amount_usd'];
        $status = $data['status'];
        $order_id = $data['order_id'];

        $updatePayments = $conn->prepare("UPDATE payments SET payment_response=:payment_response WHERE payment_extra=:payment_extra");
        $updatePayments->execute(array("payment_response" => json_encode($data), "payment_extra" => $order_id));

        if ($status == "paid" || $status == "paid_over") {
            $getfrompay = $conn->prepare("SELECT * FROM payments WHERE payment_extra=:payment_extra");
            $getfrompay->execute(array("payment_extra" => $order_id));
            $getfrompay = $getfrompay->fetch(PDO::FETCH_ASSOC);

            $user = $conn->prepare("SELECT * FROM clients WHERE client_id=:client_id");
            $user->execute(array("client_id" => $getfrompay['client_id']));
            $user = $user->fetch(PDO::FETCH_ASSOC);

            if (countRow(['table' => 'payments', 'where' => ['client_id' => $user['client_id'], 'payment_method' => $method['id'], 'payment_status' => 1, 'payment_delivery' => 1, 'payment_extra' => $order_id]])) {
                $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra ');
                $payment->execute(['extra' => $order_id]);
                $payment = $payment->fetch(PDO::FETCH_ASSOC);

                $payment_bonus = $conn->prepare('SELECT * FROM payments_bonus WHERE bonus_method=:method && bonus_from<=:from ORDER BY bonus_from DESC LIMIT 1');
                $payment_bonus->execute(['method' => $method['id'], 'from' => $payment['payment_amount']]);
                $payment_bonus = $payment_bonus->fetch(PDO::FETCH_ASSOC);
                if ($payment_bonus) {
                    $amount = $payment['payment_amount'] + (($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100);

                    $bonus_amount = ($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100;

                } else {

                    $amount = $payment['payment_amount'];
                }

                $conn->beginTransaction();

                $payment_id = $payment['payment_id'];

                $old_balance = $payment['balance'];

                $added_funds = $amount;

                $final_balance = $old_balance + $added_funds;

                $update = $conn->prepare('UPDATE payments SET client_balance=:balance, payment_status=:status, payment_response=:payment_response , payment_delivery=:delivery WHERE payment_id=:id ');
                $update = $update->execute(['balance' => $payment['balance'], "payment_response" => json_encode($data), 'status' => 3, 'delivery' => 2, 'id' => $payment['payment_id']]);

                $balance = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id ');

                $balance = $balance->execute(['id' => $payment['client_id'], 'balance' => $payment['balance'] + $amount]);

                $insert = $conn->prepare('INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ');

                $insert25 = $conn->prepare("INSERT INTO payments SET client_id=:client_id , client_balance=:client_balance , payment_amount=:payment_amount , payment_method=:payment_method , payment_status=:status, payment_delivery=:delivery , payment_note=:payment_note , payment_create_date=:payment_create_date , payment_extra=:payment_extra , bonus=:bonus");

                if ($payment_bonus) {
                    $insert25->execute(array("client_id" => $payment['client_id'], "client_balance" => (($payment['balance'] + $amount) - $bonus_amount), "payment_amount" => $bonus_amount, "payment_method" => 36, 'status' => 3, 'delivery' => 2, "payment_note" => "Bonus added", "payment_create_date" => date('Y-m-d H:i:s'), "payment_extra" => "Bonus added for previous payment", "bonus" => 1));

                    $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'] . ' and included %' . $payment_bonus['bonus_amount'] . ' bonus , and Final balance is ' . $final_balance . ' ', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
                } else {

                    $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'] . ' and Final balance is ' . $final_balance . ' ', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
                }

                if ($update && $balance) {
                    afterPaymentDone($payment["username"], $payment['payment_amount'], $method_name);
                    $conn->commit();
                    echo 'OK';
                    exit;
                } else {
                    $conn->rollBack();
                    echo 'NO';
                    exit;
                }

            } else {

                exit;
            }
        } else {
            exit;
        }
    } else {
        exit;
    }

    
    
    
}





else if ($method_name == 'paywant') {
    $apiKey = $extras['apiKey'];
    $apiSecret = $extras['apiSecret'];
    $SiparisID = $_POST['SiparisID'];
    $ExtraData = $_POST['ExtraData'];
    $UserID = $_POST['UserID'];
    $ReturnData = $_POST['ReturnData'];
    $Status = $_POST['Status'];
    $OdemeKanali = $_POST['OdemeKanali'];
    $OdemeTutari = $_POST['OdemeTutari'];
    $NetKazanc = $_POST['NetKazanc'];
    $Hash = $_POST['Hash'];
    $order_id = $_POST['ExtraData'];
    $hashKontrol = base64_encode(hash_hmac('sha256', $SiparisID . '|' . $ExtraData . '|' . $UserID . '|' . $ReturnData . '|' . $Status . '|' . $OdemeKanali . '|' . $OdemeTutari . '|' . $NetKazanc . $apiKey, $apiSecret, true));
    if ($hashKontrol != $Hash) {
        exit('HASH Hatal覺');
        exit();
    }
    if ($Status == 100) {
        if (countRow(['table' => 'payments', 'where' => ['payment_privatecode' => $order_id, 'payment_delivery' => 1]])) {
            $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_privatecode=:orderid ');
            $payment->execute(['orderid' => $order_id]);
            $payment = $payment->fetch(PDO::FETCH_ASSOC);
            $payment_bonus = $conn->prepare('SELECT * FROM payments_bonus WHERE bonus_method=:method && bonus_from<=:from ORDER BY bonus_from DESC LIMIT 1 ');
            $payment_bonus->execute(['method' => $method['id'], 'from' => $payment['payment_amount']]);
            $payment_bonus = $payment_bonus->fetch(PDO::FETCH_ASSOC);
            if ($payment_bonus) {
                $amount = $payment['payment_amount'] + (($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100);
            } else {
                $amount = $payment['payment_amount'];
            }
            $extra = $_POST;
            $extra = json_encode($extra);
            $conn->beginTransaction();
            $update = $conn->prepare('UPDATE payments SET client_balance=:balance, payment_status=:status, payment_delivery=:delivery, payment_extra=:extra WHERE payment_id=:id ');
            $update = $update->execute(['balance' => $payment['balance'], 'status' => 3, 'delivery' => 2, 'extra' => $extra, 'id' => $payment['payment_id']]);
            $balance = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id ');
            $balance = $balance->execute(['id' => $payment['client_id'], 'balance' => $payment['balance'] + $amount]);
            $insert = $conn->prepare('INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ');
            if ($payment_bonus) {
                $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'] . ' and included %' . $payment_bonus['bonus_amount'] . ' bonus.', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
            } else {
                $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'], 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
            }
            if ($update && $balance) {
                afterPaymentDone($payment["username"], $payment['payment_amount'], $method_name);
                $conn->commit();
                echo 'OK';
            } else {
                $conn->rollBack();
                echo 'NO';
            }
        } else {
            echo 'OK-';
        }
    } else {
        $update = $conn->prepare('UPDATE payments SET payment_status=:status, payment_delivery=:delivery WHERE payment_privatecode=:code  ');
        $update = $update->execute(['status' => 2, 'delivery' => 1, 'code' => $order_id]);
        echo 'NOOO';
    }
} 

    else if ($method_name == 'paypal') {
       
  require_once "lib/paypal/autoload.php";
    
      $method = $conn->prepare("SELECT * FROM payment_methods WHERE id=:id ");
        $method->execute(array("id" => 1));
        $method = $method->fetch(PDO::FETCH_ASSOC);
        $extra = json_decode($method["method_extras"], true);
        

        $clientId = $extra['clientId']; 
        $clientSecret = $extra['clientSecret'];
    

        
            $environment = new PayPalCheckoutSdk\Core\ProductionEnvironment($clientId, $clientSecret);
        
    

        $client = new PayPalCheckoutSdk\Core\PayPalHttpClient($environment);


    $order_id = $_POST['ORDERID'] = $_REQUEST['token'];
    
   
    $request = new PayPalCheckoutSdk\Orders\OrdersCaptureRequest($order_id);
    $request->prefer('return=representation');
  

        // Call API with your client and get a response for your call
        $response = $client->execute($request);


// Retrieve order ID from request
$order_id = $_REQUEST['token'] ?? null;

if (!$order_id) {
    echo "Order ID is missing.";
    exit();
}

// Fetch payment details
$paymentStmt = $conn->prepare("SELECT * FROM payments WHERE payment_extra = :payment_extra");
$paymentStmt->execute(["payment_extra" => $order_id]);
$payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    echo "Payment not found.";
    exit();
}

// Check if the payment already exists
if (countRow(['table' => 'payments', 'where' => [
    'client_id' => $payment['client_id'],
    'payment_method' => 1,
    'payment_status' => 1,
    'payment_delivery' => 1,
    'payment_extra' => $order_id
]])) {
    // Process payment details
    $paymentStmt = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id = payments.client_id WHERE payments.payment_extra = :extra');
    $paymentStmt->execute(['extra' => $order_id]);
    $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        echo "Payment details not found.";
        exit();
    }

    // Convert payment amount
    $payment['payment_amount'] = convertCurrencyUpdated("USD", $settings["site_currency"], $payment['payment_amount']);
    $amount = $payment['payment_amount'];
    
    // Fee percentage
$fee_percentage = $extras['fee']; // Example: 5 for 5%

// Calculate commission amount
$commission = ($payment_amount * $fee_percentage) / 100;

// Subtract commission from payment amount
$payment['payment_amount'] = $payment_amount - $commission;

    // Begin transaction
    $conn->beginTransaction();

    // Update payment status
    $update = $conn->prepare('UPDATE payments SET client_balance = :balance, payment_status = :status, payment_delivery = :delivery WHERE payment_id = :id');
    $update->execute([
        'balance' => $payment['balance'],
        'status' => 3,
        'delivery' => 2,
        'id' => $payment['payment_id']
    ]);

    // Update client balance
    $balance = $conn->prepare('UPDATE clients SET balance = :balance WHERE client_id = :id');
    $balance->execute([
        'id' => $payment['client_id'],
        'balance' => $payment['balance'] + $amount
    ]);

    // Log the action
    $insert = $conn->prepare('INSERT INTO client_report SET client_id = :c_id, action = :action, report_ip = :ip, report_date = :date');
    $insert->execute([
        'c_id' => $payment['client_id'],
        'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'],
        'ip' => GetIP(),
        'date' => date('Y-m-d H:i:s')
    ]);

    if ($update && $balance && $insert) {
        $conn->commit();

        // Trigger referral commission
        // Redirect to add funds page
        header('Location: ' . site_url('addfunds'));
        echo 'OK';
    } else {
        $conn->rollBack();
        header('Location: ' . site_url('addfunds'));
        echo 'NO';
    }
} else {
    header('Location: ' . site_url('addfunds'));
}
 
}









else if ($method_name == 'paypal') {
    $ipn = new PaypalIPN();
    // Use the sandbox endpoint during testing.
    // $ipn->useSandbox();


    $response1 = array(
        'time' => date("Y-m-d H:i:s"),
        'response' => $_POST
    );




    $verified = $ipn->verifyIPN();
    if ($verified) {
        if (countRow(['table' => 'payments', 'where' => ['client_id' => $_POST['custom'], 'payment_method' => 32, 'payment_status' => 1, 'payment_delivery' => 1, 'payment_extra' => $_POST['invoice']]])) {
            if ($_POST['payment_status'] == 'Completed') {
                $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra ');
                $payment->execute(['extra' => $_POST['invoice']]);
                $payment = $payment->fetch(PDO::FETCH_ASSOC);
                $payment_bonus = $conn->prepare('SELECT * FROM payments_bonus WHERE bonus_method=:method && bonus_from<=:from ORDER BY bonus_from DESC LIMIT 1');
                $payment_bonus->execute(['method' => $method['id'], 'from' => $payment['payment_amount']]);
                $payment_bonus = $payment_bonus->fetch(PDO::FETCH_ASSOC);
                if ($payment_bonus) {
                    $amount = $payment['payment_amount'] + (($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100);
                    $bonus_amount = ($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100;
                } else {
                    $amount = $payment['payment_amount'];
                }
                $conn->beginTransaction();

                $update = $conn->prepare('UPDATE payments SET client_balance=:balance, payment_status=:status,  payment_response=:payment_response,payment_delivery=:delivery WHERE payment_id=:id ');
                $update = $update->execute(['balance' => $payment['balance'], 'status' => 3, 'delivery' => 2, "payment_response" => json_encode(array($response1)), 'id' => $payment['payment_id']]);

                $balance = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id ');
                $balance = $balance->execute(['id' => $payment['client_id'], 'balance' => $payment['balance'] + $amount]);

                $insert = $conn->prepare('INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ');
                $insert25 = $conn->prepare("INSERT INTO payments SET client_id=:client_id , client_balance=:client_balance , payment_amount=:payment_amount , payment_method=:payment_method ,
                payment_status=:status, payment_delivery=:delivery , payment_note=:payment_note , payment_create_date=:payment_create_date , payment_extra=:payment_extra , bonus=:bonus");



                if ($payment_bonus) {

                    $insert25->execute(array(
                        "client_id" => $payment['client_id'], "client_balance" => (($payment['balance'] + $amount) - $bonus_amount),
                        "payment_amount" => $bonus_amount, "payment_method" =>  1, 'status' => 3, 'delivery' => 2, "payment_note" => "Bonus added", "payment_create_date" => date('Y-m-d H:i:s'), "payment_extra" => "Bonus added for previous payment",
                        "bonus" => 1
                    ));
                    $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'] . ' and included %' . $payment_bonus['bonus_amount'] . ' bonus.', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
                } else {
                    $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'], 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
                }
                if ($update && $balance) {
                    afterPaymentDone($payment["username"], $payment['payment_amount'], $method_name);
                    $conn->commit();
                    echo 'OK';
                } else {
                    $conn->rollBack();
                    echo 'NO';
                }
            } else {
                $update = $conn->prepare('UPDATE payments SET payment_status=:payment_status WHERE client_id=:client_id, payment_method=:payment_method, payment_delivery=:payment_delivery, payment_extra=:payment_extra');
                $update = $update->execute(['payment_status' => 2, 'client_id' => $_POST['custom'], 'payment_method' => 32, 'payment_delivery' => 1, 'payment_extra' => $_POST['invoice']]);
            }
        }
    }

    header("HTTP/1.1 200 OK");
}





 
 else if ($method_name == 'stripe') {


    \Stripe\Stripe::setApiKey($extras['stripe_secret_key']);

    $payload = @file_get_contents('php://input');
    $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
    $event = null;

    p($_SERVER);

    try {
        $event = \Stripe\Webhook::constructEvent(
            $payload,
            $sig_header,
            $extras['stripe_webhooks_secret']
        );
    } catch (\UnexpectedValueException $e) {
        http_response_code(400);
        exit();
    } catch (\Stripe\Exception\SignatureVerificationException $e) {
        http_response_code(400);
        exit();
    }



    // Handle the event
    if ($event->type == 'checkout.session.completed') {
        $user = $conn->prepare("SELECT * FROM clients WHERE email=:email");
        $user->execute(array("email" => $event->data->object->customer_email));
        $user = $user->fetch(PDO::FETCH_ASSOC);
        if (countRow(['table' => 'payments', 'where' => ['client_id' => $user['client_id'], 'payment_method' => 2, 'payment_status' => 1, 'payment_delivery' => 1, 'payment_extra' => $event->data->object->client_reference_id]])) {
            if ($event->type == 'checkout.session.completed') {
                $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra ');
                $payment->execute(['extra' => $event->data->object->client_reference_id]);
                $payment = $payment->fetch(PDO::FETCH_ASSOC);
                $payment_bonus = $conn->prepare('SELECT * FROM payments_bonus WHERE bonus_method=:method && bonus_from<=:from ORDER BY bonus_from DESC LIMIT 1');
                $payment_bonus->execute(['method' => $method['id'], 'from' => $payment['payment_amount']]);
                $payment_bonus = $payment_bonus->fetch(PDO::FETCH_ASSOC);
                if ($payment_bonus) {
                    $amount = $payment['payment_amount'] + (($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100);
                } else {
                    $amount = $payment['payment_amount'];
                }
                $conn->beginTransaction();

                $update = $conn->prepare('UPDATE payments SET client_balance=:balance, payment_status=:status, payment_delivery=:delivery WHERE payment_id=:id ');
                $update = $update->execute(['balance' => $payment['balance'], 'status' => 3, 'delivery' => 2, 'id' => $payment['payment_id']]);

                $balance = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id ');
                $balance = $balance->execute(['id' => $payment['client_id'], 'balance' => $payment['balance'] + $amount]);

                $insert = $conn->prepare('INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ');
                if ($payment_bonus) {
                    $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'] . ' and included %' . $payment_bonus['bonus_amount'] . ' bonus.', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
                } else {
                    $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'], 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
                }
                if ($update && $balance) {
                    afterPaymentDone($payment["username"], $payment['payment_amount'], $method_name);
                    $conn->commit();
                    echo 'OK';
                } else {
                    $conn->rollBack();
                    echo 'NO';
                }
            } else {
                $update = $conn->prepare('UPDATE payments SET payment_status=:payment_status WHERE client_id=:client_id, payment_method=:payment_method, payment_delivery=:payment_delivery, payment_extra=:payment_extra');
                $update = $update->execute(['payment_status' => 2, 'client_id' => $user['client_id'], 'payment_method' => 2, 'payment_delivery' => 1, 'payment_extra' => $event->data->object->client_reference_id]);
            }
        }
    }
    http_response_code(200);
} else if ($method_name == 'coinpayments') {
    $merchant_id = $extras['merchant_id'];
    $secret = $extras['ipn_secret'];

    function errorAndDie($error_msg)
    {
        die('IPN Error: ' . $error_msg);
    }

    if (!isset($_POST['ipn_mode']) || $_POST['ipn_mode'] != 'hmac') {
        $ipnmode = $_POST['ipn_mode'];
        errorAndDie("IPN Mode is not HMAC $ipnmode");
    }

    if (!isset($_SERVER['HTTP_HMAC']) || empty($_SERVER['HTTP_HMAC'])) {
        errorAndDie("No HMAC signature sent");
    }

    $merchant = isset($_POST['merchant']) ? $_POST['merchant'] : '';
    if (empty($merchant)) {
        errorAndDie("No Merchant ID passed");
    }

    if (!isset($_POST['merchant']) || $_POST['merchant'] != trim($merchant_id)) {
        errorAndDie('No or incorrect Merchant ID passed');
    }

    $request = file_get_contents('php://input');
    if ($request === FALSE || empty($request)) {
        errorAndDie("Error reading POST data");
    }

    $hmac = hash_hmac("sha512", $request, $secret);
    if ($hmac != $_SERVER['HTTP_HMAC']) {
        errorAndDie("HMAC signature does not match");
    }

    // HMAC Signature verified at this point, load some variables. 

    $status = intval($_POST['status']);
    $status_text = $_POST['status_text'];

    $txn_id = $_POST['txn_id'];
    $currency1 = $_POST['currency1'];

    $amount1 = floatval($_POST['amount1']);

    $order_currency = $settings['currency'];
    $order_total = $amount1;

    $subtotal = $_POST['subtotal'];
    $shipping = $_POST['shipping'];

    ///////////////////////////////////////////////////////////////

    // Check the original currency to make sure the buyer didn't change it. 
    if ($currency1 != $order_currency) {
        errorAndDie('Original currency mismatch!');
    }

    if ($amount1 < $order_total) {
        errorAndDie('Amount is less than order total!');
    }

    if ($status >= 100 || $status == 2) {
        $user = $conn->prepare("SELECT * FROM clients WHERE email=:email");
        $user->execute(array("email" => $_POST['email']));
        $user = $user->fetch(PDO::FETCH_ASSOC);
        if (countRow(['table' => 'payments', 'where' => ['client_id' => $user['client_id'], 'payment_method' => 8, 'payment_status' => 1, 'payment_delivery' => 1, 'payment_extra' => $_POST['txn_id']]])) {
            if ($status >= 100 || $status == 2) {
                $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra ');
                $payment->execute(['extra' => $_POST['txn_id']]);
                $payment = $payment->fetch(PDO::FETCH_ASSOC);
                $payment_bonus = $conn->prepare('SELECT * FROM payments_bonus WHERE bonus_method=:method && bonus_from<=:from ORDER BY bonus_from DESC LIMIT 1');
                $payment_bonus->execute(['method' => $method['id'], 'from' => $payment['payment_amount']]);
                $payment_bonus = $payment_bonus->fetch(PDO::FETCH_ASSOC);
                if ($payment_bonus) {
                    $amount = $payment['payment_amount'] + (($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100);
                } else {
                    $amount = $payment['payment_amount'];
                }
                $conn->beginTransaction();

                $update = $conn->prepare('UPDATE payments SET client_balance=:balance, payment_status=:status, payment_delivery=:delivery WHERE payment_id=:id ');
                $update = $update->execute(['balance' => $payment['balance'], 'status' => 3, 'delivery' => 2, 'id' => $payment['payment_id']]);

                $balance = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id ');
                $balance = $balance->execute(['id' => $payment['client_id'], 'balance' => $payment['balance'] + $amount]);

                $insert = $conn->prepare('INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ');
                if ($payment_bonus) {
                    $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'] . ' and included %' . $payment_bonus['bonus_amount'] . ' bonus.', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
                } else {
                    $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'], 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
                }
                if ($update && $balance) {
                    afterPaymentDone($payment["username"], $payment['payment_amount'], $method_name);
                    $conn->commit();
                    echo 'OK';
                } else {
                    $conn->rollBack();
                    echo 'NO';
                }
            } else {
                $update = $conn->prepare('UPDATE payments SET payment_status=:payment_status WHERE client_id=:client_id, payment_method=:payment_method, payment_delivery=:payment_delivery, payment_extra=:payment_extra');
                $update = $update->execute(['payment_status' => 2, 'client_id' => $user['client_id'], 'payment_method' => 8, 'payment_delivery' => 1, 'payment_extra' => $_POST['txn_id']]);
            }
        }
    }
    die('IPN OK');
} else if ($method_name == 'payeer') {

    $response1 = json_encode(array(array(
        'time' => date("Y-m-d H:i:s"),
        'response' => $_POST
    )));


    if (isset($_REQUEST['m_operation_id']) && isset($_REQUEST['m_sign'])) {
        $m_key = $extras["secret_key"];


        $arHash = array(
            $_REQUEST['m_operation_id'],
            $_REQUEST['m_operation_ps'],
            $_REQUEST['m_operation_date'],
            $_REQUEST['m_operation_pay_date'],
            $_REQUEST['m_shop'],
            $_REQUEST['m_orderid'],
            $_REQUEST['m_amount'],
            $_REQUEST['m_curr'],
            $_REQUEST['m_desc'],
            $_REQUEST['m_status']
        );



        if (isset($_REQUEST['m_params'])) {
            $arHash[] = $_REQUEST['m_params'];
        }



        $arHash[] = $m_key;


        $sign_hash = strtoupper(hash('sha256', implode(':', $arHash)));



        if ($_REQUEST['m_sign'] == $sign_hash && $_REQUEST['m_status'] == 'success') {
            ob_end_clean();
            $tnx_id = $_REQUEST['m_orderid'];


            $getfrompay = $conn->prepare("SELECT * FROM payments WHERE payment_extra=:payment_extra");
            $getfrompay->execute(array("payment_extra" => $tnx_id));
            $getfrompay = $getfrompay->fetch(PDO::FETCH_ASSOC);





            $user = $conn->prepare("SELECT * FROM clients WHERE client_id=:client_id");
            $user->execute(array("client_id" => $getfrompay['client_id']));
            $user = $user->fetch(PDO::FETCH_ASSOC);

            if (countRow(['table' => 'payments', 'where' => ['client_id' => $user['client_id'], 'payment_method' => 35, 'payment_status' => 1, 'payment_delivery' => 1, 'payment_extra' => $tnx_id]])) {
                if ($getfrompay ) {
                    $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra ');
                    $payment->execute(['extra' => $tnx_id]);
                    $payment = $payment->fetch(PDO::FETCH_ASSOC);


                    //referral

                    if ($user["ref_by"]) {
                        $reff = $conn->prepare("SELECT * FROM referral WHERE referral_code=:referral_code ");
                        $reff->execute(array("referral_code" => $user["ref_by"]));
                        $reff  = $reff->fetch(PDO::FETCH_ASSOC);




                        $newAmount = $payment['payment_amount'];

                        $update3 = $conn->prepare("UPDATE referral SET referral_totalFunds_byReffered=:referral_totalFunds_byReffered,
                            referral_total_commision=:referral_total_commision WHERE referral_code=:referral_code ");
                        $update3 = $update3->execute(array(
                            "referral_code" => $user["ref_by"],
                            "referral_totalFunds_byReffered" => round($reff["referral_totalFunds_byReffered"] + $newAmount, 2),
                            "referral_total_commision" => round($reff["referral_total_commision"] + (($settings["referral_commision"] / 100) * $newAmount), 2)
                        ));
                    }
                    //referral


                    $payment_bonus = $conn->prepare('SELECT * FROM payments_bonus WHERE bonus_method=:method && bonus_from<=:from ORDER BY bonus_from DESC LIMIT 1');
                    $payment_bonus->execute(['method' => $method['id'], 'from' => $payment['payment_amount']]);
                    $payment_bonus = $payment_bonus->fetch(PDO::FETCH_ASSOC);
                    if ($payment_bonus) {
                        $amount = $payment['payment_amount'] + (($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100);
                    } else {
                        $amount = $payment['payment_amount'];
                    }

                    $conn->beginTransaction();

                    $amount = round($amount, 2);

                    if ($settings["site_currency"] == "INR") {
                        $update = $conn->prepare('UPDATE payments SET client_balance=:balance, payment_response=:payment_response , payment_amount=:payment_amount , payment_status=:status, payment_delivery=:delivery WHERE payment_id=:id ');
                        $update = $update->execute(['balance' => $payment['balance'], "payment_response" => $response1, "payment_amount" => round($payment['payment_amount'], 2),  'status' => 3, 'delivery' => 2, 'id' => $payment['payment_id']]);
                    } else {
                        $update = $conn->prepare('UPDATE payments SET client_balance=:balance, payment_response=:payment_response , payment_status=:status, payment_delivery=:delivery WHERE payment_id=:id ');
                        $update = $update->execute(['balance' => $payment['balance'], "payment_response" => $response1, 'status' => 3, 'delivery' => 2, 'id' => $payment['payment_id']]);
                    }

                    $balance = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id ');
                    $balance = $balance->execute(['id' => $payment['client_id'], 'balance' => $payment['balance'] + $amount]);

                    $insert = $conn->prepare('INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ');
                    if ($payment_bonus) {
                        $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'] . ' and included %' . $payment_bonus['bonus_amount'] . ' bonus , and Final balance 
                            is ' . $final_balance  . ' ', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
                    } else {
                        $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'] . ' and Final balance 
                            is ' . $final_balance  . ' ', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
                    }
                    if ($update && $balance) {
                        afterPaymentDone($payment["username"], $payment['payment_amount'], $method_name);
                        $conn->commit();
                        header('location:' . site_url());
                        echo 'OK';
                    } else {
                        $conn->rollBack();
                        header('location:' . site_url());
                        echo 'NO';
                    }
                }
            } else {
                header('location:' . site_url());
            }
        } else {
            ob_end_clean();
            header('location:' . site_url());
        }
    } else {
        ob_end_clean();
        header('location:' . site_url());
    }
} else if ($method_name == 'coinbase_commerce') {


    $secret = $extras["Webhook Key"];
    $headerName = 'X-CC-Webhook-Signature';
    $headers = getallheaders();
    $signraturHeader = isset($headers[$headerName]) ? $headers[$headerName] : null;
    $payload = trim(file_get_contents('php://input'));

    try {
        $event = Webhook::buildEvent($payload, $signraturHeader, $secret);
        // http_response_code(200);
    } catch (\Exception $exception) {
        // http_response_code(400);
        $error = 'Error occured. ' . $exception->getMessage();
        $event = json_decode($payload, true);
    }

    //if attempt_number = 1 and type == charge:created

    $res_code = $event["event"]["data"]["code"];
    $res_amount = $event["event"]["data"]["pricing"]["local"]["amount"];
    $res_currency = $event["event"]["data"]["pricing"]["local"]["currency"];

    $getfrompay = $conn->prepare("SELECT * FROM payments WHERE payment_extra=:payment_extra && payment_method=:method_id");
    $getfrompay->execute(array("payment_extra" => $res_code, "method_id" => $method["id"]));
    $getfrompay = $getfrompay->fetch(PDO::FETCH_ASSOC);
    $payment_extraa = json_decode($getfrompay["payment_extraa"], true);

    $user = $conn->prepare("SELECT * FROM clients WHERE client_id=:client_id");
    $user->execute(array("client_id" => $getfrompay['client_id']));
    $user = $user->fetch(PDO::FETCH_ASSOC);

    if ($getfrompay && $getfrompay["payment_extra"] == $res_code) :
        if ($event["attempt_number"] == 1 && $event["event"]["type"] == "charge:created" && $payment_extraa["amount"] == $res_amount) :


            $response1 = json_encode(array(array(
                'time' => date("Y-m-d H:i:s"),
                'response' => $event
            )));

            $insert = $conn->prepare("UPDATE payments SET payment_response=:payment_response , payment_update_date=:payment_update_date WHERE payment_extra=:payment_extra && payment_method=:method_id");
            $insert = $insert->execute(array("payment_extra" => $res_code, "method_id" => $method["id"], "payment_response" => $response1, "payment_update_date" => date("Y-m-d H:i:s")));


        else :

            //if attempt_number > 1 get server old response from db

            $old_server_res = json_decode($getfrompay["payment_response"], true);

            $index = count($old_server_res) - 1;

            $old_server_res = $old_server_res[$index]["response"];



            if ($old_server_res["event"]["type"] != $event["event"]["type"]) :


                $response = array(
                    'time' => date("Y-m-d H:i:s"),
                    'response' => $event
                );
                $new_res = json_decode($getfrompay["payment_response"], true);
                array_push($new_res, $response);


                $new_res = json_encode($new_res);


                $insert = $conn->prepare("UPDATE payments SET payment_response=:payment_response , payment_update_date=:payment_update_date WHERE payment_extra=:payment_extra && payment_method=:method_id");
                $insert = $insert->execute(array("payment_extra" => $res_code, "method_id" => $method["id"], "payment_response" => $new_res, "payment_update_date" => date("Y-m-d H:i:s")));


                if ($event["event"]["type"] == "charge:confirmed" && $payment_extraa["amount"] == $res_amount) :

                    //payment has been done

                    $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra ');
                    $payment->execute(['extra' => $res_code]);
                    $payment = $payment->fetch(PDO::FETCH_ASSOC);




                    if ($user["ref_by"]) :
                        $reff = $conn->prepare("SELECT * FROM referral WHERE referral_code=:referral_code ");
                        $reff->execute(array("referral_code" => $user["ref_by"]));
                        $reff  = $reff->fetch(PDO::FETCH_ASSOC);




                        $newAmount = $payment['payment_amount'];

                        $update3 = $conn->prepare("UPDATE referral SET referral_totalFunds_byReffered=:referral_totalFunds_byReffered,
                        referral_total_commision=:referral_total_commision WHERE referral_code=:referral_code ");
                        $update3 = $update3->execute(array(
                            "referral_code" => $user["ref_by"],
                            "referral_totalFunds_byReffered" => round($reff["referral_totalFunds_byReffered"] + $newAmount, 2),
                            "referral_total_commision" => round($reff["referral_total_commision"] + (($settings["referral_commision"] / 100) * $newAmount), 2)
                        ));
                    endif;


                    $payment_bonus = $conn->prepare('SELECT * FROM payments_bonus WHERE bonus_method=:method && bonus_from<=:from ORDER BY bonus_from DESC LIMIT 1');
                    $payment_bonus->execute(['method' => $method['id'], 'from' => $payment['payment_amount']]);
                    $payment_bonus = $payment_bonus->fetch(PDO::FETCH_ASSOC);
                    if ($payment_bonus) {
                        $amount = $payment['payment_amount'] + (($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100);
                    } else {
                        $amount = $payment['payment_amount'];
                    }

                    $conn->beginTransaction();

                    $amount = round($amount, 2);


                    $update = $conn->prepare('UPDATE payments SET client_balance=:balance, payment_status=:status, payment_delivery=:delivery WHERE payment_id=:id ');
                    $update = $update->execute(['balance' => $payment['balance'], 'status' => 3, 'delivery' => 2, 'id' => $payment['payment_id']]);


                    $balance = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id ');
                    $balance = $balance->execute(['id' => $payment['client_id'], 'balance' => $payment['balance'] + $amount]);

                    $insert = $conn->prepare('INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ');
                    if ($payment_bonus) {
                        $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'] . ' and included %' . $payment_bonus['bonus_amount'] . ' bonus , and Final balance 
                        is ' . $final_balance  . ' ', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
                    } else {
                        $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'] . ' and Final balance 
                        is ' . $final_balance  . ' ', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
                    }
                    if ($update && $balance) {
                        afterPaymentDone($payment["username"], $payment['payment_amount'], $method_name);
                        $conn->commit();
                    } else {
                        $conn->rollBack();
                    }


                else :

                    //no payment has been given

                    // if charge is failed then cancel the charge as well
                    if ($event["event"]["type"] == "charge:failed" && $payment_extraa["amount"] == $res_amount) :

                        $chargeObj = Charge::retrieve($res_code);

                        if ($chargeObj) {
                            $chargeObj->cancel();
                        }

                    endif;
                endif;

            else :
                if ($event["event"]["type"] == "charge:failed" && $payment_extraa["amount"] == $res_amount) :

                    $chargeObj = Charge::retrieve($res_code);

                    if ($chargeObj) {
                        $chargeObj->cancel();
                    }

                endif;

            //same attempt
            endif;


        endif;
    else :

    endif;
} else if ($method_name == 'perfectmoney') {

    error_reporting(1);
    ini_set("display_errors", 1);
    define('BASEPATH', true);


    require_once(FILES_BASE . '/lib/perfectmoney/perfectmoney_api.php');

    $response1 = json_encode(array(array(
        'time' => date("Y-m-d H:i:s"),
        'response' => $_POST
    )));



    // print_r($_POST);  exit();

    if (isset($_REQUEST['PAYMENT_BATCH_NUM']) and $_REQUEST['PAYMENT_BATCH_NUM'] != 0) {


        $tnx_id = $_REQUEST['PAYMENT_ID'];

        $getfrompay = $conn->prepare("SELECT * FROM payments WHERE payment_extra=:payment_extra");
        $getfrompay->execute(array("payment_extra" => $tnx_id));
        $getfrompay = $getfrompay->fetch(PDO::FETCH_ASSOC);
        $getfrompay_extrass = json_decode($getfrompay["payment_extraa"], true);

        $user = $conn->prepare("SELECT * FROM clients WHERE client_id=:client_id");
        $user->execute(array("client_id" => $getfrompay['client_id']));
        $user = $user->fetch(PDO::FETCH_ASSOC);

        // check V2_hash
        $v2_hash = false;
        $v2_hash = check_v2_hash($extras['passphrase']);




        if (countRow(['table' => 'payments', 'where' => ['client_id' => $user['client_id'], 'payment_method' => 23, 'payment_status' => 1, 'payment_delivery' => 1, 'payment_extra' => $tnx_id]]) && $v2_hash) {


            if ($getfrompay ) {

                $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra ');
                $payment->execute(['extra' => $tnx_id]);
                $payment = $payment->fetch(PDO::FETCH_ASSOC);



                    if ($user["ref_by"]) {
                    $reff = $conn->prepare("SELECT * FROM referral WHERE referral_code=:referral_code ");
                    $reff->execute(array("referral_code" => $user["ref_by"]));
                    $reff  = $reff->fetch(PDO::FETCH_ASSOC);




                    $newAmount = $payment['payment_amount'];

                    $update3 = $conn->prepare("UPDATE referral SET referral_totalFunds_byReffered=:referral_totalFunds_byReffered,
                    referral_total_commision=:referral_total_commision WHERE referral_code=:referral_code ");
                    $update3 = $update3->execute(array(
                        "referral_code" => $user["ref_by"],
                        "referral_totalFunds_byReffered" => round($reff["referral_totalFunds_byReffered"] + $newAmount, 2),
                        "referral_total_commision" => round($reff["referral_total_commision"] + (($settings["referral_commision"] / 100) * $newAmount), 2)
                    ));
                }



                $payment_bonus = $conn->prepare('SELECT * FROM payments_bonus WHERE bonus_method=:method && bonus_from<=:from ORDER BY bonus_from DESC LIMIT 1');
                $payment_bonus->execute(['method' => $method['id'], 'from' => $payment['payment_amount']]);
                $payment_bonus = $payment_bonus->fetch(PDO::FETCH_ASSOC);
                if ($payment_bonus) {
                    $amount = $payment['payment_amount'] + (($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100);
                } else {
                    $amount = $payment['payment_amount'];
                }

                $conn->beginTransaction();

                $amount = round($amount, 2);


                $update = $conn->prepare('UPDATE payments SET client_balance=:balance, payment_response=:payment_response ,payment_status=:status, payment_delivery=:delivery WHERE payment_id=:id ');
                $update = $update->execute(['balance' => $payment['balance'], "payment_response" => ($response1),  'status' => 3, 'delivery' => 2, 'id' => $payment['payment_id']]);


                $balance = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id ');
                $balance = $balance->execute(['id' => $payment['client_id'], 'balance' => $payment['balance'] + $amount]);

                $insert = $conn->prepare('INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ');
                if ($payment_bonus) {
                    $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'] . ' and included %' . $payment_bonus['bonus_amount'] . ' bonus , and Final balance 
                    is ' . $final_balance  . ' ', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
                } else {
                    $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'] . ' and Final balance 
                    is ' . $final_balance  . ' ', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
                }
                if ($update && $balance) {
                    afterPaymentDone($payment["username"], $payment['payment_amount'], $method_name);
                    $conn->commit();
                    header('location:' . site_url());
                    echo 'OK';
                } else {
                    $conn->rollBack();
                    header('location:' . site_url());
                    echo 'NO';
                }
            } else {

                $update = $conn->prepare('UPDATE payments SET payment_status=:payment_status WHERE client_id=:client_id, payment_method=:payment_method, payment_delivery=:payment_delivery, payment_extra=:payment_extra');
                $update = $update->execute(['payment_status' => 2, 'client_id' => $user['client_id'], 'payment_method' => 23, 'payment_delivery' => 1, 'payment_extra' => $_POST['ORDERID']]);
                header('location:' . site_url());
            }
        } else {

            header('location:' . site_url());
        }
    } else {

        header('location:' . site_url());
    }
} 



else if ($method_name == '2checkout') {
    /* Instant Payment Notification */
    $pass        = "AABBCCDDEEFF";    /* pass to compute HASH */
    $result        = "";                 /* string for compute HASH for received data */
    $return        = "";                 /* string to compute HASH for return result */
    $signature    = $_POST["HASH"];    /* HASH received */
    $body        = "";
    /* read info received */
    ob_start();
    // while (list($key, $val) = each($_POST)) {
    //     $$key = $val;
    //     /* get values */
    //     if ($key != "HASH") {
    //         if (is_array($val)) $result .= ArrayExpand($val);
    //         else {
    //             $size        = strlen(StripSlashes($val)); /*StripSlashes function to be used only for PHP versions <= PHP 5.3.0, only if the magic_quotes_gpc function is enabled */
    //             $result    .= $size . StripSlashes($val);  /*StripSlashes function to be used only for PHP versions <= PHP 5.3.0, only if the magic_quotes_gpc function is enabled */
    //         }
    //     }
    // }
    $body = ob_get_contents();
    ob_end_flush();
    $date_return = date("YmdHis");
    $return = strlen($_POST["IPN_PID"][0]) . $_POST["IPN_PID"][0] . strlen($_POST["IPN_PNAME"][0]) . $_POST["IPN_PNAME"][0];
    $return .= strlen($_POST["IPN_DATE"]) . $_POST["IPN_DATE"] . strlen($date_return) . $date_return;
    function ArrayExpand($array)
    {
        $retval = "";
        for ($i = 0; $i < sizeof($array); $i++) {
            $size        = strlen(StripSlashes($array[$i]));  /*StripSlashes function to be used only for PHP versions <= PHP 5.3.0, only if the magic_quotes_gpc function is enabled */
            $retval    .= $size . StripSlashes($array[$i]);  /*StripSlashes function to be used only for PHP versions <= PHP 5.3.0, only if the magic_quotes_gpc function is enabled */
        }
        return $retval;
    }
    function hmac($key, $data)
    {
        $b = 64; // byte length for md5
        if (strlen($key) > $b) {
            $key = pack("H*", md5($key));
        }
        $key  = str_pad($key, $b, chr(0x00));
        $ipad = str_pad('', $b, chr(0x36));
        $opad = str_pad('', $b, chr(0x5c));
        $k_ipad = $key ^ $ipad;
        $k_opad = $key ^ $opad;
        return md5($k_opad  . pack("H*", md5($k_ipad . $data)));
    }
    $hash =  hmac($pass, $result); /* HASH for data received */
    $body .= $result . "\r\n\r\nHash: " . $hash . "\r\n\r\nSignature: " . $signature . "\r\n\r\nReturnSTR: " . $return;
    if ($hash == $signature) {
        echo "Verified OK!";
        /* ePayment response */
        $result_hash =  hmac($pass, $return);
        echo "<EPAYMENT>" . $date_return . "|" . $result_hash . "</EPAYMENT>";
    }
} else if ($method_name == 'mollie') {

    $mollie = new MollieApiClient();
    $mollie->setApiKey($extras['live_api_key']);

    $molliepay = $mollie->payments->get($_POST["id"]);

    if ($molliepay->isPaid() && !$molliepay->hasRefunds() && !$molliepay->hasChargebacks()) {
        $user = $conn->prepare("SELECT * FROM clients WHERE email=:email");
        $user->execute(array("email" => $molliepay->description));
        $user = $user->fetch(PDO::FETCH_ASSOC);
        if (countRow(['table' => 'payments', 'where' => ['client_id' => $user['client_id'], 'payment_method' => 11, 'payment_status' => 1, 'payment_delivery' => 1, 'payment_extra' => $molliepay->metadata->order_id]])) {
            if ($molliepay->isPaid() && !$molliepay->hasRefunds() && !$molliepay->hasChargebacks()) {
                $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra ');
                $payment->execute(['extra' => $molliepay->metadata->order_id]);
                $payment = $payment->fetch(PDO::FETCH_ASSOC);
                $payment_bonus = $conn->prepare('SELECT * FROM payments_bonus WHERE bonus_method=:method && bonus_from<=:from ORDER BY bonus_from DESC LIMIT 1');
                $payment_bonus->execute(['method' => $method['id'], 'from' => $payment['payment_amount']]);
                $payment_bonus = $payment_bonus->fetch(PDO::FETCH_ASSOC);
                if ($payment_bonus) {
                    $amount = $payment['payment_amount'] + (($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100);
                } else {
                    $amount = $payment['payment_amount'];
                }
                $conn->beginTransaction();

                $update = $conn->prepare('UPDATE payments SET client_balance=:balance, payment_status=:status, payment_delivery=:delivery WHERE payment_id=:id ');
                $update = $update->execute(['balance' => $payment['balance'], 'status' => 3, 'delivery' => 2, 'id' => $payment['payment_id']]);

                $balance = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id ');
                $balance = $balance->execute(['id' => $payment['client_id'], 'balance' => $payment['balance'] + $amount]);

                $insert = $conn->prepare('INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ');
                if ($payment_bonus) {
                    $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'] . ' and included %' . $payment_bonus['bonus_amount'] . ' bonus.', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
                } else {
                    $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'], 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
                }
                if ($update && $balance) {
                    afterPaymentDone($payment["username"], $payment['payment_amount'], $method_name);
                    $conn->commit();
                    echo 'OK';
                } else {
                    $conn->rollBack();
                    echo 'NO';
                }
            } else {
                $update = $conn->prepare('UPDATE payments SET payment_status=:payment_status WHERE client_id=:client_id, payment_method=:payment_method, payment_delivery=:payment_delivery, payment_extra=:payment_extra');
                $update = $update->execute(['payment_status' => 2, 'client_id' => $user['client_id'], 'payment_method' => 11, 'payment_delivery' => 1, 'payment_extra' => $molliepay->metadata->order_id]);
            }
        }
    }
    http_response_code(200);
} else if ($method_name == 'cashmaal') {

    error_reporting(1);
    ini_set("display_errors", 1);
    define('BASEPATH', true);
    $web_id = $extras["web_id"];


    // var_dump($_POST);

    if (isset($_POST['CM_TID'])) {
        $CM_TID = $_POST['CM_TID'];

        $url = "https://www.cashmaal.com/Pay/verify_v2.php?CM_TID=" . urlencode($CM_TID) . "&web_id=" . urlencode($web_id);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, $url);
        $result = curl_exec($ch);
        //$result='{"status":"1","receiver_account":"8","USD_amount":"10","fee_in_USD":"0.000","PKR_amount":"280","fee_in_PKR":"0","USD_amount_with_fee":"1.670","PKR_amount_with_fee":"280","trx_website":"website.com","transaction_id":"2JW9651118P","trx_date":"25-03-2020 9:13:48 PM","order_id":"56a4a4ccc5c9f42e10d9c35a51392504","addi_info":"Test Payment","sender_details":"Fund Received From 161919","trx_details":"$1.67 Receive against TID: 2JW9651118P"}';
        curl_close($ch);
        //$obj = str_replace("'", "\'", json_decode($result , true));
        $obj = json_decode($result, true);

        //echo $obj['order_id'];
        $getfrompay = $conn->prepare("SELECT * FROM payments WHERE payment_extra=:payment_extra");
        $getfrompay->execute(array("payment_extra" => $obj['order_id'])); //$icid
        $getfrompay = $getfrompay->fetch(PDO::FETCH_ASSOC);

        $user = $conn->prepare("SELECT * FROM clients WHERE client_id=:client_id");
        $user->execute(array("client_id" => $getfrompay['client_id']));
        $user = $user->fetch(PDO::FETCH_ASSOC);


        if ($obj['status'] == 1) {
            //echo "<br>".$obj['order_id'];
            if (countRow(['table' => 'payments', 'where' => ['client_id' => $user['client_id'], 'payment_method' => 22, 'payment_status' => 1, 'payment_delivery' => 1, 'payment_extra' => $obj['order_id']]])) {


                $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra ');
                $payment->execute(['extra' => $obj['order_id']]);
                $payment = $payment->fetch(PDO::FETCH_ASSOC);
                $payment_bonus = $conn->prepare('SELECT * FROM payments_bonus WHERE bonus_method=:method && bonus_from<=:from ORDER BY bonus_from DESC LIMIT 1');
                $payment_bonus->execute(['method' => $method['id'], 'from' => $payment['payment_amount']]);
                $payment_bonus = $payment_bonus->fetch(PDO::FETCH_ASSOC);


                if ($extras["fee"]) {

                    $paymentGot = $payment['payment_amount'] + (($extras["fee"] / 100) *  $payment['payment_amount']);
                } else {
                    $paymentGot =  $payment['payment_amount'];
                }

                $paymentGot = strval($paymentGot);
                $USD_AMT = $obj['USD_amount'];





                if ($USD_AMT == $paymentGot) {


                    if ($settings["site_currency"] == "INR") {
                        $payment['payment_amount'] = $payment['payment_amount'] * $settings["dolar_charge"];
                    }

                    //referral

                    if ($user["ref_by"]) {
                        $reff = $conn->prepare("SELECT * FROM referral WHERE referral_code=:referral_code ");
                        $reff->execute(array("referral_code" => $user["ref_by"]));
                        $reff  = $reff->fetch(PDO::FETCH_ASSOC);




                        $newAmount = $payment['payment_amount'];

                        $update3 = $conn->prepare("UPDATE referral SET referral_totalFunds_byReffered=:referral_totalFunds_byReffered,
                    referral_total_commision=:referral_total_commision WHERE referral_code=:referral_code ");
                        $update3 = $update3->execute(array(
                            "referral_code" => $user["ref_by"],
                            "referral_totalFunds_byReffered" => round($reff["referral_totalFunds_byReffered"] + $newAmount, 2),
                            "referral_total_commision" => round($reff["referral_total_commision"] + (($settings["referral_commision"] / 100) * $newAmount), 2)
                        ));
                    }
                    //referral


                    if ($payment_bonus) {
                        $amount = $payment['payment_amount'] + (($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100);
                        $amountt = (($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100);

                        $bonus_amount = ($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100;
                    } else {
                        $amount = $payment['payment_amount'];
                    }

                    $conn->beginTransaction();
                    $amount = round($amount, 2);

                    if ($settings["site_currency"] == "INR") {
                        $update = $conn->prepare('UPDATE payments SET client_balance=:balance, payment_amount=:payment_amount , payment_status=:status, payment_delivery=:delivery WHERE payment_id=:id ');
                        $update = $update->execute(['balance' => $payment['balance'], "payment_amount" => round($payment['payment_amount'], 2),  'status' => 3, 'delivery' => 2, 'id' => $payment['payment_id']]);
                    } else {
                        $update = $conn->prepare('UPDATE payments SET client_balance=:balance, payment_status=:status, payment_delivery=:delivery WHERE payment_id=:id ');
                        $update = $update->execute(['balance' => $payment['balance'], 'status' => 3, 'delivery' => 2, 'id' => $payment['payment_id']]);
                    }

                    $balance = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id ');
                    $balance = $balance->execute(['id' => $payment['client_id'], 'balance' => $payment['balance'] + $amount]);

                    $insert = $conn->prepare('INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ');
                    $insert25 = $conn->prepare("INSERT INTO payments SET client_id=:client_id , client_balance=:client_balance , payment_amount=:payment_amount , payment_method=:payment_method ,
                payment_status=:status, payment_delivery=:delivery , payment_note=:payment_note , payment_create_date=:payment_create_date , payment_extra=:payment_extra , bonus=:bonus");



                    if ($payment_bonus) {

                        $insert25->execute(array(
                            "client_id" => $payment['client_id'], "client_balance" => (($payment['balance'] + $amount) - $bonus_amount),
                            "payment_amount" => $bonus_amount, "payment_method" =>  22, 'status' => 3, 'delivery' => 2, "payment_note" => "Bonus added", "payment_create_date" => date('Y-m-d H:i:s'), "payment_extra" => "Bonus added for previous payment",
                            "bonus" => 1
                        ));
                        $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'] . ' and included %' . $payment_bonus['bonus_amount'] . ' bonus.', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);

                        $icid = md5(rand(1, 999999));
                        $paymentCode = createPaymentCode();

                        $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount,payment_delivery=:delivery, payment_privatecode=:code, payment_method=:method, payment_mode=:mode, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
                        $insert->execute(array("c_id" => $payment['client_id'], "amount" => $amountt, "delivery" => 2, "code" => $paymentCode, "method" => 25, "mode" => "Auto", "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $icid));
                    } else {
                        $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'], 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
                    }

                    if ($update && $balance) {
                        afterPaymentDone($payment["username"], $payment['payment_amount'], $method_name);
                        $conn->commit();
                        $check = 1;
                        header('location:' . site_url());
                        //echo 'OK';
                    } else {
                        $conn->rollBack();
                        header('location:' . site_url());
                        //echo 'NO';
                    }
                } else {
                    $update = $conn->prepare('UPDATE payments SET payment_status=:payment_status WHERE client_id=:client_id && payment_method=:payment_method && payment_delivery=:payment_delivery && payment_extra=:payment_extra');
                    $update = $update->execute(['payment_status' => 2, 'client_id' => $user['client_id'], 'payment_method' => 22, 'payment_delivery' => 1, 'payment_extra' => $obj['order_id']]);
                    header('location:' . site_url());
                    $check = 0;
                }
            } else {
                //duplicate payment or no payment found
                // echo "error in cashmaal obj";
                // echo "Error:" . $objj['error'];
                $error = 1;
                $errorText = "Duplicate/No payment found!";

                header('location:' . site_url("addfunds"));
            }
        }
    } else {
        $check = 0;
        echo "transaction cancelled by user";
        header('location:' . site_url());
    }
} else if ($method_name == 'paytmqr') {

    if ($_POST['ORDERID']) {

        error_reporting(1);
        ini_set("display_errors", 1);

        require_once(FILES_BASE . '/lib/paytm/encdec_paytm.php');

        $responseParamList = array();

        $responseParamList = getTxnStatusNew($_POST);


        $response1 = array(
            'time' => date("Y-m-d H:i:s"),
            'response' => $responseParamList
        );





        if ($_POST['ORDERID'] == $responseParamList["ORDERID"]) {
            $getfrompay = $conn->prepare("SELECT * FROM payments WHERE payment_extra=:payment_extra");
            $getfrompay->execute(array("payment_extra" => $_POST['ORDERID']));
            $getfrompay = $getfrompay->fetch(PDO::FETCH_ASSOC);

            $user = $conn->prepare("SELECT * FROM clients WHERE client_id=:client_id");
            $user->execute(array("client_id" => $getfrompay['client_id']));
            $user = $user->fetch(PDO::FETCH_ASSOC);


            if (countRow(['table' => 'payments', 'where' => ['client_id' => $user['client_id'], 'payment_method' => 14, 'payment_status' => 1, 'payment_delivery' => 1, 'payment_extra' => $_POST['ORDERID']]])) {
                if ($responseParamList["STATUS"] == "TXN_SUCCESS") {
                    $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra ');
                    $payment->execute(['extra' => $_POST['ORDERID']]);
                    $payment = $payment->fetch(PDO::FETCH_ASSOC);

                    // if ($settings['site_currency'] == "USD") {
                    //     $payment['payment_amount'] = $payment['payment_amount'] / $settings["dolar_charge"];
                    // }

                    
                    
                    
$payment_amount = $payment['payment_amount'];
// Fee percentage
$fee_percentage = $extras['fee']; // Example: 5 for 5%

// Calculate commission amount
$commission = ($payment_amount * $fee_percentage) / 100;

// Subtract commission from payment amount
$payment['payment_amount'] = $payment_amount - $commission;







                    if ($settings['site_currency'] != "INR") :
                        $payment['payment_amount'] = convertCurrencyUpdated("INR", $settings['site_currency'], $payment['payment_amount']);
                    endif;



                    //referral

                    if ($user["ref_by"]) {
                        $reff = $conn->prepare("SELECT * FROM referral WHERE referral_code=:referral_code ");
                        $reff->execute(array("referral_code" => $user["ref_by"]));
                        $reff  = $reff->fetch(PDO::FETCH_ASSOC);




                        $newAmount = $payment['payment_amount'];

                        $update3 = $conn->prepare("UPDATE referral SET referral_totalFunds_byReffered=:referral_totalFunds_byReffered,
                    referral_total_commision=:referral_total_commision WHERE referral_code=:referral_code ");
                        $update3 = $update3->execute(array(
                            "referral_code" => $user["ref_by"],
                            "referral_totalFunds_byReffered" => round($reff["referral_totalFunds_byReffered"] + $newAmount, 2),
                            "referral_total_commision" => round($reff["referral_total_commision"] + (($settings["referral_commision"] / 100) * $newAmount), 2)
                        ));
                    }
                    //referral





                    $requestParamList = array("MID" => $_POST["MID"], "ORDERID" => $_POST["ORDERID"]);

                    $res2 = getTxnStatusNew($_POST);

                    $response2 = array(
                        'time' => date("Y-m-d H:i:s"),
                        'response' => $responseParamList
                    );

                    $server_response = json_encode(array(
                        $response1, $response2
                    ));


                    $payment_bonus = $conn->prepare('SELECT * FROM payments_bonus WHERE bonus_method=:method && bonus_from<=:from ORDER BY bonus_from DESC LIMIT 1');
                    $payment_bonus->execute(['method' => $method['id'], 'from' => $payment['payment_amount']]);
                    $payment_bonus = $payment_bonus->fetch(PDO::FETCH_ASSOC);
                    if ($payment_bonus) {
                        $amount = $payment['payment_amount'] + (($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100);

                        $bonus_amount = ($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100;
                    } else {
                        $amount = $payment['payment_amount'];
                    }

                    $conn->beginTransaction();

                    $amount = round($amount, 2);

                    $payment_id = $payment['payment_id'];
                    $old_balance =  $payment['balance'];

                    $added_funds = $amount;

                    $final_balance =  $old_balance + $added_funds;



                    $update = $conn->prepare('UPDATE payments SET client_balance=:balance, payment_amount=:payment_amount , payment_response=:payment_response , payment_status=:status, payment_delivery=:delivery WHERE payment_id=:id ');
                        $update = $update->execute(['balance' => $payment['balance'], "payment_response" => $server_response, "payment_amount" =>  round($payment['payment_amount'], 2),  'status' => 3, 'delivery' => 2, 'id' => $payment['payment_id']]);
                 
                    $balance = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id ');
                    $balance = $balance->execute(['id' => $payment['client_id'], 'balance' => $payment['balance'] + $amount]);

                    $insert = $conn->prepare('INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ');

                    $insert25 = $conn->prepare("INSERT INTO payments SET client_id=:client_id , client_balance=:client_balance , payment_amount=:payment_amount , payment_method=:payment_method ,
                payment_status=:status, payment_delivery=:delivery , payment_note=:payment_note , payment_create_date=:payment_create_date , payment_extra=:payment_extra , bonus=:bonus");


                    if ($payment_bonus) {
                        $insert25->execute(array(
                            "client_id" => $payment['client_id'], "client_balance" => (($payment['balance'] + $amount) - $bonus_amount),
                            "payment_amount" => $bonus_amount, "payment_method" =>  14, 'status' => 3, 'delivery' => 2, "payment_note" => "Bonus added", "payment_create_date" => date('Y-m-d H:i:s'), "payment_extra" => "Bonus added for previous payment",
                            "bonus" => 1
                        ));

                        $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'] . ' and included %' . $payment_bonus['bonus_amount'] . ' bonus , and Final balance 
                    is ' . $final_balance  . ' ', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
                    } else {
                        $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'] . ' and Final balance 
                    is ' . $final_balance  . ' ', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
                    }
                    if ($update && $balance) {
                        afterPaymentDone($payment["username"], $payment['payment_amount'], $method_name);
                        $conn->commit();
                        header('location:' . site_url() . '/addfunds');
                        echo 'OK';
                    } else {
                        $conn->rollBack();
                        header('location:' . site_url());
                        echo 'NO';
                    }
                } else {
                    if ($response1["response"]["TXNAMOUNT"] != $response2["response"]["TXNAMOUNT"]) {
                        $fraud_risk = 2;
                    } else {
                        $fraud_risk = 1;
                    }
                    $update = $conn->prepare('UPDATE payments SET payment_status=:payment_status , payment_response=:payment_response, fraud_risk=:fraud_risk WHERE client_id=:client_id, payment_method=:payment_method, payment_delivery=:payment_delivery, payment_extra=:payment_extra');
                    $update = $update->execute(['payment_status' => 2, "fraud_risk" => $fraud_risk, "payment_response" => json_encode(array($response1)), 'client_id' => $user['client_id'], 'payment_method' => 14, 'payment_delivery' => 1, 'payment_extra' => $_POST['ORDERID']]);
                }
            }
        } else {
            header('location:' . site_url());
        }
    } else {
        header('location:' . site_url());
    }
} else if ($method_name == 'paytm') {

    require_once(FILES_BASE . '/lib/paytm/encdec_paytm.php');

    $paytmChecksum = "";
    $paramList = array();
    $isValidChecksum = "FALSE";

    $paramList = $_POST;
    


    $response1 = array(
        'time' => date("Y-m-d H:i:s"),
        'response' => $_POST
    );


    $paytmChecksum = isset($_POST["CHECKSUMHASH"]) ? $_POST["CHECKSUMHASH"] : ""; //Sent by Paytm pg

    if ($paytmChecksum) {

        //Verify all parameters received from Paytm pg to your application. Like MID received from paytm pg is same as your application嚙編 MID, TXN_AMOUNT and ORDER_ID are same as what was sent by you to Paytm PG for initiating transaction etc.
        $isValidChecksum = verifychecksum_e($paramList, $extras['merchant_key'], $paytmChecksum); //will return TRUE or FALSE string.

        if ($isValidChecksum == "TRUE") {
            $getfrompay = $conn->prepare("SELECT * FROM payments WHERE payment_extra=:payment_extra");
            $getfrompay->execute(array("payment_extra" => $_POST['ORDERID']));
            $getfrompay = $getfrompay->fetch(PDO::FETCH_ASSOC);


            $requestParamList = array("MID" => $_POST["MID"], "ORDERID" => $_POST["ORDERID"]);

            $responseParamList = getTxnStatusNew($_POST);


            $response2 = array(
                'time' => date("Y-m-d H:i:s"),
                'response' => $responseParamList
            );

            $server_response = json_encode(array(
                $response1, $response2
            ));



            $user = $conn->prepare("SELECT * FROM clients WHERE client_id=:client_id");
            $user->execute(array("client_id" => $getfrompay['client_id']));
            $user = $user->fetch(PDO::FETCH_ASSOC);
            if (countRow(['table' => 'payments', 'where' => ['client_id' => $user['client_id'], 'payment_method' => 12, 'payment_status' => 1, 'payment_delivery' => 1, 'payment_extra' => $_POST['ORDERID']]])) {
                if ($_POST["STATUS"] == "TXN_SUCCESS" && ($response1["response"]["TXNAMOUNT"] == $response2["response"]["TXNAMOUNT"])) {
                    $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra ');
                    $payment->execute(['extra' => $_POST['ORDERID']]);
                    $payment = $payment->fetch(PDO::FETCH_ASSOC);
                    $payment_bonus = $conn->prepare('SELECT * FROM payments_bonus WHERE bonus_method=:method && bonus_from<=:from ORDER BY bonus_from DESC LIMIT 1');
                    $payment_bonus->execute(['method' => $method['id'], 'from' => $payment['payment_amount']]);
                    $payment_bonus = $payment_bonus->fetch(PDO::FETCH_ASSOC);

                    //referral

                    if ($user["ref_by"]) {
                        $reff = $conn->prepare("SELECT * FROM referral WHERE referral_code=:referral_code ");
                        $reff->execute(array("referral_code" => $user["ref_by"]));
                        $reff  = $reff->fetch(PDO::FETCH_ASSOC);



                        $newAmount = $payment['payment_amount'];

                        $update3 = $conn->prepare("UPDATE referral SET referral_totalFunds_byReffered=:referral_totalFunds_byReffered,
                    referral_total_commision=:referral_total_commision WHERE referral_code=:referral_code ");
                        $update3 = $update3->execute(array(
                            "referral_code" => $user["ref_by"],
                            "referral_totalFunds_byReffered" => round($reff["referral_totalFunds_byReffered"] + $newAmount, 2),
                            "referral_total_commision" => round($reff["referral_total_commision"] + (($settings["referral_commision"] / 100) * $newAmount), 2)
                        ));
                    }
                    //referral



                    if ($payment_bonus) {
                        $amount = $payment['payment_amount'] + (($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100);
                        $bonus_amount = ($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100;
                    } else {
                        $amount = $payment['payment_amount'];
                    }
                    $conn->beginTransaction();

                    $payment_id = $payment['payment_id'];
                    $old_balance =  $payment['balance'];
                    $amount = round($amount, 2);
                    $added_funds = $amount;

                    $final_balance =  $old_balance + $added_funds;



                    $payment['payment_amount'] = round($payment['payment_amount'], 2);

                    $update = $conn->prepare('UPDATE payments SET client_balance=:balance, payment_status=:status, payment_delivery=:delivery  , payment_response=:payment_response  WHERE payment_id=:id ');
                    $update = $update->execute(['balance' => $payment['balance'], 'status' => 3, 'delivery' => 2, 'id' => $payment['payment_id'], "payment_response" => $server_response]);


                    $balance = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id ');
                    $balance = $balance->execute(['id' => $payment['client_id'], 'balance' => round($payment['balance'] + $amount, 2)]);

                    $insert = $conn->prepare('INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ');
                    $insert25 = $conn->prepare("INSERT INTO payments SET client_id=:client_id , client_balance=:client_balance , payment_amount=:payment_amount , payment_method=:payment_method ,
                payment_status=:status, payment_delivery=:delivery , payment_note=:payment_note , payment_create_date=:payment_create_date , payment_extra=:payment_extra , bonus=:bonus");



                    if ($payment_bonus) {
                        $insert25->execute(array(
                            "client_id" => $payment['client_id'], "client_balance" => (($payment['balance'] + $amount) - $bonus_amount),
                            "payment_amount" => $bonus_amount, "payment_method" =>  12, 'status' => 3, 'delivery' => 2, "payment_note" => "Bonus added", "payment_create_date" => date('Y-m-d H:i:s'), "payment_extra" => "Bonus added for previous payment",
                            "bonus" => 1
                        ));
                        $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'] . ' and included %' . $payment_bonus['bonus_amount'] . ' bonus , and Final balance 
                    is ' . $final_balance  . ' ', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
                    } else {
                        $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'] . ' and Final balance 
                    is ' . $final_balance  . ' ', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
                    }
                    if ($update && $balance) {
                        afterPaymentDone($payment["username"], $payment['payment_amount'], $method_name);
                        $conn->commit();
                        header('location:' . site_url());
                        echo 'OK';
                    } else {
                        $conn->rollBack();
                        header('location:' . site_url());
                        echo 'NO';
                    }
                } else {

                    if ($response1["response"]["TXNAMOUNT"] != $response2["response"]["TXNAMOUNT"]) {
                        $fraud_risk = 2;
                    } else {
                        $fraud_risk = 1;
                    }

                    $update = $conn->prepare('UPDATE payments SET payment_status=:payment_status , payment_response=:payment_response , payment_delivery=:payment_delivery , fraud_risk=:fraud_risk WHERE client_id=:client_id && payment_extra=:payment_extra && payment_method=:payment_method');
                    $update = $update->execute(['payment_status' => 1, "payment_response" => $server_response, "fraud_risk" => $fraud_risk, 'client_id' => $user['client_id'], 'payment_method' => 12, 'payment_delivery' => 1, 'payment_extra' => $_POST['ORDERID']]);
                    header('location:' . site_url());
                }
            }
        }
    } else {
        header('location:' . site_url());
    }
} else if ($method_name == 'payumoney') {
    header('location:' . site_url());
} else if ($method_name == 'instamojo') {
    header('location:' . site_url());
} else if ($method_name == 'phonepeQr') {

    //verify_payment_integrity
    $transactionId = $_POST["transactionId"];
    $amount = $_POST["amount"];
    $payment_token = $_POST["payment_token"];

    $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra ');
    $payment->execute(['extra' => $transactionId]);
    $payment = $payment->fetch(PDO::FETCH_ASSOC);

    $sessionPaymentResponse = $_SESSION['payment'][$payment_token];



    if ($sessionPaymentResponse["status"] == "success" && $sessionPaymentResponse["transactionId"] == $transactionId && $sessionPaymentResponse["amount"] == $_POST["amount"]) :
        //process further
        processPaymentAndAdd($method, $amount, $payment, $user);
    else :
        header('location:' . site_url('addfunds') . '?error=Invalid Transaction!');
    endif;
} else if ($method_name == 'easypaise') {

    //verify_payment_integrity
    $transactionId = $_POST["transactionId"];
    $amount = $_POST["amount"];
    $payment_token = $_POST["payment_token"];

    $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra ');
    $payment->execute(['extra' => $transactionId]);
    $payment = $payment->fetch(PDO::FETCH_ASSOC);

    $sessionPaymentResponse = $_SESSION['payment'][$payment_token];


    if ($sessionPaymentResponse["status"] == "success" && $sessionPaymentResponse["transactionId"] == $transactionId && $sessionPaymentResponse["amount"] == $_POST["amount"]) :
        //process further
        processPaymentAndAdd($method, $amount, $payment, $user);
    else :
        header('location:' . site_url('addfunds') . '?error=Invalid Transaction!');
    endif;
} else if ($method_name == 'jazzcash') {

    //verify_payment_integrity
    $transactionId = $_POST["transactionId"];
    $amount = $_POST["amount"];
    $payment_token = $_POST["payment_token"];

    $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra');
    $payment->execute(['extra' => $transactionId]);
    $payment = $payment->fetch(PDO::FETCH_ASSOC);

    $sessionPaymentResponse = $_SESSION['payment'][$payment_token];


    if ($sessionPaymentResponse["status"] == "success" && $sessionPaymentResponse["transactionId"] == $transactionId && $sessionPaymentResponse["amount"] == $_POST["amount"]) :
        //process further
        processPaymentAndAdd($method, $amount, $payment, $user);
    else :
        header('location:' . site_url('addfunds') . '?error=Invalid Transaction!');
    endif;
} else if ($method_name == 'cashfree') {


    $secret_key = $extras["secret_key"];
    $orderId = $_POST["orderId"];
    $orderAmount = $_POST["orderAmount"];
    $referenceId = $_POST["referenceId"];
    $txStatus = $_POST["txStatus"];
    $paymentMode = $_POST["paymentMode"];
    $txMsg = $_POST["txMsg"];
    $txTime = $_POST["txTime"];
    $signature = $_POST["signature"];

    $data = $orderId . $orderAmount . $referenceId . $txStatus . $paymentMode . $txMsg . $txTime;

    $hash_hmac = hash_hmac('sha256', $data, $secret_key, true);
    $computedSignature = base64_encode($hash_hmac);

    $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra');
    $payment->execute(['extra' => $orderId]);
    $payment = $payment->fetch(PDO::FETCH_ASSOC);


    $server_response = json_encode(array([
        'time' => date("Y-m-d H:i:s"),
        'response' => $_POST
    ]));

    if ($txStatus == "SUCCESS" && $computedSignature == $signature) :
        //process further
        processPaymentAndAdd($method, $orderAmount, $payment, $user, $server_response);
    else :
        header('location:' . site_url('addfunds') . '?error=Invalid Transaction!');
    endif;
} else if ($method_name == 'swiftpay_ph') {



    $reference_id = $_REQUEST['x_reference_no'];

    $sessionTxnInfo = $_SESSION["payment"][$reference_id];


    if ($_REQUEST["signature"] == $sessionTxnInfo["signature"]) :
        //Payment Verified.. Signature Correct
        processPaymentAndAdd($method, $amount, $payment, $user);
    else :
        //Payment Not Verified
        header('location:' . site_url('addfunds') . '?error=Invalid Transaction!');
    endif;
} else if ($method_name == 'primepayments') {


    $secret2 = $extras['secret2']; // Secret word 2

    $hash = md5($secret2 . $_POST['orderID'] . $_POST['payWay'] . $_POST['innerID'] . $_POST['sum'] . $_POST['webmaster_profit']);

    if ($hash != $_POST['sign']) : header('location:' . site_url('addfunds') . '?error=Something wrong!');
    endif; // signature verification

    $txn_id = $_POST['innerID'];

    $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra');
    $payment->execute(['extra' => $txn_id]);
    $payment = $payment->fetch(PDO::FETCH_ASSOC);

    if (empty($payment)) :
        header('location:' . site_url('addfunds') . '?error=Invalid Transaction Id!');
    endif;

    $payment_response = json_decode($payment['payment_response'], true);

    array_push($payment_response, [
        'time' => date("Y-m-d H:i:s"),
        'response' => $_POST
    ]);

    $amount = $payment["payment_amount"];

    processPaymentAndAdd($method, $amount, $payment, $user, json_encode($payment_response));
    
} else if ($method_name == 'toyyibPay') {

    $postData = $_POST;
    
     $name = "POST";
    $data = $_POST;
     

    // $postData = '{"refno":"TP30837536096363915170723","status":"1","reason":"Approved","billcode":"2wzmo4cx","order_id":"TP_1699908425","amount":"2.00","status_id":"1","msg":"ok","transaction_id":"TP30837536096363915170723","fpx_transaction_id":"2307170739460349","hash":"d781fb9ffcd78216895b465daa0e4b2b","transaction_time":"2023-07-17 07:39:46"}';
    
    //$postData = '{"refno":"TP30847285460413802180723","status":"1","reason":"Approved","billcode":"pdkj3fxp","order_id":"TP_1692831277","amount":"2.00","status_id":"1","msg":"ok","transaction_id":"TP30847285460413802180723","fpx_transaction_id":"2307171838470283","hash":"de70819a99ccb56b40cd76084ae83a1f","transaction_time":"2023-07-17 18:38:47"}';
    
    
   
    if (empty($postData)) :
        header('location:' . site_url('addfunds'));
        return;
    endif;

    if (!is_array($postData)) {
        $postData = json_decode($postData, true);
    }
    
    
    
$transid = $_REQUEST["order_id"];
        $pstatus = $_REQUEST['status_id'];

  
 if($pstatus == 1) :

        $txn_id = $transid;

        $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra && payments.payment_status=:payment_status && payment_delivery=:payment_delivery');
        $payment->execute(['extra' => $txn_id, 'payment_status' => 1, 'payment_delivery' => 1]);
        $payment = $payment->fetch(PDO::FETCH_ASSOC);


  
        $payment_response = json_decode($payment['payment_response'], true);
         
        if ($payment_response[0]["response"][0]["BillCode"] != $postData["billcode"] || $payment_response[0]["response"][0]["Amount"] != $postData["amount"]) :
            header('location:' . site_url('addfunds'));
            return;
        endif;


        $user = $conn->prepare('SELECT * FROM clients WHERE clients.client_id=:client_id');
        $user->execute(['client_id' => $payment["client_id"]]);
        $user = $user->fetch(PDO::FETCH_ASSOC);

        if (empty($payment) || empty($user)) :
            header('location:' . site_url('addfunds') . '?error=Invalid Transaction Id!');
        else :

            array_push($payment_response, [
                'time' => date("Y-m-d H:i:s"),
                'response' => $postData
            ]);
            $amount = $postData["amount"];
            processPaymentAndAdd($method, $amount, $payment, $user, json_encode($payment_response));
        endif;
    else :

        header('location:' . site_url('addfunds') . '?error=Invalid Transaction!');

    endif;

    header('location:' . site_url('addfunds'));
 


} elseif ($method_name == 'mercadopago'){
    
   
       
     $referenceId = $_SESSION['tids'];

        if(!$referenceId){
             header('location:' . site_url(''));
            exit;
        }
        
   
         
    $_POST['ORDERID'] =  $referenceId;

    $getfrompay = $conn->prepare("SELECT * FROM payments WHERE payment_extra=:payment_extra");
    $getfrompay->execute(array("payment_extra" => $_POST['ORDERID']));
    $getfrompay = $getfrompay->fetch(PDO::FETCH_ASSOC);

    $user = $conn->prepare("SELECT * FROM clients WHERE client_id=:client_id");
    $user->execute(array("client_id" => $getfrompay['client_id']));
    $user = $user->fetch(PDO::FETCH_ASSOC);

    if (countRow(['table' => 'payments', 'where' => ['client_id' => $user['client_id'], 'payment_method' => $method['id'], 'payment_status' => 1, 'payment_delivery' => 1, 'payment_extra' => $_POST['ORDERID']]])):
       
        if ($_REQUEST['status'] === 'approved') {
            
            
            $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra ');
            $payment->execute(['extra' => $_POST['ORDERID']]);
            $payment = $payment->fetch(PDO::FETCH_ASSOC);
            $amount = $payment['payment_amount'];
            $conn->beginTransaction();

            $update = $conn->prepare('UPDATE payments SET client_balance=:balance, payment_status=:status, payment_delivery=:delivery WHERE payment_id=:id ');
            $update = $update->execute(['balance' => $payment['balance'], 'status' => 3, 'delivery' => 2, 'id' => $payment['payment_id']]);

            $balance = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id ');
            $balance = $balance->execute(['id' => $payment['client_id'], 'balance' => $payment['balance'] + $amount]);

            $insert = $conn->prepare('INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ');

            $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'], 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
            if ($update && $balance) {
                $conn->commit();
                  // referralCommission 
               
              // referralCommission 
                header('location:' . site_url(''));
                echo 'OK';
            } else {
                $conn->rollBack();
                header('location:' . site_url('addfunds'));
                echo 'NO';
            }
        } else {

            $update = $conn->prepare('UPDATE payments SET payment_status=:payment_status WHERE client_id=:client_id and payment_method=:payment_method and payment_delivery=:payment_delivery and payment_extra=:payment_extra');
            $update->execute(array('payment_status' => 2, 'client_id' => $user['client_id'], 'payment_method' => $method['id'], 'payment_delivery' => 1, 'payment_extra' => $_POST['ORDERID']));
        }
        
          
    endif;
     header('location:' . site_url(''));
    exit;
}



else if ($method_name == 'bananapay') {


    $postData = file_get_contents('php://input');
    //$postData = "s";

    if (empty($postData)) :

        header('location:' . site_url('addfunds'));

    else :



        $xmlString = str_replace('\\/', '/', $postData);
        $xml = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA);
        $jsonData = json_encode($xml);
 

        // $jsonData = '{"pay_way":"gcashpay","notify_type":"trade_status_sync","notify_time":"1687935606",
        // "transaction_id":"20230628121212800110170770541740128","out_trade_no":"BP_1692628974",
        // "pay_time":"1687946183","total_fee":"50.00","fee_type":"PHP","pay_bank":"gcashpay","coupon_fee":"0",
        // "cash_fee":"1.00","cash_fee_type":"PHP","result_code":"SUCCESS","return_code":"SUCCESS",
        // "trade_status":"TRADE_SUCCESS",
        // "order_info":"{\"goods_type\":\"unlimit\",\"goods_name\":\"Balance Recharge (Simlasay1002)\",\"goods_price\":\"0.02\",\"goods_ext_price\":\"1.00\",\"goods_describe\":\"GCASH online WEB pay\",\"fee_type\":\"PHP\",\"ext_fee_type\":\"PHP\"}","sign":"90F8F11A332B19D599E454271C50730F"}';

       


        $jsonData = json_decode($jsonData, true);

        $checkSignArr = $jsonData;
        unset($checkSignArr["sign"]);
        ksort($checkSignArr);

        $checkSignString = "";
        foreach ($checkSignArr as $key => $val) {
            $checkSignString .= $key . "=" . $val . "&";
        }

        $checkSignString .= "key" . "=" . $extras["notify_key"];
        $checkSign = md5($checkSignString);

        if (trim(strtolower($checkSign)) != trim(strtolower($jsonData['sign']))) : header('location:' . site_url('addfunds') . '?error=Unauthorized Transaction!'); // signature verification failed

        else :

            if (trim(strtolower($jsonData["result_code"])) == trim(strtolower("SUCCESS"))) :


                $txn_id = $jsonData['out_trade_no'];
               
                $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra && payments.payment_status=:payment_status && payment_delivery=:payment_delivery');
                $payment->execute(['extra' => $txn_id , 'payment_status' => 1 , 'payment_delivery' => 1]);
                $payment = $payment->fetch(PDO::FETCH_ASSOC);

           
                $user = $conn->prepare('SELECT * FROM clients WHERE clients.client_id=:client_id');
                $user->execute(['client_id' => $payment["client_id"]]);
                $user = $user->fetch(PDO::FETCH_ASSOC);

                if (empty($payment)) :
                    header('location:' . site_url('addfunds') . '?error=Invalid Transaction Id!');
                else :


                    $payment_response = json_decode($payment['payment_response'], true);

                    array_push($payment_response, [
                        'time' => date("Y-m-d H:i:s"),
                        'response' => $jsonData
                    ]);

                    $amount = $jsonData["total_fee"];
                    processPaymentAndAdd($method, $amount, $payment, $user, json_encode($payment_response));
                endif;
            else :

                header('location:' . site_url('addfunds') . '?error=Invalid Transaction!');

            endif;

        endif;

    endif;
} else if ($method_name == 'enot') {


    $merchant_id = $_REQUEST['merchant']; // id of your store 
    $additional_secret_key    = $extras['additional_secret_key'];

    $sign = md5($merchant . ':' . $_REQUEST['amount'] . ':' . $additional_secret_key . ':' . $_REQUEST['merchant_id']);

    if ($sign == $_REQUEST['sign_2']) :
        //Payment Verified.. Signature Correct

        $custom_field = $_REQUEST['custom_field'];


        $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra');
        $payment->execute(['extra' => $custom_field["order_id"]]);
        $payment = $payment->fetch(PDO::FETCH_ASSOC);

        $amount = $payment["payment_amount"];
        if ('RUB' != $settings["site_currency"]) :
            $amount = convertCurrencyUpdated('RUB', $settings["site_currency"], $amount);
        endif;


        processPaymentAndAdd($method, $amount, $payment, $user);
    else :
        //Payment Not Verified
        header('location:' . site_url('addfunds') . '?error=Invalid Transaction!');
    endif;
} else if ($method_name == 'buxph') {



    $bux = new Bux([
        'app_key' => $extras["api_key"],
        'client_id' => $extras["client_id"],
        'client_secret' => $extras["client_secret"]
    ]);

    if (
        empty($_POST['client_id']) ||
        empty($_POST['signature']) ||
        empty($_POST['order_id']) ||
        empty($_POST['status'])
    ) {
        header("Location:" . site_url('addfunds'));
    } else {

        if ($bux->isValidMessage([
            'client_id' => $_POST['client_id'],
            'signature' => $_POST['signature'],
            'order_id' => $_POST['order_id'],
            'status' => $_POST['status']
        ]) !== true) {
            header("Location:" . site_url('addfunds'));
        } else {

            // $response = $_POST;

            // $name = strtotime(date("Y-m-d H:i:s"));
            // $fp = fopen(FILES_BASE . "/buxph_$name.txt", "wb");
            // fwrite($fp, json_encode($_POST));
            // fclose($fp);

            if ($_POST["status"] == "wc-processing") :
                //Payment Verified.. Signature Correct

                $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra');
                $payment->execute(['extra' => $_POST["order_id"]]);
                $payment = $payment->fetch(PDO::FETCH_ASSOC);

                $user = $conn->prepare('SELECT * FROM clients WHERE clients.client_id=:client_id');
                $user->execute(['client_id' => $payment["client_id"]]);
                $user = $user->fetch(PDO::FETCH_ASSOC);

                $amount = $payment["payment_amount"];
                if ('PHP' != $settings["site_currency"]) :
                    $amount = convertCurrencyUpdated($settings["site_currency"], "PHP", $amount);
                endif;


                processPaymentAndAdd($method, $amount, $payment, $user, json_encode($_POST));
            else :
                //Payment Not Verified
                header('location:' . site_url('addfunds') . '?error=Invalid Transaction!');
            endif;
        }
    }
} else if ($method_name == 'gcash_auto') {
    $postdata = $_REQUEST;

    // $postdata = '{"success":"1","request_id":"2ed3b739e3d0ecade4efb18269bb7c07","amount":"1.00","reference":"977756342","response_message":"Transaction Successful","response_advise":"Transaction is approved","timestamp":"2022-04-20 18:58:08 +08:00"}';
    // $postdata = json_decode($postdata, true);


    if (isset($postdata['success']) && isset($postdata['request_id']) && isset($postdata['reference'])) :



        $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra ');
        $payment->execute(['extra' => $postdata['request_id']]);
        $payment = $payment->fetch(PDO::FETCH_ASSOC);

        $user = $conn->prepare("SELECT * FROM clients WHERE client_id=:client_id");
        $user->execute(array("client_id" => $payment['client_id']));
        $user = $user->fetch(PDO::FETCH_ASSOC);


        $server_response = array([
            'time' => date("Y-m-d H:i:s"),
            'response' => $postdata
        ]);
        $old_response = json_decode($payment["payment_response"], true);


        array_push($server_response, json_decode($old_response, true)[0]);

        $server_response = json_encode($server_response);

        if ($postdata['success'] == 1 && $postdata['response_message'] == "Transaction Successful" && $postdata['response_advise'] = "Transaction is approved" && $payment["payment_delivery"] != 3 && $payment["payment_delivery"] != 2 && ($payment["payment_status"] == 1 || $payment["payment_status"] == 2)) :
            //transaction succesfull

            if ($user["ref_by"]) :
                $reff = $conn->prepare("SELECT * FROM referral WHERE referral_code=:referral_code ");
                $reff->execute(array("referral_code" => $user["ref_by"]));
                $reff  = $reff->fetch(PDO::FETCH_ASSOC);

                $newAmount = $payment['payment_amount'];

                $update3 = $conn->prepare("UPDATE referral SET referral_totalFunds_byReffered=:referral_totalFunds_byReffered,
                referral_total_commision=:referral_total_commision WHERE referral_code=:referral_code ");
                $update3 = $update3->execute(array(
                    "referral_code" => $user["ref_by"],
                    "referral_totalFunds_byReffered" => round($reff["referral_totalFunds_byReffered"] + $newAmount, 2),
                    "referral_total_commision" => round($reff["referral_total_commision"] + (($settings["referral_commision"] / 100) * $newAmount), 2)
                ));
            endif;

            $payment_bonus = $conn->prepare('SELECT * FROM payments_bonus WHERE bonus_method=:method && bonus_from<=:from ORDER BY bonus_from DESC LIMIT 1');
            $payment_bonus->execute(['method' => $method['id'], 'from' => $payment['payment_amount']]);
            $payment_bonus = $payment_bonus->fetch(PDO::FETCH_ASSOC);
            if ($payment_bonus) {
                $amount = $payment['payment_amount'] + (($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100);

                $bonus_amount = ($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100;
            } else {
                $amount = $payment['payment_amount'];
            }

            $conn->beginTransaction();
            $amount = round($amount, 2);
            $payment_id = $payment['payment_id'];
            $old_balance =  $payment['balance'];
            $added_funds = $amount;
            $final_balance =  $old_balance + $added_funds;

            $update = $conn->prepare('UPDATE payments SET client_balance=:balance, payment_status=:status, payment_response=:payment_response , payment_delivery=:delivery WHERE payment_id=:id ');
            $update = $update->execute(['balance' => $payment['balance'],  "payment_response" => $server_response,  'status' => 3, 'delivery' => 2, 'id' => $payment['payment_id']]);

            $balance = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id ');
            $balance = $balance->execute(['id' => $payment['client_id'], 'balance' => $payment['balance'] + $amount]);

            $insert = $conn->prepare('INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ');

            $insert25 = $conn->prepare("INSERT INTO payments SET client_id=:client_id , client_balance=:client_balance , payment_amount=:payment_amount , payment_method=:payment_method ,
            payment_status=:status, payment_delivery=:delivery , payment_note=:payment_note , payment_create_date=:payment_create_date , payment_extra=:payment_extra , bonus=:bonus");

            if ($payment_bonus) {
                $insert25->execute(array(
                    "client_id" => $payment['client_id'], "client_balance" => (($payment['balance'] + $amount) - $bonus_amount),
                    "payment_amount" => $bonus_amount, "payment_method" =>  14, 'status' => 3, 'delivery' => 2, "payment_note" => "Bonus added", "payment_create_date" => date('Y-m-d H:i:s'), "payment_extra" => "Bonus added for previous payment",
                    "bonus" => 1
                ));

                $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'] . ' and included %' . $payment_bonus['bonus_amount'] . ' bonus , and Final balance 
                is ' . $final_balance  . ' ', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
            } else {
                $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'] . ' and Final balance 
                is ' . $final_balance  . ' ', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
            }

            if ($update && $balance) {
                afterPaymentDone($payment["username"], $payment['payment_amount'], $method_name);
                $conn->commit();
                header('location:' . site_url() . 'addfunds');
                echo 'OK';
            } else {
                $conn->rollBack();
                header('location:' . site_url());
                echo 'NO';
            }
        else :
            //transaction unsuccesfull

            if ($postdata["amount"] != ($old_response[0]['response']['amount'] + $old_response[0]['response']['fee'])) {
                $fraud_risk = 2;
            } else {
                $fraud_risk = 1;
            }

            $update = $conn->prepare('UPDATE payments SET payment_status=:payment_status , payment_response=:payment_response, fraud_risk=:fraud_risk WHERE client_id=:client_id, payment_method=:payment_method, payment_delivery=:payment_delivery, payment_extra=:payment_extra');
            $update = $update->execute(['payment_status' => 2, "fraud_risk" => $fraud_risk, "payment_response" => $server_response, 'client_id' => $user['client_id'], 'payment_method' => 14, 'payment_delivery' => 1, 'payment_extra' => $_POST['ORDERID']]);

            header('location:' . site_url());
        endif;
    else :
        header('location:' . site_url());
    endif;
} else if ($method_name == 'payumoneyV2') {


    error_reporting(1);
    ini_set("display_errors", 1);
    define('BASEPATH', true);

    $postdata = $_POST;



    $response1 = array(
        'time' => date("Y-m-d H:i:s"),
        'response' => $_POST
    );



    $msg = '';
    $salt = $_SESSION['salt'];


    if (!$salt) {
        $salt = $extras["salt_key"];
    }




    //This function is used to double check payment
    function verifyPayment($key, $salt, $txnid, $status)
    {
        $command = "verify_payment"; //mandatory parameter

        $hash_str = $key  . '|' . $command . '|' . $txnid . '|' . $salt;
        $hash = strtolower(hash('sha512', $hash_str)); //generate hash for verify payment request

        $r = array('key' => $key, 'hash' => $hash, 'var1' => $txnid, 'command' => $command);

        $qs = http_build_query($r);
        //for production
        $wsUrl = "https://info.payu.in/merchant/postservice.php?form=2";

        //for test
        // $wsUrl = "https://test.payu.in/merchant/postservice.php?form=2";

        try {
            $c = curl_init();
            curl_setopt($c, CURLOPT_URL, $wsUrl);
            curl_setopt($c, CURLOPT_POST, 1);
            curl_setopt($c, CURLOPT_POSTFIELDS, $qs);
            curl_setopt($c, CURLOPT_CONNECTTIMEOUT, 30);
            curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($c, CURLOPT_SSLVERSION, 6); //TLS 1.2 mandatory
            curl_setopt($c, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($c, CURLOPT_SSL_VERIFYPEER, 0);
            $o = curl_exec($c);
            if (curl_errno($c)) {
                $sad = curl_error($c);
                throw new Exception($sad);
            }
            curl_close($c);



            $response = json_decode($o, true);

            if (isset($response['status'])) {
                // response is in Json format. Use the transaction_detailspart for status
                $response = $response['transaction_details'];
                $response = $response[$txnid];

                if ($response['status'] == $status) //payment response status and verify status matched
                    return true;
                else
                    return false;
            } else {
                return false;
            }
        } catch (Exception $e) {
            return false;
        }
    }


    $tnx_id = $_REQUEST['txnid'];

    $paymetStatusCheck = $conn->prepare("SELECT * FROM payments WHERE payment_extra=:payment_extra && payment_delivery=:payment_delivery");
    $paymetStatusCheck->execute(array("payment_extra" => $tnx_id, "payment_delivery" => 1));
    $paymetStatusCheck = $paymetStatusCheck->fetch(PDO::FETCH_ASSOC);

    if (isset($postdata['key']) && $paymetStatusCheck) {


        $key                =   $postdata['key'];
        $txnid                 =     $postdata['txnid'];
        $amount              =     $postdata['amount'];
        $productInfo          =     $postdata['productinfo'];
        $firstname            =     $postdata['firstname'];
        $email                =    $postdata['email'];
        $udf5                =   $postdata['udf5'];
        $status                =     $postdata['status'];
        $resphash            =     $postdata['hash'];
        //Calculate response hash to verify	
        $keyString               =      $key . '|' . $txnid . '|' . $amount . '|' . $productInfo . '|' . $firstname . '|' . $email . '|||||' . $udf5 . '|||||';
        $keyArray               =     explode("|", $keyString);
        $reverseKeyArray     =     array_reverse($keyArray);
        $reverseKeyString    =    implode("|", $reverseKeyArray);
        $CalcHashString     =     strtolower(hash('sha512', $salt . '|' . $status . '|' . $reverseKeyString)); //hash without additionalcharges


        //Comapre status and hash. Hash verification is mandatory.
        if ($status == 'success'  && $resphash == $CalcHashString) {
            $msg = "Transaction Successful, Hash Verified...<br />";

            if (verifyPayment($key, $salt, $txnid, $status)) {
                $msg = "Transaction Successful, Hash Verified...Payment Verified...";

                $response2 = array(
                    'time' => date("Y-m-d H:i:s"),
                    'response' => array(
                        "msg" => $msg,
                    )
                );


                $server_response = json_encode(array(
                    $response1, $response2
                ));


                $tnx_id = $_REQUEST['txnid'];

                $getfrompay = $conn->prepare("SELECT * FROM payments WHERE payment_extra=:payment_extra");
                $getfrompay->execute(array("payment_extra" => $tnx_id));
                $getfrompay = $getfrompay->fetch(PDO::FETCH_ASSOC);

                $user = $conn->prepare("SELECT * FROM clients WHERE client_id=:client_id");
                $user->execute(array("client_id" => $getfrompay['client_id']));
                $user = $user->fetch(PDO::FETCH_ASSOC);


                $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra ');
                $payment->execute(['extra' => $tnx_id]);
                $payment = $payment->fetch(PDO::FETCH_ASSOC);

                $payment_extraaa = json_decode($payment["payment_extraa"], true);


                if ($settings["site_currency"] == "INR") {
                    $condition = $_REQUEST["amount"] ==  $payment["payment_amount"];
                } else {
                    $condition = $_REQUEST["amount"] ==  $payment_extraaa["amount"];
                }

                // if ($settings["site_currency"] == "USD") {
                //     $condition = $_REQUEST["amount"] ==  $payment_extraaa["amount"];
                // } else {
                //     $condition = $_REQUEST["amount"] ==  $paymentGot;
                // }

                if ($condition) {

                    //referral

                    if ($extras["fee"]) {
                        $amountToAdd  =  $payment['payment_amount'] / (1 + $extras["fee"] / 100);
                    } else {
                        $amountToAdd =  $payment['payment_amount'];
                    }



                    if ($user["ref_by"]) {
                        $reff = $conn->prepare("SELECT * FROM referral WHERE referral_code=:referral_code ");
                        $reff->execute(array("referral_code" => $user["ref_by"]));
                        $reff  = $reff->fetch(PDO::FETCH_ASSOC);




                        $newAmount = $payment['payment_amount'];

                        $update3 = $conn->prepare("UPDATE referral SET referral_totalFunds_byReffered=:referral_totalFunds_byReffered,
                    referral_total_commision=:referral_total_commision WHERE referral_code=:referral_code ");
                        $update3 = $update3->execute(array(
                            "referral_code" => $user["ref_by"],
                            "referral_totalFunds_byReffered" => round($reff["referral_totalFunds_byReffered"] + $newAmount, 2),
                            "referral_total_commision" => round($reff["referral_total_commision"] + (($settings["referral_commision"] / 100) * $newAmount), 2)
                        ));
                    }
                    //referral


                    $payment_bonus = $conn->prepare('SELECT * FROM payments_bonus WHERE bonus_method=:method && bonus_from<=:from ORDER BY bonus_from DESC LIMIT 1');
                    $payment_bonus->execute(['method' => $method['id'], 'from' => $payment['payment_amount']]);
                    $payment_bonus = $payment_bonus->fetch(PDO::FETCH_ASSOC);

                    if ($payment_bonus) {
                        $amount = $amountToAdd + (($amountToAdd * $payment_bonus['bonus_amount']) / 100);
                    } else {
                        $amount = $amountToAdd;
                    }

                    $conn->beginTransaction();

                    $amount = round($amount, 2);

                    $update = $conn->prepare('UPDATE payments SET client_balance=:balance, payment_response=:payment_response, payment_status=:status, payment_delivery=:delivery WHERE payment_id=:id ');
                    $update = $update->execute(['balance' => $payment['balance'], 'status' => 3, "payment_response" => $server_response, 'delivery' => 2, 'id' => $payment['payment_id']]);


                    $old_balance =  $payment['balance'];

                    $added_funds = $amount;

                    $final_balance =  $old_balance + $added_funds;


                    $balance = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id ');
                    $balance = $balance->execute(['id' => $payment['client_id'], 'balance' => $payment['balance'] + $amount]);

                    $insert = $conn->prepare('INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ');
                    if ($payment_bonus) {
                        $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'] . ' and included %' . $payment_bonus['bonus_amount'] . ' bonus , and Final balance
                    is ' . $final_balance  . ' ', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
                    } else {
                        $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $method['method_name'] . ' and Final balance
                    is ' . $final_balance  . ' ', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
                    }
                    if ($update && $balance) {
                        afterPaymentDone($payment["username"], $payment['payment_amount'], $method_name);
                        $conn->commit();
                        header("Location:" . site_url(""));
                    } else {

                        $conn->rollBack();
                        header('location:' . site_url());
                    }
                } else {

                    header('location:' . site_url());
                }
            } else {
                $msg = "Transaction Successful, Hash Verified..Payment Verification failed..";
                $update = $conn->prepare('UPDATE payments SET payment_extraa=:payment_extraa,payment_response=:payment_response WHERE payment_extra=:payment_extra ');
                $update = $update->execute(['payment_extraa' => $msg, 'payment_extra' => $txnid, "payment_response" => json_encode(array($response1))]);

                if ($update) {
                    header('location:' . site_url());
                } else {
                    header('location:' . site_url());
                }
            }
        } else {
            //tampered or failed
            $msg = "Payment failed for Hash not verified...";

            $update = $conn->prepare('UPDATE payments SET  payment_response=:payment_response WHERE payment_extra=:payment_extra ');
            $update = $update->execute(['payment_extra' => $txnid, "payment_response" => json_encode(array($response1))]);

            if ($update) {
                header('location:' . site_url());
            } else {
                header('location:' . site_url());
            }
        }
    } else {
        header('location:' . site_url());
    };
}
