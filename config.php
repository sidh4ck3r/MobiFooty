<?php
/**
 * MOBIFOOTY — Screenshot-to-Prediction paid module
 * ------------------------------------------------------------------
 * Central configuration. Nothing here is served to the client —
 * secret keys stay server-side. Public-safe values (tiers, prices,
 * provider list, sandbox flags) are exposed via api/app.php only.
 */

define('APP_NAME', 'Mobifooty');
define('APP_ENV', getenv('APP_ENV') ?: 'development'); // development | production

/* Optional per-machine overrides (API keys, sandbox flag, DB creds).
 * Loaded FIRST so its putenv() values are visible to the getenv()
 * calls below. Git-ignored and blocked from HTTP by .htaccess. */
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

/* ------------------------------------------------------------------
 * DATABASE (XAMPP defaults: root / no password)
 * ------------------------------------------------------------------ */
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'mobifooty');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

/* ------------------------------------------------------------------
 * PAYSTACK (Ghana MoMo collections)
 * ------------------------------------------------------------------ */
// Get keys at https://dashboard.paystack.com/#/settings/developers
define('PAYSTACK_SECRET_KEY', getenv('PAYSTACK_SECRET_KEY') ?: 'sk_test_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
define('PAYSTACK_PUBLIC_KEY', getenv('PAYSTACK_PUBLIC_KEY') ?: 'pk_test_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
// The secret used to verify webhook signatures (x-paystack-signature).
// Use a value you control; it does NOT have to equal the API secret,
// but it must match the value you configure on the webhook settings page.
define('PAYSTACK_WEBHOOK_SECRET', getenv('PAYSTACK_WEBHOOK_SECRET') ?: 'change-me-webhook-secret');
define('PAYSTACK_BASE', 'https://api.paystack.co');

// SANDBOX = true simulates the MoMo charge + webhook entirely on this
// server (no live money, no Paystack account needed). Tier unlock still
// happens ONLY inside api/webhook.php. Flip to false for live payments.
define('PAYSTACK_SANDBOX', getenv('PAYSTACK_SANDBOX') ? strtoupper(getenv('PAYSTACK_SANDBOX')) === 'TRUE' : true);

/* ------------------------------------------------------------------
 * VISION API — screenshot parsing
 * ------------------------------------------------------------------
 * VISION_PROVIDER:
 *   openai  -> OpenAI chat completions (gpt-4o / gpt-4o-mini) vision
 *   gemini  -> Google Gemini (gemini-1.5-flash / gemini-2.0-flash)
 *   none    -> deterministic local simulation (marked "simulation" in
 *              the response so it is never mistaken for a real parse)
 *
 * If the configured provider key is missing/blank the API falls back
 * to simulation mode rather than erroring, so the flow stays demoable.
 */
define('VISION_PROVIDER', getenv('VISION_PROVIDER') ?: 'openai'); // openai | gemini | none
define('OPENAI_API_KEY', getenv('OPENAI_API_KEY') ?: '');
define('OPENAI_MODEL', getenv('OPENAI_MODEL') ?: 'gpt-4o-mini');
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: '');
define('GEMINI_MODEL', getenv('GEMINI_MODEL') ?: 'gemini-3.6-flash');

/* ------------------------------------------------------------------
 * TIERS — single source of truth for pricing (GHS)
 * ------------------------------------------------------------------
 * analyses_per_day: -1 = unlimited
 * valid_days:       0  = never expires (lifetime)
 */
function mobifooty_tiers()
{
    return [
        'starter' => [
            'name'             => 'Starter',
            'price_ghs'        => 10,
            'valid_days'       => 1,      // 24-hour pass
            'analyses_per_day' => 2,
            'tagline'          => 'Try a single day of probability-based picks.',
            'features'         => [
                '2 screenshot analyses / day',
                'Poisson model probabilities on 1X2 + Over/Under',
                'League-tabbed SportyBet-style odds grid',
                '24-hour access',
            ],
        ],
        'basic' => [
            'name'             => 'Basic',
            'price_ghs'        => 25,
            'valid_days'       => 7,
            'analyses_per_day' => 5,
            'tagline'          => 'A full week of daily fixture reads.',
            'features'         => [
                '5 screenshot analyses / day',
                'Poisson model probabilities on 1X2 + Over/Under',
                'League-tabbed SportyBet-style odds grid',
                'Model picks highlighted inside the grid',
                '7-day access',
            ],
        ],
        'pro' => [
            'name'             => 'Pro',
            'price_ghs'        => 50,
            'valid_days'       => 30,
            'analyses_per_day' => 15,
            'tagline'          => 'Heavy matchday user. Reads every day.',
            'features'         => [
                '15 screenshot analyses / day',
                'Poisson model probabilities on 1X2 + Over/Under',
                'League-tabbed SportyBet-style odds grid',
                'Model picks highlighted inside the grid',
                'Full bet-history / track-record dashboard',
                '30-day access',
            ],
        ],
        'elite' => [
            'name'             => 'Elite',
            'price_ghs'        => 100,
            'valid_days'       => 0,      // no expiry
            'analyses_per_day' => -1,     // unlimited
            'tagline'          => 'One-time purchase. No expiry. Unlimited.',
            'features'         => [
                'Unlimited screenshot analyses',
                'Poisson model probabilities on 1X2 + Over/Under',
                'League-tabbed SportyBet-style odds grid',
                'Model picks highlighted inside the grid',
                'Full bet-history / track-record dashboard',
                'Lifetime access — never expires',
            ],
        ],
    ];
}

/* Tier order (low -> high) used for "path to next tier" progress. */
function mobifooty_tier_order()
{
    return ['starter', 'basic', 'pro', 'elite'];
}

/* ------------------------------------------------------------------
 * RESPONSIBLE-GAMING / SELF-EXCLUSION
 * ------------------------------------------------------------------ */
define('SELF_EXCLUSION_DAYS_MIN', 7); // minimum self-exclusion period in days

/* Mobile-money network detection from Ghana phone prefixes. */
function mobifooty_network_from_phone($phone)
{
    $p = preg_replace('/\D+/', '', $phone);
    if (strpos($p, '233') === 0 && strlen($p) === 12) {
        $p = '0' . substr($p, 3);
    }
    if (strlen($p) !== 10 || $p[0] !== '0') {
        return null;
    }
    $prefixes = [
        'mtn' => ['024', '025', '054', '055', '059'],
        'vod' => ['020', '050'],
        'atl' => ['026', '027', '057'],
    ];
    foreach ($prefixes as $network => $list) {
        foreach ($list as $prefix) {
            if (strpos($p, $prefix) === 0) {
                return ['network' => $network, 'phone' => $p];
            }
        }
    }
    return null;
}

function mobifooty_network_label($network)
{
    $labels = [
        'mtn' => 'MTN MoMo',
        'vod' => 'Telecel Cash (Vodafone)',
        'atl' => 'AirtelTigo Money',
    ];
    return $labels[$network] ?? $network;
}