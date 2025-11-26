<?php
declare(strict_types=1);

$title .= 'Affiliate Program';
userSecureHeader();

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

if (!isset($conn) || !($conn instanceof PDO)) {
    http_response_code(500);
    echo "PDO connection unavailable";
    exit;
}

if (!isset($user['client_id'])) {
    http_response_code(403);
    echo "Unauthorized";
    exit;
}

$CLIENT_ID = (int)$user['client_id'];
$COMMISSION_RATE = 10.00; // 10% commission on every order

/* ---------- Helpers ---------- */
function jres(bool $ok, string $msg = '', array $data = []): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => $ok,
        'message' => $msg,
        'data' => $data
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- Initialize affiliate stats if not exists ---------- */
function ensureAffiliateStats(PDO $conn, int $clientId, string $refCode): void {
    $check = $conn->prepare("SELECT id FROM affiliate_stats WHERE client_id = :cid LIMIT 1");
    $check->execute([':cid' => $clientId]);
    
    if (!$check->fetch()) {
        $ins = $conn->prepare("
            INSERT INTO affiliate_stats 
              (client_id, ref_code, created_at, updated_at) 
            VALUES 
              (:cid, :ref, NOW(), NOW())
        ");
        $ins->execute([':cid' => $clientId, ':ref' => $refCode]);
    }
}

// Ensure current user has affiliate stats
$userRefCode = $user['ref_code'] ?? '';
if (empty($userRefCode)) {
    // Generate ref_code if missing
    $userRefCode = strtoupper(substr(bin2hex(random_bytes(6)), 0, 8));
    $upd = $conn->prepare("UPDATE clients SET ref_code = :ref WHERE client_id = :cid");
    $upd->execute([':ref' => $userRefCode, ':cid' => $CLIENT_ID]);
    $user['ref_code'] = $userRefCode;
}

ensureAffiliateStats($conn, $CLIENT_ID, $userRefCode);

/* ---------- AJAX Handlers ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            
            /* Get dashboard stats */
            case 'get_stats': {
                $st = $conn->prepare("
                    SELECT total_clicks, total_signups, total_orders, 
                           total_earned, total_withdrawn, available_balance
                      FROM affiliate_stats
                     WHERE client_id = :cid
                     LIMIT 1
                ");
                $st->execute([':cid' => $CLIENT_ID]);
                $stats = $st->fetch(PDO::FETCH_ASSOC);
                
                if (!$stats) {
                    $stats = [
                        'total_clicks' => 0,
                        'total_signups' => 0,
                        'total_orders' => 0,
                        'total_earned' => '0.0000',
                        'total_withdrawn' => '0.0000',
                        'available_balance' => '0.0000'
                    ];
                }
                
                // Count active referrals
                $act = $conn->prepare("
                    SELECT COUNT(*) as cnt 
                      FROM clients 
                     WHERE referred_by = :ref
                ");
                $act->execute([':ref' => $userRefCode]);
                $activeCount = (int)$act->fetchColumn();
                
                jres(true, 'ok', [
                    'stats' => $stats,
                    'active_referrals' => $activeCount,
                    'ref_link' => site_url() . '?ref=' . $userRefCode
                ]);
            }
            
            /* Get earnings history */
            case 'get_earnings': {
                $lim = max(10, min(100, (int)($_POST['limit'] ?? 25)));
                $off = max(0, (int)($_POST['offset'] ?? 0));
                
                $st = $conn->prepare("
                    SELECT e.id, e.referred_client_id, e.order_amount, 
                           e.commission_rate, e.commission_amount, 
                           e.status, e.created_at,
                           c.username as ref_username
                      FROM affiliate_earnings e
                      LEFT JOIN clients c ON c.client_id = e.referred_client_id
                     WHERE e.affiliate_client_id = :cid
                     ORDER BY e.id DESC
                     LIMIT :lim OFFSET :off
                ");
                $st->bindValue(':cid', $CLIENT_ID, PDO::PARAM_INT);
                $st->bindValue(':lim', $lim, PDO::PARAM_INT);
                $st->bindValue(':off', $off, PDO::PARAM_INT);
                $st->execute();
                
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($rows as &$r) {
                    $r['commission_amount_formatted'] = function_exists('convert_currency') 
                        ? convert_currency($r['commission_amount'], true) 
                        : number_format((float)$r['commission_amount'], 2);
                }
                unset($r);
                
                jres(true, 'ok', [
                    'rows' => $rows,
                    'next_offset' => (count($rows) < $lim ? null : $off + $lim)
                ]);
            }
            
            /* Get referrals list */
            case 'get_referrals': {
                $lim = max(10, min(100, (int)($_POST['limit'] ?? 25)));
                $off = max(0, (int)($_POST['offset'] ?? 0));
                
                $st = $conn->prepare("
                    SELECT c.client_id, c.username, c.email, c.register_date,
                           COALESCE(SUM(o.provider_price), 0) as total_spent,
                           COUNT(o.id) as order_count
                      FROM clients c
                      LEFT JOIN sms_orders o ON o.client_id = c.client_id
                     WHERE c.referred_by = :ref
                     GROUP BY c.client_id
                     ORDER BY c.register_date DESC
                     LIMIT :lim OFFSET :off
                ");
                $st->bindValue(':ref', $userRefCode, PDO::PARAM_STR);
                $st->bindValue(':lim', $lim, PDO::PARAM_INT);
                $st->bindValue(':off', $off, PDO::PARAM_INT);
                $st->execute();
                
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                
                jres(true, 'ok', [
                    'rows' => $rows,
                    'next_offset' => (count($rows) < $lim ? null : $off + $lim)
                ]);
            }
            
            /* Get withdrawal history */
case 'get_withdrawals': {
    $lim = max(10, min(100, (int)($_POST['limit'] ?? 25)));
    $off = max(0, (int)($_POST['offset'] ?? 0));
    
    $st = $conn->prepare("
        SELECT id, amount, status, created_at, processed_at, notes
          FROM affiliate_withdrawals
         WHERE client_id = :cid
         ORDER BY id DESC
         LIMIT :lim OFFSET :off
    ");
    $st->bindValue(':cid', $CLIENT_ID, PDO::PARAM_INT);
    $st->bindValue(':lim', $lim, PDO::PARAM_INT);
    $st->bindValue(':off', $off, PDO::PARAM_INT);
    $st->execute();
    
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($rows as &$r) {
        $r['amount_formatted'] = function_exists('convert_currency') 
            ? convert_currency($r['amount'], true) 
            : '₹' . number_format((float)$r['amount'], 2);
    }
    unset($r);
    
    jres(true, 'ok', [
        'rows' => $rows,
        'next_offset' => (count($rows) < $lim ? null : $off + $lim)
    ]);
}
       /* Request withdrawal - Auto-approved, adds to main balance */
case 'request_withdrawal': {
    $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
    
    if ($amount <= 0) {
        jres(false, 'Invalid amount');
    }
    
    if ($amount < 10) {
        jres(false, 'Minimum withdrawal is ₹10');
    }
    
    // Check available balance
    $st = $conn->prepare("
        SELECT available_balance 
          FROM affiliate_stats 
         WHERE client_id = :cid 
         LIMIT 1
    ");
    $st->execute([':cid' => $CLIENT_ID]);
    $avail = (float)($st->fetchColumn() ?: 0);
    
    if ($amount > $avail) {
        jres(false, 'Insufficient affiliate balance');
    }
    
    $conn->beginTransaction();
    
    try {
        // Add to user's main balance
        $updClient = $conn->prepare("
            UPDATE clients 
               SET balance = balance + :amt
             WHERE client_id = :cid
        ");
        $updClient->execute([':amt' => $amount, ':cid' => $CLIENT_ID]);
        
        // Deduct from affiliate available balance and add to withdrawn total
        $updStats = $conn->prepare("
            UPDATE affiliate_stats 
               SET available_balance = available_balance - :amt1,
                   total_withdrawn = total_withdrawn + :amt2,
                   updated_at = NOW()
             WHERE client_id = :cid
        ");
        $updStats->execute([
            ':amt1' => $amount,
            ':amt2' => $amount,
            ':cid' => $CLIENT_ID
        ]);
        
        // Log withdrawal for record
        $insLog = $conn->prepare("
            INSERT INTO affiliate_withdrawals 
              (client_id, amount, status, processed_at, created_at) 
            VALUES 
              (:cid, :amt, 'approved', NOW(), NOW())
        ");
        $insLog->execute([':cid' => $CLIENT_ID, ':amt' => $amount]);
        
        $conn->commit();
        
        // Update runtime user balance
        $user['balance'] = (float)$user['balance'] + $amount;
        
        jres(true, 'Withdrawal successful! $' . number_format($amount, 2) . ' added to your main balance.', [
            'new_balance' => number_format($user['balance'], 2)
        ]);
        
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        jres(false, 'Withdrawal failed: ' . $e->getMessage());
    }
}
            default:
                jres(false, 'Unknown action');
        }
        
    } catch (Throwable $e) {
        jres(false, $e->getMessage());
    }
    exit;
}