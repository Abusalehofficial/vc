<?php

function fspcurlwithoutpost($url,$header="") {
$curl = curl_init();

curl_setopt_array($curl, array(
  CURLOPT_URL => $url,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => "",
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 0,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => "GET",
  CURLOPT_HTTPHEADER => $header
));

// return curl_exec($curl);

// curl_close($curl);

   $response = curl_exec($curl);
   curl_close($curl);
   return $response;

}

	
 function data($id) {
    global $conn;
    $select = $conn->prepare("SELECT * FROM payment_methods WHERE id = :id");
    $select->execute(array("id" => $id));
    $row = $select->fetch(PDO::FETCH_ASSOC);

    return $row['method_extras'];
}

$extra = data(309);
$extra = json_decode($extra, true);
// $r = fspcurlwithoutpost(base64_decode($extra["api_for_ip"]) . "?ORDERID=" . $_POST["transactionId"] . "&MID=".$extra["mid"], array());

$ORDERID = $_POST["transactionId"];
$MID = preg_replace('/%20/', '', $extra["mid"]);

$JsonData = json_encode(array("MID" => $MID, "ORDERID" => $ORDERID));

$r = fspcurlwithoutpost("https://securegw.paytm.in/order/status?JsonData=$JsonData", array());
$response = json_decode($r, true);

$id = $_POST["transactionId"];
 
if (
    isset($response['ORDERID']) && $response['ORDERID'] == $id &&
    $response['STATUS'] === 'TXN_SUCCESS' &&
    $response['RESPCODE'] === '01' &&
    $response['RESPMSG'] === 'Txn Success' &&
    date('Y-m-d') === date('Y-m-d', strtotime($response['TXNDATE']))
)
{
        
        
    $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra ');
        $payment->execute(['extra' => $id]);
        $payment = $payment->fetch(PDO::FETCH_ASSOC);
    $amt = number_format($payment['payment_amount'], 2, '.', '');
        if( !empty($response['TXNAMOUNT']) && $amt !== $response['TXNAMOUNT'] ){
        
      $responseArray['STATUS'] = 'amount_not_matched';

 $responseArray['CALLBACK'] = site_url("");
echo $modifiedJson = json_encode($responseArray); 

exit();
 }   
                      
 
  
 $paymentExtra = $id;                
              
              
    $payment = $conn->prepare('SELECT * FROM payments 
            INNER JOIN clients ON clients.client_id = payments.client_id 
            WHERE payments.payment_extra = :extra AND payments.payment_status != :undesired_status');

        $payment->execute([
            'extra' => $paymentExtra,
            'undesired_status' => 3
        ]);

        $payment = $payment->fetch(PDO::FETCH_ASSOC);
    
    
        
    // 1nd start //    
 $payment_bonus = $conn->prepare('SELECT * FROM payments_bonus WHERE bonus_method=:method && bonus_from<=:from ORDER BY bonus_from DESC LIMIT 1');
					$payment_bonus->execute(['method' => $method['id'], 'from' => $payment['payment_amount']]);
					$payment_bonus = $payment_bonus->fetch(PDO::FETCH_ASSOC);
					if ($payment_bonus) {
                        $converted_amount = convertCurrencyUpdated("INR", $settings["site_currency"], $payment['payment_amount']);
                        $bonus_amount = ($converted_amount * $payment_bonus['bonus_amount']) / 100;
                        $amount = $converted_amount + $bonus_amount;
                    } else {
                        $amount = convertCurrencyUpdated("INR", $settings["site_currency"], $payment['payment_amount']);
                    }
				
				
				
		// 1nd end		
				
        $conn->beginTransaction();
        // start 
        	if ($payment_bonus) {
					    
					    $insert = $conn->prepare('INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ');
					$insert25 = $conn->prepare("INSERT INTO payments SET client_id=:client_id , client_balance=:client_balance , payment_amount=:payment_amount , payment_method=:payment_method ,
                payment_status=:status, payment_delivery=:delivery , payment_note=:payment_note , payment_create_date=:payment_create_date , payment_extra=:payment_extra , bonus=:bonus");
						$insert25->execute(array(
							"client_id" => $payment['client_id'], "client_balance" => (($payment['balance'] + $amount) - $bonus_amount),
							"payment_amount" => $bonus_amount, "payment_method" =>  $method['id'], 'status' => 3, 'delivery' => 2, "payment_note" => "Bonus added", "payment_create_date" => date('Y-m-d H:i:s'), "payment_extra" => "Bonus added for previous payment",
							"bonus" => 1
						));
						$insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["currency"] . ' payment has been made with ' . $method['method_name'] . ' and included %' . $payment_bonus['bonus_amount'] . ' bonus.', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
					} 
					// end
					
					$payment['payment_amount'] = convertCurrencyUpdated("INR", $settings["site_currency"], $payment['payment_amount']);
    
    
    
    
        // Calculate the payment amount without bonus
        $amount = $payment['payment_amount'] - (($payment['payment_amount'] * $extras['fee']) / 100);
        
						
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
            'balance' => $payment['balance'] + $amount + ($payment_bonus ? $bonus_amount : 0)
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

$responseArray['STATUS'] = 'SUCCESS';

 $responseArray['CALLBACK'] = site_url("addfunds");

}else{
                $conn->rollBack();

   $responseArray['STATUS'] = 'failed';

 $responseArray['CALLBACK'] = site_url("");
  
}
     
 
    
} else {
      
$responseArray['STATUS'] = 'Not Received payment yet';

 $responseArray['CALLBACK'] = site_url("");

}

 echo $modifiedJson = json_encode($responseArray); 

exit();


