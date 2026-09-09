<?php
/**
 * Mobifooty — provider helpers: Paystack MoMo + Vision parsing.
 */

require_once __DIR__ . '/bootstrap.php';

/* ----------------------------------------------------------------
 * HTTP
 * ---------------------------------------------------------------- */
function http_json($method, $url, $headers, $payload = null, $timeout = 30)
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    if ($payload !== null) {
        $opts[CURLOPT_POSTFIELDS] = is_string($payload) ? $payload : json_encode($payload);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['status' => 0, 'body' => null, 'error' => $err];
    }
    return ['status' => $status, 'body' => json_decode($body, true), 'error' => null];
}

/* ----------------------------------------------------------------
 * PAYSTACK — Ghana Mobile Money
 * ---------------------------------------------------------------- */
function paystack_headers()
{
    return [
        'Authorization: Bearer ' . PAYSTACK_SECRET_KEY,
        'Content-Type: application/json',
    ];
}

/**
 * Initiate a MoMo charge. In Ghana this pushes an STK/USSD payment
 * prompt to the customer's phone (provider: mtn | vod | atl).
 * Returns the Paystack transaction reference.
 */
function paystack_initialize_transaction($email, $amountPesewas, $reference, $metadata = [])
{
    $payload = [
        'email'    => $email,
        'amount'   => (string)$amountPesewas,
        'currency' => 'GHS',
        'reference'=> $reference,
        'metadata' => $metadata,
        'channels' => ['mobile_money']
    ];
    return http_json('POST', PAYSTACK_BASE . '/transaction/initialize', paystack_headers(), $payload, 40);
}

/** Check the status of a pending charge. */
function paystack_check_charge($reference)
{
    return http_json('GET', PAYSTACK_BASE . '/charge/' . rawurlencode($reference), paystack_headers(), null, 30);
}

/** Verify a transaction reference (terminal truth for status). */
function paystack_verify_transaction($reference)
{
    return http_json('GET', PAYSTACK_BASE . '/transaction/verify/' . rawurlencode($reference), paystack_headers(), null, 30);
}

/**
 * Verify the Paystack webhook signature. Paystack signs the raw request
 * body with HMAC-SHA512 using your webhook secret.
 */
function paystack_webhook_signature_valid($rawBody, $headerSignature)
{
    if (!$headerSignature) {
        return false;
    }
    $expected = hash_hmac('sha512', $rawBody, PAYSTACK_WEBHOOK_SECRET);
    return hash_equals($expected, $headerSignature);
}

/* ----------------------------------------------------------------
 * TIER UNLOCK — the ONLY place a tier is granted.
 * Called from api/webhook.php (verified webhook) and from the
 * sandbox-only dev path inside webhook.php. Never from a client call.
 * ---------------------------------------------------------------- */
