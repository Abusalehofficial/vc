<?php
 function data($id) {
    global $conn;
    $select = $conn->prepare("SELECT * FROM payment_methods WHERE id = :id");
    $select->execute(array("id" => $id));
    $row = $select->fetch(PDO::FETCH_ASSOC);

    return $row['method_extras'];
}

$extra = data(120);
$extra = json_decode($extra, true);
// Merchant key (from EXPAY BRASIL credentials panel)
$merchant_key = $extra['apikey'];

// Step 1: Receive JSON notification from EXPAY BRASIL
$input = file_get_contents('php://input');
$notification = json_decode($input, true);

if (!isset($notification['date_notification'], $notification['invoice_id'], $notification['token'])) {
    http_response_code(400); // Bad request
    echo json_encode(['error' => 'Invalid notification format']);
    exit;
}

// Extract information from the notification
$date_notification = $notification['date_notification'];
$invoice_id = $notification['invoice_id'];
$token = $notification['token'];

// Step 2: Prepare the response array
$response_data = [
    'merchant_key' => $merchant_key,
    'token' => $token
];

// Step 3: Send the response to the EXPAY BRASIL endpoint using cURL
$url = 'https://expaybrasil.com/en/request/status';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($response_data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/x-www-form-urlencoded',
    'Accept: application/json'
]);

$result = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

 if ($http_code == 200) {
    // Decode the JSON response
    $decoded_result = json_decode($result, true);

    // Check if decoding was successful

        if (isset($decoded_result['result']) && $decoded_result['result'] === true) {
            // Extract the 'invoice_id' and 'status'
            $transaction = $decoded_result['transaction_request'] ?? [];
            $invoice_id = $transaction['invoice_id'] ?? null;
            $status = $transaction['status'] ?? null;

            // Check the payment status
            if ($status === 'paid') {
                echo "Payment verified for Invoice ID: $invoice_id\n";
                 $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra AND payments.payment_status != 3 ');
                $payment->execute(['extra' => $invoice_id]);
                $payment = $payment->fetch(PDO::FETCH_ASSOC);
                
               $payment['payment_amount'] = convertCurrencyUpdated("BRL", $settings['site_currency'], $payment['payment_amount']);
                 
                
                        
              // var_dump($settings['site_currency']);exit();endif;
                
                $payment_bonus = $conn->prepare('SELECT * FROM payments_bonus WHERE bonus_method=:method && bonus_from<=:from ORDER BY bonus_from DESC LIMIT 1');
                $payment_bonus->execute(['method' => $method['id'], 'from' => $payment['payment_amount']]);
                $payment_bonus = $payment_bonus->fetch(PDO::FETCH_ASSOC);
                            if( $payment_bonus ) {
                    $amount = $payment['payment_amount'] + (($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100) - (($payment['payment_amount'] * $extras['fee']) / 100) - (($payment['payment_amount'] * $extras['fee']) / 100);
                } else {
                    $amount = $payment['payment_amount'] - (($payment['payment_amount'] * $extras['fee']) / 100);
                }
                       
                   $conn->beginTransaction();

                $update = $conn->prepare('UPDATE payments SET client_balance=:balance,payment_amount=:amount, payment_status=:status, payment_delivery=:delivery WHERE payment_id=:id ');
                $update = $update->execute(['balance' => $payment['balance'],'amount' =>$amount, 'status' => 3, 'delivery' => 2, 'id' => $payment['payment_id']]);
              
                $balance = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id ');
                $balance = $balance->execute(['id' => $payment['client_id'], 'balance' => $payment['balance'] + $amount]);

                $insert = $conn->prepare('INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ');
                
     
            if( $payment_bonus ) {
                    $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["currency"] . ' payment has been made with ' . $method['method_name'] . ' and included %' . $payment_bonus['bonus_amount'] . ' bonus.', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s') ]);
                } else {
                    $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["currency"] . ' payment has been made with ' . $method['method_name'], 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s') ]);
                }
                if ($update && $balance) {
                    $conn->commit();
        
                    echo 'OK';
                } else {
                    $conn->rollBack();
        
                    echo 'NO';
                } 
      
            } else {
                echo "Payment status is not paid. Current status: $status\n";
            }
        } else {
            echo "Error: Invalid result field in response.\n";
        }
    
} else {
    echo "HTTP Error: $http_code\n";
}
 
?>