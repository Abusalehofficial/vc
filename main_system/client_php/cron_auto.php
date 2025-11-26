<?php
require $_SERVER["DOCUMENT_ROOT"] . '/main_system/client_php/cron_scheduler/vendor/autoload.php';

use GO\Scheduler;

 if($_GET["keys"]==md5(site_url("/crons?id=" . site_url()))){
        

$scheduler = new Scheduler([
    'tempDir' => $_SERVER["DOCUMENT_ROOT"] . '/main_system/cron_tmp'
]);
function runCron($url) {

    $ch = curl_init();

    // Set URL and other appropriate options
    curl_setopt($ch, CURLOPT_URL, site_url($url));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    // Execute and get the response
    $response = curl_exec($ch);

    // Check for errors
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception("Curl error: " . $error);
    }

    // Close the curl session
    curl_close($ch);

    return $response;
}
$scheduler->clearJobs();

$stmt = $conn->query('SELECT id, url, schedule FROM cron_jobs');
$jobs = $stmt->fetchAll();

foreach ($jobs as $job) {
    $url = $job['url'];
    $schedule = $job['schedule'];

    // Schedule the job
    $scheduler->call(function() use ($url) {
        runCron($url);
    })->at($schedule)->onlyOne();
}

$scheduler->resetRun()
          ->run();
          echo 1 ;
 }
?>
