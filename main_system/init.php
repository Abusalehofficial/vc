<?php

// ============================================
// 1. Session Configuration (BEFORE session_start)
// ============================================
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Strict');

 // Destroy existing session first

 

// NOW set the new lifetime
ini_set('session.cookie_lifetime', 3600);
ini_set('session.gc_maxlifetime', 3600);

// Start fresh session
session_start();

// Force regenerate with new settings


ob_start();
require __DIR__ . '/phplibraries/autoload.php';

$jsonData = json_decode(file_get_contents(__DIR__ . '/env.json'), true);
$protocol = empty($_SERVER['HTTPS']) ? 'http://' : 'https://';
$domain = $_SERVER['HTTP_HOST'];
$uri = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$url = $protocol . $domain . $uri;

define('PATH', realpath('.'));
define('SUBFOLDER', $jsonData["SUBFOLDER"]);
define('URL', $url);
define('STYLESHEETS_URL', $url);

// ============================================
// 2. Error Reporting (Enable for debugging, disable in production)
// ============================================
// DEV MODE
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

// PRODUCTION MODE
 

// ============================================
// 3. Database Connection
// ============================================
$db_credentials = [
    "name"    => $jsonData["db_name"],
    "host"    => "localhost",
    "user"    => $jsonData["db_user"],
    "pass"    => $jsonData["db_pass"],
    "charset" => "utf8mb4"
];

if (empty($db_credentials['user']) || empty($db_credentials['pass']) || empty($db_credentials['name'])) {
    die("Database credentials are incomplete.");
}

$db_host = $db_credentials["host"];
$db_user = $db_credentials["user"];
$db_pass = $db_credentials["pass"];
$db_name = $db_credentials["name"];
$db_charset = $db_credentials["charset"];

$dsn = sprintf("mysql:host=%s;dbname=%s;charset=%s", $db_host, $db_name, $db_charset);

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $conn = new PDO($dsn, $db_user, $db_pass, $options);
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Please contact support.");
}

$pdo = $conn;

$app = [
    'api_key' => getenv('SMSMAN_API_KEY') ?: 'TEXKFMod11M3bAZOCKxBtV9y7vWtrMKK',
    'api_base' => 'https://api.smspool.net/stubs/handler_api',
    'currency' => 'INR',
];

$settings = $conn->prepare("SELECT * FROM settings WHERE id=:id");
$settings->execute(array("id" => 1));
$settings = $settings->fetch(PDO::FETCH_ASSOC);
define('THEME', $settings["site_theme"]);

$loader = new \Twig\Loader\FilesystemLoader(FILES_BASE . '/main_system/templates/' . THEME);

function decryptIt1($string)
{
    $ciphering = "AES-128-CTR";
    $options = 0;
    $decryption_iv = '1234567891011121';
    $decryption_key = TOKEN;
    return openssl_decrypt($string, $ciphering, $decryption_key, $options, $decryption_iv);
}

function GetIP1()
{
    if (getenv("HTTP_CLIENT_IP") && strcasecmp(getenv("HTTP_CLIENT_IP"), "unknown")) 
        $ip = getenv("HTTP_CLIENT_IP");
    elseif (getenv("HTTP_X_FORWARDED_FOR") && strcasecmp(getenv("HTTP_X_FORWARDED_FOR"), "unknown")) 
        $ip = getenv("HTTP_X_FORWARDED_FOR");
    elseif (getenv("REMOTE_ADDR") && strcasecmp(getenv("REMOTE_ADDR"), "unknown")) 
        $ip = getenv("REMOTE_ADDR");
    elseif (isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] && strcasecmp($_SERVER['REMOTE_ADDR'], "unknown")) 
        $ip = $_SERVER['REMOTE_ADDR'];
    else 
        $ip = "unknown";
    
    $ip_array = explode(",", $ip);
    return count($ip_array) > 1 ? $ip_array[0] : $ip;
}

// ============================================
// 4. Secure Cookie Helper (renamed from setcookie)
// ============================================
function secure_cookie($name, $value = "", $expire = 0, $path = "/", $domain = "", $secure = true, $httponly = true)
{
    $params = [
        'expires'  => $expire,
        'path'     => $path,
        'domain'   => $domain,
        'secure'   => $secure,
        'httponly' => $httponly,
        'samesite' => 'Strict'
    ];
    return setcookie($name, $value, $params);
}

define('GRAPH', 1);
require FILES_BASE . '/main_system/constant.php';

