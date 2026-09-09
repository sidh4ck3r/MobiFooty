<?php
/**
 * Mobifooty — user identity & state.
 *
 * GET  /api/user.php            -> current session user (or null)
 * POST /api/user.php {phone}    -> register/load user by MoMo number
 * POST /api/user.php {action:'self-exclude'} -> toggle self-exclusion
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/helpers.php';

$method = $_SERVER['REQUEST_METHOD'];

/* ---------------- GET: current user + full state ---------------- */
if ($method === 'GET') {
    $user = current_user();
    if (!$user) {
        json_ok(['user' => null]);
    }
    json_ok(['user' => user_payload($user)]);
}

/* ---------------- DELETE: sign out ---------------- */
if ($method === 'DELETE') {
    session_destroy();
    json_ok(['success' => true]);
}

/* ---------------- POST ---------------- */
$body = body_json();

if (($body['action'] ?? null) === 'self-exclude') {
    $user = current_user();
    if (!$user) {
        json_err('Not signed in', 401);
    }
    $days = max(SELF_EXCLUSION_DAYS_MIN, (int)($body['days'] ?? SELF_EXCLUSION_DAYS_MIN));
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare('UPDATE users SET self_excluded = 1, self_exclude_until = ? WHERE id = ?');
    $stmt->execute([date('Y-m-d H:i:s', time() + $days * 86400), $user['id']]);
    json_ok(['self_excluded' => true, 'until' => date('Y-m-d H:i:s', time() + $days * 86400)]);
}

if (($body['action'] ?? null) === 'cancel-self-exclusion') {
    $user = current_user();
    if (!$user) {
        json_err('Not signed in', 401);
    }
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare('UPDATE users SET self_excluded = 0, self_exclude_until = NULL WHERE id = ?');
    $stmt->execute([$user['id']]);
    json_ok(['self_excluded' => false]);
}

/* ---------------- Register / sign in with phone ---------------- */
$phone = preg_replace('/\D+/', '', $body['phone'] ?? '');
if (!$phone) {
    json_err('Phone number is required');
}

$detected = mobifooty_network_from_phone($phone);
if (!$detected) {
    json_err('Unrecognised Ghana mobile-money number. Use an MTN, Telecel or AirtelTigo number (e.g. 0241234567).');
}
$phone = $detected['phone'];
$mode = $body['mode'] ?? 'login';

$db = Database::getInstance()->getConnection();

// Check if user already exists.
$stmt = $db->prepare('SELECT * FROM users WHERE phone = ?');
$stmt->execute([$phone]);
$user = $stmt->fetch();

if ($mode === 'signup') {
    // Sign Up: reject if already registered
    if ($user) {
        json_err('This number is already registered. Please sign in instead.');
    }
    $name = trim($body['name'] ?? '');
    $email = trim($body['email'] ?? '');
    if (!$name) {
        json_err('Name is required for sign up.');
    }
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_err('A valid email address is required for sign up.');
    }
    $stmt = $db->prepare('INSERT INTO users (phone, name, email) VALUES (?, ?, ?)');
    $stmt->execute([$phone, $name, $email]);
    $userId = (int)$db->lastInsertId();
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
} else {
    // Sign In: reject if not registered
    if (!$user) {
        json_err('No account found with this number. Please sign up first.');
    }
}

$_SESSION['mobifooty_user_id'] = (int)$user['id'];

// Auto-expire a stale tier row (tier_expires_at in the past -> treat as none).
if ($user['current_tier'] && $user['tier_expires_at'] && strtotime($user['tier_expires_at']) <= time()) {
    $db->prepare('UPDATE users SET current_tier = NULL, tier_expires_at = NULL WHERE id = ?')->execute([$user['id']]);
    $user['current_tier'] = null;
    $user['tier_expires_at'] = null;
}

json_ok(['user' => user_payload($user)], 201);

/* ---------------- payload builder ---------------- */
function user_payload($user)
{
    $tiers = mobifooty_tiers();
    $order = mobifooty_tier_order();
    $active = user_has_active_tier($user);

    // Progress to the next tier: share of lifetime confirmed spend vs the
    // next tier's price. Elite is the end of the path.
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare('SELECT COALESCE(SUM(amount_ghs), 0) AS spent FROM transactions WHERE user_id = ? AND status = "success"');
    $stmt->execute([$user['id']]);
    $lifetimeSpend = (float)$stmt->fetch()['spent'];

    $idx = array_search($user['current_tier'], $order);
    $nextTierKey = ($idx !== false && $idx < count($order) - 1) ? $order[$idx + 1] : null;
    $nextTier = $nextTierKey ? tier_payload($nextTierKey) : null;

    $progress = 0;
    if ($nextTier) {
        $progress = min(1, $lifetimeSpend / max(1, $nextTier['price_ghs']));
    }

    return [
        'id'            => (int)$user['id'],
        'phone'         => $user['phone'],
        'name'          => $user['name'],
        'email'         => $user['email'],
        'network'       => mobifooty_network_from_phone($user['phone'])['network'] ?? null,
        'current_tier'  => $user['current_tier'],
        'tier'          => $user['current_tier'] ? tier_payload($user['current_tier']) : null,
        'tier_active'   => $active,
        'tier_expires_at' => $user['tier_expires_at'],
        'lifetime_spend_ghs' => $lifetimeSpend,
        'next_tier'     => $nextTier,
        'progress_to_next'   => round($progress, 4),
        'analyses_left_today' => $active ? analysis_quota_remaining((int)$user['id'], $user['current_tier']) : 0,
        'self_excluded' => (bool)$user['self_excluded'],
        'self_exclude_until' => $user['self_exclude_until'],
        'transactions'  => user_transactions($user['id']),
    ];
}
