<?php


           
           
         $referenceId = $_REQUEST['payment_id'];

          $method = $conn->prepare("SELECT * FROM payment_methods WHERE id=:id ");
    $method->execute(array("id" => 9090));
    $method = $method->fetch(PDO::FETCH_ASSOC);
    $extra = json_decode($method["method_extras"], true);
    
        $channel = $extra['channel'];
        $secret = $extra['secret'];
        $website = $extra['websiteUrl'];
                 
         $headers = array(
            "Content-Type: application/json",
            "channel: $channel",
            "secret: $secret",
            "websiteurl: $website"
        );
        
      
                 $url = 'https://whish.money/itel-service/api/payment/collect/status';
       

         $post_vals = array(
            'currency'=> 'USD',
            'externalId' => $referenceId
        );
        
     
   $data = json_encode($post_vals);
        
         $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $result = @curl_exec($ch);
        if (curl_errno($ch)) {
            die("PAYTR IFRAME connection error. err:" . curl_error($ch));
        }
        curl_close($ch);
        $result = $result?$result:null;
        $result = json_decode($result,true);
         
         $payment_success = @$result['data']['collectStatus'] == 'success' ? true : false;


 
    $getfrompay = $conn->prepare("SELECT * FROM payments WHERE payment_extra=:payment_extra AND payments.payment_status !=3 ");
    $getfrompay->execute(array("payment_extra" => $referenceId));
    $getfrompay = $getfrompay->fetch(PDO::FETCH_ASSOC);
//asume the error =1
    $user = $conn->prepare("SELECT * FROM clients WHERE client_id=:client_id");
    $user->execute(array("client_id" => $getfrompay['client_id']));
    $user = $user->fetch(PDO::FETCH_ASSOC);

             if (@$payment_success) {
            
            
            $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra AND payments.payment_status !=3 ');
            $payment->execute(['extra' => $referenceId]);
            $payment = $payment->fetch(PDO::FETCH_ASSOC);
            
             $payment['payment_amount'] = convertCurrencyUpdated("USD", $settings["site_currency"], $payment['payment_amount']);
            
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
               
                header('location:' . site_url());
                echo 'OK';
            } else {
                $conn->rollBack();
                header('location:' . site_url());
                echo 'NO';
            }
        } else {

            $update = $conn->prepare('UPDATE payments SET payment_status=:payment_status WHERE client_id=:client_id and payment_method=:payment_method and payment_delivery=:payment_delivery and payment_extra=:payment_extra');
            $update->execute(array('payment_status' => 2, 'client_id' => $user['client_id'], 'payment_method' => "9090", 'payment_delivery' => 1, 'payment_extra' => $referenceId));
        }
 