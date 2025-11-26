<?php
 $payment = $_GET['status'];

function data($id) {
    global $conn;
    $select = $conn->prepare("SELECT * FROM payment_methods WHERE id = :id");
    $select->execute(array("id" => $id));
    $row = $select->fetch(PDO::FETCH_ASSOC);

    return $row['method_extras'];
}

$extra = data(990);
$extra = json_decode($extra, true);

$secretKey = $extra["secretKey"];
if ($payment == 'completed' || $payment == 'successful') {
   //main
 $transactionId = htmlspecialchars($_GET["transaction_id"]);
    $payment_extra = htmlspecialchars($_GET["tx_ref"]);

   
   
            $url = "https://api.flutterwave.com/v3/transactions/{$transactionId}/verify";

            $headers = [
                "Content-Type: application/json",
                "Authorization: Bearer " . $secretKey . "",
            ];

            $curl = curl_init();
            curl_setopt_array(
                $curl,
                [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => "",
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => "GET",
                    CURLOPT_HTTPHEADER => $headers,
                ]
            );
            $response = curl_exec($curl);
            curl_close($curl);

            $response = json_decode($response, 1);

            if ($response['status'] == 'success') {
           
                
   $payment_extra = htmlspecialchars($_GET["tx_ref"]);
    $payment = $conn->prepare('SELECT * FROM payments 
            INNER JOIN clients ON clients.client_id = payments.client_id 
            WHERE payments.payment_extra = :extra AND payments.payment_status != :undesired_status');

        $payment->execute([
            'extra' => $payment_extra,
            'undesired_status' => 3
        ]);

        $payment = $payment->fetch(PDO::FETCH_ASSOC);
        
        $payment['payment_amount'] = convertCurrencyUpdated("GHS", $settings["site_currency"], $payment['payment_amount']);

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
                    header('location:' . site_url(addfunds));
}else{
    
                    header('location:' . site_url(addfunds));

}

?>
