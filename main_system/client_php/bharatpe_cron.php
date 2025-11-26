<?php

        require FILES_BASE . '/lib/bharat_lib.php';
        
        
$sql = "SELECT method_extras FROM payment_methods WHERE id = :id";
$statement = $conn->prepare($sql);
$statement->execute(['id' => 6766]);
$row = $statement->fetch(PDO::FETCH_ASSOC);
$method_extras = $row['method_extras'];
$extra = json_decode($method_extras, true);


        $sec_key = $extra["sec_key"];
        $apikey = $extra["apikey"];
$bharatpeAPI = new BharatPeAPI($sec_key);

  $response_verify_otp= $bharatpeAPI->cron($apikey);

 
$data = json_decode($method_extras, true);

$new_apikey_value = $response_verify_otp;
$data['apikey'] = $new_apikey_value;

$new_json_data = json_encode($data);

 $sql = "UPDATE payment_methods SET method_extras = :method_extras WHERE id = :id";
$statement = $conn->prepare($sql);
$statement->execute(['method_extras' => $new_json_data, 'id' =>6766]);

 echo $updated_rows = $statement->rowCount();


