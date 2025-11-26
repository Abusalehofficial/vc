<?php
// Retrieve data from $_REQUEST
$trx_id = isset($_REQUEST['trx_id']) ? $_REQUEST['trx_id'] : null;
$sid = isset($_REQUEST['sid']) ? $_REQUEST['sid'] : null;
$reference_id = isset($_REQUEST['reference_id']) ? $_REQUEST['reference_id'] : null;
$status = isset($_REQUEST['status']) ? $_REQUEST['status'] : null;
$status_code = isset($_REQUEST['status_code']) ? $_REQUEST['status_code'] : null;
$via = isset($_REQUEST['via']) ? $_REQUEST['via'] : null;
$channel = isset($_REQUEST['channel']) ? $_REQUEST['channel'] : null;
$buyer_name = isset($_REQUEST['buyer_name']) ? $_REQUEST['buyer_name'] : null;
$buyer_email = isset($_REQUEST['buyer_email']) ? $_REQUEST['buyer_email'] : null;
$buyer_phone = isset($_REQUEST['buyer_phone']) ? $_REQUEST['buyer_phone'] : null;
$total = isset($_REQUEST['total']) ? $_REQUEST['total'] : null;
$amount = isset($_REQUEST['amount']) ? $_REQUEST['amount'] : null;
$fee = isset($_REQUEST['fee']) ? $_REQUEST['fee'] : null;
$expired_at = isset($_REQUEST['expired_at']) ? $_REQUEST['expired_at'] : null;
$paid_at = isset($_REQUEST['paid_at']) ? $_REQUEST['paid_at'] : null;
$settlement_status = isset($_REQUEST['settlement_status']) ? $_REQUEST['settlement_status'] : null;
$url = isset($_REQUEST['url']) ? $_REQUEST['url'] : null;

// Example of how you might verify the payment status
if ($status_code === '1' && $status === 'berhasil') {
    $invoiceId = $sid;

    // Prepare to fetch payment details
    $paymentDetailsStmt = $conn->prepare("SELECT * FROM payments WHERE payment_extra=:payment_extra");
    $paymentDetailsStmt->execute([
        "payment_extra" => $invoiceId
    ]);

    // Check if payment exists
    if ($paymentDetailsStmt->rowCount()) {
        $paymentDetails = $paymentDetailsStmt->fetch(PDO::FETCH_ASSOC);

        // Fetch user details
        $userStmt = $conn->prepare('SELECT * FROM clients WHERE client_id=:id');
        $userStmt->execute(['id' => $paymentDetails['client_id']]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        // Verify if payment has already been processed
        $paymentExists = countRow([
            'table' => 'payments',
            'where' => [
                'client_id' => $user['client_id'],
                'payment_method' => 8826,
                'payment_status' => 3, // Processed status
                'payment_delivery' => 2, // Delivered
                'payment_extra' => $invoiceId
            ]
        ]);

        // If payment not already processed
        if (!$paymentExists) {
            // Get payment and client details
            $paymentStmt = $conn->prepare('SELECT * FROM payments 
                INNER JOIN clients ON clients.client_id = payments.client_id 
                WHERE payments.payment_extra = :extra AND payments.payment_status != :undesired_status');
            $paymentStmt->execute([
                'extra' => $invoiceId,
                'undesired_status' => 3 // Status other than 'processed'
            ]);
            $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
         
            // Calculate the payment amount without the fee
            $netAmount = $payment['payment_amount'];

            // Begin transaction
            $conn->beginTransaction();

            try {
                // Update payment status
                $updatePaymentStmt = $conn->prepare('UPDATE payments SET client_balance=:balance, payment_amount=:amount, payment_status=:status, payment_delivery=:delivery WHERE payment_id=:id');
                $updatePaymentStmt->execute([
                    'balance' => $payment['balance'], 
                    'amount' => $netAmount, 
                    'status' => 3, // Set status to 'processed'
                    'delivery' => 2, // Set delivery status
                    'id' => $payment['payment_id']
                ]);

                // Update client balance
                $updateBalanceStmt = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id');
                $updateBalanceStmt->execute([
                    'id' => $payment['client_id'], 
                    'balance' => $payment['balance'] + $netAmount
                ]);

                // Insert client action into the report
                $insertReportStmt = $conn->prepare('INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date');
                $insertReportStmt->execute([
                    'c_id' => $payment['client_id'], 
                    'action' => 'New ' . $netAmount . ' ' . $settings["currency"] . ' payment has been made with ' . $via, 
                    'ip' => GetIP(), 
                    'date' => date('Y-m-d H:i:s')
                ]);

                // Commit the transaction
                $conn->commit();
                echo "Payment verified and processed successfully.";
            } catch (Exception $e) {
                // Rollback in case of any error
                $conn->rollBack();
                echo "Transaction failed: " . $e->getMessage();
            }
        } else {
            echo "Payment has already been processed.";
        }
    } else {
        echo "No payment record found.";
    }
} else {
    // Handle failed or pending payments
    echo "Payment not verified. Status: " . $status;
}
?>