<?php


// Function to retrieve payment method details
function getPaymentMethodExtras($id) {
    global $conn;
    $select = $conn->prepare("SELECT method_extras FROM payment_methods WHERE id = :id");
    $select->execute(['id' => $id]);
    $row = $select->fetch(PDO::FETCH_ASSOC);
    return $row ? json_decode($row['method_extras'], true) : null;
}

// Verify the reference parameter
$reference = $_GET['reference'] ?? '';
if (empty($reference)) {
    die('No reference supplied');
}

// Retrieve payment method extras
$paymentMethodId = 399; // Replace with your actual payment method ID
$extras = getPaymentMethodExtras($paymentMethodId);
if (!$extras || !isset($extras['secret_key'])) {
    die('Payment method configuration error');
}

// Initialize cURL to verify the transaction
$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => "https://api.paystack.co/transaction/verify/" . rawurlencode($reference),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "accept: application/json",
        "authorization: Bearer " . $extras['secret_key'],
        "cache-control: no-cache"
    ],
]);

$response = curl_exec($curl);
if ($response === false) {
    die('Curl error: ' . curl_error($curl));
}
curl_close($curl);

$tranx = json_decode($response, true);
if (!$tranx['status']) {
    die('API returned error: ' . $tranx['message']);
}

// Check transaction status
if ($tranx['data']['status'] === 'success') {
    // Fetch payment details
    $paymentStmt = $conn->prepare('SELECT payments.*, clients.balance FROM payments INNER JOIN clients ON clients.client_id = payments.client_id WHERE payments.payment_extra = :extra');
    $paymentStmt->execute(['extra' => $reference]);
    $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        die('Payment record not found');
    }

    // Convert payment amount to site currency
    $paymentAmount = convertCurrencyUpdated("NGN", $settings["site_currency"], $payment['payment_amount']);

    // Calculate bonus if applicable
    $bonusStmt = $conn->prepare('SELECT * FROM payments_bonus WHERE bonus_method = :method AND bonus_from <= :from ORDER BY bonus_from DESC LIMIT 1');
    $bonusStmt->execute(['method' => $paymentMethodId, 'from' => $paymentAmount]);
    $bonus = $bonusStmt->fetch(PDO::FETCH_ASSOC);

    $bonusAmount = $bonus ? ($paymentAmount * $bonus['bonus_amount'] / 100) : 0;
    $feeAmount = isset($extras['fee']) ? ($paymentAmount * $extras['fee'] / 100) : 0;
    $totalAmount = $paymentAmount + $bonusAmount - $feeAmount;

    try {
        $conn->beginTransaction();

        // Update payment record
        $updatePayment = $conn->prepare('UPDATE payments SET payment_amount = :amount, payment_status = :status, payment_delivery = :delivery WHERE payment_id = :id');
        $updatePayment->execute([
            'amount' => $totalAmount,
            'status' => 3, // Assuming 3 means completed
            'delivery' => 2, // Assuming 2 means delivered
            'id' => $payment['payment_id']
        ]);

        // Update client balance
        $newBalance = $payment['balance'] + $totalAmount;
        $updateClient = $conn->prepare('UPDATE clients SET balance = :balance WHERE client_id = :id');
        $updateClient->execute([
            'balance' => $newBalance,
            'id' => $payment['client_id']
        ]);

        // Insert into client report
        $action = 'New ' . $totalAmount . ' ' . $settings["site_currency"] . ' payment has been made with ' . $extras['method_name'];
        if ($bonus) {
            $action .= ' and included ' . $bonus['bonus_amount'] . '% bonus.';
        }
        $insertReport = $conn->prepare('INSERT INTO client_report (client_id, action, report_ip, report_date) VALUES (:client_id, :action, :ip, :date)');
        $insertReport->execute([
            'client_id' => $payment['client_id'],
            'action' => $action,
            'ip' => $_SERVER['REMOTE_ADDR'],
            'date' => date('Y-m-d H:i:s')
        ]);

        $conn->commit();
        header('Location: ' . site_url('addfunds'));
        exit('OK');
    } catch (Exception $e) {
        $conn->rollBack();
        error_log('Transaction failed: ' . $e->getMessage());
        header('Location: ' . site_url('addfunds'));
        exit('Transaction failed');
    }
} else {
    header('Location: ' . site_url('addfunds'));
    exit('Payment not successful');
}
?>
