# ⚡ Mobifooty — Screenshot-to-Prediction paid module

A paid "upload a VGames screenshot → get Poisson-model predictions" module for Mobifooty,
replicating the instantvirtualhack.com flow end-to-end. Built on the Instantfoot
dark analytics-terminal palette with **zero external libraries** (vanilla JS + PHP + MySQL).

```
mobifooty/
├── index.html            # SPA: Dashboard · Tiers · Upload · Results · History
├── config.php            # all config: DB, Paystack, vision API, tiers (server-only)
├── .htaccess             # blocks direct web access to config.php / includes / database
├── includes/
│   ├── Database.php      # PDO singleton (house pattern)
│   ├── bootstrap.php     # CORS/JSON headers, session, JSON helpers
│   └── helpers.php       # Paystack charge/verify/webhook + vision parsing
├── api/
│   ├── init.php          # idempotent schema bootstrap (auto-runs from the SPA)
│   ├── app.php           # public config: tiers, networks, sandbox flags
│   ├── user.php          # sign-in by MoMo number, tier state, self-exclusion
│   ├── payment.php       # initiate MoMo charge (Paystack), poll status
│   ├── webhook.php       # Paystack webhook — the ONLY place a tier is granted
│   ├── analysis.php      # screenshot upload → vision parse → quota enforcement
│   └── bets.php          # track record CRUD + running totals
└── database/schema.sql   # reference schema (the app self-installs it)
```

## 1. Requirements

- XAMPP (Apache + PHP 8 + MySQL) — or any PHP 8 + MySQL host
- The app lives at `http://localhost/mobifooty/`

## 2. Install

1. Copy this folder into `C:\xampp\htdocs\mobifooty`.
2. Start Apache + MySQL in the XAMPP control panel.
3. Open `http://localhost/mobifooty/` — the DB and tables self-install on first load.
4. (Optional) review `config.php` for DB credentials (XAMPP defaults are `root` / empty).

The app runs fully in **sandbox mode** out of the box — you can complete the entire
flow (sign in → pay → approve → tier unlocks via simulated webhook → upload → analyse →
results → track record) without any API keys.

## 3. Going live

### 3.1 Paystack (Ghana Mobile Money)

1. Create a Paystack account at <https://paystack.com> (Ghana region) and get your keys
   from **Settings → API Keys & Webhooks**.
2. In `config.php`:
   - `PAYSTACK_SECRET_KEY` / `PAYSTACK_PUBLIC_KEY` — your live/test keys
   - `PAYSTACK_WEBHOOK_SECRET` — a secret **you** choose; it must match the value you
     enter on the Paystack webhook settings page
   - `PAYSTACK_SANDBOX` → `false`
3. Set the **Webhook URL** on the Paystack dashboard to your public URL for
   `api/webhook.php`, e.g. `https://yourdomain.com/mobifooty/api/webhook.php`.
   Paystack signs each callback with `x-paystack-signature` (HMAC-SHA512 of the raw body
   using your webhook secret) — the endpoint verifies this before granting anything.
   For local testing, `ngrok http 80` gives you a public tunnel to point Paystack at.
4. Test with a test MoMo number via the Paystack dashboard.

**Security model (important):** a tier is granted **only** inside `api/webhook.php`
after a signature-verified `charge.success` callback. The client's "payment sent" state
is display-only; spoofing it can never unlock anything. The sandbox's simulated
callback uses the exact same code path and is disabled automatically in live mode.

### 3.2 Vision API (screenshot parsing)

The "AI analysis" step calls a real vision model. Set one provider in `config.php`:

| Provider | Config | Model |
|----------|--------|-------|
| OpenAI | `VISION_PROVIDER=openai` + `OPENAI_API_KEY` | `gpt-4o-mini` (default) |
| Gemini  | `VISION_PROVIDER=gemini` + `GEMINI_API_KEY` | `gemini-3.6-flash` (default) |

Notes:
- Newer Google keys use the `AQ.` prefix (replacing the old `AIza`). Both work with
  the standard `generateContent` endpoint used here.
- Transient provider overloads (429/5xx) are retried automatically (3 attempts, backoff).
- If no key is configured, or the provider call ultimately fails, the API falls back to a
  **clearly-flagged simulation** — responses carry `"simulation": true` and the UI shows
  "⚠ SIMULATION MODE", so a simulated parse is never mistaken for a real one.
- Keys are stored in `config.local.php` (git-ignored, blocked by `.htaccess`) or as
  environment variables — never commit real keys.

## 4. Feature map

- **Tiers** — Starter GHS 10 / Basic GHS 25 / Pro GHS 50 / Elite GHS 100 (lifetime,
  unlimited). The tiers screen highlights the recommended/next tier and shows a
  lifetime-spend progress tracker to the next tier.
- **Payment** — "Add Your Mobile Money Number" with automatic network detection
  (MTN `024/025/054/055/059`, Telecel Cash `020/050`, AirtelTigo `026/027/057`),
  Paystack MoMo charge, pending → success → failed states, webhook-verified unlock,
  and every transaction stored (ref, amount, tier, timestamp, network).
- **Upload + AI** — staged progress UI ("Reading Screenshot…" → animated % →
  "AI ANALYSIS RUNNING" with a don't-leave-page warning), vision parse of league /
  fixtures / 1X2 / O2.5 / BTTS odds, then the existing Instantfoot **Poisson engine**
  computes model probabilities shown **separately** from bookmaker odds.
- **Results** — SportyBet-style league-tabbed odds grid; the model's pick is
  highlighted inside the grid (green pick cell + blue MODEL probability column),
  never blended into the odds.
- **Track record** — Settled / Unsettled / All tabs, per-bet date, type, stake,
  return, W/L, plus running totals (staked, returned, profit, hit rate).
- **Responsible gaming** — no "guaranteed winner" language anywhere; a visible
  18+/stake-responsibly note near payment, plus a working self-exclusion that blocks
  purchases and analyses server-side.

## 5. API cheat-sheet

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `api/init.php` | POST | create DB + tables (idempotent) |
| `api/app.php` | GET | public config (tiers, networks, flags) |
| `api/user.php` | GET/POST | current user / sign in / self-exclude |
| `api/payment.php` | POST | initiate MoMo charge → `{reference, status}` |
| `api/payment.php?reference=…` | GET | poll status |
| `api/webhook.php` | POST | Paystack callback (HMAC-verified) — grants tiers |
| `api/analysis.php` | POST | upload screenshot → parsed fixtures |
| `api/bets.php` | GET/POST | track record |

## 6. Notes

- No external libraries anywhere: vanilla CSS/JS on the client, plain PHP + PDO server-side.
- Prices and tier features are configurable in `config.php` (`mobifooty_tiers()`).
- Ghana phone prefixes / network mapping live in `mobifooty_network_from_phone()`.