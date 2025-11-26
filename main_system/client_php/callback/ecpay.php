<?php

$methodId = 123;
$method = $conn->prepare("SELECT * FROM payment_methods WHERE id = :id");
$method->execute(["id" => $methodId]);
$method = $method->fetch();
$methodExtras = json_decode($method['method_extras'], true);

// Assuming you redirected user to ECPay and now they came back with this GET parameter
$MerchantTradeNo = $_REQUEST['MerchantTradeNo'] ?? null;

if (!$MerchantTradeNo) {
    header("Location: " . site_url("addfunds/failed"));
    exit();
}

// Set up API parameters
$params = [
    'MerchantID' => $methodExtras['MerchantID'],
    'MerchantTradeNo' => $MerchantTradeNo
];

// Generate CheckMacValue
$params['CheckMacValue'] = generateCheckMacValue($params, $methodExtras['HashKey'], $methodExtras['HashIV']);
$sandbox = ($methodExtras['sandbox'] == 'live') ? false : true;

    $queryurl = 'https://payment.ecpay.com.tw/Cashier/QueryTradeInfo/V5';

// Send API request
$ch = curl_init($queryurl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

// Parse the response
parse_str($response, $result);

// Check result
if (isset($result['TradeStatus']) && $result['TradeStatus'] == '10200083') {
    // Payment successss
    $paymentDetails = $conn->prepare("SELECT * FROM payments WHERE payment_extra = ? AND payment_status = 1");
    $paymentDetails->execute([$MerchantTradeNo]);
    $paymentDetails = $paymentDetails->fetch();

    if ($paymentDetails) {
        $user = $conn->prepare("SELECT * FROM clients WHERE client_id = ?");
        $user->execute([$paymentDetails['client_id']]);
        $user = $user->fetch();

        $conn->prepare("UPDATE payments SET 
            payment_status = 3,
            payment_delivery = 2,
            client_balance = ?
            WHERE payment_extra = ? AND payment_status = 1")
            ->execute([$user['balance'] + $paymentDetails['payment_amount'], $MerchantTradeNo]);

        $conn->prepare("UPDATE clients SET balance = balance + ? WHERE client_id = ?")
            ->execute([$paymentDetails['payment_amount'], $user['client_id']]);
    }

    header("Location: " . site_url("addfunds/success"));
} else {
    header("Location: " . site_url("addfunds/failed"));
}
exit();

// Function to generate CheckMacValue
function generateCheckMacValue($params, $HashKey, $HashIV) {
    ksort($params);
    $checkStr = '';
foreach ($params as $key => $value) {
    $checkStr .= $key . '=' . $value . '&';
}
$checkStr = rtrim($checkStr, '&');
    $encoded = 'HashKey=' . $HashKey . '&' . $checkStr . '&HashIV=' . $HashIV;
   
    return strtoupper(hash('md5', $encoded));
}
?>