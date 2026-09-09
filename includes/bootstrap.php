<?php
/**
 * Mobifooty — API bootstrap (house pattern: CORS + JSON + PDO).
 * Every endpoint under /api starts with require_once __DIR__ . '/../includes/bootstrap.php'.
 */

require_once __DIR__ . '/../config.php';

header('Access-Control-Allow-Origin: ' . (APP_ENV === 'production' ? 'same-origin' : '*'));
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/Database.php';

set_exception_handler(function ($e) {
    error_log('[Mobifooty] Unhandled exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
    exit;
});

/* Session ties a browser to a user record (phone is the identity). */
if (session_status() === PHP_SESSION_NONE) {
    session_name('mobifooty_sess');
    session_start();
}

function json_response($payload, $code = 200)
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

function json_ok($data = null, $code = 200)
{
    json_response(['success' => true, 'data' => $data], $code);
}

function json_err($message, $code = 400, $extra = [])
{
    json_response(array_merge(['success' => false, 'error' => $message], $extra), $code);
}

function body_json()
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/* The current user row (from session), or null. */
function current_user()
{
    $db = Database::getInstance()->getConnection();
    $id = $_SESSION['mobifooty_user_id'] ?? null;
    if (!$id) {
        return null;
    }
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    return $user ?: null;
}

/* Flatten a tier key to a public-facing tier object with computed fields. */
function tier_payload($tierKey)
{
    $tiers = mobifooty_tiers();
    $t = $tiers[$tierKey] ?? null;
    if (!$t) {
        return null;
    }
    return [
        'key'        => $tierKey,
        'name'       => $t['name'],
        'price_ghs'  => $t['price_ghs'],
        'valid_days' => $t['valid_days'],
        'analyses_per_day' => $t['analyses_per_day'],
        'tagline'    => $t['tagline'],
        'features'   => $t['features'],
    ];
}

/* True when the user has an active (non-expired) tier right now. */
function user_has_active_tier($user)
{
    if (!$user) {
        return false;
    }
    if (!$user['current_tier']) {
        return false;
    }
    $t = mobifooty_tiers()[$user['current_tier']] ?? null;
    if (!$t) {
        return false;
    }
    if ((int)$t['valid_days'] === 0) {
        return true; // lifetime (Elite)
    }
    $expires = strtotime($user['tier_expires_at']);
    return $expires !== false && $expires > time();
}