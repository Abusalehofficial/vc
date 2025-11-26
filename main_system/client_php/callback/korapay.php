<?php
function data($id) {
    global $conn;
    $select = $conn->prepare("SELECT * FROM payment_methods WHERE id = :id");
    $select->execute(array(":id" => $id));
    $row = $select->fetch(PDO::FETCH_ASSOC);

    return $row['method_extras'];
}

$extra = data(5346);
$extra = json_decode($extra, true);

$secret_key = $extra["secret_key"];
$transaction_reference = $_REQUEST['reference']; // Replace with the actual transaction reference

// Endpoint URL
$endpoint = "https://api.korapay.com/merchant/api/v1/charges/$transaction_reference";

// Initialize cURL session
$ch = curl_init($endpoint);

// Set the cURL options
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Authorization: Bearer ' . $secret_key,
));

// Execute the cURL session and get the response
$response = curl_exec($ch);

// Check for cURL errors
if (curl_errno($ch)) {
    echo 'cURL error: ' . curl_error($ch);
}

// Close the cURL session
curl_close($ch);

$result = json_decode($response, true);

if ($result['data']['status'] == "success") {
    $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra AND payments.payment_status != 3 ');
    $payment->execute(['extra' => $result['data']['reference']]);
    $payment = $payment->fetch(PDO::FETCH_ASSOC);


    $payment['payment_amount'] = convertCurrencyUpdated("NGN", $settings["site_currency"], $payment['payment_amount']);


    $payment_bonus = $conn->prepare('SELECT * FROM payments_bonus WHERE bonus_method=:method && bonus_from<=:from ORDER BY bonus_from DESC LIMIT 1');
    $payment_bonus->execute(['method' => $payment['payment_method'], 'from' => $payment['payment_amount']]);
    $payment_bonus = $payment_bonus->fetch(PDO::FETCH_ASSOC);

    if ($payment_bonus) {
        $amount = $payment['payment_amount'] + (($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100) - (($payment['payment_amount'] * $extra['fee']) / 100) - (($payment['payment_amount'] * $extra['fee']) / 100);
    } else {
        $amount = $payment['payment_amount'] - (($payment['payment_amount'] * $extra['fee']) / 100);
    }

    $conn->beginTransaction();

    $update = $conn->prepare('UPDATE payments SET client_balance=:balance,payment_amount=:amount, payment_status=:status, payment_delivery=:delivery WHERE payment_id=:id ');
    $update->execute(['balance' => $payment['balance'], 'amount' => $amount, 'status' => 3, 'delivery' => 2, 'id' => $payment['payment_id']]);

    $balance = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id ');
    $balance->execute(['id' => $payment['client_id'], 'balance' => $payment['balance'] + $amount]);

    $insert = $conn->prepare('INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ');

    if ($payment_bonus) {
        $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["currency"] . ' payment has been made with ' . $payment['payment_method'] . ' and included %' . $payment_bonus['bonus_amount'] . ' bonus.', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
    } else {
        $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["currency"] . ' payment has been made with ' . $payment['payment_method'], 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
    }
    
    if ($update && $balance) {
        $conn->commit();
        header('location:' . site_url('addfunds'));
        echo 'OK';
    } else {
        $conn->rollBack();
        header('location:' . site_url('addfunds'));
        echo 'NO';
    }
} else {
    header('location:' . site_url('addfunds'));
}
?>