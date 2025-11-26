<?php

function data($id) {
    global $conn;
    $select = $conn->prepare("SELECT * FROM payment_methods WHERE id = :id");
    $select->execute(array("id" => $id));
    $row = $select->fetch(PDO::FETCH_ASSOC);

    return $row['method_extras'];
}


$extra = data(6672);
$extras = json_decode($extra, true);

	$invoice_id = $_REQUEST['invoice_id'];
	if (empty($invoice_id)) {
		$up_response = file_get_contents('php://input');
		$up_response_decode = json_decode($up_response, true);
		$invoice_id = $up_response_decode['invoice_id'];
	}

	if(empty($invoice_id)){
	    die('Direct access is not allowed.');
	}

	$apiKey =  trim($extras['api_key']);
	$host = parse_url(trim($extras['api_url']),  PHP_URL_HOST);
	$apiUrl = "https://{$host}/api/verify-payment";

	$invoice_data = [
		'invoice_id' => $invoice_id
	];

	$curl = curl_init();
	curl_setopt_array($curl, [
		CURLOPT_URL => $apiUrl,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_ENCODING => "",
		CURLOPT_MAXREDIRS => 10,
		CURLOPT_TIMEOUT => 30,
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		CURLOPT_CUSTOMREQUEST => "POST",
		CURLOPT_POSTFIELDS => json_encode($invoice_data),
		CURLOPT_HTTPHEADER => [
			"RT-UDDOKTAPAY-API-KEY: " . $apiKey,
			"accept: application/json",
			"content-type: application/json"
		],
	]);

	$response = curl_exec($curl);
	$err = curl_error($curl);

	curl_close($curl);

	if ($err) {
		echo "cURL Error #:" . $err;
		exit();
	}


	if (!empty($response)) {
		// Decode response data
		$data = json_decode($response, true);

		if (!isset($data['status']) && !isset($data['metadata']['invoice_id'])) {
			die('Invalid Response.');
		}

		if (isset($data['status']) && $data['status'] == 'COMPLETED') {
			if (countRow(['table' => 'payments', 'where' => ['client_id' => $data['metadata']['user_id'], 'payment_method' => 6672, 'payment_status' => 1, 'payment_delivery' => 1, 'payment_extra' => $data['metadata']['txnid']]])) {
				if ($data['status'] == 'COMPLETED') {
					$payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra ');
					$payment->execute(['extra' => $data['metadata']['txnid']]);
					$payment = $payment->fetch(PDO::FETCH_ASSOC);
					$payment_bonus = $conn->prepare('SELECT * FROM payments_bonus WHERE bonus_method=:method && bonus_from<=:from ORDER BY bonus_from DESC LIMIT 1');
					$payment_bonus->execute(['method' => $method['id'], 'from' => $payment['payment_amount']]);
					$payment_bonus = $payment_bonus->fetch(PDO::FETCH_ASSOC);
					if ($payment_bonus) {
						$amount = $payment['payment_amount'] + (($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100);
						$bonus_amount = ($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100;
					} else {
						$amount = $payment['payment_amount'];
					}
					$conn->beginTransaction();
					$update = $conn->prepare('UPDATE payments SET client_balance=:balance, payment_status=:status, payment_delivery=:delivery WHERE payment_id=:id ');
					$update = $update->execute(['balance' => $payment['balance'], 'status' => 3, 'delivery' => 2, 'id' => $payment['payment_id']]);

					$balance = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id ');
					$balance = $balance->execute(['id' => $payment['client_id'], 'balance' => $payment['balance'] + $amount]);

					$insert = $conn->prepare('INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ');
					$insert25 = $conn->prepare("INSERT INTO payments SET client_id=:client_id , client_balance=:client_balance , payment_amount=:payment_amount , payment_method=:payment_method ,
                payment_status=:status, payment_delivery=:delivery , payment_note=:payment_note , payment_create_date=:payment_create_date , payment_extra=:payment_extra , bonus=:bonus");

					if ($payment_bonus) {
						$insert25->execute(array(
							"client_id" => $payment['client_id'], "client_balance" => (($payment['balance'] + $amount) - $bonus_amount),
							"payment_amount" => $bonus_amount, "payment_method" =>  1, 'status' => 3, 'delivery' => 2, "payment_note" => "Bonus added", "payment_create_date" => date('Y-m-d H:i:s'), "payment_extra" => "Bonus added for previous payment",
							"bonus" => 1
						));
						$insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["currency"] . ' payment has been made with ' . $method['method_name'] . ' and included %' . $payment_bonus['bonus_amount'] . ' bonus.', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
					} else {
						$insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["currency"] . ' payment has been made with ' . $method['method_name'], 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
					}
					if ($update && $balance) {
						$conn->commit();
						echo 'OK';
					} else {
						$conn->rollBack();
						echo 'NO';
					}
				} else {
					$update = $conn->prepare('UPDATE payments SET payment_status=:payment_status WHERE client_id=:client_id, payment_method=:payment_method, payment_delivery=:payment_delivery, payment_extra=:payment_extra');
					$update = $update->execute(['payment_status' => 2, 'client_id' => $data['metadata']['user_id'], 'payment_method' => 6672, 'payment_delivery' => 1, 'payment_extra' => $data['metadata']['txnid']]);
				}
			}
		}
		header('Location:' . site_url('addfunds'));
	}
	exit('Invalid Request');

