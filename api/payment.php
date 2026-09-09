<?php
/**
 * Mobifooty — payments (Paystack Ghana Mobile Money).
 *
 * POST /api/payment.php {tier, phone}
 *   -> creates a pending transaction, calls Paystack /charge which pushes
 *      the STK/USSD prompt to the customer's phone, returns the reference.
 *
 * GET  /api/payment.php?reference=REF
 *   -> returns the stored transaction + gateway status (pending/success/
 *      failed). Used for polling. NOTE: a tier is ONLY granted inside
 *      api/webhook.php after Paystack's signed callback — never here.
 *
 * GET  /api/payment.php?action=transactions
 *   -> current user's transaction history.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/helpers.php';

$method = $_SERVER['REQUEST_METHOD'];
$user = current_user();
$body = $method === 'POST' ? body_json() : [];
$action = $_GET['action'] ?? $body['action'] ?? 'charge';

/* ---------------- GET: transaction history ---------------- */
if ($method === 'GET' && $action === 'transactions') {
    if (!$user) {
        json_err('Not signed in', 401);
    }
    json_ok(['transactions' => user_transactions($user['id'])]);
}

/* ---------------- POST/GET: submit OTP ---------------- */
if ($action === 'submit_otp') {
    if (!$user) json_err('Not signed in', 401);
    $ref = $body['reference'] ?? '';
    $otp = $body['otp'] ?? '';
    if (!$ref || !$otp) json_err('Missing reference or OTP');
    
    $res = http_json('POST', PAYSTACK_BASE . '/charge/submit_otp', paystack_headers(), [
        'otp' => $otp,
        'reference' => $ref
    ]);

    if ($res['status'] === 0 || $res['body'] === null) {
        json_err('Could not reach the payment provider.', 502);
    }
    $pStatus = $res['body']['data']['status'] ?? 'pending';
    $db = Database::getInstance()->getConnection();
    
    if ($pStatus === 'success') {
        $db->prepare('UPDATE transactions SET status = "success" WHERE reference = ?')->execute([$ref]);
        $stmt = $db->prepare('SELECT id, tier FROM transactions WHERE reference = ?');
        $stmt->execute([$ref]);
        $tRow = $stmt->fetch();
        if ($tRow) grant_tier($user['id'], $tRow['tier'], $tRow['id']);
    } elseif ($pStatus === 'failed') {
        $db->prepare('UPDATE transactions SET status = "failed" WHERE reference = ?')->execute([$ref]);
    }

    json_ok([
        'reference' => $ref,
        'status'    => in_array($pStatus, ['success', 'failed']) ? $pStatus : 'pending',
        'paystack_status' => $pStatus,
        'message'   => $res['body']['data']['message'] ?? $res['body']['message'] ?? 'OTP submitted'
    ]);
}

/* ---------------- GET: poll one reference ---------------- */
if ($method === 'GET' && !empty($_GET['reference']) && $action !== 'transactions') {
    $ref = preg_replace('/[^A-Za-z0-9._\-=]/', '', $_GET['reference']);
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare('SELECT * FROM transactions WHERE reference = ?');
    $stmt->execute([$ref]);
    $txn = $stmt->fetch();
    if (!$txn) {
        json_err('Transaction not found', 404);
    }

    // Re-check with Paystack so the displayed state tracks reality even
    // before (or without) the webhook. Display only — unlock stays in webhook.php.
    if (!PAYSTACK_SANDBOX && $txn['status'] === 'pending') {
        $res = paystack_verify_transaction($ref);
        if ($res['status'] > 0) {
            $remote = $res['body']['data']['status'] ?? null;
            if ($remote === 'success' || $remote === 'failed') {
                $stmt = $db->prepare('UPDATE transactions SET status = ?, gateway_response = ? WHERE id = ?');
                $stmt->execute([$remote, $res['body']['data']['gateway_response'] ?? null, $txn['id']]);
                $txn['status'] = $remote;
                // Grant tier immediately on success so user doesn't wait for webhook
                if ($remote === 'success') {
                    grant_tier($txn['user_id'], $txn['tier'], $txn['id']);
                }
            }
        }
    }

    // Sandbox: pending charges auto-resolve after ~20s so the demo can be
    // watched end-to-end. Unlock is still only applied by the sandbox
    // webhook path (api/webhook.php), never here.
    if (PAYSTACK_SANDBOX && $txn['status'] === 'pending') {
        $age = time() - strtotime($txn['created_at']);
        if ($age > 20) {
            $stmt = $db->prepare('UPDATE transactions SET status = "success" WHERE id = ?');
            $stmt->execute([$txn['id']]);
            $txn['status'] = 'success';
        }
    }

    json_ok([
        'reference'  => $txn['reference'],
        'status'     => $txn['status'],
        'amount_ghs' => (float)$txn['amount_ghs'],
        'tier'       => $txn['tier'],
        'tier_unlocked' => (bool)$txn['tier_unlocked'],
        'network'    => $txn['network'],
        'sandbox'    => PAYSTACK_SANDBOX,
    ]);
}

