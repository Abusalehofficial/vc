<?php
userSecureHeader();

if (route(1) == "v2") :
    function servicePackage($type)
    {
        switch ($type) {
            case 1:
                $service_type = "Default";
                break;
            case 2:
                $service_type = "Package";
                break;
            case 3:
                $service_type = "Custom Comments";
                break;
            case 4:
                $service_type = "Custom Comments Package";
                break;
            default:
                $service_type = "Subscriptions";
                break;
        }
        return $service_type;
    }
    $smmapi = new SMMApi();
    $action = $_REQUEST["action"];
    $key = $_REQUEST["key"];
    $orderid = $_REQUEST["order"];
    if (empty($orderid)) :
        $orderid = $_REQUEST["orders"];
    endif;
    $refillid = $_REQUEST["refill"];
    $serviceid = $_REQUEST["service"];
    $quantity = $_REQUEST["quantity"];
    $link = $_REQUEST["link"];
    $username = $_REQUEST["username"];
    $posts = $_REQUEST["posts"];
    $delay = $_REQUEST["delay"];
    $otoMin = $_REQUEST["min"];
    $otoMax = $_REQUEST["max"];
    
    $comments = $_REQUEST["comments"];

// Break the comments into lines and remove empty lines
$lines = array_filter(array_map('trim', explode("\n", $comments)));