function grant_tier($userId, $tierKey, $transactionId)
{
    $db = Database::getInstance()->getConnection();
    $t = mobifooty_tiers()[$tierKey] ?? null;
    if (!$t) {
        return false;
    }
    $expiresAt = null;
    if ((int)$t['valid_days'] > 0) {
        $expiresAt = date('Y-m-d H:i:s', time() + (int)$t['valid_days'] * 86400);
    }

    $db->beginTransaction();
    try {
        // Lifetime tiers simply overwrite. Expiring tiers extend from
        // the current expiry (or now) so stacking Starter/Basic works.
        $user = current_user_row($userId);
        $base = $expiresAt ? (($user && $user['tier_expires_at'] && strtotime($user['tier_expires_at']) > time())
            ? $user['tier_expires_at']
            : date('Y-m-d H:i:s'))
            : null;
        $newExpiry = $expiresAt;
        if ($base && strtotime($base) > time()) {
            $newExpiry = date('Y-m-d H:i:s', strtotime($base) + (int)$t['valid_days'] * 86400);
        }

        $stmt = $db->prepare('UPDATE users SET current_tier = ?, tier_expires_at = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$tierKey, $newExpiry, $userId]);

        $stmt = $db->prepare('UPDATE transactions SET status = "success", tier_unlocked = 1, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$transactionId]);

        $db->commit();
        return true;
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('[Mobifooty grant_tier] ' . $e->getMessage());
        return false;
    }
}

function current_user_row($userId)
{
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

/* Transaction history for a user (used by payment.php + user.php). */
function user_transactions($userId)
{
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare('SELECT id, reference, network, phone, amount_ghs, tier, status, tier_unlocked, gateway_response, created_at
                          FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 50');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/* ----------------------------------------------------------------
 * QUOTA — server-side enforcement of per-tier daily analysis count
 * ---------------------------------------------------------------- */
function analysis_quota_remaining($userId, $tierKey)
{
    $t = mobifooty_tiers()[$tierKey] ?? null;
    if (!$t) {
        return 0;
    }
    if ((int)$t['analyses_per_day'] === -1) {
        return PHP_INT_MAX; // unlimited
    }
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare('SELECT COUNT(*) AS c FROM analyses WHERE user_id = ? AND created_at >= CURDATE()');
    $stmt->execute([$userId]);
    $used = (int)$stmt->fetch()['c'];
    return max(0, (int)$t['analyses_per_day'] - $used);
}

/* ----------------------------------------------------------------
 * VISION — parse a screenshot into structured fixtures
 * ---------------------------------------------------------------- */
function vision_system_prompt()
{
    return <<<EOT
You are the OCR + fixture-extraction engine of a football prediction tool.
Read the uploaded betting-app screenshot (SportyBet / VGames style) and extract every visible
fixture. Return STRICT JSON only — no markdown, no commentary — in exactly this shape:

{
  "league": "string — league or section name visible in the screenshot (e.g. England, Premier League, Champions)",
  "fixtures": [
    {
      "home": "Home team name exactly as shown",
      "away": "Away team name exactly as shown",
      "odds": {
        "home": 1.23, "draw": 5.50, "away": 11.00,
        "over25": 1.85, "under25": 1.95,
        "btts_yes": 1.70, "btts_no": 2.05
      }
    }
  ]
}

Rules:
- Capture decimal odds exactly as printed (Ghana format, e.g. "2.10"). If a 1X2 column is missing, infer nothing — omit the key.
- If Over/Under or BTTS columns are not visible, omit those keys; the engine will compute its own model probabilities.
- Skip promo banners, jackpot blocks and live icons. Only rows that are actual match fixtures.
- Keep team names short and exact (no extra punctuation).
EOT;
}

/**
 * Parse a screenshot (PNG/JPEG bytes) into fixtures.
 * Returns ['simulation' => bool, 'raw' => ..., 'league' => ..., 'fixtures' => [...]].
 * Falls back to deterministic simulation when no provider/key is configured.
 */
function vision_parse_screenshot($imageBytes)
{
    $provider = strtolower(VISION_PROVIDER);
    $base64 = base64_encode($imageBytes);

    if ($provider === 'openai' && OPENAI_API_KEY) {
        $res = vision_openai($base64);
        if ($res) {
            return $res;
        }
    } elseif ($provider === 'gemini' && GEMINI_API_KEY) {
        $res = vision_gemini($base64);
        if ($res) {
            return $res;
        }
    }

    // No provider/key or provider call failed -> simulation, clearly flagged.
    return vision_simulation($base64);
}

function vision_openai($base64)
{
    $payload = [
        'model' => OPENAI_MODEL,
        'messages' => [[
            'role'    => 'user',
            'content' => [
                ['type' => 'text', 'text' => vision_system_prompt()],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . $base64]],
            ],
        ]],
        'response_format' => ['type' => 'json_object'],
        'max_tokens' => 2000,
    ];
    $res = http_json('POST', 'https://api.openai.com/v1/chat/completions', [
        'Authorization: Bearer ' . OPENAI_API_KEY,
        'Content-Type: application/json',
    ], $payload, 90);

    if ($res['status'] !== 200 || !isset($res['body']['choices'][0]['message']['content'])) {
        error_log('[Mobifooty vision_openai] ' . json_encode($res));
        return null;
    }
    $parsed = json_decode($res['body']['choices'][0]['message']['content'], true);
    return normalize_vision_result($parsed);
}

function vision_gemini($base64)
{
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent?key=' . GEMINI_API_KEY;
    $payload = [
        'contents' => [[
            'parts' => [
                ['text' => vision_system_prompt()],
                ['inline_data' => ['mime_type' => 'image/png', 'data' => $base64]],
            ],
        ]],
        'generationConfig' => [
            'responseMimeType' => 'application/json',
        ],
    ];
    $res = vision_retry(function () use ($url, $payload) {
        return http_json('POST', $url, ['Content-Type: application/json'], $payload, 90);
    });
    if (!$res || $res['status'] !== 200) {
        error_log('[Mobifooty vision_gemini] ' . json_encode($res));
        return null;
    }
    $text = $res['body']['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!$text) {
        return null;
    }
    // Strip markdown fences if the model wrapped the JSON.
    $text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text)));
    $parsed = json_decode($text, true);
    return normalize_vision_result($parsed);
}

/** Retry transient provider failures (429 rate limit, 5xx overload). */
function vision_retry($callable, $attempts = 3)
{
    $delay = 1.0;
    for ($i = 1; $i <= $attempts; $i++) {
        $res = $callable();
        $status = $res['status'] ?? 0;
        if ($status === 200) {
            return $res;
        }
        $retryable = in_array($status, [429, 500, 502, 503, 504], true) || $status === 0;
        if (!$retryable || $i === $attempts) {
            return $res;
        }
        usleep((int)($delay * 1000000));
        $delay *= 1.7;
    }
    return $res ?? null;
}

