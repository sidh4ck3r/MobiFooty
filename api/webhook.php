<?php
/**
 * Mobifooty — Paystack webhook receiver.
 *
 * Paystack POSTs signed events here (configure this URL on the Paystack
 * dashboard → Settings → API Keys & Webhooks → Webhook URL; a public URL
 * is required for live mode — ngrok works for local testing).
 *
 * SECURITY MODEL:
 *  - The raw request body is verified against the x-paystack-signature
 *    header using HMAC-SHA512 + PAYSTACK_WEBHOOK_SECRET (hash_equals,
 *    timing-safe). Events that fail verification are rejected with 403.
 *  - This endpoint is the ONLY place a tier is granted (grant_tier()).
 *    The client can never unlock anything by claiming "payment sent".
 *  - Idempotent: repeated delivery of the same event is a no-op.
 *
 * SANDBOX (PAYSTACK_SANDBOX=true): Paystack never reaches a local dev
 * machine, so the frontend may call this endpoint with the header
 * X-Dev-Webhook-Secret (must equal PAYSTACK_WEBHOOK_SECRET) to simulate
 * Paystack delivering a charge.success event. It runs the exact same
 * processing code as a real webhook. Disabled automatically in live mode.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/helpers.php';

$rawBody = file_get_contents('php://input');
$headers = function_exists('getallheaders') ? getallheaders() : [];
$signature = $headers['x-paystack-signature'] ?? ($_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? null);

$isSandboxDev = PAYSTACK_SANDBOX
    && ($headers['X-Dev-Webhook-Secret'] ?? ($_SERVER['HTTP_X_DEV_WEBHOOK_SECRET'] ?? null)) === PAYSTACK_WEBHOOK_SECRET;

if (!$isSandboxDev && !paystack_webhook_signature_valid($rawBody, $signature)) {
    error_log('[Mobifooty webhook] rejected: bad signature');
    json_err('Invalid signature', 403);
}

$event = json_decode($rawBody, true);
if (!is_array($event) || !isset($event['event'])) {
    json_err('Malformed event', 400);
}

$db = Database::getInstance()->getConnection();

switch ($event['event']) {
    case 'charge.success':
        $data = $event['data'] ?? [];
        $ref = $data['reference'] ?? null;
        if (!$ref) {
            error_log('[Mobifooty webhook] charge.success without reference');
            break;
        }
        $stmt = $db->prepare('SELECT * FROM transactions WHERE reference = ?');
        $stmt->execute([$ref]);
        $txn = $stmt->fetch();
        if (!$txn) {
            error_log('[Mobifooty webhook] unknown reference: ' . $ref);
            break;
        }
        // Idempotency: already handled?
        if ((int)$txn['tier_unlocked'] === 1 && $txn['status'] === 'success') {
            break;
        }
        if (($data['status'] ?? 'success') !== 'success') {
            $db->prepare('UPDATE transactions SET status = "failed", gateway_response = ? WHERE id = ?')
               ->execute([$data['gateway_response'] ?? 'charge not successful', $txn['id']]);
            break;
        }
        if (!grant_tier((int)$txn['user_id'], $txn['tier'], (int)$txn['id'])) {
            error_log('[Mobifooty webhook] grant_tier failed for ref ' . $ref);
        }
        break;

    case 'charge.failed':
        $ref = $event['data']['reference'] ?? null;
        if ($ref) {
            $db->prepare('UPDATE transactions SET status = "failed", gateway_response = ? WHERE reference = ?')
               ->execute([$event['data']['gateway_response'] ?? 'charge failed', $ref]);
        }
        break;

    default:
        error_log('[Mobifooty webhook] unhandled event: ' . $event['event']);
        break;
}

json_ok(['received' => true, 'event' => $event['event']]);