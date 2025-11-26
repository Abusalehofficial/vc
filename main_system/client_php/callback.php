<?php


if(!route(1)){
    
        header("Location: " . site_url(''));

}

$method_name = route(1);
$method = $conn->prepare('SELECT * FROM payment_methods WHERE method_get=:get');
$method->execute(['get' => $method_name]);
$method = $method->fetch(PDO::FETCH_ASSOC);
$extras = json_decode($method['method_extras'], true);

  if( !file_exists( controller('callback/'.route(1))) ){
  require controller('404');
exit();
         }

    require controller('callback/'.route(1));

    