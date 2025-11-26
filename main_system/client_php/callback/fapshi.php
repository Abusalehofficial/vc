<?php
declare(strict_types=1);

/** --------- Utilities --------- */
function redirect(string $path): void {
    header('Location: ' . $path);
    exit;
}

function jsonResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/** --------- Detect Request Type --------- */
$isWebhook = ($_SERVER['REQUEST_METHOD'] === 'POST');
$externalId = '';
$webhookStatus = '';
$webhookData = null;

if ($isWebhook) {
    // Handle POST webhook request
    $input = file_get_contents('php://input');
    $webhookData = json_decode($input, true);
    
    if (!is_array($webhookData) || empty($webhookData['externalId'])) {
        jsonResponse(['error' => 'Invalid webhook data'], 400);
    }
    
    $externalId = (string)($webhookData['externalId'] ?? '');
    $webhookStatus = strtoupper((string)($webhookData['status'] ?? ''));
} else {
    // Handle GET browser redirect
    $externalId = filter_input(INPUT_GET, 'externalId', FILTER_SANITIZE_STRING) ?? '';
}

if ($externalId === '') {
    if ($isWebhook) {
        jsonResponse(['error' => 'Missing externalId'], 400);
    } else {
        redirect(site_url('addfunds'));
    }
}

