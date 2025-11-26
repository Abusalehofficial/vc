<?php
declare(strict_types=1);

/**
 * Fapshi Reconciliation (Batch, Silent)
 * - No CLI args, no file locking, no file logging.
 * - Processes all non-completed Fapshi payments that have a transId in payment_note.
 * - Uses prepared statements + per-row transactions (idempotent).
 *
 * Assumptions:
 * - $conn (PDO) is already bootstrapped (ERRMODE_EXCEPTION).
 * - Fapshi SDK class available via your autoloader.
 */

const METHOD_ID_FAPSHI = 2269;
const BATCH_LIMIT      = 100;

// ---- Helpers ----------------------------------------------------------

function methodExtras(PDO $conn, int $id): array {
    $stmt = $conn->prepare('SELECT method_extras FROM payment_methods WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $json = $stmt->fetchColumn();
    return $json ? (json_decode((string)$json, true) ?: []) : [];
}

function selectBatchPending(PDO $conn, int $limit): array {
    $sql = 'SELECT p.payment_id, p.client_id, p.payment_amount, p.payment_status,
                   p.payment_note, c.balance AS client_balance
              FROM payments p
              INNER JOIN clients c ON c.client_id = p.client_id
             WHERE p.payment_method = :mid
               AND p.payment_status <> 3
               AND p.payment_note IS NOT NULL
               AND p.payment_note <> ""
             ORDER BY p.payment_id DESC
             LIMIT :lim';
    $stmt = $conn->prepare($sql);
    $stmt->bindValue('mid', METHOD_ID_FAPSHI, PDO::PARAM_INT);
    $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function processPayment(PDO $conn, Fapshi $fapshi, array $row, array $cfg): bool {
    try {
        $conn->beginTransaction();

        // Re-lock fresh copy for idempotency
        $stmt = $conn->prepare(
            'SELECT p.*, c.balance AS client_balance
               FROM payments p
               INNER JOIN clients c ON c.client_id = p.client_id
              WHERE p.payment_id = :pid
              FOR UPDATE'
        );
        $stmt->execute(['pid' => $row['payment_id']]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$p) { $conn->rollBack(); return false; }

        if ((int)$p['payment_status'] === 3) {
            $conn->rollBack(); // already completed
            return true;
        }

        $transId = (string)($p['payment_note'] ?? '');
        if ($transId === '') {
            $conn->rollBack();
            return false;
        }

        // Gateway status
        $resp = $fapshi->payment_status($transId);
        $respArr = is_array($resp) ? $resp : json_decode((string)$resp, true);
        if (!is_array($respArr)) { $conn->rollBack(); return false; }

        $status = strtoupper((string)($respArr['status'] ?? ''));
        if ($status !== 'SUCCESSFUL') { // strict per your instruction
            $conn->rollBack();
            return false;
        }

        // Amount calc -> convert, fee, credit
        $rawAmount  = (float)$p['payment_amount'];                 // gateway currency
        $fxRate     = (float)$cfg['fxRate'];                       // e.g. 601.79
        $baseAmount = $fxRate > 0 ? ($rawAmount / $fxRate) : $rawAmount;
        $feePct     = (float)$cfg['feePct'];
        $feeAmount  = $feePct > 0 ? ($baseAmount * ($feePct / 100.0)) : 0.0;
        $credit     = round($baseAmount - $feeAmount, 2);
        if ($credit <= 0) { $conn->rollBack(); return false; }

        $newBalance = round(((float)$p['client_balance']) + $credit, 2);

        // Persist
        $conn->prepare(
            'UPDATE payments
                SET client_balance = :bal,
                    payment_amount = :net_amt,
                    payment_status = 3,
                    payment_delivery = 2
              WHERE payment_id = :pid'
        )->execute([
            'bal'     => $newBalance,
            'net_amt' => $credit,
            'pid'     => $p['payment_id'],
        ]);

        $conn->prepare('UPDATE clients SET balance = :bal WHERE client_id = :cid')
             ->execute(['bal' => $newBalance, 'cid' => $p['client_id']]);

        $conn->prepare(
            'INSERT INTO client_report (client_id, action, report_ip, report_date)
             VALUES (:cid, :act, :ip, :dt)'
        )->execute([
            'cid' => $p['client_id'],
            'act' => sprintf('Cron: New %.2f %s payment credited via Fapshi', $credit, $cfg['currency']),
            'ip'  => '127.0.0.1',
            'dt'  => date('Y-m-d H:i:s'),
        ]);

        $conn->commit();
        return true;

    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        // Silent by design (no logs/files)
        return false;
    }
}

// ---- Bootstrap --------------------------------------------------------

/** @var PDO $conn */
$extra   = methodExtras($conn, METHOD_ID_FAPSHI);
$apiUser = (string)($extra['api_user'] ?? '');
$apiKey  = (string)($extra['api_key'] ?? '');
$feePct  = (float)($extra['fee'] ?? 0.0);
$fxRate  = (float)($extra['conversion_rate'] ?? 601.79);

// Hard fail silently if misconfigured
if ($apiUser === '' || $apiKey === '' || !class_exists('Fapshi')) {
    exit(0);
}

try {
    $fapshi = new Fapshi($apiUser, $apiKey);
} catch (Throwable $e) {
    exit(0);
}

$cfg = [
    'currency' => 'NGN',
    'feePct'   => $feePct,
    'fxRate'   => $fxRate,
];

// ---- Run (batch-only) -------------------------------------------------

$rows = selectBatchPending($conn, BATCH_LIMIT);
if (!$rows) { exit(0); }

foreach ($rows as $r) {
    processPayment($conn, $fapshi, $r, $cfg);
}


exit(0);
