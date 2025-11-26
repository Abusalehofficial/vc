<?php
if(!$_POST){
    
            header("Location: " . site_url(''));
exit();
}

function isMobile() {
    $userAgent = $_SERVER['HTTP_USER_AGENT'];
    $mobileKeywords = ['Mobile', 'Android', 'iPhone', 'iPad', 'Windows Phone'];

    foreach ($mobileKeywords as $keyword) {
        if (stripos($userAgent, $keyword) !== false) {
            return true;
        }
    }

    return false;
}

if($_SESSION['ID']!== $_POST["id"]){
echo '<script>
    removeCookise("countdown");

    function removeCookise(name) {
        document.cookie = name + "=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
    }
</script>';

}
 $upi_id =  $_POST["upi_id"];
        $upi_name =  $_POST["name"];
        $tn = $_POST["id"];
        $am = $_POST["amount"];
$tx = "upi://pay?pa={$upi_id}&pn={$upi_name}&cu=INR&am=$am&mam=$am&tr=$tn&tn=$upi_name";
$callback_url =$_POST["callback_url"];
    $_SESSION['ID']=$tn;






?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UPI</title>
      <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <script src="https://client.myrentalpanel.com/public/js/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
    
             body {
 background:#626A87;
  background-position: center;
  background-repeat: no-repeat;
  background-size: cover;
        }

  .container {
       
       <?php if(isMobile()){
    
    
?>
     max-width: 385px;
        margin: 50px auto;
        text-align: center;
        background-color: #ffffff;
        padding: 20px;
        border-radius: 5px;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        border: 5px solid #ccc; /* Add border styling */ 
        
        <?}else{?>
                 max-width: 400px;
        margin: 50px auto;
        text-align: center;
        background-color: #ffffff;
        padding: 20px;
        border-radius: 5px;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        border: 5px solid #e5e5e5; /* Add border styling */
            <?}?>
        }

   
        .form-group {
            margin-bottom: 20px;
        }
         #payment-qrcode-container {
            display: flex;
            align-items: center;
            justify-content: center;
            
         }

        /* Add styling to the QR code itself if needed */
        #payment-qrcode img {
            max-width: 100%;
            max-height: 100%;
            width: auto;
            height: auto;
 
        }
    </style>
</head>
<body>
<div id="containers" class="container">
   

 <div class="row justify-content-center">
            <div class="col-md-12">
                
               <h5 class="font-weight-bold mb-1"><?php echo $settings['site_name']; ?></h5>
<p class="text-muted mb-3">Transfer to</p>
<div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-2">
    <span class="text-left font-weight-bold">Total Amount</span>
    <span class="text-right text-primary font-weight-bold"><?php echo $_POST['amount']; ?></span>
</div>
<style>
    .border-bottom {
        border-color: #eaeaea !important;
    }
</style>

                
 <div id="payment-qrcode-container">
    <div id="payment-qrcode" class="mb-3"></div>
</div>
                    
                      <div id="qr-description" class="text-center mb-3">
<!-- <p>
        Scan the above QR code using 
        <span style="background-color: yellow;">BHIM, Paytm, GPay, PhonePe, or ANY UPI app</span>
     </p>  -->              </div>
              
                   <div class="text-center mt-3">

                </div>
                <div id="payment-status" class="mt-3"></div>
                <div>
                   <span class="text-center font-weight-bold">This QR code will expire in <span id="countdown"></span>
                </div>
                <hr>
                <div class="col-md-12 text-center mt-0">
                    <button type="button" class="btn btn-danger" onclick="handleFailedPayment('/')">Cancel</button>

             </div>
        </div>
    </div>
     <script src="https://cdn.jsdelivr.net/npm/bootstrap/dist/js/bootstrap.min.js"></script>
    <script src="https://client.myrentalpanel.com/public/js/qrcode.min.js"></script>
<script>
  