// ============================================
// 5. Auto Login from Cookie (FIXED)
// ============================================
if (isset($_COOKIE["_user"]) && isset($_COOKIE["_user_token"]) && !isset($_SESSION["msmbilisim_userlogin"])) {
    try {
        $username = decryptIt1($_COOKIE["_user"]);
        $token = decryptIt1($_COOKIE["_user_token"]);
        
        if (!$username || !$token) {
            throw new Exception("Invalid cookie data");
        }

        $user_data = explode('||', $token);
        
        if (count($user_data) !== 5) {
            throw new Exception("Malformed token");
        }

        $user_id = $user_data[0];
        $user_browser = $user_data[2];
        $user_ip = $user_data[3];
        $user_pass = $user_data[4];
        $current_browser_info = $_SERVER["HTTP_USER_AGENT"];

        $row = $conn->prepare("SELECT * FROM clients WHERE username=:username");
        $row->execute(array("username" => $username));
        $row = $row->fetch(PDO::FETCH_ASSOC);

        if ($row && 
            $current_browser_info === $user_browser && 
            $user_pass === $row["password"] && 
            $row["username"] === $username) {
            
            $_SESSION["msmbilisim_userlogin"] = 1;
            $_SESSION["msmbilisim_userid"] = $row["client_id"];
            $_SESSION["msmbilisim_userpass"] = $row["password"];

            $insert = $conn->prepare("INSERT INTO client_report SET client_id=:c_id, report_type=:report_type, action=:action, report_ip=:ip, report_date=:date");
            $insert->execute([
                "c_id" => $row["client_id"],
                "report_type" => 2,
                "action" => "User Auto Logged in.",
                "ip" => GetIP1(),
                "date" => date("Y-m-d H:i:s")
            ]);

            $update = $conn->prepare("UPDATE clients SET login_date=:date, login_ip=:ip WHERE client_id=:c_id");
            $update->execute([
                "c_id" => $row["client_id"],
                "date" => date("Y.m.d H:i:s"),
                "ip" => GetIP1()
            ]);
        } else {
            throw new Exception("Authentication failed");
        }
    } catch (Exception $e) {
        // Clear invalid session/cookies
        error_log("Auto-login failed: " . $e->getMessage());
        
        session_unset();
        session_destroy();
        
        secure_cookie("_user", "", time() - 3600, '/');
        secure_cookie("_user_token", "", time() - 3600, '/');
    }
}

function removeSlashes($url)
{
    $array = explode("/", $url);
    if (empty($array[3])) {
        $final = $array[2] . '_';
    } else {
        $final = $array[2] . '_' . $array[3] . '_';
    }
    return $final;
}

if (!defined('CACHE_HEADER')) {
    $header = removeSlashes(URL);
    define('CACHE_HEADER', $header);
}

$settings = $conn->prepare("SELECT * FROM settings WHERE id=:id");
$settings->execute(array("id" => 1));
$settings = $settings->fetch(PDO::FETCH_ASSOC);

$custom_settings = $conn->prepare("SELECT * FROM custom_settings WHERE id=:id");
$custom_settings->execute(array("id" => 1));
$custom_settings = $custom_settings->fetch(PDO::FETCH_ASSOC);

date_default_timezone_set($settings['default_timezone']);

if (isset($_SESSION["msmbilisim_userlogin"])) {
    $user = $conn->prepare("SELECT * FROM clients WHERE client_id=:id");
    $user->execute(array("id" => $_SESSION["msmbilisim_userid"]));
    $user = $user->fetch(PDO::FETCH_ASSOC);
    $user['auth'] = $_SESSION["msmbilisim_userlogin"];
}

if (isset($_SESSION["msmbilisim_adminlogin"])) {
    $admin = $conn->prepare("SELECT * FROM admins WHERE admin_id=:id");
    $admin->execute(array("id" => $_SESSION["msmbilisim_adminid"]));
    $admin = $admin->fetch(PDO::FETCH_ASSOC);
    $admin["admin_auth"] = $_SESSION["msmbilisim_adminid"];
    $admin["access"] = json_decode($admin["access"], true);
}

$twig = new \Twig\Environment($loader, ['autoescape' => false]);

foreach (glob(__DIR__ . '/extra_php/functions/*.php') as $helper) {
    require $helper;
}

foreach (glob(__DIR__ . '/extra_php/classes/*.php') as $class) {
    require $class;
}

$timezones = get_timezone_list();
$languagesList = json_decode(get_languages_list(), true);