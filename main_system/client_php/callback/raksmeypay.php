<?php
function data($id) {
    global $conn;
    $select = $conn->prepare("SELECT * FROM payment_methods WHERE id = :id");
    $select->execute(array("id" => $id));
    $row = $select->fetch(PDO::FETCH_ASSOC);

    return $row['method_extras'];
}

if(!empty($_GET["transaction_id"])){
    $transaction_id = $_GET["transaction_id"];
    $extra = data(6969);
    $extra = json_decode($extra, true);
    $payment_verify_url = "https://raksmeypay.com/api/payment/verify/" . $extra["profile_id"];
    $profile_key = $extra["profile_key"];
    $hash = sha1($profile_key . $transaction_id);
    $data = [
        "transaction_id" => $transaction_id,
        "hash" => $hash
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $payment_verify_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    $response = json_decode($response, 1);
     
    if(!empty($response["payment_status"]) && strtoupper($response["payment_status"]) == "SUCCESS"){
        //main
        $payment = $conn->prepare('SELECT * FROM payments 
            INNER JOIN clients ON clients.client_id = payments.client_id 
            WHERE payments.payment_extra = :extra AND payments.payment_status != :undesired_status');

        $payment->execute([
            'extra' => $_GET["transaction_id"],
            'undesired_status' => 3
        ]);

        $payment = $payment->fetch(PDO::FETCH_ASSOC);

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

        header("Location:" .  site_url('addfunds'));
        exit;
    }

    header("Location:" .  site_url('addfunds'));
    exit;
}
header("Location:" .  site_url('addfunds'));
exit;