<?php
declare(strict_types=1);

userSecureHeader();

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

if (!isset($conn) || !($conn instanceof PDO)) { http_response_code(500); echo "PDO \$conn not available."; exit; }
if (!isset($user['client_id'])) { http_response_code(403); echo "client_id missing"; exit; }

$CLIENT_ID = (int)$user['client_id'];
$aid       = isset($_GET['aid']) ? (string)$_GET['aid'] : ''; // STRING (can be alphanumeric)
$apiKey    = isset($app['api_key']) ? (string)$app['api_key'] : '';
$BASE      = 'https://api.smspool.net';

/* ---------- helpers ---------- */
function jres(bool $ok, string $msg = '', array $data = []): void {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['status'=>$ok,'message'=>$msg,'data'=>$data], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  exit;
}
function bad(): void { http_response_code(400); echo "Bad activation id"; exit; }

function norm_status(string $st): string { return strtoupper(trim($st)); }
function is_final_status(string $st): bool {
  $s = norm_status($st);
  return in_array($s, ['EXPIRED','CANCELLED','COMPLETED','STATUS_CODE_RECEIVED'], true);
}

/* ---------- SMSPool HTTP: GET with query (fixes 404) ---------- */
 
function smspool_get(string $path, array $params, string $apiKey): array {
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
/* ---------- DB helpers ---------- */
function fetch_order(PDO $conn,int $cid,string $aid): ?array {
  $st=$conn->prepare("
    SELECT o.*,
           TIMESTAMPDIFF(SECOND, o.created_at, NOW()) AS age_sec,
           COALESCE(s.display_name,s.name) AS service_name,
           COALESCE(c.local_name,c.name_en) AS country_name
      FROM sms_orders o
      LEFT JOIN sms_services  s ON s.service_id=o.service_id
      LEFT JOIN sms_countries c ON c.provider_id=o.country_id
     WHERE o.client_id=:cid AND o.activation_id=:aid
     LIMIT 1
  ");
  $st->execute([':cid'=>$cid, ':aid'=>$aid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

/* mark expired if: >10 min, no otp_code, not final, not already expired */
/* mark expired if: >30 min, no otp_code, not final, not already expired */
function maybe_expire(PDO $conn,int $cid,string $aid): bool {
  $st=$conn->prepare("
    UPDATE sms_orders
       SET status='EXPIRED',
           expired_at=NOW(),
           last_status_at=NOW()
     WHERE client_id=:cid AND activation_id=:aid
       AND otp_code IS NULL
       AND status NOT IN ('CANCELLED','COMPLETED','STATUS_CODE_RECEIVED','EXPIRED')
       AND expired_at IS NULL
       AND created_at <= (NOW() - INTERVAL 30 MINUTE)
     LIMIT 1
  ");
  $st->execute([':cid'=>$cid, ':aid'=>$aid]);
  return $st->rowCount() === 1;
}

/* refund helper: idempotent (checks refunded_at) */
/* refund helper: idempotent (checks refunded_at) */
function refund_on_cancel(PDO $conn, int $cid, string $aid): array {
  $conn->beginTransaction();
  try {
    $sel = $conn->prepare("
      SELECT id, provider_price, status, refunded_at
      FROM sms_orders
      WHERE client_id = ? AND activation_id = ?
      FOR UPDATE
    ");
    $sel->execute([$cid, $aid]);
    $o = $sel->fetch(PDO::FETCH_ASSOC);
    
    if (!$o) {
      $conn->rollBack();
      return [false, 'Not found'];
    }

    $status = norm_status((string)$o['status']);
    if (in_array($status, ['COMPLETED', 'STATUS_CODE_RECEIVED'], true)) {
      $conn->rollBack();
      return [false, 'Cannot cancel after code/completion'];
    }
    
    if (!empty($o['refunded_at'])) {
      $conn->rollBack();
      return [true, 'Already refunded'];
    }

    $amount = (float)$o['provider_price'];

    // Update order status
    $conn->prepare("
      UPDATE sms_orders
      SET status = 'CANCELLED',
          last_status_at = NOW(),
          refunded_at = NOW()
      WHERE client_id = ? AND activation_id = ?
    ")->execute([$cid, $aid]);

    // Refund client balance (FIX: bind amount twice)
    $conn->prepare("
      UPDATE clients
      SET balance = balance + ?,
          spent = GREATEST(0, spent - ?)
      WHERE client_id = ?
    ")->execute([$amount, $amount, $cid]);

    $conn->commit();
    return [true, 'Refunded'];
    
  } catch (Throwable $e) {
    if ($conn->inTransaction()) {
      $conn->rollBack();
    }
    error_log("Refund error: " . $e->getMessage());
    return [false, 'Refund error: ' . $e->getMessage()];
  }
}
/* ---------- AJAX ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST' && isset($_POST['action'])) {
  $action = $_POST['action'];

  if ($action !== 'u_open_activation') {
    if ($aid === '' && isset($_POST['activation_id'])) $aid = (string)$_POST['activation_id']; // keep string
    if ($aid === '') jres(false,'Bad activation id');
    $own=$conn->prepare("SELECT id FROM sms_orders WHERE client_id=:cid AND activation_id=:aid LIMIT 1");
    $own->execute([':cid'=>$CLIENT_ID, ':aid'=>$aid]);
    if(!$own->fetch()) jres(false,'Not found or no access');
  }

  switch($action){

    /* open panel data */
   case 'u_open_activation': {
  $aid=(string)($_POST['activation_id']??'');
  if($aid==='') jres(false,'Bad activation id');

  maybe_expire($conn,$CLIENT_ID,$aid);

  $row = fetch_order($conn,$CLIENT_ID,$aid);
  if(!$row) jres(false,'Not found or no access');

  $leftSec = max(0, (30*60) - (int)$row['age_sec']); // Changed from 10*60 to 30*60
  $final   = is_final_status((string)$row['status']);

  jres(true,'ok',[
    'order'=>[
      'status'        => $row['status'],
      'otp_code'      => $row['otp_code'],
      'otp_text'      => $row['otp_text'],
      'service_name'  => $row['service_name'],
      'country_name'  => $row['country_name'],
      'phone'         => $row['phone'],
      'created_at'    => $row['created_at'],
    ],
    'leftMs'=>$leftSec*1000,
    'final'=>$final
  ]);
}
    /* poll provider for code */
    case 'u_status_poll': {
      if($apiKey === '') jres(false,'Missing API key');

      maybe_expire($conn,$CLIENT_ID,$aid);

      $o = fetch_order($conn,$CLIENT_ID,$aid);
      if(!$o) jres(false,'Not found');

      if (is_final_status($o['status'])) {
        jres(true,'final',[
          'status'=>$o['status'],
          'code'=>$o['otp_code'],
          'text'=>$o['otp_text'],
          'final'=>true
        ]);
      }

      try {
        // SMSPool: GET /sms/check?key=...&orderid=...
        $resp = smspool_get('sms/check', ['orderid' => $aid], $apiKey);
      } catch(Throwable $e){
        jres(false,'Provider error: '.$e->getMessage());
      }

      $provStatus = (int)($resp['status'] ?? 0);
      $code = isset($resp['sms']) ? (string)$resp['sms'] : null;
      $text = isset($resp['full_sms']) ? (string)$resp['full_sms'] : null;
$status = ($provStatus === 3 || ($code!==null && $code!=='')) ? 'STATUS_CODE_RECEIVED'
                                                                : 'STATUS_WAIT_CODE';
$st=$conn->prepare("UPDATE sms_orders
                      SET status=:st,
                          otp_code = COALESCE(:code, otp_code),
                          otp_text = COALESCE(:txt,  otp_text),
                          last_status_at = NOW()
                    WHERE client_id=:cid AND activation_id=:aid");
$st->execute([
  ':st'=>$status, ':code'=>$code, ':txt'=>$text,
  ':cid'=>$CLIENT_ID, ':aid'=>$aid
]);

// ✅ Process affiliate commission ONLY if status is STATUS_CODE_RECEIVED
if ($status === 'STATUS_CODE_RECEIVED') {
    // Fetch order details with actual price from sms_prices
    $orderSt = $conn->prepare("
        SELECT o.id, o.country_id, o.service_id, c.referred_by,
               COALESCE(p.custom_price, p.provider_cost) AS price
          FROM sms_orders o
          JOIN clients c ON c.client_id = o.client_id
          LEFT JOIN sms_prices p ON p.country_id = o.country_id AND p.service_id = o.service_id
         WHERE o.activation_id = :aid AND o.client_id = :cid
         LIMIT 1
    ");
    $orderSt->execute([':aid' => $aid, ':cid' => $CLIENT_ID]);
    $orderData = $orderSt->fetch(PDO::FETCH_ASSOC);

    // Process commission if order found and user was referred
    if ($orderData && !empty($orderData['referred_by']) && $orderData['price'] !== null) {
        $newOrderId = (int)$orderData['id'];
        $pprice = (float)$orderData['price'];
        $refCode = (string)$orderData['referred_by'];
        
        // Get affiliate client_id
        $afSt = $conn->prepare("SELECT client_id FROM clients WHERE ref_code = :ref LIMIT 1");
        $afSt->execute([':ref' => $refCode]);
        $affRow = $afSt->fetch(PDO::FETCH_ASSOC);
        
        if ($affRow) {
            $affiliateClientId = (int)$affRow['client_id'];
            $commissionRate = 10.00; // 10%
            $commissionAmount = ($pprice * $commissionRate) / 100;
            
            // Check if commission already credited (prevent duplicates)
            $checkSt = $conn->prepare("
                SELECT id FROM affiliate_earnings 
                 WHERE order_id = :oid 
                 LIMIT 1
            ");
            $checkSt->execute([':oid' => $newOrderId]);
            
            if (!$checkSt->fetch()) { // Only insert if not already credited
                try {
                    // Log earning
                    $insEarn = $conn->prepare("
                        INSERT INTO affiliate_earnings 
                          (affiliate_client_id, referred_client_id, order_id, order_amount, 
                           commission_rate, commission_amount, status, created_at)
                        VALUES 
                          (:aff, :ref, :oid, :oamt, :rate, :comm, 'credited', NOW())
                    ");
                    $insEarn->execute([
                        ':aff' => $affiliateClientId,
                        ':ref' => $CLIENT_ID,
                        ':oid' => $newOrderId,
                        ':oamt' => $pprice,
                        ':rate' => $commissionRate,
                        ':comm' => $commissionAmount
                    ]);
                    
                    // Update affiliate stats
                    $updStats = $conn->prepare("
                        INSERT INTO affiliate_stats 
                          (client_id, ref_code, total_orders, total_earned, available_balance, created_at, updated_at)
                        VALUES 
                          (:aff, :ref, 1, :comm1, :comm2, NOW(), NOW())
                        ON DUPLICATE KEY UPDATE
                          total_orders = total_orders + 1,
                          total_earned = total_earned + :comm3,
                          available_balance = available_balance + :comm4,
                          updated_at = NOW()
                    ");
                    $updStats->execute([
                        ':aff' => $affiliateClientId,
                        ':ref' => $refCode,
                        ':comm1' => $commissionAmount,
                        ':comm2' => $commissionAmount,
                        ':comm3' => $commissionAmount,
                        ':comm4' => $commissionAmount
                    ]);
                } catch (Throwable $e) {
                    // Silent fail - log error but don't break flow
                    error_log("Affiliate commission failed: " . $e->getMessage());
                }
            }
        }
    }
}

jres(true,'ok',[
  'status'=>$status, 'code'=>$code, 'text'=>$text,
  'final'=>($status==='STATUS_CODE_RECEIVED')
]);
    }

    /* resend code */
    case 'u_resend_sms': {
      if($apiKey === '') jres(false,'Missing API key');

      $o = fetch_order($conn,$CLIENT_ID,$aid);
      if(!$o) jres(false,'Not found');
      if (is_final_status($o['status'])) jres(false,'Locked: final status');

      try {
        // GET /sms/resend
        $resp = smspool_get('sms/resend', ['orderid' => $aid], $apiKey);
      } catch(Throwable $e){
        jres(false,'Provider error: '.$e->getMessage());
      }

      if ((int)($resp['success'] ?? 0) !== 1) {
        $msg = (string)($resp['message'] ?? 'Resend failed');
        jres(false, $msg ?: 'Resend failed');
      }

      jres(true,'resent',[
        'message' => (string)($resp['message'] ?? 'OK'),
        'resend'  => (int)($resp['resend'] ?? 0)
      ]);
    }

    /* local expire guard */
    case 'u_maybe_expire': {
      $changed = maybe_expire($conn,$CLIENT_ID,$aid);
      $o = fetch_order($conn,$CLIENT_ID,$aid);
      jres(true, $changed ? 'expired' : 'nochange', [
        'status'=>$o['status'],
        'final'=>is_final_status($o['status'])
      ]);
    }

    /* set status / cancel / complete */
    case 'u_set_status': {
      if($apiKey === '') jres(false,'Missing API key');

      $val = (int)($_POST['status']??0); // -1 cancel, 6 complete, 1 mark "sent"
      $o   = fetch_order($conn,$CLIENT_ID,$aid);
      if(!$o) jres(false,'Not found');

      if (is_final_status($o['status'])) jres(false,'Locked: final status');

      if ($val === -1) { // cancel + refund
        try {
          // GET /sms/cancel
          $prov = smspool_get('sms/cancel', ['orderid' => $aid], $apiKey);
        } catch(Throwable $e){
          jres(false,'Provider error: '.$e->getMessage());
        }
        if ((int)($prov['success'] ?? 0) !== 1) jres(false,'Provider refused cancel');

        [$ok,$msg] = refund_on_cancel($conn,$CLIENT_ID,$aid);
        if(!$ok) jres(false,$msg);
        jres(true,'cancelled+refunded',['provider'=>$prov,'status'=>'CANCELLED','final'=>true]);
      }

      if ($val === 6) { // complete (finalize locally)
        $conn->prepare("
          UPDATE sms_orders
             SET status='COMPLETED',
                 completed_at=NOW(),
                 last_status_at=NOW()
           WHERE client_id=:cid AND activation_id=:aid
        ")->execute([':cid'=>$CLIENT_ID, ':aid'=>$aid]);

        jres(true,'completed',['status'=>'COMPLETED','final'=>true]);
      }

      // Non-final transitions (e.g., mark "SMS sent")
      $new = ($val===1 ? 'STATUS_SMS_SENT' : 'STATUS_WAIT_CODE');
      $conn->prepare("
        UPDATE sms_orders
           SET status=:s, last_status_at=NOW()
         WHERE client_id=:cid AND activation_id=:aid
      ")->execute([':s'=>$new, ':cid'=>$CLIENT_ID, ':aid'=>$aid]);

      jres(true,'ok',['status'=>$new,'final'=>false]);
    }
  }

  jres(false,'Unknown action');
}

/* ---------- guard for initial GET ---------- */
if ($aid==='') bad();
$st=$conn->prepare("SELECT activation_id FROM sms_orders WHERE client_id=:cid AND activation_id=:aid LIMIT 1");
$st->execute([':cid'=>$CLIENT_ID, ':aid'=>$aid]);
if(!$st->fetch()){ http_response_code(404); echo "Not found"; exit; }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Activation #<?php echo htmlspecialchars($aid, ENT_QUOTES, 'UTF-8'); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
body{background:#fff}
.box{max-width:820px;margin:6vh auto;border:1px solid #e9ecef;border-radius:14px;box-shadow:0 8px 24px rgba(16,24,40,.08)}
.box .hd{padding:14px 16px;border-bottom:1px solid #eef2f7;display:flex;justify-content:space-between;align-items:center}
.box .bd{padding:16px}
.kpi{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.kpi .card{border:1px dashed #e5e7eb;border-radius:10px;padding:10px 12px}
.code{font-size:28px;font-weight:700;letter-spacing:2px}
.meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:12px}
.meta .item{border:1px solid #eef2f7;border-radius:10px;padding:10px 12px;background:#fcfcfd}
.label{font-size:.75rem;color:#6b7280}
.value{font-weight:600;word-break:break-all}
.log{background:#fafafa;border:1px solid #eee;border-radius:10px;padding:10px;max-height:260px;overflow:auto;font-size:.9rem}
.btn[disabled]{pointer-events:none;opacity:.6}
@media (max-width: 640px){ .kpi{grid-template-columns:1fr} .meta{grid-template-columns:1fr} }
</style>
</head>
<body>
<div class="box">
  <div class="hd">
    <div><strong>Activation</strong> <span class="text-muted">#<?php echo htmlspecialchars($aid, ENT_QUOTES, 'UTF-8'); ?></span></div>
    <div><span id="timer" class="badge bg-primary">--:--</span></div>
  </div>

  <div class="bd">
    <!-- Phone / Service / Country -->
    <div class="meta">
      <div class="item">
        <div class="label">Phone</div>
        <div id="v-phone" class="value">—</div>
      </div>
      <div class="item">
        <div class="label">Service</div>
        <div id="v-service" class="value">—</div>
      </div>
      <div class="item">
        <div class="label">Country</div>
        <div id="v-country" class="value">—</div>
      </div>
    </div>

    <div class="kpi mb-3">
      <div class="card"><div class="label">Status</div><div id="st">—</div></div>
      <div class="card"><div class="label">Code</div><div id="code" class="code">—</div></div>
      <div class="card"><div class="label">Message</div><div id="msg" class="small">—</div></div>
    </div>

    <div class="d-flex gap-2 flex-wrap mb-3">
      <button class="btn btn-outline-secondary btn-sm" data-action="1">Mark SMS Sent</button>
      <button class="btn btn-outline-warning btn-sm" data-action="3" id="btn-resend">Request Another Code</button>

      <button class="btn btn-danger btn-sm ms-auto" data-action="-1">Cancel</button>
    </div>

    <div class="log" id="log"></div>
  </div>
</div>

<script>
const AID = <?php echo json_encode($aid, JSON_UNESCAPED_SLASHES); ?>; // keep full string (fixes "Access denied")
const POLL_MS = 4000;

let expireAt = null;
let pollT=null, tickT=null;

const toast=(icon,title)=>Swal.fire({toast:true,position:'top-end',timer:1800,showConfirmButton:false,icon,title});
function post(action,data,cb){ $.ajax({url:'',method:'POST',data:Object.assign({action},data||{}),dataType:'json',success:cb,error:()=>cb({status:false,message:'Network error'})}); }
function addLog(line){ const t = new Date().toLocaleTimeString(); $('#log').prepend(`<div><span class="text-muted">[${t}]</span> ${line}</div>`); }
function setButtons(final){ $('button[data-action]').prop('disabled', !!final); }

function poll(){
  post('u_status_poll',{activation_id:AID},res=>{
    if(!res.status){ addLog(`<span class="text-danger">Poll failed:</span> ${res.message||'error'}`); return; }
    const d = res.data;
    $('#st').text(d.status || '—');
    $('#code').text(d.code || '—');
    $('#msg').text(d.text || (d.code ? 'Code received' : '—'));
    if(d.final){ setButtons(true); stopTimers(); addLog('<i>Final state. Timers stopped.</i>'); }
  });
}

function tick(){
  if (expireAt===null) return;
  const left = Math.max(0, expireAt - Date.now());
  const m = Math.floor(left/60000), s = Math.floor((left%60000)/1000);
  $('#timer').text(`${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`);
  if(left<=0){
    stopTimers();
    addLog('<span class="text-danger">Local timer finished → asking server to expire…</span>');
    post('u_maybe_expire',{activation_id:AID},res=>{
      if(res.status){
        $('#st').text(res.data.status||'EXPIRED');
        setButtons(true);
        addLog('<b>Expired</b> (server-validated).');
        toast('warning','Expired');
      }else{
        addLog('Expire request failed.');
      }
    });
  }
}

function stopTimers(){ if(pollT){clearInterval(pollT);pollT=null} if(tickT){clearInterval(tickT);tickT=null} }

function doSetStatus(val){
  $('button[data-action]').prop('disabled',true);
  post('u_set_status',{activation_id:AID,status:val},res=>{
    if(res.status){
      addLog(`<i>setStatus(${val})</i> → ${res.data.status || 'ok'}`);
      $('#st').text(res.data.status || $('#st').text());
      if(res.data.status==='COMPLETED' || res.data.status==='CANCELLED'){ stopTimers(); setButtons(true); }
      if(val!==-1 && val!==6) $('button[data-action]').prop('disabled',false);
      poll();
    }else{
      toast('error',res.message||'Failed');
      $('button[data-action]').prop('disabled',false);
    }
  });
}

$(document).on('click','[data-action]',function(){
  const v = parseInt($(this).data('action'),10);
  if(v===3){ // resend
    post('u_resend_sms',{activation_id:AID},res=>{
      if(res.status){ toast('success','Resent'); addLog('Resend: '+(res.data.message||'OK')); }
      else { toast('error',res.message||'Resend failed'); addLog('<span class="text-danger">Resend failed:</span> '+(res.message||'')); }
    });
    return;
  }
  doSetStatus(v);
});

// initial open
post('u_open_activation',{activation_id:AID},res=>{
  if(!res.status){ addLog('<span class="text-danger">Access denied</span>'); return; }
  const d = res.data;

  // Fill meta
  $('#v-phone').text(d.order.phone || '—');
  $('#v-service').text(d.order.service_name || '—');
  $('#v-country').text(d.order.country_name || '—');

  $('#st').text(d.order.status || '—');
  $('#code').text(d.order.otp_code || '—');
  $('#msg').text(d.order.otp_text || '—');

  expireAt = Date.now() + (d.leftMs ?? 0);
  setButtons(!!d.final);

  if(d.final){
    $('#timer').text('00:00');
    addLog('Already final on open.');
  }else{
    addLog('Activation opened. Polling started.');
    poll(); pollT=setInterval(poll, POLL_MS);
    tick(); tickT=setInterval(tick, 5000);
  }
});
</script>
</body>
</html>
<?php exit; ?>