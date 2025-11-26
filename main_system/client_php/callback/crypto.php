<?php
function data($id) {
    global $conn;
    $select = $conn->prepare("SELECT * FROM payment_methods WHERE id = :id");
    $select->execute(array("id" => $id));
    $row = $select->fetch(PDO::FETCH_ASSOC);

    return $row['method_extras'];
}
$extra = data(1110);
$extra = json_decode($extra, true);
$json = file_get_contents('php://input');
$data = json_decode($json, JSON_UNESCAPED_UNICODE);

$invoiceId = $data['order_id'];


     if ($data['status'] == 'paid_over'  || $data['status'] === 'paid') {
 

$invoiceId = $data['order_id'];

      $paymentDetails = $conn->prepare("SELECT * FROM payments WHERE payment_extra=:payment_extra");
      
      
        $paymentDetails->execute([
            "payment_extra" => $invoiceId
        ]);

        if ($paymentDetails->rowCount()) {
            $paymentDetails = $paymentDetails->fetch(PDO::FETCH_ASSOC);
$user = $conn->prepare('SELECT * FROM clients WHERE client_id=:id');
$user->execute(array('id'=>$paymentDetails['client_id'] ));
$user = $user->fetch(PDO::FETCH_ASSOC);
            if (
                !countRow([
                    'table' => 'payments',
                    'where' => [
                        'client_id' => $user['client_id'],
                        'payment_method' => 1110,
                        'payment_status' => 3,
                        'payment_delivery' => 2,
                        'payment_extra' => $invoiceId
                    ]
                ])
            ) {
              
               $payment = $conn->prepare('SELECT * FROM payments 
            INNER JOIN clients ON clients.client_id = payments.client_id 
            WHERE payments.payment_extra = :extra AND payments.payment_status != :undesired_status');

        $payment->execute([
            'extra' => $invoiceId,
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
                
            }
        }
}
        