function normalize_vision_result($parsed)
{
    if (!is_array($parsed) || empty($parsed['fixtures']) || !is_array($parsed['fixtures'])) {
        return null;
    }
    $fixtures = [];
    foreach ($parsed['fixtures'] as $f) {
        if (!isset($f['home'], $f['away'])) {
            continue;
        }
        $odds = isset($f['odds']) && is_array($f['odds']) ? $f['odds'] : [];
        $fixtures[] = [
            'home' => trim((string)$f['home']),
            'away' => trim((string)$f['away']),
            'odds' => [
                'home'      => isset($odds['home'])      ? (float)$odds['home']      : null,
                'draw'      => isset($odds['draw'])      ? (float)$odds['draw']      : null,
                'away'      => isset($odds['away'])      ? (float)$odds['away']      : null,
                'over25'    => isset($odds['over25'])    ? (float)$odds['over25']    : null,
                'under25'   => isset($odds['under25'])   ? (float)$odds['under25']   : null,
                'btts_yes'  => isset($odds['btts_yes'])  ? (float)$odds['btts_yes']  : null,
                'btts_no'   => isset($odds['btts_no'])   ? (float)$odds['btts_no']   : null,
            ],
        ];
    }
    if (count($fixtures) === 0) {
        return null;
    }
    return [
        'simulation' => false,
        'provider'   => strtoupper(VISION_PROVIDER),
        'league'     => trim((string)($parsed['league'] ?? 'Fixtures')),
        'fixtures'   => $fixtures,
    ];
}

/**
 * Deterministic offline stand-in used when no vision provider/key is
 * configured (or the provider call failed). It reads nothing from the
 * image — it only produces a plausible fixture set so the full
 * payment -> upload -> analysis -> results flow can be demonstrated.
 * Responses are always flagged simulation:true and shown as such in the UI.
 */
function vision_simulation($base64)
{
    // Seed deterministically from the image bytes so re-running the same
    // screenshot yields the same fixtures (feels like a real parse).
    $seed = 0;
    $len = strlen($base64);
    for ($i = 0; $i < $len; $i += 97) {
        $seed = ($seed * 31 + ord($base64[$i])) & 0x7fffffff;
    }
    mt_srand($seed);

    $leagues = [
        'England'  => [['Manchester City','Liverpool'],['Arsenal','Chelsea'],['Tottenham','Manchester United'],['Newcastle','Aston Villa'],['Brighton','West Ham']],
        'Spain'    => [['Real Madrid','Barcelona'],['Atletico Madrid','Sevilla'],['Athletic Bilbao','Real Sociedad'],['Villarreal','Valencia']],
        'Germany'  => [['Bayern Munich','Borussia Dortmund'],['RB Leipzig','Bayer Leverkusen'],['Eintracht Frankfurt','Stuttgart'],['Wolfsburg','Hoffenheim']],
        'Italy'    => [['Inter Milan','Juventus'],['AC Milan','Napoli'],['AS Roma','Lazio'],['Fiorentina','Atalanta']],
        'Champions League' => [['PSG','Real Madrid'],['Liverpool','Bayern Munich'],['Barcelona','Inter Milan'],['Arsenal','AC Milan']],
    ];
    $leagueKeys = array_keys($leagues);
    $league = $leagueKeys[$seed % count($leagueKeys)];
    $pairs = $leagues[$league];

    $fixtures = [];
    foreach ($pairs as $i => $pair) {
        // Bookmaker odds: home favourite-ish with a spread, draw ~3.2-3.6.
        $homeOdds  = round(mt_rand(140, 260) / 100, 2);
        $drawOdds  = round(mt_rand(300, 380) / 100, 2);
        $awayOdds  = round(mt_rand(230, 480) / 100, 2);
        $fixtures[] = [
            'home' => $pair[0],
            'away' => $pair[1],
            'odds' => [
                'home'     => $homeOdds,
                'draw'     => $drawOdds,
                'away'     => $awayOdds,
                'over25'   => round(mt_rand(165, 210) / 100, 2),
                'under25'  => round(mt_rand(170, 215) / 100, 2),
                'btts_yes' => round(mt_rand(155, 195) / 100, 2),
                'btts_no'  => round(mt_rand(180, 225) / 100, 2),
            ],
        ];
    }

    return [
        'simulation' => true,
        'provider'   => 'SIMULATION',
        'league'     => $league,
        'fixtures'   => $fixtures,
    ];
}