function generateQRCode(text) {
        var qrcode = new QRCode(document.getElementById("payment-qrcode"), {
            text: text,
        width: 256, // Increase the width and height
            height: 256,
                        correctLevel: QRCode.CorrectLevel.H, // Use High error correction level

        });
    }

 
    
    generateQRCode('<?php echo $tx; ?>');
</script>
    
<script>
    function upiCountdown(elm, minute, second, url) {
         var storedCountdown = getCookie(elm);

        if (storedCountdown) {
             var countdownArray = storedCountdown.split(/[:]+/);
            minute = parseInt(countdownArray[0]);
            second = parseInt(countdownArray[1]);
        }

        updateTimer();

        function updateTimer() {
            document.getElementById(elm).innerHTML = minute + ":" + second;
        }

        startTimer();

        function startTimer() {
            var presentTime = document.getElementById(elm).innerHTML;
            var timeArray = presentTime.split(/[:]+/);
            var m = parseInt(timeArray[0]);
            var s = checkSecond(parseInt(timeArray[1]) - 1);

            if (s == 59) {
                m = m - 1;
            }

            if (m < 0) {
                handleFailedPayment(url);
                return;
            }

            // Store the current countdown in the cookie
            setCookie(elm, m + ":" + s);

            document.getElementById(elm).innerHTML = m + ":" + s;
            setTimeout(startTimer, 1000);
        }

        function checkSecond(sec) {
            if (sec < 10 && sec >= 0) {
                sec = "0" + sec;
            } // add zero in front of numbers < 10
            if (sec < 0) {
                sec = "59";
            }
            return sec;
        }

        function setCookie(name, value) {
            document.cookie = name + "=" + value + "; path=/";
        }

        function getCookie(name) {
            var nameEQ = name + "=";
            var cookies = document.cookie.split(';');
            for (var i = 0; i < cookies.length; i++) {
                var cookie = cookies[i];
                while (cookie.charAt(0) == ' ') cookie = cookie.substring(1, cookie.length);
                if (cookie.indexOf(nameEQ) == 0) return cookie.substring(nameEQ.length, cookie.length);
            }
            return null;
        }
    }

    function handleFailedPayment(url,error="") {
        

        Swal.fire({
            title: 'Payment Fail',
            text: error,
            icon: 'error',
            timer: 3000,
            timerProgressBar: true,
            showConfirmButton: false,
        }).then(function () {
                            removeCookie("countdown");

            window.location.href = url;
            
        });
    }
 function playAudio(audioPath) {
    var audio = new Audio(audioPath);
    audio.play();
  }

    function submitPayment(transactionId) {
        $.ajax({
            url: '<?php echo $callback_url; ?>',
            method: 'POST',
            data: {
                transactionId: transactionId, csrf_token: "<?=$csrfToken?>"
            },
            dataType: 'json',
            success: function (response) {
        if (response && response.STATUS === "SUCCESS") {
    playAudio('https://client.myrentalpanel.com/public/audio/paytm_payment_tune.mp3');

    clearInterval(successInterval);

    handleSuccessfulPayment(response.CALLBACK);
    return;
} else if (response.STATUS === "amount_not_matched") {
        clearInterval(successInterval);

     handleFailedPayment(response.CALLBACK,"Amount Doest No Matched Cheksum#404");
         return;

} else {
  
}

            },
        });
    }

    function handleSuccessfulPayment(txnId) {
        Swal.fire({
            title: 'Payment Success',
            text: '',
            icon: 'success',
            timer: 3000,
            timerProgressBar: true,
            showConfirmButton: false,
        }).then(function () {
                                        removeCookie("countdown");

                        window.location.href = txnId;

        });

    }
   
   

    
    
 function removeCookie(name) {
            document.cookie = name + "=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
        }
    upiCountdown("countdown", 5, 0, '/');

    var successInterval = setInterval(function () {
        var transactionId = "<?php echo $tn ?>";
        submitPayment(transactionId);
    }, 3000);
</script>


</body>
</html>