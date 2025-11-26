<?php
declare(strict_types=1);

$title .= 'SMS';
userSecureHeader();

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

if (!isset($conn) || !($conn instanceof PDO)) { http_response_code(500); echo "PDO \$conn not available."; exit; }
if (!isset($user['client_id']))            { http_response_code(403); echo "client_id missing"; exit; }

$CLIENT_ID = (int)$user['client_id'];
$apiKey    = isset($app['api_key']) ? (string)$app['api_key'] : '';
$BASE      = 'https://api.smspool.net';

/* ---------- helpers ---------- */
function jres($ok,$msg='',$data=[]){
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['status'=>(bool)$ok,'message'=>$msg,'data'=>$data], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  exit;
}


function getCountryEmoji(string $countryName): string {
    $flags = [
        'United States' => '🇺🇸',
        'United Kingdom' => '🇬🇧',
        'Canada' => '🇨🇦',
        'Germany' => '🇩🇪',
        'France' => '🇫🇷',
        'India' => '🇮🇳',
        'Australia' => '🇦🇺',
        'Brazil' => '🇧🇷',
        'China' => '🇨🇳',
        'Japan' => '🇯🇵',
        'Mexico' => '🇲🇽',
        'Russia' => '🇷🇺',
        'Spain' => '🇪🇸',
        'Italy' => '🇮🇹',
        'Netherlands' => '🇳🇱',
        'Sweden' => '🇸🇪',
        'Poland' => '🇵🇱',
        'Turkey' => '🇹🇷',
        'South Korea' => '🇰🇷',
        'Indonesia' => '🇮🇩',
        'Thailand' => '🇹🇭',
        'Vietnam' => '🇻🇳',
        'Philippines' => '🇵🇭',
        'Malaysia' => '🇲🇾',
        'Singapore' => '🇸🇬',
        'Pakistan' => '🇵🇰',
        'Bangladesh' => '🇧🇩',
        'Nigeria' => '🇳🇬',
        'Egypt' => '🇪🇬',
        'South Africa' => '🇿🇦',
        'Argentina' => '🇦🇷',
        'Colombia' => '🇨🇴',
        'Chile' => '🇨🇱',
        'Peru' => '🇵🇪',
        'Venezuela' => '🇻🇪',
        'Ukraine' => '🇺🇦',
        'Romania' => '🇷🇴',
        'Czech Republic' => '🇨🇿',
        'Portugal' => '🇵🇹',
        'Greece' => '🇬🇷',
        'Belgium' => '🇧🇪',
        'Austria' => '🇦🇹',
        'Switzerland' => '🇨🇭',
        'Israel' => '🇮🇱',
        'Saudi Arabia' => '🇸🇦',
        'UAE' => '🇦🇪',
        'Kazakhstan' => '🇰🇿',
    ];
    
    return $flags[$countryName] ?? '🌍';
}

function getServiceIconClass(string $serviceName): string {
    $name = strtolower($serviceName);
    
    if (strpos($name, 'instagram') !== false) return 'instagram';
    if (strpos($name, 'google') !== false || strpos($name, 'gmail') !== false || strpos($name, 'youtube') !== false) return 'google';
    if (strpos($name, 'whatsapp') !== false) return 'whatsapp';
    if (strpos($name, 'facebook') !== false) return 'facebook';
    if (strpos($name, 'telegram') !== false) return 'telegram';
    if (strpos($name, 'twitter') !== false || strpos($name, 'x.com') !== false) return 'twitter';
    if (strpos($name, 'tiktok') !== false) return 'tiktok';
    if (strpos($name, 'apple') !== false) return 'apple';
    if (strpos($name, 'viber') !== false) return 'viber';
    if (strpos($name, 'snapchat') !== false) return 'snapchat';
    if (strpos($name, 'linkedin') !== false) return 'linkedin';
    if (strpos($name, 'discord') !== false) return 'discord';
    
    return 'default';
}

