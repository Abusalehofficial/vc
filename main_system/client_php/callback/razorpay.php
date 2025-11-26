<?php
     if ($_SERVER['REQUEST_METHOD'] === 'POST') {

function data($id) {
    global $conn;
    $select = $conn->prepare("SELECT * FROM payment_methods WHERE id = :id");
    $select->execute(array("id" => $id));
    $row = $select->fetch(PDO::FETCH_ASSOC);

    return $row['method_extras'];
}

      
    $extra = data(15);
    $extra = json_decode($extra, true);
    
$apiSecret =  $extra['api_secret_key'];

    $paymentData = json_decode(file_get_contents('php://input'), true);
    
     $generatedSignature = hash_hmac('sha256', $paymentData['razorpay_order_id'].'|'.$paymentData['razorpay_payment_id'], $apiSecret);
    
    if ($generatedSignature === $paymentData['razorpay_signature']) {
        //main
        $payment = $conn->prepare('SELECT * FROM payments 
            INNER JOIN clients ON clients.client_id = payments.client_id 
            WHERE payments.payment_extra = :extra AND payments.payment_status != :undesired_status');

        $payment->execute([
            'extra' =>  $paymentData['razorpay_order_id'],
            'undesired_status' => 3
        ]);

        $payment = $payment->fetch(PDO::FETCH_ASSOC);
        
        $payment['payment_amount'] = convertCurrencyUpdated("INR", $settings["site_currency"], $payment['payment_amount']);

        // Calculate the payment amount without bonus
        $amount = $payment['payment_amount'] - (($payment['payment_amount'] * $extra['fee']) / 100);

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
                    echo json_encode(['success' => true]);

        } else {
            $conn->rollBack();
                    echo json_encode(['success' => false]);

        }

       
    }
}else{
                        echo json_encode(['success' => false]);

}
     exit;
 