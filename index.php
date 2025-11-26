<?php

define('FILES_BASE', __DIR__);
require FILES_BASE . '/main_system/init.php';

use PHPMailer\PHPMailer\PHPMailer;
use Phpfastcache\Helper\Psr16Adapter;

$defaultDriver = 'Files';
$phpFastCache = new Psr16Adapter($defaultDriver);
$mail = new PHPMailer;

// ============================================
// 1. Safe Query String Parsing
// ============================================
$first_route = explode('?', $_SERVER["REQUEST_URI"]);

if (isset($first_route[1])) {
    $gets = explode('&', $first_route[1]);
    foreach ($gets as $get) {
        if (strpos($get, '=') !== false) {
            $get = explode('=', $get);
            if (!isset($_GET[$get[0]])) {  // Don't overwrite existing GET params
                $_GET[$get[0]] = $get[1] ?? '';
            }
        }
    }
}

// ============================================
// 2. Route Parsing
// ============================================
$routes = array_filter(explode('/', $first_route[0]));

if (SUBFOLDER === true) {
    array_shift($routes);
    $route = $routes;
} else {
    $route = [];
    foreach ($routes as $index => $value) {
        $route[$index - 1] = $value;
    }
}

// ============================================
// 3. SMTP Settings Check
// ============================================
if (empty($settings["smtp_user"])) {
    $update = $conn->prepare("UPDATE settings SET smtp_user=:smtp_user, smtp_pass=:smtp_pass, smtp_server=:smtp_server, smtp_port=:smtp_port");
    $update->execute([
        "smtp_user" => "",
        "smtp_pass" => "",
        "smtp_server" => "",
        "smtp_port" => 587
    ]);
}

// ============================================
// 4. Module Handler
// ============================================
if (route(0) == "modules" && !empty(route(1)) && !empty($_POST["action"])) {
    extraModules(route(1), $_POST);
}

$extras_website_info = json_decode($settings["website_info"], true);

// ============================================
// 5. IP Blocking
// ============================================
$blocked_ips = array_filter([
    $settings['ip_block'] ?? '',
    $settings['ip_block2'] ?? ''
]);

$visitor_ip = $_SERVER['REMOTE_ADDR'];

if (in_array($visitor_ip, $blocked_ips)) {
    http_response_code(403);
    exit('Access Denied');
}

// ============================================
// 6. WWW Redirect
// ============================================
$force_non_www = true;
$use_https = true;
$host = $_SERVER['HTTP_HOST'];
$uri = $_SERVER['REQUEST_URI'];

if ($force_non_www && strpos($host, 'www.') === 0) {
    $host = substr($host, 4);
    $redirect_url = ($use_https ? 'https://' : 'http://') . $host . $uri;
    header('Location: ' . $redirect_url, true, 301);
    exit;
}

// ============================================
// 7. Currency Data Check
// ============================================
if (empty($settings['currency_conversion_data']) ||
    trim($settings['currency_conversion_data']) === 'Array' ||
    json_decode($settings['currency_conversion_data'], true) === null) {
    
    try {
        fetchUpdatedCurrency();
        $settings = $conn->prepare("SELECT * FROM settings WHERE id=:id");
        $settings->execute(['id' => 1]);
        $settings = $settings->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Currency fetch failed: " . $e->getMessage());
    }
}


