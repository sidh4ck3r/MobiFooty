<?php
/**
 * Mobifooty — screenshot analysis.
 *
 * POST /api/analysis.php  (multipart: file, or JSON {image_base64, filename})
 *   Requirements:
 *     - signed-in user with an ACTIVE tier (checked server-side)
 *     - analysis quota remaining for that tier (server-side, not client)
 *     - not self-excluded
 *   Flow: validate image -> vision_parse_screenshot() -> store analysis
 *   -> return league + fixtures. The client then runs the Poisson engine
 *   over the returned fixtures (model probability is never blended with
 *   bookmaker odds — they are returned as separate fields).
 *
 * GET /api/analysis.php?recent=1 -> last analysis for the user.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/helpers.php';

$method = $_SERVER['REQUEST_METHOD'];

/* ---------------- GET: most recent analysis ---------------- */
if ($method === 'GET' && isset($_GET['recent'])) {
    $user = current_user();
    if (!$user) {
        json_err('Not signed in', 401);
    }
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare('SELECT id, tier, filename, simulation, league, fixtures, created_at
                          FROM analyses WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();
    if (!$row) {
        json_ok(['analysis' => null]);
    }
    $row['fixtures'] = json_decode($row['fixtures'], true);
    $row['simulation'] = (bool)$row['simulation'];
    json_ok(['analysis' => $row]);
}

/* ---------------- POST: analyze ---------------- */
if ($method === 'POST') {
    $user = current_user();
    if (!$user) {
        json_err('Sign in with your mobile-money number first', 401);
    }
    if ((bool)$user['self_excluded']) {
        json_err('Your account is self-excluded. Analyses are paused until ' . ($user['self_exclude_until'] ?? 'further notice') . '.', 403);
    }
    if (!user_has_active_tier($user)) {
        json_err('No active tier. Choose a plan and complete payment first.', 402);
    }

    $tierKey = $user['current_tier'];
    $remaining = analysis_quota_remaining((int)$user['id'], $tierKey);
    if ($remaining <= 0) {
        $tier = mobifooty_tiers()[$tierKey];
        $limit = $tier['analyses_per_day'];
        json_err('Daily analysis limit reached for your ' . $tier['name'] . ' tier (' . $limit . '/day). Try again tomorrow or upgrade.', 429);
    }

    // --- Extract image bytes: multipart upload or base64 JSON ---
    $imageBytes = null;
    $filename = null;

    if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
        $imageBytes = file_get_contents($_FILES['file']['tmp_name']);
        $filename = basename($_FILES['file']['name'] ?? 'screenshot.png');
    } else {
        $body = body_json();
        $imageBytes = isset($body['image_base64']) ? base64_decode($body['image_base64']) : null;
        $filename = basename($body['filename'] ?? 'screenshot.png');
    }

    if (!$imageBytes || strlen($imageBytes) < 100) {
        json_err('No screenshot received — upload a PNG or JPG of the VGames/SportyBet odds list.');
    }

    // --- Basic image validation (magic bytes) ---
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($imageBytes);
    $allowed = ['image/png', 'image/jpeg', 'image/webp'];
    if (!in_array($mime, $allowed, true)) {
        json_err('Unsupported file type (' . $mime . '). Upload a PNG or JPG screenshot.');
    }
    if (strlen($imageBytes) > 8 * 1024 * 1024) {
        json_err('Screenshot too large (max 8 MB).');
    }

    // --- Vision parse (real provider or flagged simulation) ---
    $result = vision_parse_screenshot($imageBytes);

    // --- Persist (fixtures JSON; model column filled client-side by the Poisson engine) ---
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare('INSERT INTO analyses (user_id, tier, filename, simulation, league, fixtures)
                          VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        (int)$user['id'], $tierKey, $filename,
        $result['simulation'] ? 1 : 0,
        $result['league'],
        json_encode($result['fixtures']),
    ]);
    $analysisId = (int)$db->lastInsertId();

    $remaining = analysis_quota_remaining((int)$user['id'], $tierKey);

    json_ok([
        'analysis_id' => $analysisId,
        'league'      => $result['league'],
        'fixtures'    => $result['fixtures'],
        'simulation'  => $result['simulation'],
        'provider'    => $result['provider'],
        'analyses_left_today' => $remaining,
    ], 201);
}

json_err('Method not allowed', 405);