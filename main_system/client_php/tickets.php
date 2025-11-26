<?php

$title .= $languageArray["tickets.title"];

userSecureHeader();



if ($_GET["message"]) {
    $_SESSION["data"]['message']  = urldecode($_GET["message"]);
}


if (route(1) == "old_tickets") :
    p("okay");
endif;



if ($_POST["action"] == "getFields" && $_POST["selectedOption"]) :

    $selectedOption = $_POST["selectedOption"];

    if ($selectedOption == 1) :

        $options = ["Refill", "Cancel", "Speed Up", "Restart", "Not Started", "Mark as completed without done", "Other"];

        $html = '<div class="form-group" id="order-group" style="display: block;"><label>Order ID: [For multiple orders, please separate them using comma. (example: 31851525,31851511,31851509)]</label><input type="text" class="form-control" value="' . $_SESSION["tickets_data"]["Subject"]["order_id"] . '" name="Subject[order_id]" id="orderid"><label style="margin-top:15px">Request</label><select class="form-control" id="want" name="Subject[order_request]">';

        foreach ($options as $option) :
            if (trim($_SESSION["tickets_data"]["Subject"]["order_request"]) == trim($option)) :
                $selected = " selected ";
            else : $selected = " ";
            endif;
            $html .= '<option ' . $selected . ' value="' . $option . '">' . $option . '</option>';
        endforeach;

        $html .= '</select></div>';
    elseif ($selectedOption == 2) :
        $html = '<div class="form-group" id="payment-group"><label>Payment</label><select class="form-control" id="payment" name="Subject[payment_method]">';

        $paymentsList = $conn->prepare("SELECT method_extras FROM payment_methods WHERE method_type=:type && method_status=2 && id!=:id6  ORDER BY method_line ASC ");
        $paymentsList->execute(array("type" => 2, "id6" => 6));
        $paymentsList = $paymentsList->fetchAll(PDO::FETCH_ASSOC);


        foreach ($paymentsList as $method) :

            $method = json_decode($method["method_extras"], true);

            $session_name = strtolower(trim($_SESSION["tickets_data"]["Subject"]["payment_method"]));
            $payment_method_name = strtolower(trim($method["name"]));

            if ($session_name == $payment_method_name) :
                $selected = " selected ";
            else : $selected = " ";
            endif;

            $html .= '<option ' . $selected . ' value="' . $method["name"] . '">' . $method["name"] . '</option>';

        endforeach;

        $html .= '</select>
        <label style="margin-top:15px">Payment / Transaction ID(s):</label>
        <input type="text" class="form-control" name="Subject[transaction_id]" value="' . $_SESSION["tickets_data"]["Subject"]["transaction_id"] . '" id="PaymentID">
        <label style="margin-top:15px">Payment / Email ID(s):</label>
        <input type="text" class="form-control" name="Subject[email_id]" value="' . $_SESSION["tickets_data"]["Subject"]["email_id"] . '" id="emailID">
        <label style="margin-top:15px">Add Amount</label>
        <input type="number" class="form-control" name="Subject[payment_amount]" value="' . $_SESSION["tickets_data"]["Subject"]["payment_amount"] . '" id="addamount"></div>';
    else :
        $html = '';
    endif;

    echo json_encode($html);
    exit();
elseif ($_POST["action"] == "getFields" && !$_POST["selectedOption"]) :
    echo json_encode("");
    exit();
endif;