// track_ref_click.php
if (isset($_GET['ref']) && !empty($_GET['ref'])) {
    $refCode = trim((string)$_GET['ref']);
    
    // Store in cookie for 30 days
    setcookie('ref', $refCode, time() + (86400 * 30), '/');
    
    // Log click (optional)
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        
        $ins = $conn->prepare("
            INSERT INTO affiliate_clicks 
              (ref_code, ip_address, user_agent, referer, clicked_at) 
            VALUES 
              (:ref, :ip, :ua, :ref_url, NOW())
        ");
        $ins->execute([
            ':ref' => $refCode,
            ':ip' => $ip,
            ':ua' => substr($ua, 0, 255),
            ':ref_url' => substr($referer, 0, 500)
        ]);
        
        // Update click count
        $upd = $conn->prepare("
            UPDATE affiliate_stats 
               SET total_clicks = total_clicks + 1,
                   last_click_at = NOW(),
                   updated_at = NOW()
             WHERE ref_code = :ref
        ");
        $upd->execute([':ref' => $refCode]);
        
    } catch (Throwable $e) {
        // Silent fail
    }
}
// ============================================
// 8. Cron Check
// ============================================
if (!checkCacheExits('cronCheck')) {
    clearStuckCronFiles();
}

$google_login = $settings["google_login"] ?? 0;

// ============================================
// 9. Language Handler
// ============================================
if (isset($_GET["lang"]) && (!isset($user['auth']) || $user['auth'] != 1)) {
    include 'main_system/language/list.php';
    if (countRow(["table" => "languages", "where" => ["language_type" => 2, "language_code" => $_GET["lang"]]])) {
        unset($_SESSION["lang"]);
        $_SESSION["lang"] = $_GET["lang"];
        include 'main_system/language/' . $_GET["lang"] . '.php';
    } else {
        $_SESSION["lang"] = $_GET["lang"];
        include 'main_system/language/' . $_GET["lang"] . '.php';
    }
    $selectedLang = $_SESSION["lang"];
    header("Location:" . site_url());
    exit;
} else {
    if (isset($_SESSION["lang"]) && (!isset($user['auth']) || $user['auth'] != 1)) {
        $language = $_SESSION["lang"];
    } elseif (!isset($user['auth']) || $user['auth'] != 1) {
        $language = $conn->prepare("SELECT * FROM languages WHERE default_language=:default");
        $language->execute(["default" => 1]);
        $language = $language->fetch(PDO::FETCH_ASSOC);
        $language = $language["language_code"] ?? 'en';
    } else {
        if (getRow(["table" => "languages", "where" => ["language_code" => $user["lang"]]])) {
            $language = $user["lang"];
        } else {
            $language = $conn->prepare("SELECT * FROM languages WHERE default_language=:default");
            $language->execute(["default" => 1]);
            $language = $language->fetch(PDO::FETCH_ASSOC);
            $language = $language["language_code"] ?? 'en';
        }
    }

    include 'main_system/language/' . $language . '.php';
    $selectedLang = $language;
}

// ============================================
// 10. Route Logic
// ============================================
if ($settings["panel_frozen"] == 1 && $route[0] != "allcontrol") {
    $route[0] = "frozen";
    $routeType = 0;
} elseif (!isset($route[0]) && $_SESSION["msmbilisim_userlogin"] == true) {
    $route[0] = "sms";
    $routeType = 0;
} elseif (!isset($route[0]) && $_SESSION["msmbilisim_userlogin"] == false) {
    $route[0] = "auth";
    $routeType = 1;
} elseif ($route[0] == "auth" && $_SESSION["msmbilisim_userlogin"] == false) {
    $routeType = 1;
} else {
    $routeType = 0;
}

if (empty($user["user_pref_curr"]) && $_SESSION["msmbilisim_userlogin"]) {
    $user["user_pref_curr"] = $settings["site_currency"];
}

// ============================================
// 11. Cron Handler
// ============================================
if (route(0) == "cron") {
    if (isSelfRequest()) {
        if (!file_exists(cron(route(1)))) {
            require cron('index');
            die();
        }
        require cron(route(1));
        die();
    } else {
        http_response_code(403);
        exit("Access forbidden");
    }
}

// ============================================
// 12. Controller Check
// ============================================
if (!file_exists(controller($route[0]))) {
    $route[0] = "404";
}

if (route(0) != "admin" && $settings["site_maintenance"] == 1) {
    include 'main_system/templates/maintenance.php';
    exit();
}

if (route(0) != 'admin' && route(0) != 'logout' && isset($_SESSION['otp_logins']) && $_SESSION['otp_logins'] == 342) {
    $route[0] = 'otp_auth';
}

if ($settings["service_list"] == 2) {
    $serviceList = 1;
}

addNewLanguageEssentials();
require controller($route[0]);

// ============================================
// 13. Captcha & Reset Page
// ============================================
if ($settings["recaptcha"] == 1) {
    $captcha = false;
} elseif (($settings["recaptcha"] == 2 && (route(1) == "register" || route(0) == "resetpassword")) || isset($_SESSION["recaptcha"])) {
    $captcha = true;
}

$resetPage = ($settings["resetpass_page"] == 2);

$active_menu = (route(0) == "auth") ? route(1) : route(0);

// ============================================
// 14. Snow Effect
// ============================================
if (isset($custom_settings["snow_status"]) && $custom_settings["snow_status"] == 2) {
    runSnow();
}

// ============================================
// 15. Continue only for non-admin/ajax routes
// ============================================
if (route(0) != "admin" && route(0) != "ajax_data") {
    
    $languages = $conn->prepare("SELECT * FROM languages WHERE language_type=:type");
    $languages->execute(["type" => 2]);
    $languages = $languages->fetchAll(PDO::FETCH_ASSOC);
    $languagesL = [];
    
    foreach ($languages as $language) {
        $l = [
            "name" => $language["language_name"],
            "code" => $language["language_code"],
            "active" => 0
        ];
        
        if (isset($_SESSION["lang"]) && $language["language_code"] == $_SESSION["lang"]) {
            $l["active"] = 1;
        } elseif (!isset($_SESSION["lang"])) {
            $l["active"] = $language["default_language"];
        }
        
        array_push($languagesL, $l);
    }

    $templateDir = $templateDir ?? route($routeType);
    $contentGet = ($templateDir == "login" || $templateDir == "register") ? "auth" : $templateDir;

    function getPageContent($conn, $contentGet, $column) {
        $stmt = $conn->prepare("SELECT $column FROM pages WHERE page_get=:get");
        $stmt->execute(["get" => $contentGet]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $content = $result[$column] ?? "";
        return ($content == "<p><br></p>") ? "" : $content;
    }

    $content = getPageContent($conn, $contentGet, "page_content");
    $content2 = getPageContent($conn, $contentGet, "page_content2");
    $page_title = getPageContent($conn, $contentGet, "page_title");

    $sth1 = $conn->prepare("SELECT * FROM notifications_popup WHERE expiry_date >= DATE(now()) AND status=:status ORDER BY id DESC");
    $sth1->execute(["status" => 1]);
    $popupNotif1 = $sth1->fetchAll(PDO::FETCH_ASSOC);

    unset($_SESSION["popSeen"]);

    $pagesData = $conn->prepare("SELECT page_get, page_icon, page_status_auth, page_status_non_auth, page_meta FROM pages");
    $pagesData->execute();
    $pagesData = $pagesData->fetchAll(PDO::FETCH_ASSOC);

    // ============================================
    // 16. Currency Preference Handler
    // ============================================
    if (isset($_GET["user_pref_curr"])) {
        $user_pref_curr = urldecode($_GET["user_pref_curr"]);
        $user_pref_curr = explode(" ", $user_pref_curr);
        $user_pref_curr = $user_pref_curr[0];

        if (isset($user['auth']) && $user['auth']) {
            $update = $conn->prepare("UPDATE clients SET user_pref_curr=:u_c WHERE client_id=:client_id");
            $update->execute(["client_id" => $user['client_id'], "u_c" => $user_pref_curr]);
        } else {
            $_SESSION["guest_pref_curr"] = $user_pref_curr;
        }
        header("Location: " . ($_SERVER['HTTP_REFERER'] ?? site_url()));
        exit;
    }

    // ============================================
    // 17. Modules & Currency Setup (SAFE)
    // ============================================
    $modules = getAllModulesById();
    $currency_module = $modules[13][0] ?? null;
    $currency_module_data = [];
    
    if ($currency_module) {
        $currency_module_data = json_decode($currency_module["module_data"] ?? '[]', true) ?: [];
    }
    
    $currencies = [];

    if (empty($templateDir)) {
        header("Location: " . site_url('logout'));
        exit;
    }

    // ============================================
    // 18. Render Templates
    // ============================================
    if (!isset($_SESSION["msmbilisim_userlogin"]) || $_SESSION["msmbilisim_userlogin"] != 1 || (isset($user["client_type"]) && $user["client_type"] == 1)) {
        // Guest/Non-authenticated user
        
        $pagesInformation = $conn->prepare("SELECT * FROM pages WHERE (page_show=:page_show1 OR page_show=:page_show2) ORDER BY order_by ASC");
        $pagesInformation->execute(["page_show1" => 3, "page_show2" => 4]);
        $pagesInformation = $pagesInformation->fetchAll(PDO::FETCH_ASSOC);

        $guest_pref_curr = $_SESSION["guest_pref_curr"] ?? $settings["site_currency"];

        foreach ($currency_module_data as $currency) {
            if ($guest_pref_curr != $currency) {
                $symbol = getCurrencySymbol($currency);
                $name = $currency . " " . $symbol;
                array_push($currencies, ["currency_name" => $name]);
            }
        }

        $curr_conversion_price = round(convertCurrencyUpdated($settings["site_currency"], $guest_pref_curr, 1), $settings["rates_rounding"]);
        $curr_symbol = getCurrencySymbol($guest_pref_curr);

        echo $twig->render($templateDir . '.twig', [
            'site' => [
                'url' => URL,
                'favicon' => $settings['favicon'] ?? '',
                "logo" => $settings["site_logo"] ?? '',
                "logo_size" => 55,
                "site_name" => $settings["site_name"] ?? '',
                'service_speed' => $settings["service_speed"] ?? '',
                "keywords" => $settings["site_keywords"] ?? '',
                "description" => $settings["site_description"] ?? '',
                'languages' => $languagesL
            ],
            'styleList' => $stylesheet["stylesheets"] ?? '',
            'scriptList' => '',
            'captchaKey' => $settings["recaptcha_key"] ?? '',
            'captcha' => $captcha ?? false,
            'resetPage' => $resetPage ?? false,
            'error' => $error ?? 0,
            'errorText' => $errorText ?? '',
            'success' => $success ?? 0,
            'successText' => $successText ?? '',
            'title' => $title ?? '',
            'user' => $user ?? [],
            'data' => $_SESSION["data"] ?? [],
            'settings' => $settings,
            'search' => urldecode($_GET["search"] ?? ''),
            "active_menu" => $active_menu,
            'contentText' => $content,
            'contentText2' => $content2,
            'page_title' => $page_title,
            'headerCode' => $settings["custom_header"] ?? '',
            'footerCode' => $settings["custom_footer"] ?? '',
            'lang' => $languageArray ?? [],
            'timezones' => $timezones ?? [],
            'popupNotif' => $popupNotif ?? [],
            'currencies' => $currencies,
            'curr_conversion_price' => $curr_conversion_price,
            'curr_symbol' => $curr_symbol,
            'routess' => $route,
            'guest_pref_curr' => $guest_pref_curr,
            'pagesData' => $pagesData,
            'pagesInformation' => $pagesInformation,
            "cdn_base_url" => $cdn_base_url ?? '',
            'apiPageContent' => $apiPageContent ?? '',
            'blogs' => $blogs ?? [],
            'blogtitle' => $blogtitle ?? '',
            'blogcontent' => $blogcontent ?? '',
            'blogimage' => $blogimage ?? '',
            'google_login' => $google_login
        ]);
        
    } else {
        // Authenticated user
        
        if (!isset($user['client_id'])) {
            header("Location: " . site_url('logout'));
            exit;
        }

        if (isset($currency_module["status"]) && $currency_module["status"] == 2) {
            if (!in_array($user["user_pref_curr"], $currency_module_data)) {
                $user["user_pref_curr"] = $settings["site_currency"];
            }
        } else {
            $user["user_pref_curr"] = $settings['site_currency'];
        }

        $user_id = $user["client_id"];

        $pagesInformation = $conn->prepare("SELECT * FROM pages WHERE (page_show=:page_show1 OR page_show=:page_show2) ORDER BY order_by ASC");
        $pagesInformation->execute(["page_show1" => 3, "page_show2" => 4]);
        $pagesInformation = $pagesInformation->fetchAll(PDO::FETCH_ASSOC);

        // Membership module
        $membership_module = $modules[14][0] ?? null;
        if ($membership_module && isset($membership_module["status"]) && $membership_module["status"] == 2 && $membership_module["visibility"] == 2) {
            $membership_module_data = json_decode($membership_module["module_data"] ?? '{}', true);
            $membership_status = $membership_module_data["status"] ?? [];
            $membership_status = getMembershipStatusAsLinkedToNext($membership_status);
            $membership_module_data["status"] = $membership_status;
            $membership_benfits = $membership_module_data["benefits"] ?? [];
            $custom_status = $membership_module_data["custom_status"] ?? [];
            $user["status"] = findMembershipStatus($user_id, $membership_status, $custom_status);
        }

        // Point system module
        $point_module = $modules[15][0] ?? null;
        if ($point_module && isset($point_module["status"]) && $point_module["status"] == 2 && $point_module["visibility"] == 2) {
            [$points_module_data_final, $user] = getPointsInformation($point_module, $user);
        }

        foreach ($currency_module_data as $currency) {
            if ($user["user_pref_curr"] != $currency) {
                $symbol = getCurrencySymbol($currency);
                $name = $currency . " " . $symbol;
                array_push($currencies, ["currency_name" => $name]);
            }
        }

        $curr_conversion_price = round(convertCurrencyUpdated($settings["site_currency"], $user["user_pref_curr"], 1), $settings["rates_rounding"]);
        $curr_symbol = getCurrencySymbol($user["user_pref_curr"]);

        // Stats
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM clients WHERE client_type = 2");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $activetotalClients = $result['total'];

        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM clients");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalClients = $result['total'];

        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM tickets WHERE client_id = :client_id");
        $stmt->execute(["client_id" => $user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalTickets = $result['total'];

        $addOnData = getAddonData();

        echo $twig->render($templateDir . '.twig', [
            'site' => [
                'url' => URL,
                'favicon' => $settings['favicon'] ?? '',
                "logo" => $settings["site_logo"] ?? '',
                "logo_size" => 55,
                "site_name" => $settings["site_name"] ?? '',
                'service_speed' => $settings["service_speed"] ?? '',
                "keywords" => $settings["site_keywords"] ?? '',
                "description" => $settings["site_description"] ?? '',
                'languages' => $languagesL
            ],
            'count' => [
                'clients' => ['total' => $totalClients],
                'tickets' => ['total' => $totalTickets],
            ],
            'styleList' => $stylesheet["stylesheets"] ?? '',
            'scriptList' => '',
            'captchaKey' => $settings["recaptcha_key"] ?? '',
            'captcha' => $captcha ?? false,
            'resetPage' => $resetPage ?? false,
            'error' => $error ?? 0,
            'errorText' => $errorText ?? '',
            'success' => $success ?? 0,
            'successText' => $successText ?? '',
            'title' => $title ?? '',
            'user' => $user,
            'data' => $_SESSION["data"] ?? [],
            'statu' => $statusu ?? '',
            'settings' => $settings,
            'search' => urldecode($_GET["search"] ?? ''),
            "active_menu" => $active_menu,
            'ticketList' => $ticketList ?? [],
            'messageList' => $messageList ?? [],
            'ticketCount' => new_ticket($user['client_id']),
            'paymentsList' => $methodList ?? [],
            'transactions' => $transaction_logs ?? [],
            'PaytmQRimage' => $PaytmQRimage ?? '',
            'PaytmQRimg' => $PaytmQRimg ?? '',
            'PaytmQR' => $PaytmQR["method_type"] ?? '',
            'bankPayment' => $bankPayment["method_type"] ?? '',
            'bankList' => $bankList ?? [],
            'status' => $route[1] ?? '',
            'contentText' => $content,
            'contentText2' => $content2,
            'page_title' => $page_title,
            'headerCode' => $settings["custom_header"] ?? '',
            'footerCode' => $settings["custom_footer"] ?? '',
            'lang' => $languageArray ?? [],
            'timezones' => $timezones ?? [],
            'popupNotif' => $popupNotif ?? [],
            'ref_content' => $ref_content ?? '',
            'ref_payouts' => $ref_payouts ?? [],
            'chilpanel_logs' => $chilpanel_logs ?? [],
            'currencies' => $currencies,
            'curr_conversion_price' => $curr_conversion_price,
            'curr_symbol' => $curr_symbol,
            'routess' => $route,
            'payoneerPayment' => $payoneerPayment ?? '',
            'payoneerPaymentExtra' => $payoneerPaymentExtra ?? '',
            'pagesData' => $pagesData,
            'pagesInformation' => $pagesInformation,
            'PhonepeQrExtras' => $PhonepeQrExtras ?? [],
            'errorLogsStatus' => $errorLogsStatus ?? 0,
            'ticketSubjectList' => $ticketSubjectList ?? [],
            'annoucements' => $annoucements ?? [],
            "task_nofity" => $task_nofity ?? 0,
            "instructionArray" => $paymentMethodsForInstructionsArray ?? [],
            "cdn_base_url" => $cdn_base_url ?? '',
            "api_key" => $api_key ?? '',
            "activetotalClients" => $activetotalClients,
            'addOnData' => $addOnData,
            'currenciesDataforChild' => $currenciesDataforChild ?? [],
            'apiPageContent' => $apiPageContent ?? '',
            'favCategories' => $favCategories ?? [],
            'blogs' => $blogs ?? [],
            'blogtitle' => $blogtitle ?? '',
            'blogcontent' => $blogcontent ?? '',
            'blogimage' => $blogimage ?? '',
            'google_login' => $google_login
        ]);
    }

    
}

if (route(0) != "admin") {
    unset($_SESSION["data"]);
}

ob_end_flush();