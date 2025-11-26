<?php
$merchantTradeNoToFind =     $_SESSION['TID'];
if ($_REQUEST['suc']== $_SESSION['VERI']) {
    $paymentExtra = $_SESSION['TID'];                
              
              
    $payment = $conn->prepare('SELECT * FROM payments 
            INNER JOIN clients ON clients.client_id = payments.client_id 
            WHERE payments.payment_extra = :extra AND payments.payment_status != :undesired_status');

        $payment->execute([
            'extra' => $paymentExtra,
            'undesired_status' => 3
        ]);

        $payment = $payment->fetch(PDO::FETCH_ASSOC);
        
        $payment['payment_amount'] = convertCurrencyUpdated("USD", $settings["site_currency"], $payment['payment_amount']);

        // Calculate the payment amount without bonus
        $amount = $payment['payment_amount'] - (($payment['payment_amount'] * $extras['fee']) / 100);

        $conn->beginTransaction();

        $update = $conn->prepare('UPDATE payments SET client_balance=:balance, payment_amount=:amount, payment_status=:status, payment_delivery=:delivery WHERE payment_id=:id');
        $update = $update->execute([
            'balance' => $payment['balance'], 
            'amount' => $amount, 
            'status' => 3, 
            'delivery' => 2, 
            'id' => $payment['payment_id']
        ]);

        $balance = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id');
        $balance = $balance->execute([
            'id' => $payment['client_id'], 
            'balance' => $payment['balance'] + $amount
        ]);

        $insert = $conn->prepare('INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date');
        $insert = $insert->execute([
            'c_id' => $payment['client_id'], 
            'action' => 'New ' . $amount . ' ' . $settings["currency"] . ' payment has been made with ' . $method['method_name'], 
            'ip' => GetIP(), 
            'date' => date('Y-m-d H:i:s')
        ]);

        if ($update && $balance) {
            $conn->commit();
        } else {
            $conn->rollBack();
        }

       

     session_unset();
            header("Location:" .  site_url('addfunds'));
        exit;      
      
        } else {
session_unset();
 header("Location:" .  site_url('addfunds'));
        exit;
         }
