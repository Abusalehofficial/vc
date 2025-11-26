<?php


//Custom pages controller

$active_route = $route[0];

$pageData = $conn->prepare("SELECT * FROM pages WHERE page_get=:page_get");
$pageData->execute(["page_get" => $active_route]);
$pageData  = $pageData->fetch(PDO::FETCH_ASSOC);




// Add header controller content //
if($pageData["page_show"] == 1):  // hidden page
    show404();
else: 
    userSecureHeader();
endif;



if(empty($pageData)) :  // No page found
    show404();
else: // Custom pages controller content if available
    loadCustomController();
endif;




// Helper functions //

function show404(){
    require FILES_BASE . '/main_system/client_php/404.php';
    exit();
}


function loadCustomController(){}