/** --------- DB + Config Load (PDO $conn must exist globally) --------- */
function methodExtras(PDO $conn, int $id): array {
    $stmt = $conn->prepare('SELECT method_extras FROM payment_methods WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $json = $stmt->fetchColumn();
    if (!$json) return [];
    $arr = json_decode((string)$json, true);
    return is_array($arr) ? $arr : [];
}

// Your fapshi method id here
$fapshiMethodId = 2269;

// Load gateway config (api keys, fees, conversion)
$extra = methodExtras($conn, $fapshiMethodId);
$apiUser  = (string)($extra['api_user']        ?? '');
$apiKey   = (string)($extra['api_key']         ?? '');
$feePct   = (float) ($extra['fee']             ?? 0.0);
$fxRate   = (float) ($extra['conversion_rate'] ?? 601.79); // fallback to your constant

if ($apiUser === '' || $apiKey === '') {
    // Misconfigured gateway
    if ($isWebhook) {
        jsonResponse(['error' => 'Gateway misconfigured'], 500);
    } else {
        redirect(site_url('addfunds'));
    }
}

/** --------- Fapshi SDK bootstrap --------- */
// Expecting your autoload & Fapshi class to be available.
try {
    $fapshi = new Fapshi($apiUser, $apiKey);
} catch (Throwable $e) {
    if ($isWebhook) {
        jsonResponse(['error' => 'SDK initialization failed'], 500);
    } else {
        redirect(site_url('addfunds'));
    }
}

/** --------- Fetch Payment Row (FOR UPDATE) --------- */
try {
    // Begin early so row is locked through status check & credit
    $conn->beginTransaction();

    // Join client for balance + guard against already-completed
    $q = $conn->prepare(
        'SELECT p.*, c.client_id, c.balance AS client_balance
           FROM payments p
           INNER JOIN clients c ON c.client_id = p.client_id
          WHERE p.payment_extra = :extra
          LIMIT 1
          FOR UPDATE'
    );
    $q->execute(['extra' => $externalId]);
    $payment = $q->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        // Nothing to do
        $conn->rollBack();
        if ($isWebhook) {
            jsonResponse(['error' => 'Payment not found'], 404);
        } else {
            redirect(site_url('addfunds'));
        }
    }

    // Idempotency: if already completed, exit cleanly
    if ((int)$payment['payment_status'] === 3) {
        $conn->rollBack(); // nothing changed
        if ($isWebhook) {
            jsonResponse(['message' => 'Payment already processed'], 200);
        } else {
            redirect(site_url('addfunds'));
        }
    }

    // We expect transId saved in payment_note at initiation time
    $transId = (string)($payment['payment_note'] ?? '');
    if ($transId === '') {
        $conn->rollBack();
        if ($isWebhook) {
            jsonResponse(['error' => 'Transaction ID not found'], 400);
        } else {
            redirect(site_url('addfunds'));
        }
    }

    /** --------- Handle Webhook Status (if POST) --------- */
    if ($isWebhook && $webhookStatus === 'CREATED') {
        // For CREATED status, just acknowledge and do nothing
        $conn->rollBack();
        jsonResponse(['message' => 'Webhook received, waiting for payment confirmation'], 200);
    }

    /** --------- Confirm with Fapshi --------- */
    $resp = $fapshi->payment_status($transId);
    $respArr = is_array($resp) ? $resp : json_decode((string)$resp, true);
    if (!is_array($respArr)) {
        $conn->rollBack();
        if ($isWebhook) {
            jsonResponse(['error' => 'Invalid response from payment gateway'], 500);
        } else {
            redirect(site_url('addfunds'));
        }
    }

    // Only accept SUCCESSFUL status for crediting account
    $status = strtoupper((string)($respArr['status'] ?? ''));
    $isSuccess = ($status === 'SUCCESSFUL');
    
    if (!$isSuccess) {
        // Not successful yet; no state change
        $conn->rollBack();
        if ($isWebhook) {
            // For webhooks, return 200 even if not successful yet (to acknowledge receipt)
            jsonResponse(['message' => 'Payment not yet successful', 'status' => $status], 200);
        } else {
            redirect(site_url('addfunds'));
        }
    }

    /** --------- Amount Calculation ---------
     * Your original code divided by 601.79; we keep that behavior via $fxRate.
     * Then apply fee% if any, and round to 2 decimals safely for money.
     */
    $rawAmount = (float)$payment['payment_amount']; // stored in gateway currency
    $baseAmount = $fxRate > 0 ? ($rawAmount / $fxRate) : $rawAmount; // convert to panel currency
    $feeAmount  = ($feePct > 0) ? ($baseAmount * ($feePct / 100.0)) : 0.0;
    $credit     = round($baseAmount - $feeAmount, 2);
    if ($credit <= 0) {
        // Defensive: do not credit zero/negative
        $conn->rollBack();
        if ($isWebhook) {
            jsonResponse(['error' => 'Invalid credit amount'], 400);
        } else {
            redirect(site_url('addfunds'));
        }
    }

    // New client balance
    $newBalance = round(((float)$payment['client_balance']) + $credit, 2);

    /** --------- Persist Changes --------- */
    // 1) Update payment (set completed)
    $up1 = $conn->prepare(
        'UPDATE payments
            SET client_balance = :client_balance,
                payment_amount = :net_amount,
                payment_status = :status_done,
                payment_delivery = :delivery_done
          WHERE payment_id = :pid'
    )->execute([
        'client_balance' => $newBalance,
        'net_amount'     => $credit,
        'status_done'    => 3,   // completed
        'delivery_done'  => 2,   // your code uses 2
        'pid'            => $payment['payment_id'],
    ]);

    // 2) Update client balance
    $up2 = $conn->prepare(
        'UPDATE clients SET balance = :balance WHERE client_id = :cid'
    )->execute([
        'balance' => $newBalance,
        'cid'     => $payment['client_id'],
    ]);

    // 3) Insert client report
    // Get currency and method name safely
    $currency = $settings['currency'] ?? ($settings['site_currency'] ?? '');
    $methodName = $method['method_name'] ?? 'Fapshi';
    
    $reportText = sprintf(
        'New %.2f %s payment has been made with %s',
        $credit,
        $currency,
        $methodName
    );

    $ins = $conn->prepare(
        'INSERT INTO client_report (client_id, action, report_ip, report_date)
         VALUES (:cid, :action, :ip, :dt)'
    )->execute([
        'cid'    => $payment['client_id'],
        'action' => $reportText,
        'ip'     => GetIP(),
        'dt'     => date('Y-m-d H:i:s'),
    ]);

    if ($up1 && $up2 && $ins) {
        $conn->commit();
        if ($isWebhook) {
            jsonResponse(['message' => 'Payment processed successfully', 'externalId' => $externalId], 200);
        } else {
            redirect(site_url('addfunds'));
        }
    } else {
        $conn->rollBack();
        if ($isWebhook) {
            jsonResponse(['error' => 'Failed to update payment'], 500);
        } else {
            redirect(site_url('addfunds'));
        }
    }

} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    if ($isWebhook) {
        // Log error for webhooks but return generic message
        error_log('Fapshi webhook error: ' . $e->getMessage());
        jsonResponse(['error' => 'Internal server error'], 500);
    } else {
        // Quiet fail to funds page (avoid leaking internal errors)
        redirect(site_url('addfunds'));
    }
}