function getServiceFontAwesome(string $serviceName): string {
    $name = strtolower($serviceName);
    
    if (strpos($name, 'instagram') !== false) return 'fab fa-instagram';
    if (strpos($name, 'google') !== false || strpos($name, 'gmail') !== false || strpos($name, 'youtube') !== false) return 'fab fa-google';
    if (strpos($name, 'whatsapp') !== false) return 'fab fa-whatsapp';
    if (strpos($name, 'facebook') !== false) return 'fab fa-facebook-f';
    if (strpos($name, 'telegram') !== false) return 'fab fa-telegram';
    if (strpos($name, 'twitter') !== false || strpos($name, 'x.com') !== false) return 'fab fa-twitter';
    if (strpos($name, 'tiktok') !== false) return 'fab fa-tiktok';
    if (strpos($name, 'apple') !== false) return 'fab fa-apple';
    if (strpos($name, 'viber') !== false) return 'fab fa-viber';
    if (strpos($name, 'snapchat') !== false) return 'fab fa-snapchat';
    if (strpos($name, 'linkedin') !== false) return 'fab fa-linkedin';
    if (strpos($name, 'discord') !== false) return 'fab fa-discord';
    
    return 'fas fa-mobile-alt';
}
/** GET helper for SMSPool (matches their examples that build a querystring URL) */function smspool_get(string $path, array $params, string $apiKey): array {
    $params['key'] = $apiKey;
    $url = "https://api.smspool.net/{$path}?" . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'Infozeen-SMSPool/1.0 PHP',
    ]);
    $resp = curl_exec($ch);
    $err  = curl_errno($ch);
    curl_close($ch);

    if ($err !== 0 || !$resp) throw new RuntimeException("cURL error $err or empty body");

    $json = json_decode($resp, true);
    return (json_last_error() === JSON_ERROR_NONE) ? $json : ['raw'=>$resp];
}
/* ---------- AJAX ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST' && isset($_POST['action'])) {
  try{
    switch($_POST['action']){

/* -------- Countries (only those that have prices) -------- */
   /* -------- Countries with flags -------- */
case 'u_countries': {
    $sql = "SELECT c.provider_id AS id,
                   COALESCE(NULLIF(c.local_name,''), c.name_en) AS name,
                   c.country_logo AS flag
              FROM sms_countries c
             WHERE EXISTS (SELECT 1 FROM sms_prices p WHERE p.country_id = c.provider_id)
             ORDER BY name ASC";
    $st = $conn->query($sql);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    
    // Add flag emoji fallback if no logo
    foreach ($rows as &$row) {
        if (empty($row['flag'])) {
            $row['flag'] = getCountryEmoji($row['name']);
        }
    }
    unset($row);
    
    jres(true, 'ok', ['rows' => $rows]);
}