/* ---------------- POST: initiate charge ---------------- */
if ($method === 'POST' && $action === 'charge') {
    $tierKey = $body['tier'] ?? null;
    $tier = tier_payload($tierKey);
    if (!$tier) {
        json_err('Select a valid tier');
    }

    if (!$user) {
        json_err('Sign in with your mobile-money number first', 401);
    }
    if ((bool)$user['self_excluded']) {
        json_err('Your account is self-excluded. Purchases are paused until ' . ($user['self_exclude_until'] ?? 'further notice') . '. Contact support to review.', 403);
    }

    // The customer's MoMo number (they may pay with a different line than
    // the one they signed in with, but default to the account line).
    $phone = preg_replace('/\D+/', '', $body['phone'] ?? $user['phone']);
    $detected = mobifooty_network_from_phone($phone);
    if (!$detected) {
        json_err('Unrecognised Ghana mobile-money number. Use an MTN, Telecel or AirtelTigo number (e.g. 0241234567).');
    }
    $phone = $detected['phone'];
    $network = $detected['network'];

    $amountPesewas = (int)round($tier['price_ghs'] * 100);
    $reference = 'MFY-' . strtoupper(bin2hex(random_bytes(8)));
    $email = $user['email'] ?: $user['phone'] . '@mobifooty.com';

    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare('INSERT INTO transactions (user_id, reference, provider, network, phone, amount_ghs, tier, status, meta)
                          VALUES (?, ?, "paystack", ?, ?, ?, ?, "pending", ?)');
    $stmt->execute([
        $user['id'], $reference, $network, $phone,
        $tier['price_ghs'], $tierKey,
        json_encode(['initiator_phone' => $user['phone'], 'tier_name' => $tier['name']]),
    ]);
    $txnId = (int)$db->lastInsertId();

    // --- Sandbox mode: skip Paystack entirely (demo). ---
    if (PAYSTACK_SANDBOX) {
        json_ok([
            'reference' => $reference,
            'status'    => 'pending',
            'amount_ghs' => $tier['price_ghs'],
            'tier'      => $tierKey,
            'network'   => $network,
            'sandbox'   => true,
            'message'   => 'Sandbox charge created. Approve it in ~20 seconds to watch the pending → success → unlock flow (webhook-verified).',
        ], 201);
    }

    // --- Live: call Paystack. ---
    $res = paystack_initialize_transaction($email, $amountPesewas, $reference, [
        'custom_fields' => [
            ['display_name' => 'Tier', 'variable_name' => 'tier', 'value' => $tier['name']],
            ['display_name' => 'User ID', 'variable_name' => 'user_id', 'value' => (string)$user['id']],
        ],
    ]);

    if ($res['status'] === 0 || $res['body'] === null) {
        $db->prepare('UPDATE transactions SET status = "failed", gateway_response = ? WHERE id = ?')
           ->execute(['network_error: ' . ($res['error'] ?? 'unknown'), $txnId]);
        json_err('Could not reach the payment provider. Try again in a moment.', 502);
    }

    $authUrl = $res['body']['data']['authorization_url'] ?? null;
    $accessCode = $res['body']['data']['access_code'] ?? null;
    $status = 'pending';
    $gateway = 'initialized';

    $db->prepare('UPDATE transactions SET status = ?, gateway_response = ? WHERE id = ?')
       ->execute([$status, $gateway, $txnId]);

    json_ok([
        'reference' => $reference,
        'status'    => $status,
        'amount_ghs' => $tier['price_ghs'],
        'tier'      => $tierKey,
        'network'   => $network,
        'sandbox'   => false,
        'authorization_url' => $authUrl,
        'access_code' => $accessCode,
        'message'   => 'Complete your payment securely via Paystack.',
        'require_otp' => false
    ], 201);
}

json_err('Method not allowed', 405);