if (!route(1)) {




    $ticket_subjects = $conn->prepare("SELECT * FROM ticket_subjects ORDER BY subject_line ASC");
    $ticket_subjects->execute(array());
    $ticket_subjects = $ticket_subjects->fetchAll(PDO::FETCH_ASSOC);

    $ticketSubjectList = [];

    foreach ($ticket_subjects as $ticket_subject) {
        $o["first_id"]    = $ticket_subject[0]["subject_id"];
        $o["subject_id"]    = $ticket_subject["subject_id"];
        $o["subject"]    = $ticket_subject["subject"];
        array_push($ticketSubjectList, $o);
    }

    $tickets = $conn->prepare("SELECT * FROM tickets WHERE client_id=:c_id AND is_deleted=1 ORDER BY lastupdate_time DESC ");
    $tickets->execute(array("c_id" => $user["client_id"]));
    $tickets = $tickets->fetchAll(PDO::FETCH_ASSOC);
    $ticketList = [];
    foreach ($tickets as $ticket) {
        foreach ($ticket as $key => $value) {
            if ($key == "status") {
                $t[$key] = $languageArray["tickets.status." . $value];
            } else {
                $t[$key] = $value;
            }
        }
        array_push($ticketList, $t);
    }

    $user["ticket_data"] = [
        'subject' => $_SESSION["tickets_data"]["Subject"]["main"],
        'message' => $_SESSION["tickets_data"]["message"],
    ];

    if ($_POST) {



        foreach ($_POST as $key => $value) {
            $_SESSION["tickets_data"][$key]  = $value;
        }

        $message  = htmlspecialchars($_POST["message"]);
        $message = str_replace("\n", "<br />", $message);

        if ($settings["ticket_version"] == 2  && $settings["site_theme"] == "boost") :
            $subject = $_POST["subject"];

        elseif ($settings["ticket_version"] == 2) :

            if ($_POST["Subject"]["main"] == 1) :
                $subject = "Order Id " . $_POST["Subject"]["order_id"] . " - " . $_POST["Subject"]["order_request"];
                $messageExtra = 'Order Issue :<br/>
                Order Id : ' . $_POST["Subject"]["order_id"] . '<br/>
                Order Request : ' . $_POST["Subject"]["order_request"] . '<br/>';
                $message = $messageExtra . "Message : " .  $message;
            elseif ($_POST["Subject"]["main"] == 2) :
                $subject = "Payment with " . $_POST["Subject"]["payment_method"] . " - Transaction Id : " . $_POST["Subject"]["transaction_id"];
                $messageExtra = 'Payment Issue :<br/>
                Payment Method : ' . $_POST["Subject"]["payment_method"] . '<br/>
                Payment Transaction Id : ' . $_POST["Subject"]["transaction_id"] . '<br/>
                Payment Email Id : ' . $_POST["Subject"]["email_id"] . '<br/>
                Payment Amount : ' . $_POST["Subject"]["payment_amount"] . '<br/>';
                $message = $messageExtra . "Message : " .  $message;
            else :
                foreach ($ticket_subjects as $t_subject) :
                    if ($t_subject["subject_id"] == $_POST["Subject"]["main"]) :
                        $subject = $t_subject["subject"];
                        break;
                    endif;
                endforeach;
            endif;
        else :
            $subject = $_POST["subject"];
        endif;

        if ($settings["ticket_version"] == 2) {
            $auto_reply = $_POST["Subject"]["main"];
        }


        if ($settings["ticket_version"] == 2) {
            $message = str_replace(" ,", "<br />", $message);
        }

        if (empty($subject)) {
            $error    = 1;
            $errorText = $languageArray["error.tickets.new.subject"];
        } elseif (strlen(str_replace(' ', '', $message)) < 10) {
            $error    = 1;
            $errorText = str_replace("{length}", "10", $languageArray["error.tickets.new.message.length"]);
        } elseif (open_ticket($user["client_id"]) >= $settings["open_tickets"]) {
            $error    = 1;
            $errorText = str_replace("{limit}", $settings["open_tickets"], $languageArray["error.tickets.new.limit"]);
        } else {
            $conn->beginTransaction();
            $insert = $conn->prepare("INSERT INTO tickets SET client_id=:c_id, subject=:subject, time=:time, lastupdate_time=:last_time ");
            $insert = $insert->execute(array("c_id" => $user["client_id"], "subject" => $subject, "time" => date("Y.m.d H:i:s"), "last_time" => date("Y.m.d H:i:s")));
            if ($insert) {
                $ticket_id = $conn->lastInsertId();
            }


            $insert2 = $conn->prepare("INSERT INTO ticket_reply SET ticket_id=:t_id, client_id=:client_id ,  message=:message, time=:time ");
            $insert2 = $insert2->execute(array("t_id" => $ticket_id, "client_id" => $user["client_id"], "message" => $message, "time" => date("Y.m.d H:i:s")));
            $insert3 = $conn->prepare("INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ");
            $insert3 = $insert3->execute(array("c_id" => $user["client_id"], "action" => "New support request created #" . $ticket_id, "ip" => GetIP(), "date" => date("Y-m-d H:i:s")));



            $post = $conn->prepare("SELECT * FROM ticket_subjects WHERE subject_id=:subject_id and auto_reply=:auto_reply");
            $post->execute(array("subject_id" => $auto_reply, "auto_reply" => 1));
            $post = $post->fetch(PDO::FETCH_ASSOC);



            if ($post) {

                $insert4 = $conn->prepare("INSERT INTO ticket_reply SET ticket_id=:t_id, support=:support, message=:message, time=:time ");
                $insert4 = $insert4->execute(array("t_id" => $ticket_id, "support" => 2, "message" => $post["content"], "time" => date("Y.m.d H:i:s")));

                $insert5 = $conn->prepare("INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ");
                $insert5 = $insert5->execute(array("c_id" => $user["client_id"], "action" => "Support Request <strong>automatic</strong> answered on ID:" . $ticket_id, "ip" => GetIP(), "date" => date("Y-m-d H:i:s")));
            }







            if ($insert && $insert2 && $insert3) :
                unset($_SESSION["tickets_data"]);
                unset($user["ticket_data"]);
                $conn->commit();
                if ($settings["alert_newticket"] == 2) :

                    sendMail(["subject" => "New Support Request Available.", "body" => "<h3>There a new support request on your website with this ticket id : # " . $ticket_id . ".</h3><h3> Username : " . $user['username'] . "</h3>
              <h3>Ticket Subject : " . $subject . " </h3> <h3> Message : " . $message . " </h3>", "mail" => $settings["admin_mail"]]);

                endif;
                header('Location:' . site_url('tickets/') . $ticket_id);
            else :
                $error    = 1;
                $errorText = $languageArray["error.tickets.new.fail"];
                $conn->rollBack();
            endif;
        }
    }
} elseif (route(1) && preg_replace('/[^0-9]/', '', route(1)) && !preg_replace('/[^a-zA-Z]/', '', route(1))) {


    $templateDir  = "open_ticket";
    $ticketUpdate = $conn->prepare("UPDATE tickets SET support_new=:new  WHERE client_id=:c_id && ticket_id=:t_id ");
    $ticketUpdate->execute(array("c_id" => $user["client_id"], "new" => 1, "t_id" => route(1)));
    $ticketUpdate = $ticketUpdate->fetch(PDO::FETCH_ASSOC);



    $messageList  = $conn->prepare("SELECT * FROM ticket_reply WHERE ticket_id=:t_id  ORDER BY id ASC");
    $messageList->execute(array("t_id" => route(1)));
    $messageList  = $messageList->fetchAll(PDO::FETCH_ASSOC);





    $ticketList = $conn->prepare("SELECT * FROM tickets WHERE client_id=:c_id && ticket_id=:t_id ");
    $ticketList->execute(array("c_id" => $user["client_id"], "t_id" => route(1)));
    $ticketList = $ticketList->fetch(PDO::FETCH_ASSOC);
    $messageList["ticket"]  = $ticketList;



    if ($messageList[0]["client_id"] != $user["client_id"] || $ticketList["is_deleted"] == 2) {
        Header("Location:" . site_url('tickets'));
    }

    if ($_POST) {

        foreach ($_POST as $key => $value) {
            $_SESSION["data"][$key]  = $value;
        }

        $message  = htmlspecialchars($_POST["message"]);
        $message = str_replace("\n", "<br />", $message);


      


        if (strlen(str_replace(' ', '', $message)) < 5 && empty($imageResponse)) {
            $error    = 1;
            $errorText = str_replace("{length}", "5", $languageArray["error.tickets.read.message.length"]);
        } elseif ($ticketList["canmessage"] == 1) {
            $error    = 1;
            $errorText = $languageArray["error.tickets.read.message.cant"];
        } else {
            $conn->beginTransaction();


            $update = $conn->prepare("UPDATE tickets SET lastupdate_time=:last_time, status=:status, client_new=:new WHERE ticket_id=:t_id ");
            $update = $update->execute(array("last_time" => date("Y.m.d H:i:s"), "t_id" => route(1), "new" => 2, "status" => "pending"));

            if (!empty($message)) :
                $insert = $conn->prepare("INSERT INTO ticket_reply SET ticket_id=:t_id, message=:message, client_id=:client_id , time=:time ");
                $insert = $insert->execute(array("t_id" => route(1), "message" => $message, "client_id" => $user["client_id"], "time" => date("Y.m.d H:i:s")));
            endif;
            if (!empty($imageResponse)) :
                $link = '<a class="supportLink" href=' . $imageResponse["image_url"] . ' target="_blank">' . $imageResponse["image_url"] . '</a>';
                $insert = $conn->prepare("INSERT INTO ticket_reply SET ticket_id=:t_id, message=:message, client_id=:client_id , time=:time ");
                $insert = $insert->execute(array("t_id" => route(1), "message" => $link, "client_id" => $user["client_id"], "time" => date("Y.m.d H:i:s")));
            endif;

            $insert3 = $conn->prepare("INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ");
            $insert3 = $insert3->execute(array("c_id" => $user["client_id"], "action" => "Support request responded #" . route(1), "ip" => GetIP(), "date" => date("Y-m-d H:i:s")));
            if ($update && $insert && $insert3) :
                unset($_SESSION["data"]);
                $conn->commit();
                if ($settings["alert_newticket_user"] == 2) :
                    sendMail([
                        "subject" => "Support #" . route(1) . " new reply.",
                        "body" => "<h3>There a new reply on your website for the ticket id : # " . route(1) . ".</h3><h3> Username : " . $user['username'] . "</h3>
              <h3> Reply : " . $message . " </h3>", "mail" => $settings["admin_mail"]
                    ]);

                endif;
                header("Location:" . site_url('tickets/') . route(1));
            else :
                $error    = 1;
                $errorText = $languageArray["error.tickets.read.fail"];
                $conn->rollBack();
            endif;
        }
    }
} elseif (route(1) && preg_replace('/[^a-zA-Z]/', '', route(1))) {

    header('Location:' . site_url('404'));
}
