<?php
/**
 * Mobifooty — public app config.
 * Only non-secret values are exposed. NEVER return PAYSTACK_SECRET_KEY,
 * webhook secret or vision keys from here.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

$tiers = [];
foreach (mobifooty_tier_order() as $key) {
    $tiers[$key] = tier_payload($key);
}

json_ok([
    'app' => [
        'name' => APP_NAME,
        'env'  => APP_ENV,
    ],
    'tiers' => $tiers,
    'networks' => [
        ['key' => 'mtn', 'label' => 'MTN MoMo',     'prefixes' => ['024','025','054','055','059']],
        ['key' => 'vod', 'label' => 'Telecel Cash',  'prefixes' => ['020','050']],
        ['key' => 'atl', 'label' => 'AirtelTigo Money', 'prefixes' => ['026','027','057']],
    ],
    'payment' => [
        'sandbox'  => PAYSTACK_SANDBOX,
        'currency' => 'GHS',
        'public_key' => PAYSTACK_PUBLIC_KEY,
        'min_phone_digits' => 10,
        // Only exposed while PAYSTACK_SANDBOX is on: lets the demo trigger
        // the simulated webhook from the browser. Never present in live mode.
        'dev_webhook_secret' => PAYSTACK_SANDBOX ? PAYSTACK_WEBHOOK_SECRET : null,
    ],
    'vision' => [
        'provider'   => strtolower(VISION_PROVIDER) === 'none' || !(OPENAI_API_KEY || GEMINI_API_KEY)
            ? 'simulation'
            : strtolower(VISION_PROVIDER),
        'configured' => (bool)(OPENAI_API_KEY || GEMINI_API_KEY),
    ],
    'self_exclusion_min_days' => SELF_EXCLUSION_DAYS_MIN,
]);