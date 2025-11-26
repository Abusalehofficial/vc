<?php

$title .= "Refer & Earn";

userSecureHeader();




if ($settings["service_list"] == 1 && !$_SESSION["msmbilisim_userlogin"]) :
  header("Location:" . site_url());
endif;

if ($settings["referral_status"] == 1) :
  header("Location:" . site_url());
endif;



if (route(1) == "paid-out") :


  $ref_code = $user["ref_code"];



  $pendingPayouts =  $conn->prepare("SELECT * FROM 
        referral_payouts WHERE r_p_code=:r_p_code and r_p_status=:r_p_status ");
  $pendingPayouts->execute(array("r_p_code" => $ref_code, "r_p_status" => 0));
  $pendingPayouts  = $pendingPayouts->fetch(PDO::FETCH_ASSOC);

 

  if (!$pendingPayouts) :
    //only if no pending payouts

    $ref_content  = $conn->prepare("SELECT * FROM referral WHERE referral_code=:referral_code ");
    $ref_content->execute(array("referral_code" => $ref_code));
    $ref_content  = $ref_content->fetch(PDO::FETCH_ASSOC);

    $referral_total_commision = $ref_content["referral_total_commision"];
    $referral_earned_commision = $ref_content["referral_earned_commision"];
    $referral_requested_commision = $ref_content["referral_requested_commision"];
    $referral_rejected_commision = $ref_content["referral_rejected_commision"];


    $referral_paid_remaining = $referral_total_commision - ($referral_earned_commision +
      $referral_requested_commision + $referral_rejected_commision);

     

    if ($referral_paid_remaining < $settings["referral_payout"] || $referral_paid_remaining == 0) {
      header("Location:" . site_url('refer'));
    } else {



      $insert  = $conn->prepare("INSERT INTO referral_payouts SET r_p_code=:r_p_code 
    , r_p_amount_requested=:r_p_amount_requested , r_p_requested_at=:r_p_requested_at , 
    r_p_updated_at=:r_p_updated_at");
      $insert = $insert->execute(array(
        "r_p_code" => $ref_code, "r_p_requested_at" => date("Y-m-d H:i:s"),
        "r_p_amount_requested" => $referral_paid_remaining, "r_p_updated_at" => date("Y-m-d H:i:s")
      ));


      if ($insert) :
        $success = "1";
        $successText = "Payout Request Added, It may take 2-7 days to process.";

        header("Location:" . site_url('refer'));

        $update  = $conn->prepare("UPDATE referral SET 
          referral_requested_commision=:referral_requested_commision WHERE 
          referral_code=:referral_code");
        $update = $update->execute(array(
          "referral_code" => $ref_code,
          "referral_requested_commision" => $referral_paid_remaining
        ));


        header("Location:" . site_url('refer'));
      endif;
    }
  endif;
endif;


$ref_content  = $conn->prepare("SELECT * FROM referral WHERE referral_code=:referral_code ");
$ref_content->execute(array("referral_code" => $user['ref_code']));
$ref_content  = $ref_content->fetch(PDO::FETCH_ASSOC);

 

$ref_content["referral_clicks"] = empty($ref_content["referral_clicks"]) ? 0 : $ref_content["referral_clicks"];
$ref_content["referral_sign_up"] = empty($ref_content["referral_sign_up"]) ? 0 : $ref_content["referral_sign_up"];


$ref_content["ref_conversion_rate"] = round(($ref_content["referral_sign_up"] / $ref_content["referral_clicks"]) * 100 , 2);

$ref_content["ref_conversion_rate"] = is_nan($ref_content["ref_conversion_rate"]) || $ref_content["ref_conversion_rate"] == "" ? 0 : $ref_content["ref_conversion_rate"];
 
 

$ref_content["ref_unpaid_earning"] = $ref_content["referral_total_commision"] - ($ref_content["referral_earned_commision"] + $ref_content["referral_requested_commision"] + $ref_content["referral_rejected_commision"]);

$ref_content["paid_out_allowed"] = $ref_content["ref_unpaid_earning"] >= $settings["referral_payout"] ? "" : "disabled";


$ref_payouts  = $conn->prepare("SELECT * FROM referral_payouts WHERE r_p_code=:r_p_code ORDER BY r_p_id DESC");
$ref_payouts->execute(array("r_p_code" => $user['ref_code']));
$ref_payouts  = $ref_payouts->fetchAll(PDO::FETCH_ASSOC);

foreach ($ref_payouts as $payout) :

  switch ($payout["r_p_status"]) {
    case 0:
      $status = "Pending";
      break;
    case 1:
      $status = "Disapproved";
      break;
    case 2:
      $status = "Approved";
      break;
    case 3:
      $status = "Rejected";
      break;
    default:
      $status = "Pending";
      break;
  }

  $payout["status"] = $status;

endforeach;

// $ref_content = array(
//   'ref_conversion_rate' => '50'
// );


// p($user);