// Join the filtered lines back into a string if needed
$comments = implode("\n", $lines);

    $runs = $_REQUEST["runs"];
    $interval = $_REQUEST["interval"];
    $expiry = date("Y.m.d", strtotime($_REQUEST["expiry"]));
    $subscriptions = 0;
    $client = $conn->prepare("SELECT * FROM clients WHERE apikey=:key ");
    $client->execute(array("key" => $key));
    $clientDetail = $client->fetch(PDO::FETCH_ASSOC);
    $user = $clientDetail;
    $currency = $settings['site_currency'];

    $hashtag = $_REQUEST["hashtag"];
    $groups = $_REQUEST["groups"];
    $username = $_REQUEST["username"];
    $media = $_REQUEST["media"];
    $poll = $_REQUEST["poll"];
    $usernames = $_REQUEST["mentions_custom"];
    $hashtags = $_REQUEST["mentions_hashtags"];
    $mentions = $_REQUEST["mentions"];


    if (empty($action) || empty($key)) :
        $output = array('error' => 'Missing data', 'status' => "101");
    elseif (!$client->rowCount()) :
        $output = array('error' => 'API key invalid', 'status' => "102");
    elseif ($clientDetail["client_type"] == 1) :
        $output = array('error' => 'Your account is inactive', 'status' => "103");
    else :
        if ($action == "balance") :
            $output = array('balance' => $clientDetail["balance"], 'currency' => $currency);
        elseif ($action == "status") :
            if (!empty($orderid)) :
                // $output = array('error' => 'Order not found.', 'status' => "104");
                $orderId = explode(",", $orderid);
                if (count($orderId) == 1) {
                    $orderid = $orderId[0];
                    $order = $conn->prepare("SELECT * FROM orders WHERE order_id=:id && client_id=:client ");
                    $order->execute(array("client" => $clientDetail["client_id"], "id" => $orderid));
                    $orderDetail = $order->fetch(PDO::FETCH_ASSOC);
                    if ($order->rowCount()) :
                        if ($orderDetail["subscriptions_type"] == 2) :
                            $output = array('status' => ucwords($orderDetail["subscriptions_status"]), "posts" => $orderDetail["subscriptions_posts"]);
                        elseif ($orderDetail["dripfeed"] != 1) :
                            $output = array('status' => ucwords($orderDetail["subscriptions_status"]), "runs" => $orderDetail["dripfeed_runs"]);
                        else :
                            $output = array('charge' => $orderDetail["order_charge"], "start_count" => $orderDetail["order_start"], 'status' => ucfirst($orderDetail["order_status"]), "remains" => $orderDetail["order_remains"], "currency" => $currency);
                        endif;
                    else :
                        $output = array('error' => 'Order not found.', 'status' => "104");
                    endif;
                } else {
                    $response_array = array();
                    foreach ($orderId as $o_id) :
                        $order = $conn->prepare("SELECT * FROM orders WHERE order_id=:id && client_id=:client ");
                        $order->execute(array("client" => $clientDetail["client_id"], "id" => $o_id));
                        $orderDetail = $order->fetch(PDO::FETCH_ASSOC);
                        if ($order->rowCount()) :
                            if ($orderDetail["subscriptions_type"] == 2) :
                                $output = array('status' => ucwords($orderDetail["subscriptions_status"]), "posts" => $orderDetail["subscriptions_posts"]);
                            elseif ($orderDetail["dripfeed"] != 1) :
                                $output = array('status' => ucwords($orderDetail["subscriptions_status"]), "runs" => $orderDetail["dripfeed_runs"]);
                            else :
                                $output = array('charge' => $orderDetail["order_charge"], "start_count" => $orderDetail["order_start"], 'status' => ucfirst($orderDetail["order_status"]), "remains" => $orderDetail["order_remains"], "currency" => $currency);
                            endif;
                        else :
                            $output = array('error' => 'Order not found.', 'status' => "104");
                        endif;
                        $response_array[$o_id] = $output;
                    endforeach;
                    $output = $response_array;
                }
            else :
                $output = array('error' => 'Empty Order Id.', 'status' => "104");

            endif;

        elseif ($action == "refill") :

            $order_id = $orderid;

            $order = $conn->prepare("SELECT * FROM orders INNER JOIN services LEFT JOIN service_api ON services.service_api = service_api.id WHERE services.service_id = orders.service_id  
            AND orders.client_id=:c_id AND orders.order_id=:order_id ");
            $order->execute(array("c_id" => $clientDetail["client_id"], "order_id" => $order_id));
            $order = $order->fetch(PDO::FETCH_ASSOC);


            $refill_tasks =  $conn->prepare("SELECT * FROM tasks WHERE task_type=:type && order_id=:id ORDER BY task_id DESC LIMIT 1");
            $refill_tasks->execute(array("id" => $order_id, "type" => 1));
            $refill_tasks  = $refill_tasks->fetch(PDO::FETCH_ASSOC);


            $now = $order['service_refill_days'];
            $time = strtotime("$now day", strtotime($order['order_create']));
            $new_time = date('Y.m.d H:i:s', $time);
            $time2 = date('Y.m.d H:i:s');


            if (empty($refill_tasks)) {
                $refill_end_date = date("Y.m.d H:i:s", strtotime($order['last_check']) + 84600);
            } else {
                $refill_end_date = date("Y.m.d H:i:s", strtotime($refill_tasks["task_created_at"]) + 84600);
            }



            if ($new_time > $time2 && $order["service_refill_days"] != 0 && $order["service_refill"] == 1  && $time2 > $refill_end_date) {
                $refillAllowed = true;
            } else {
                $refillAllowed = false;
            }




            // if (
            //     !countRow(['table' => 'tasks', 'where' => ['task_type' => 1, 'task_status' => 'pending', 'client_id' => $clientDetail["client_id"], 'order_id' => $order_id]]) &&
            //     !countRow(['table' => 'tasks', 'where' => ['task_type' => 1, 'task_status' => 'inprogress', 'client_id' => $clientDetail["client_id"], 'order_id' => $order_id]]) &&
            //     countRow(['table' => 'orders', 'where' => ['order_id' => $order_id, 'client_id' => $clientDetail["client_id"]]])
            //     && $refillAllowed
            // ) :

            if (
                countRow(['table' => 'tasks', 'where' => ['task_type' => 1, 'task_status' => 'pending', 'client_id' => $clientDetail["client_id"], 'order_id' => $order_id]])
            ) :
                $output = array('error' => 'Refill pending', 'status' => 200);
            elseif (
                countRow(['table' => 'tasks', 'where' => ['task_type' => 1, 'task_status' => 'inprogress', 'client_id' => $clientDetail["client_id"], 'order_id' => $order_id]])
            ) :
                $output = array('error' => 'Refill inprogress', 'status' => 200);
            elseif (
                !countRow(['table' => 'orders', 'where' => ['order_id' => $order_id, 'client_id' => $clientDetail["client_id"]]])
            ) :
                $output = array('error' => 'Incorrect order ID', 'status' => 200);
            elseif (!$refillAllowed) :
                $output = array('error' => 'Refill not allowed , try again later', 'status' => 200);
            elseif (
                !countRow(['table' => 'tasks', 'where' => ['task_type' => 1, 'task_status' => 'pending', 'client_id' => $clientDetail["client_id"], 'order_id' => $order_id]]) &&
                !countRow(['table' => 'tasks', 'where' => ['task_type' => 1, 'task_status' => 'inprogress', 'client_id' => $clientDetail["client_id"], 'order_id' => $order_id]]) &&
                countRow(['table' => 'orders', 'where' => ['order_id' => $order_id, 'client_id' => $clientDetail["client_id"]]])
                && $refillAllowed
            ) :

                //check service refill is manual or automatic
                if ($order["service_refill_mode"] == 2 && $order["api_service"] != 0) {
                    // automatic refill will be sent to provider and response added to table


                    $res = placeRefill($order);
                    $check_refill_status = 1;

                    if ($res["refill_id"] != "-") {
                        $status = "inprogress";
                        $check_refill_status = 2;
                    } elseif ($res["refill_id"] != "-" || $res["refill_status"] != "-") {
                        $status = "completed";
                    } elseif ($res["error"] != "-") {
                        $status = "rejected";
                    } else {
                        $status = "error";
                    }


                    $res = json_encode($res);

                    $insert = $conn->prepare("INSERT INTO tasks SET client_id=:c_id, order_id=:o_id,
      service_id=:s_id, task_type=:type, task_api=:api , task_response=:res , task_status=:status ,
       task_by=:task_by  , check_refill_status=:check_refill_status ");
                    $insert->execute(array(
                        "c_id" => $order["client_id"], "o_id" => $order["order_id"],
                        "s_id" => $order["service_id"], "type" => 1, "api" => 2, "res" => $res,
                        "status" => $status, "task_by" => "api", "check_refill_status" => $check_refill_status
                    ));

                    if ($insert) : $last_refill_id = $conn->lastInsertId();
                    endif;

                    if ($status == "completed") {
                        $output = array('refill' => $last_refill_id, 'status' => 200, 'message' => "Refill has been placed successfully!");
                    } else {
                        $output = array('error' => "There are no more refills available for today.");
                    }
                } else {

                    $insert = $conn->prepare("INSERT INTO tasks SET client_id=:c_id, order_id=:o_id,
       service_id=:s_id, task_type=:type, task_api=:api , task_by=:task_by ");
                    $insert->execute(array(
                        "c_id" => $order["client_id"], "o_id" => $order["order_id"],
                        "s_id" => $order["service_id"], "type" => 1, "api" => 1, "task_by" => "api"
                    ));


                    if ($insert) : $last_refill_id = $conn->lastInsertId();
                    endif;
                    $output = array('refill' => $last_refill_id, 'status' => 200, 'message' => "Refill has been placed successfully!");
                }
            else :
                $output = array('error' => 'Something went wrong');
            endif;

        elseif ($action == "refill_status") :

            $refill = $conn->prepare("SELECT * FROM tasks WHERE task_type=1 , task_id=:task_id && client_id=:client ");
            $refill->execute(array("client" => $clientDetail["client_id"], "task_id" => $refillid));
            $refillDetail = $refill->fetch(PDO::FETCH_ASSOC);



            if (!empty($refillDetail)) :
                $output = array('status' => $refillDetail["task_status"]);
            else :
                $output = array('error' => 'Refill not found');
            endif;

        elseif ($action == "cancel") :

            $order_id = $orderid;

            if (
                !countRow(['table' => 'tasks', 'where' => ['task_type' => 2, 'task_status' => 'rejected', 'client_id' => $clientDetail["client_id"], 'order_id' => $order_id]]) &&
                !countRow(['table' => 'tasks', 'where' => ['task_type' => 2, 'task_status' => 'completed', 'client_id' => $clientDetail["client_id"], 'order_id' => $order_id]]) &&
                !countRow(['table' => 'tasks', 'where' => ['task_type' => 2, 'task_status' => 'pending', 'client_id' => $clientDetail["client_id"], 'order_id' => $order_id]]) &&
                countRow(['table' => 'orders', 'where' => ['order_id' => $order_id, 'client_id' => $clientDetail["client_id"]]])
            ) {


                $order = $conn->prepare("SELECT * FROM orders INNER JOIN services LEFT JOIN service_api ON services.service_api = service_api.id WHERE services.service_id = orders.service_id  
                AND orders.client_id=:c_id AND orders.order_id=:order_id ");
                $order->execute(array("c_id" => $clientDetail["client_id"], "order_id" => $order_id));
                $order = $order->fetch(PDO::FETCH_ASSOC);

                //send api req to cancel , if api doesnt accepts req , add it as a manual request
                $smmapi   = new SMMApi();

                $get_cancel = $smmapi->action(array('key' => $order["api_key"], 'action' => 'cancel', 'order' => $order["api_orderid"]), $order["api_url"]);


                $get_cancel = json_decode(json_encode($get_cancel), true);

                $status = $get_cancel['status'];
                $message = $get_cancel['message'];
                $error = $order["api_serviceid"] == 0 ? "Manual order" : $get_cancel["error"];
                $cancel_id = $get_cancel["id"];

                $order_error = json_decode($order["order_error"], true);

                if ($order["api_serviceid"] && $order["api_orderid"] == 0 && $order_error != "-") {
                    $fail = $order_error;
                }


                $cancel_data = array();


                if ((isset($status) && $status == "Success") || !empty($cancel_id) && !empty($get_cancel)) {



                    //cancel request placed successfully

                    $res = array(
                        "status" => $status,
                        "message" => empty($message) ? "-" : $message,
                        "cancel_id" => empty($cancel_id) ? 0 : $cancel_id,
                    );
                    $res = json_encode($res);

                    $insert = $conn->prepare("INSERT INTO tasks SET client_id=:c_id, order_id=:o_id,
                  service_id=:s_id, task_type=:type, task_api=:api , task_response=:res , task_status=:status , task_by=:task_by  ");
                    $insert->execute(array(
                        "c_id" => $order["client_id"], "o_id" => $order["order_id"],
                        "s_id" => $order["service_id"], "type" => 1, "api" => 2, "res" => $res, "status" => "completed", "task_by" => "api"
                    ));
                } else {
                    //cancel request not placed 
                    if (isset($fail)) {
                        $res = array(
                            "error" => $error,
                            "order_status" => $fail["error"]
                        );
                    } else {
                        $res = array(
                            "error" => $error
                        );
                    }

                    $res = json_encode($res);

                    $insert = $conn->prepare("INSERT INTO tasks SET client_id=:c_id, order_id=:o_id,
                  service_id=:s_id, task_type=:type, task_api=:api , task_response=:res , task_by=:task_by ");
                    $insert->execute(array(
                        "c_id" => $order["client_id"], "o_id" => $order["order_id"],
                        "s_id" => $order["service_id"], "type" => 2, "api" => 1, "res" => $res, "task_by" => "api"
                    ));
                }

                $output = array('status' => 'success', 'message' => "Cancellation request added, we'll try our best to cancel it.");
            } else {
                $output = array('error' => 'Order not found / Not eligible for cancellation');
            }



        elseif ($action == "services") :
            $servicesRows = $conn->prepare("SELECT * FROM services INNER JOIN categories ON categories.category_id=services.category_id WHERE categories.category_type=:type2 && services.service_type=:type && service_status_admin=:service_status_admin  ORDER BY categories.category_line,services.service_line ASC ");
            $servicesRows->execute(array("type" => 2, "type2" => 2, "service_status_admin" => 2));
            $servicesRows = $servicesRows->fetchAll(PDO::FETCH_ASSOC);
            $services = [];
            foreach ($servicesRows as $serviceRow) {
                $search = $conn->prepare("SELECT * FROM clients_service WHERE service_id=:service && client_id=:c_id ");
                $search->execute(array("service" => $serviceRow["service_id"], "c_id" => $clientDetail["client_id"]));
                $search2 = $conn->prepare("SELECT * FROM clients_category WHERE category_id=:category && client_id=:c_id ");
                $search2->execute(array("category" => $serviceRow["category_id"], "c_id" => $clientDetail["client_id"]));
                if (($serviceRow["service_secret"] == 2 || $search->rowCount()) && ($serviceRow["category_secret"] == 2 || $search2->rowCount())) :
                    $s["rate"] = client_price($serviceRow["service_id"], $clientDetail["client_id"]);
                    
                    
                          if($user["discount_percentage"]){
            
            $percentage=$s["rate"]*$user["discount_percentage"]/100;
        $s["rate"] = $s["rate"]-$percentage;

}
      

                    $s['service'] = $serviceRow["service_id"];
                    $s['category'] = $serviceRow["category_name"];
                    $s['name'] = $serviceRow["service_name"];
                    $s['type'] = servicePackage($serviceRow["service_package"]);
                    $s['min'] = $serviceRow["service_min"];
                    $s['max'] = $serviceRow["service_max"];
                    $s['desc'] = $serviceRow["service_description"];
                    $s['refill'] = $serviceRow["service_refill"];
                    array_push($services, $s);
                endif;
            }
            $output = $services;
        elseif ($action == "add") :



            $clientBalance = $clientDetail["balance"];
            $serviceDetail = $conn->prepare("SELECT * FROM services INNER JOIN categories ON categories.category_id=services.category_id LEFT JOIN service_api ON service_api.id=services.service_api WHERE services.service_id=:id ");
            $serviceDetail->execute(array("id" => $serviceid));
            $serviceDetail = $serviceDetail->fetch(PDO::FETCH_ASSOC);
            $search = $conn->prepare("SELECT * FROM clients_service WHERE service_id=:service && client_id=:c_id ");
            $search->execute(array("service" => $serviceid, "c_id" => $clientDetail["client_id"]));
            $search2 = $conn->prepare("SELECT * FROM clients_category WHERE category_id=:category && client_id=:c_id ");
            $search2->execute(array("category" => $serviceDetail["category_id"], "c_id" => $clientDetail["client_id"]));
            if ($serviceDetail["want_username"] == 2) :
                $private_type = "username";
                $countRow = $conn->prepare("SELECT * FROM orders WHERE order_url=:url && service_id = :service_id && ( order_status=:statu || order_status=:statu2 || order_status=:statu3 ) && dripfeed=:dripfeed && subscriptions_type=:subscriptions_type ");
                $countRow->execute(array("url" => $link, "service_id" => $serviceDetail["service_id"], "statu" => "pending", "statu2" => "inprogress", "statu3" => "processing", "dripfeed" => 1, "subscriptions_type" => 1));
                $countRow = $countRow->rowCount();
            else :
                $private_type = "url";
                if (substr($link, 0, 7) == "http://") :
                    $link = substr($link, 7);
                endif;
                if (substr($link, 0, 8) == "https://") :
                    $link = substr($link, 8);
                endif;
                if (substr($link, 0, 4) == "www.") :
                    $link = substr($link, 4);
                endif;
                $countRow = $conn->prepare("SELECT * FROM orders WHERE order_url LIKE :url && service_id = :service_id && ( order_status=:statu || order_status=:statu2 || order_status=:statu3 ) && dripfeed=:dripfeed && subscriptions_type=:subscriptions_type ");
                $countRow->execute(array("url" => '%' . $link . '%', "service_id" => $serviceDetail["service_id"], "statu" => "pending", "statu2" => "inprogress", "statu3" => "processing", "dripfeed" => 1, "subscriptions_type" => 1));
                $countRow = $countRow->rowCount();
            endif;
            $link = $_REQUEST["link"];


            if (($serviceDetail["service_secret"] == 2 || $search->rowCount()) && $serviceDetail["category_type"] == 2 && $serviceDetail["service_type"] == 2 && $serviceDetail["service_status_admin"] == 2 && ($serviceDetail["category_secret"] == 2 || $search2->rowCount())) :



                if ($serviceDetail["service_package"] == 3 || $serviceDetail["service_package"] == 4) :
                    $comments_array = preg_split('/\n|\r/', $comments);
                    $comments_array = array_filter($comments_array);
                    $quantity = count($comments_array);
                    $extras   = json_encode(["comments" => $comments]);
                    $subscriptions_status = "active";
                    $subscriptions = 1;
                endif;


                if ($serviceDetail["service_package"] != 2 && $serviceDetail["service_package"] != 11) :
                    $price = client_price($serviceDetail["service_id"], $clientDetail["client_id"]) / 1000 * $quantity;
                else :
                    $price = client_price($serviceDetail["service_id"], $clientDetail["client_id"]);
                endif;

                if ($runs && $interval) :
                    $dripfeed = 2;
                    $totalcharges = $price * $runs;
                    $totalquantity = $quantity * $runs;
                    $price = $price * $runs;
                else :
                    $dripfeed = 1;
                    $totalcharges = "";
                    $totalquantity = "";
                endif;

                if (!isServicePriceCorrect($serviceDetail)) :
                    $error    = 1;
                    $errorText = "Something went wrong, Try again!";
                elseif (($runs && empty($interval)) || ($interval && empty($runs))) :
                    $output = array('error' => "You must fill in the required fields.", 'status' => 107);
                elseif ($serviceDetail["service_package"] == 1 && (empty($link) || empty($quantity))) :
                    $output = array('error' => "You must fill in the required fields.", 'status' => 107);
                elseif ($serviceDetail["service_package"] == 2 && empty($link)) :
                    $output = array('error' => "You must fill in the required fields.", 'status' => 107);
                elseif (($serviceDetail["service_package"] == 14 || $serviceDetail["service_package"] == 15) && empty($link)) :
                    $output = array('error' => "You must fill in the required fields.", 'status' => 107);
                elseif ($serviceDetail["service_package"] == 3 && (empty($link) || empty($comments))) :
                    $output = array('error' => "You must fill in the required fields.", 'status' => 107);
                elseif ($serviceDetail["service_package"] == 4 && (empty($link) || empty($comments))) :
                    $output = array('error' => "You must fill in the required fields.", 'status' => 107);
                elseif (($serviceDetail["service_package"] != 11 && $serviceDetail["service_package"] != 12 && $serviceDetail["service_package"] != 13 && $serviceDetail["service_package"] != 2) && (($dripfeed == 2 && $totalquantity < $serviceDetail["service_min"]) || ($dripfeed == 1 && $quantity < $serviceDetail["service_min"]))) :
                    $output = array('error' => "Enter a value above the minimum number.", 'status' => 108);
                elseif (($serviceDetail["service_package"] != 11 && $serviceDetail["service_package"] != 12 && $serviceDetail["service_package"] != 13) && (($dripfeed == 2 && $totalquantity > $serviceDetail["service_max"]) || ($dripfeed == 1 && $quantity > $serviceDetail["service_max"]))) :
                    $output = array('error' => "Maximum number exceed.", 'status' => 109);
                elseif (($serviceDetail["service_package"] == 11 || $serviceDetail["service_package"] == 12 || $serviceDetail["service_package"] == 13) && empty($username)) :
                    $output = array('error' => "You must fill in the required fields.", 'status' => 107);
                elseif (($serviceDetail["service_package"] == 11 || $serviceDetail["service_package"] == 12 || $serviceDetail["service_package"] == 13) && empty($otoMin)) :
                    $output = array('error' => "You must fill in the required fields.", 'status' => 107);
                elseif (($serviceDetail["service_package"] == 11 || $serviceDetail["service_package"] == 12 || $serviceDetail["service_package"] == 13) && empty($otoMax)) :
                    $output = array('error' => "You must fill in the required fields.", 'status' => 107);
                elseif (($serviceDetail["service_package"] == 11 || $serviceDetail["service_package"] == 12 || $serviceDetail["service_package"] == 13) && empty($posts)) :
                    $output = array('error' => "You must fill in the required fields.", 'status' => 107);
                elseif (($serviceDetail["service_package"] == 11 || $serviceDetail["service_package"] == 12 || $serviceDetail["service_package"] == 13) && $otoMax < $otoMin) :
                    $output = array('error' => "Minimum number can not be much than maximum number.", 'status' => 110);
                elseif (($serviceDetail["service_package"] == 11 || $serviceDetail["service_package"] == 12 || $serviceDetail["service_package"] == 13) && $otoMin < $serviceDetail["service_min"]) :
                    $output = array('error' => "Enter a value above the minimum number.", 'status' => 111);
                elseif (($serviceDetail["service_package"] == 11 || $serviceDetail["service_package"] == 12 || $serviceDetail["service_package"] == 13) && $serviceDetail["multiple_of"] != "NONE" && ($quantity % $service_detail["multiple_of"]) != 0) :
                    $output = array('error' => "Quantity must be multiple of " . $service_detail["multiple_of"], 'status' => 111);
                elseif (($serviceDetail["service_package"] == 11 || $serviceDetail["service_package"] == 12 || $serviceDetail["service_package"] == 13) && $otoMax > $serviceDetail["service_max"]) :
                    $output = array('error' => "Maximum number exceed", 'status' => 112);
                elseif ($serviceDetail["instagram_second"] == 1 && $countRow && ($serviceDetail["service_package"] != 11 && $serviceDetail["service_package"] != 12 && $serviceDetail["service_package"] != 13 && $serviceDetail["service_package"] != 14 && $serviceDetail["service_package"] != 15)) :
                    $output = array('error' => "You cannot start a new order with the same link that is active processing order.", 'status' => 113);
                elseif (instagramProfilecheck(["type" => $private_type, "url" => $link, "return" => "private"]) && $serviceDetail["instagram_private"] == 2) :
                    $output = array('error' => "Instagram profile is hidden.", 'status' => 114);
                elseif (($price > $clientDetail["balance"]) && $clientDetail["balance_type"] == 2) :
                    $output = array('error' => "Your balance is insufficient.", 'status' => 113);
                // elseif (($clientDetail["balance"] - $price < "-" . $clientDetail["debit_limit"]) && $clientDetail["balance_type"] == 1) :
                //     $output = array('error' => "Your balance is insufficient.", 'status' => 113);
                else :
                    if (!$runs) :
                        $runs = 1;
                    endif;

                    if ($serviceDetail["service_package"] == 3 || $serviceDetail["service_package"] == 4) :
                        $extras   = json_encode(["comments" => $comments]);
                    elseif ($serviceDetail["service_package"] == 11 || $serviceDetail["service_package"] == 12 || $serviceDetail["service_package"] == 13) :
                        $quantity = $otoMin . "-" . $otoMax;
                        $price = 0;
                        $extras = json_encode([]);
                        $subscriptions = 1;
                    elseif ($serviceDetail["service_package"] == 14 || $serviceDetail["service_package"] == 15) :
                        $quantity = $serviceDetail["service_min"];
                        $price = service_price($service["service_id"]);
                        $posts = $serviceDetail["service_autopost"];
                        $delay = 0;
                        $time = '+' . $serviceDetail["service_autotime"] . ' days';
                        $expiry = date('Y-m-d H:i:s', strtotime($time));
                        $otoMin = $serviceDetail["service_min"];
                        $otoMax = $serviceDetail["service_min"];
                        $extras = json_encode([]);
                    else :
                        $posts = 0;
                        $delay = 0;
                        $expiry = "1970-01-01";
                        $extras = json_encode([]);
                        $subscriptions_status = "active";
                        $subscriptions = 1;
                    endif;
                    if ($serviceDetail["start_count"] == "none") :
                        $start_count = "0";
                    else :
                        $start_count = instagramCount(["type" => $private_type, "url" => $link, "search" => $serviceDetail["start_count"]]);
                    endif;
                    
                    if($serviceDetail["service_package"] == 2){
                        $quantity = $serviceDetail["service_min"];
                    }


                    $custom_o_id = randomOrderId();
                    $currency_symbol = getCurrencySymbol($settings["site_currency"]);
      if($user["discount_percentage"]){
            
            $percentage=$price*$user["discount_percentage"]/100;
        $price = $price-$percentage;

}
      

                    if ($serviceDetail["service_api"] == 0) :


                        $conn->beginTransaction();
                        $insert = $conn->prepare("INSERT INTO orders SET order_id=:custom_o_id, order_where=:order_where, order_start=:count, order_extras=:extra,  order_profit=:profit, order_error=:error, order_detail=:detail , client_id=:c_id, service_id=:s_id, order_quantity=:quantity, order_charge=:price, order_url=:url, order_create=:create, last_check=:last ");
                        $insert = $insert->execute(array("custom_o_id" => $custom_o_id, "order_where" => "api", "count" => $start_count, "c_id" => $clientDetail["client_id"], "detail" => "cronpending", "extra" => $extras, "error" => "-", "s_id" => $serviceDetail["service_id"], "quantity" => $quantity, "price" => $price, "profit" => $price, "url" => $link, "create" => date("Y.m.d H:i:s"), "last" => date("Y.m.d H:i:s")));
                        if ($insert) :
                            $last_id = $conn->lastInsertId();
                            
                             // Balance Deduct
        $update = $conn->prepare("UPDATE clients SET balance = balance - :price, spent = spent + :price WHERE client_id=:id");
        $update = $update->execute(array(
            "price" => $price,
            "id"    => $clientDetail["client_id"]
        ));
                            
                            if ($settings["alert_newmanuelservice"] == 2) :
                                sendMail([
                                    "subject" => "New order available.",
                                    "body" => "Your website has a new manual order. Order Id #" . $last_id,
                                    "mail" => $settings["admin_mail"]
                                ]);
                            endif;
                        endif;

                        $insert2 = $conn->prepare("INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date");
                        $message = "A new order #$last_id of " . $price . $currency_symbol . " was created through the API. Current Balance => " . $clientDetail["balance"] . $currency_symbol;
                        $insert2 = $insert2->execute(array("c_id" => $clientDetail["client_id"], "action" => $message, "ip" => GetIP(), "date" => date("Y-m-d H:i:s")));
                        if ($insert && $insert2) :
                            $conn->commit();
                            $output = array('status' => 200, 'order' => $last_id);
                        else :
                            $conn->rollBack();
                            $output = array('error' => "There was an error while creating your order.", 'status' => 114);
                        endif;

                    else :

                        $conn->beginTransaction();
                        $insert = $conn->prepare("INSERT INTO orders SET order_id=:custom_o_id, order_where=:order_where, order_error=:error, order_detail=:detail, client_id=:c_id,
                            service_id=:s_id, order_quantity=:quantity, order_charge=:price, order_url=:url, order_create=:create, order_extras=:extra, last_check=:last_check,
                            order_api=:api, api_serviceid=:api_serviceid, subscriptions_status=:s_status,
                            subscriptions_type=:subscriptions, subscriptions_username=:username, subscriptions_posts=:posts, subscriptions_delay=:delay, subscriptions_min=:min,
                            subscriptions_max=:max, subscriptions_expiry=:expiry
                            ");
                        $insert = $insert->execute(array("custom_o_id" => $custom_o_id, "order_where" => "api", "c_id" => $clientDetail["client_id"], "detail" => "cronpending", "error" => "-", "s_id" => $serviceDetail["service_id"], "quantity" => $quantity, "price" => $price / $runs, "url" => $link, "create" => date("Y.m.d H:i:s"), "extra" => $extras, "last_check" => date("Y.m.d H:i:s"), "api" => $serviceDetail["id"], "api_serviceid" => $serviceDetail["api_service"], "s_status" => $subscriptions_status, "subscriptions" => $subscriptions, "username" => $username, 'posts' => $posts, "delay" => $delay, "min" => $otoMin, "max" => $otoMax, "expiry" => $expiry));

                        if ($insert) :
                            $last_id = $conn->lastInsertId();
                            
                            // Balance Deduct
        $update = $conn->prepare("UPDATE clients SET balance = balance - :price, spent = spent + :price WHERE client_id=:id");
        $update = $update->execute(array(
            "price" => $price,
            "id"    => $clientDetail["client_id"]
        ));
        
                        endif;
                        $insert2 = $conn->prepare("INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date");
                        $message = "A new order #$last_id of " . $price . $currency_symbol . " was created through the API. Current Balance => " . $clientDetail["balance"] . $currency_symbol;
                        $insert2 = $insert2->execute(array("c_id" => $clientDetail["client_id"], "action" => $message, "ip" => GetIP(), "date" => date("Y-m-d H:i:s")));
                        if ($insert  && $insert2) :
                            $conn->commit();
                            $output = array('status' => 100, 'order' => $last_id);
                        else :
                            $conn->rollBack();
                            $output = array('error' => "There was an error while creating your order", 'status' => 114);
                        endif;
                    endif;
                endif;
            else :
                $output = array('error' => 'Service not found or inactive', 'status' => "105");
            endif;
        else :
            $output = array('error' => 'Incorrect method name');
        endif;

    endif;
    header('Content-Type:application/json; charset=UTF-8;');

    print_r(json_encode($output));
    exit();
 

elseif (!route(1)) :
    $title .= " API Documentation";

    $apiPageContent[] = [];

    $apiPageContent["apiData"] = [];

    $services = [
        "heading" => "Service list",
        "parameters" => [
            "key" => "Your API key",
            "action" => "services",
        ],
        "code" => '[
    {           
        "service": 1,
        "name": "Followers",
        "type": "Default",
        "category": "First Category",
        "rate": "0.90",
        "min": "50",
        "max": "10000",
        "desc" : "Some Description",
        "refill": true
    },
    {
        "service": 2,
        "name": "Comments",
        "type": "Custom Comments",
        "category": "Second Category",
        "rate": "8",
        "min": "10",
        "desc" : "Some Description",
        "max": "1500",
        "refill": false
    }
]'
    ];
    array_push($apiPageContent["apiData"], $services);


    $newOrder = [
        "heading" => "Add order",
        "parameters" => [
            "key" => "Your API key",
            "action" => "add",
            "service" => "Service ID",
            "link" => "Link",
            "quantity" => "Quantity",
            "runs (optional)" => "Runs to deliver",
            "interval (optional)" => "Interval in minutes",
        ],
        "code" => '{
    "order": 23501
}'
    ];
    array_push($apiPageContent["apiData"], $newOrder);

    $orderStatus = [
        "heading" => "Order status",
        "parameters" => [
            "key" => "Your API key",
            "action" => "status",
            "order" => "Order ID",
        ],
        "code" => '{
    "charge": "0.27819",
    "start_count": "3572",
    "status": "Partial",
    "remains": "157",
    "currency": "USD"
}'
    ];
    array_push($apiPageContent["apiData"], $orderStatus);

    $multipleOrderStatus = [
        "heading" => "Multiple orders status",
        "parameters" => [
            "key" => "Your API key",
            "action" => "status",
            "orders" => "Order IDs separated by comma",
        ],
        "code" => '{
     "1": {
        "charge": "0.27819",
        "start_count": "3572",
        "status": "Partial",
        "remains": "157",
        "currency": "USD"
     },
     "10": {
        "error": "Incorrect order ID"
     },
     "100": {
        "charge": "1.44219",
        "start_count": "234",
        "status": "In progress",
        "remains": "10",
        "currency": "USD"
     }
}'
    ];
    array_push($apiPageContent["apiData"], $multipleOrderStatus);

    $createRefill = [
        "heading" => "Create refill",
        "parameters" => [
            "key" => "Your API key",
            "action" => "refill",
            "order" => "Order ID",
        ],
        "code" => '{
    "refill": "1"
}'
    ];
    array_push($apiPageContent["apiData"], $createRefill);


    $refillStatus = [
        "heading" => "Get refill status",
        "parameters" => [
            "key" => "Your API key",
            "action" => "refill_status",
            "refill" => "Refill ID",
        ],
        "code" => '{
    "status": "Completed"
}'
    ];
    array_push($apiPageContent["apiData"], $refillStatus);


    $userBalance = [
        "heading" => "User balance",
        "parameters" => [
            "key" => "Your API key",
            "action" => "balance",
        ],
        "code" => '{
    "balance": "100.84292",
    "currency": "USD"}'
    ];
    array_push($apiPageContent["apiData"], $userBalance);


    $apiPageContent["response"] = "Example Response";
    $apiPageContent["parameters"] = "Parameters";
    $apiPageContent["description"] = "Description";


else :
    header("Location:" . site_url());
endif;
