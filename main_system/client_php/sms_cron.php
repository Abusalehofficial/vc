<?php
declare(strict_types=1);

/**
 * SMS Order Expiry + Refund Cron (HTTP)
 * 
 * Step 1: Mark old waiting orders as EXPIRED if:
 *         - created_at < NOW() - 30 minutes
 *         - otp_code IS NULL
 *         - status IN ('PENDING', 'STATUS_WAIT_CODE')
 * 
 * Step 2: Refund EXPIRED orders
 */

define('CRON_SECRET_TOKEN', 'b8e4f9d0c3a2b6e1f9d8c7a6b5e4f3d2c1a0b9e8f7d6c5a4b3e2f1d0c9b8a7e6');

// ============================================
// HEADERS
// ============================================
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// ============================================
// TOKEN AUTHENTICATION
// ============================================
$provided_token = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? $_POST['token'] ?? '';

if (!hash_equals(CRON_SECRET_TOKEN, trim($provided_token))) {
    http_response_code(403);
    error_log("SMS Cron: Unauthorized access from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    die("403 Forbidden - Invalid token\n");
}

// ============================================
// PDO CHECK
// ============================================
if (!isset($conn) || !($conn instanceof PDO)) {
    http_response_code(500);
    echo "PDO \$conn not available.\n";
    exit(1);
}

function out(string $msg): void {
    echo "[" . date('Y-m-d H:i:s') . "] $msg\n";
}

// ============================================
// STEP 1: MARK OLD ORDERS AS EXPIRED
// ============================================
try {
    out("=== STEP 1: Marking old waiting orders as EXPIRED ===");

    // Find all pending/waiting orders older than 30 minutes with no OTP
    $findPending = $conn->prepare("
        SELECT id
        FROM sms_orders
        WHERE status IN ('PENDING', 'STATUS_WAIT_CODE')
          AND otp_code IS NULL
          AND created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
        ORDER BY id ASC
        LIMIT 500
    ");

    $lockPending = $conn->prepare("
        SELECT id, status, otp_code, created_at
        FROM sms_orders
        WHERE id = ?
        FOR UPDATE
    ");

    $markExpired = $conn->prepare("
        UPDATE sms_orders
        SET status = 'EXPIRED',
            expired_at = NOW(),
            last_status_at = NOW()
        WHERE id = ?
    ");

    $findPending->execute();
    $pendingIds = $findPending->fetchAll(PDO::FETCH_COLUMN, 0);

    if (empty($pendingIds)) {
        out("No pending/waiting orders to expire.");
    } else {
        out("Found " . count($pendingIds) . " pending/waiting order(s).");

        $expired_count = 0;
        $skipped_count = 0;

        foreach ($pendingIds as $orderId) {
            $orderId = (int)$orderId;

            try {
                $conn->beginTransaction();

                // Lock and re-check
                $lockPending->execute([$orderId]);
                $row = $lockPending->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $conn->rollBack();
                    $skipped_count++;
                    continue;
                }

                $status = $row['status'];
                $hasOtp = !empty($row['otp_code']);
                $createdAt = strtotime($row['created_at']);
                $ageMinutes = (time() - $createdAt) / 60;

                // Skip if: got OTP, status changed, or not old enough
                if ($hasOtp || !in_array($status, ['PENDING', 'STATUS_WAIT_CODE'], true) || $ageMinutes < 30) {
                    $conn->rollBack();
                    $skipped_count++;
                    continue;
                }

                // Mark as EXPIRED
                $markExpired->execute([$orderId]);
                $conn->commit();

                $expired_count++;
                out("Expired order #{$orderId} (status: {$status}, age: " . round($ageMinutes, 1) . " min)");

            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                out("Error expiring #{$orderId}: " . $e->getMessage());
            }
        }

        out("Expiry complete: expired={$expired_count}, skipped={$skipped_count}");
    }

} catch (Throwable $e) {
    out("Fatal error in STEP 1: " . $e->getMessage());
    exit(1);
}

// ============================================
// STEP 2: REFUND EXPIRED ORDERS
// ============================================
try {
    out("\n=== STEP 2: Refunding EXPIRED orders ===");

    $getCandidates = $conn->prepare("
        SELECT id
        FROM sms_orders
        WHERE status = 'EXPIRED'
          AND expired_at IS NOT NULL
          AND refunded_at IS NULL
          AND otp_code IS NULL
          AND provider_price > 0
        ORDER BY id ASC
        LIMIT 500
    ");

    $lockRow = $conn->prepare("
        SELECT id, client_id, status, otp_code, refunded_at, provider_price
        FROM sms_orders
        WHERE id = ?
        FOR UPDATE
    ");

    $refundClient = $conn->prepare("
        UPDATE clients
        SET balance = balance + ?,
            spent = GREATEST(0, spent - ?)
        WHERE client_id = ?
    ");

    $markRefunded = $conn->prepare("
        UPDATE sms_orders
        SET refunded_at = NOW(),
            last_status_at = NOW()
        WHERE id = ?
    ");

    $getCandidates->execute();
    $ids = $getCandidates->fetchAll(PDO::FETCH_COLUMN, 0);

    if (empty($ids)) {
        out("No EXPIRED orders to refund.");
        exit(0);
    }

    out("Found " . count($ids) . " EXPIRED order(s) to refund.");

    $success = 0;
    $skipped = 0;
    $errors = 0;

    foreach ($ids as $orderId) {
        $orderId = (int)$orderId;

        try {
            $conn->beginTransaction();

            $lockRow->execute([$orderId]);
            $o = $lockRow->fetch(PDO::FETCH_ASSOC);

            if (!$o) {
                $conn->rollBack();
                $skipped++;
                continue;
            }

            $status = (string)$o['status'];
            $hasOtp = !empty($o['otp_code']);
            $refunded = !empty($o['refunded_at']);
            $amount = max(0.0, (float)$o['provider_price']);
            $clientId = (int)$o['client_id'];

            // Must be EXPIRED, no OTP, not refunded, amount > 0
            if ($status !== 'EXPIRED' || $hasOtp || $refunded || $amount <= 0 || $clientId <= 0) {
                $conn->rollBack();
                $skipped++;
                continue;
            }

            // Credit balance
            $refundClient->execute([$amount, $amount, $clientId]);

            // Mark refunded
            $markRefunded->execute([$orderId]);

            $conn->commit();
            $success++;
            out("Refunded order #{$orderId}, client={$clientId}, amount={$amount}");

        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $errors++;
            out("Error refunding #{$orderId}: " . $e->getMessage());
        }
    }

    out("Refund complete: success={$success}, skipped={$skipped}, errors={$errors}");

} catch (Throwable $e) {
    out("Fatal error in STEP 2: " . $e->getMessage());
    exit(1);
}

out("\n=== Cron completed successfully ===");