/* -------- Services with icons -------- */
case 'u_services': {
    $cid = (int)($_POST['country_id'] ?? 0);
    if ($cid <= 0) jres(true, 'ok', ['rows' => []]);
    
    $st = $conn->prepare("
        SELECT s.service_id AS id,
               COALESCE(s.display_name, s.name) AS name,
               s.service_logo AS icon
          FROM sms_prices p
          JOIN sms_services s ON s.service_id = p.service_id
         WHERE p.country_id = :cid
         GROUP BY s.service_id, name, s.service_logo
         ORDER BY name ASC
    ");
    $st->execute([':cid' => $cid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    
    // Add icon class fallback if no logo
    foreach ($rows as &$row) {
        if (empty($row['icon'])) {
            $row['icon_class'] = getServiceIconClass($row['name']);
            $row['icon_fa'] = getServiceFontAwesome($row['name']);
        } else {
            $row['icon_class'] = 'custom';
            $row['icon_fa'] = null;
        }
    }
    unset($row);
    
    jres(true, 'ok', ['rows' => $rows]);
}

/* -------- Helper Functions -------- */

/* -------- Price lookup from sms_prices (country + service) -------- */
      case 'get_price_db': {
        $cid = (int)($_POST['country_id'] ?? 0);
        $sid = (string)($_POST['service_id'] ?? '');
        if ($cid<=0 || $sid==='') jres(false,'Select country & service');

        $st = $conn->prepare("
          SELECT COALESCE(custom_price, provider_cost) AS price,
                 available_count
            FROM sms_prices
           WHERE country_id = :c AND service_id = :s
           LIMIT 1
        ");
        $st->execute([':c'=>$cid, ':s'=>$sid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        jres(true,'ok',[
          'provider_price' => $row ? convert_currency((string)$row['price']) : null,
          'available'      => $row ? (int)$row['available_count'] : null
        ]);
      }

/* -------- Create order (purchase/sms via GET) -------- */
 case 'u_create_order': {
    if ($apiKey === '') jres(false,'Missing API key');

    $country = (int)($_POST['country_id'] ?? 0);
    $service = (string)($_POST['service_id'] ?? '');
    $pool    = isset($_POST['pool']) ? trim((string)$_POST['pool']) : null;

    if ($country<=0 || $service==='') jres(false,'Select country & service');

    // Price from DB
    $st = $conn->prepare("
      SELECT COALESCE(p.custom_price, p.provider_cost) AS price
        FROM sms_prices p
       WHERE p.country_id = :c AND p.service_id = :s
       LIMIT 1
    ");
    $st->execute([':c'=>$country, ':s'=>$service]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['price'] === null) jres(false,'Price not found for this country/service');

    $pprice = number_format((float)$row['price'], 4, '.', '');

    if ((float)$user['balance'] < (float)$pprice) {
      jres(false,'Insufficient balance');
    }

    // SMSPool create order
    $params = ['country'=>(string)$country, 'service'=>$service];
    if ($pool !== null && $pool !== '') $params['pool'] = $pool;

    $resp = smspool_get('purchase/sms', $params, $apiKey);

    if (!is_array($resp) || (int)($resp['success'] ?? 0) !== 1) {
      $msg = isset($resp['message']) ? (string)$resp['message'] : ((isset($resp['raw']) ? ('Provider: '.$resp['raw']) : 'Order failed'));
      jres(false, $msg);
    }

    $orderId = (string)($resp['order_id'] ?? '');
    $phone   = (string)($resp['number']   ?? '');
    if ($orderId === '' || $phone === '') jres(false,'Bad provider response');

    // Persist + debit in one transaction
    $conn->beginTransaction();
    try {
      // Insert order
      $ins = $conn->prepare("
        INSERT INTO sms_orders
          (client_id, country_id, service_id, provider_price, activation_id, phone, status, created_at, last_status_at)
        VALUES
          (:cid, :c, :s, :pp, :aid, :ph, 'STATUS_WAIT_CODE', NOW(), NOW())
      ");
      $ins->execute([
        ':cid'=>$CLIENT_ID,
        ':c'=>$country,
        ':s'=>$service,
        ':pp'=>$pprice,
        ':aid'=>$orderId,
        ':ph'=>$phone
      ]);
      
      $newOrderId = $conn->lastInsertId(); // ✅ Get order ID immediately after insert

      // Update user balance
      $newBalance = (float)$user['balance'] - (float)$pprice;
      $newSpent   = (float)$user['spent']   + (float)$pprice;

      $upd = $conn->prepare("
        UPDATE clients
           SET balance = :balance,
               spent   = :spent
         WHERE client_id = :id
      ");
      $upd->execute([
        ':id'      => $CLIENT_ID,
        ':balance' => number_format($newBalance, 4, '.', ''),
        ':spent'   => number_format($newSpent,   4, '.', '')
      ]);

      // ✅ Process affiliate commission if user was referred

      $conn->commit();

      // Reflect runtime
      $user['balance'] = $newBalance;
      $user['spent']   = $newSpent;

      jres(true,'Order placed',[
        'activation_id' => $orderId,
        'phone'         => $phone,
        'price'         => $pprice,
        'expires_in'    => (int)($resp['expires_in'] ?? 0),
        'pool'          => $resp['pool'] ?? null,
      ]);
      
    } catch (Throwable $e) {
      if ($conn->inTransaction()) $conn->rollBack();
      jres(false, 'DB error: '.$e->getMessage()); // ✅ Now user will see the actual error
    }
}
/* -------- Orders list (infinite scroll) -------- */
      case 'u_orders': {
        $lim = max(10, min(150, (int)($_POST['limit'] ?? 25)));
        $off = max(0, (int)($_POST['offset'] ?? 0));

        $st = $conn->prepare("
          SELECT
          o.id,
            o.created_at,
            o.country_id,
            o.service_id,
            o.provider_price,
            o.activation_id,
            o.phone,
            o.status,
            o.otp_code,
            o.otp_text,
            o.verification_type,
            COALESCE(s.display_name, s.name)   AS service_name,
            COALESCE(c.local_name,  c.name_en) AS country_name
          FROM sms_orders o
          LEFT JOIN sms_services  s ON s.service_id = o.service_id
          LEFT JOIN sms_countries c ON c.provider_id = o.country_id
          WHERE o.client_id = :cid
          ORDER BY o.id DESC
          LIMIT :lim OFFSET :off
        ");
        $st->bindValue(':cid', $CLIENT_ID, PDO::PARAM_INT);
        $st->bindValue(':lim', $lim, PDO::PARAM_INT);
        $st->bindValue(':off', $off, PDO::PARAM_INT);
        $st->execute();

        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
          $raw = isset($r['provider_price']) && $r['provider_price'] !== '' ? (float)$r['provider_price'] : null;
          $r['provider_price_raw'] = $raw;
          $r['provider_price'] = ($raw === null) ? null : (function_exists('convert_currency') ? convert_currency($raw, true) : $raw);
        }
        unset($r);

        jres(true, 'ok', [
          'rows'        => $rows,
          'next_offset' => (count($rows) < $lim ? null : $off + $lim)
        ]);
      }

      default:
        jres(false,'Unknown action');
    }
  }catch(Throwable $e){
    jres(false,$e->getMessage());
  }
  exit;
}
