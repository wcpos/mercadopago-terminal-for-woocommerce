# Lessons from the sibling terminal plugins

Paul (2026-10-05) asked for this plugin to "draw from all the lessons of the Stripe/SumUp/Mollie plugins". This is that checklist. Each lesson comes from a real field failure or review finding in `stripe-`, `sumup-`, `mollie-`, `square-` or `payarc-terminal-for-woocommerce`: CHANGELOGs, fix commits, closed issues, the wiki payments hub, support handoffs and the terminal-core audit. The full write-up, with sources, is kept off-repo in the worker handoff `lessons-research.md` (2026-10-05).

Status: ✅ in the code · 🔧 added in the lessons pass (#10 sweep and robustness, #11 settings and diagnostics) · ⏳ needs a Mercado Pago account or a Point Smart to verify · ➖ does not apply · ⚠️ deliberately deferred (reason given)

## A. Completing an order exactly once

| # | Lesson | Status | Where |
|---|---|---|---|
| L1 | Webhook and poll raced and completed an order twice (mollie#21: stock 27 → 12 → −3) | ✅ | Every path (webhook, poll, sweep, start-reuse, cancel, pay form) completes through `PaymentReconciler::reconcile()`: a per-order `complete_payment` claim, reload, then complete. Tests: `PaymentReconcilerTest` (held lock, stale copy reloaded, twice → idempotent) |
| L2 | Non-atomic per-order lock | ✅ | `PaymentLock` is Mollie **main**'s `INSERT IGNORE` lock, verbatim, with compare-and-delete takeover and release. Tests: `PaymentLockTest` |
| L3 | HPOS data cache served the unpaid row | ✅ | `PaymentReconciler::reload_order()` clears the post cache, `OrderCache` and `OrdersTableDataStore` cache, then `read_meta_data(true)` |
| L4 | HPOS meta cache survived a failed row-cache delete | ✅ | `reload_order()` also calls `OrdersTableDataStoreMeta::clear_cached_data()` directly |
| L5 | The request that lost the race must still answer "paid" | ✅ | `AjaxHandler::with_paid_redirect()` redirects on `paid`, `already_paid` and `conflict`; a held claim answers `paid`/`completing` when verified |
| L6 | Pay-form submit marked an unpaid order paid | ✅ | `Gateway::process_payment()` polls Mercado Pago once and succeeds only if the order is then paid |
| L7 | A declined card approved the order | ✅ | Only `processed` on a known attempt, with matching amount, payment id and terminal, completes. Tests: `PaymentReconcilerTest` invalid-order cases |

## B. Webhooks

| # | Lesson | Status | Where |
|---|---|---|---|
| L8 | The webhook body is a hint; re-fetch the payment | ✅ | `WebhookHandler` verifies `x-signature`, then `GET /v1/orders/{id}`, and reconciles from that response only. A forged `data.status` is tested |
| L9 | Webhook-only completion left charged cards on unpaid orders (sumup#6/#13) | ✅ 🔧 | The poll completes without the webhook; the `PaymentSweeper` cron (lessons pass) completes it when the browser was closed too. Test: "webhook never arrived, browser closed" |
| L10 | Stale or out-of-order events from an earlier attempt | ✅ | The attempt history binds each Mercado Pago order to its WooCommerce order by `external_reference`, and the status is always re-fetched, never taken from the event |
| L11 | Duplicate deliveries added duplicate notes | 🔧 | `PaymentReconciler::apply()` adds a final-status note only when the status changes |
| L12 | A PHP `Error` in an untested path became a fatal | 🔧 | `WebhookHandler` and `AjaxHandler` catch `\Throwable` |
| L13 | Return codes decide retries | ✅ | 401 on a bad signature, 200 for handled or irrelevant notifications, 500 on failure so Mercado Pago retries |
| L14 | Signature health was invisible | 🔧 | The diagnostics row "Last verified webhook" is written only on a verified signature and reset when credentials change. The webhook URL is shown in the field help |
| L15 | Auth must not depend on something the merchant cannot get | ✅ | Without a webhook secret, payments still complete by poll and sweep, and diagnostics say signatures are not verified |

## C. Idempotency and double charges

| # | Lesson | Status | Where |
|---|---|---|---|
| L16 | No idempotency key on create | ✅ | `X-Idempotency-Key` is mandatory on create, cancel and refund. The create key is stored before the HTTP call (`PaymentAttempt::prepare()`) |
| L17 | An indeterminate create may have succeeded | ✅ | A timeout, 5xx, 409 or 429 keeps the key and `external_reference` for the retry; only a definitive 4xx discards them. A lost create response is recovered by reference |
| L18 | A fallback transport re-sent a money-moving POST | ✅ | One WP HTTP client, no SDK, no automatic retries |
| L19 | Double Start on one order | ✅ | Start reuses a live attempt (`created`/`at_terminal`/`action_required`), refuses a paid order, and creates a new one only after a final unpaid state. Under the `create_payment` lock |
| L20 | Partial or extra capture | ✅ | An amount mismatch fails verification (order note, no completion). A second `processed` order on a paid order gets a conflict note |

## D. Amounts and currency

| # | Lesson | Status | Where |
|---|---|---|---|
| L21 | Amount taken from the client | ✅ | The amount is always computed from `$order->get_total()` on the server |
| L22 | Wrong currency exponent | ✅ ⏳ | Amounts are 2-decimal strings, as documented (`Money`). How Mercado Pago wants CLP/COP is unconfirmed; check it in the sandbox |
| L23 | An unknown account attribute blocked payments | ➖ | No account lookup gates payments |
| L24 | Verify amount and currency before completing | ✅ ⏳ | The amount is verified. The Point order has no documented currency field, so currency is implied by the seller's site; check it in the sandbox |
| L25 | An on-terminal tip changed the charged amount | ⏳ | Amount above total is treated as a mismatch (note, no completion). Whether Point Smart adds tips or interest in PDV mode is unconfirmed |

## E. Polling, timeouts, cancel and stuck payments

| # | Lesson | Status | Where |
|---|---|---|---|
| L26 | The provider's lookup decides; poll it | ✅ | The panel polls `GET /v1/orders/{id}` every 2 s; final states are shown with their reason |
| L27 | "Not found yet" is pending, not an error | 🔧 | A 404 within 60 s of create is reported as `created` with a waiting message |
| L28 | A cancelled state not recognised as final | ✅ | One status map (`PaymentAttempt` helpers); an unknown status maps to `unknown` (pending). Data-provider test over every documented status |
| L29 | Cancel is a request, not a result | ✅ | Cancel re-fetches after the API call; `processed` wins; "canceled" is shown only when the server says so (JS test) |
| L30 | An un-cancelable payment trapped the cashier | ⚠️ | Mercado Pago allows API cancel only while `created`. Once `at_terminal` the cashier is told to cancel on the terminal and the panel keeps polling. The order expires on Mercado Pago's side after `expiration_time` (5 min), which bounds the wait. No "set aside" flow until a real terminal shows it is needed |
| L31 | Auto-cancel at timeout without a final read | ✅ | The timeout cancel goes through `cancel_order_payment()`, which reads first |
| L32 | The browser cannot clean up; run a sweep | 🔧 | `PaymentSweeper` (WP-Cron, 5 min, attempts older than 120 s), reconcile-only, unscheduled on deactivation |
| L33 | The sweep query was ignored on the posts store | 🔧 | `type => shop_order` plus the `meta_key`/`EXISTS` shortcut, never `meta_query` (tested) |
| L34 | Abandoned payments invisible to the sweep | ➖ | No abandon flow (see L30) |
| L35 | A device heartbeat used as liveness | ✅ | Nothing gates on terminal "last seen" |
| L36 | A reader stuck after a timed-out dispatch | ⏳ | No device actions besides create and cancel; behaviour on a real Point Smart unverified |
| L37 | Never cancel a payment you did not start | ✅ | Cancel only targets the current attempt's own Mercado Pago order |
| L38 | Don't add calls before dispatch | 🔧 | Start's PDV check uses a 5-minute cached terminal list with a last-good fallback, so `POST /v1/orders` is the only live call. Every API call logs its duration |
| L39 | Admin hung on a slow API | ✅ | The settings terminal list uses an 8 s timeout, a 5-min transient, a text-field fallback, and is cleared on save |

## F. Terminal selection and pairing

| # | Lesson | Status | Where |
|---|---|---|---|
| L40 | Silently picking the first terminal | ✅ | Explicit or default terminal only; server-enforced lock and allowlist |
| L41 | A failed discovery wiped saved settings | ✅ | The "Enabled terminals" field is omitted (not emptied) when the list cannot be fetched |
| L42 | An empty device list looked like a fault | ✅ 🔧 | PDV status per terminal with a Switch to PDV button (restart reminder). An empty list explains how a Point Smart gets listed |
| L43 | Length limits on provider ids | ✅ | No length limits; terminal ids are taken from the list |
| L44 | Panel without readers after switching method | ✅ | The panel rebinds on `updated_checkout` and payment-method change |

## G. Auth, credentials and modes

| # | Lesson | Status | Where |
|---|---|---|---|
| L45 | The WP nonce fails inside the POS | ✅ | Payment endpoints use an order token or an order capability; the nonce is used only for the admin PDV switch |
| L46 | Anonymous endpoints | ✅ ⚠️ | No `__return_true` routes; only the signed webhook is public. The order token does not expire (as in Mollie); deferred until there is a 1.11 server-mode adapter |
| L47 | Key failures invisible | 🔧 | The diagnostics "Mercado Pago API" row makes the checkout's first call and distinguishes 401, 403 and unreachable |
| L48 | Test and live credentials mixed | 🔧 ⏳ | A warning when Live mode has a `TEST-` token. Whether test users' `APP_USR` tokens can be told apart is unconfirmed |
| L49 | OAuth refresh never called | ➖ | The merchant pastes their own access token; no OAuth |
| L50 | Saved secrets echoed in admin HTML | 🔧 | Secret fields never render their value ("Saved (ends in 1234). Leave blank to keep.") |
| L51 | Settings read options that are never saved | 🔧 | `OptionKeysTest`: every option read exists as a form field, and no Mollie leftovers |

## H. Refunds

| # | Lesson | Status | Where |
|---|---|---|---|
| L52 | Refunds created twice, impossible, or in the wrong mode | ✅ | `RefundHandler` binds to the refund WooCommerce created, never creates one, and keys on the WooCommerce refund id. MP refusals return `WP_Error` with the terminal-refund hint. The 90-day limit is left to Mercado Pago's own error |
| L53 | Refund status never refreshed | ✅ | `order.refunded` webhooks and polls reconcile and note `refunded`; a terminal-side refund shows as a note |

## I. Order status, payment method and notes

| # | Lesson | Status | Where |
|---|---|---|---|
| L54 | Per-gateway POS order status ignored | ✅ | `PaymentAttempt::claim_order_gateway()` stamps the gateway only inside completion |
| L55 | Gateway had no public title | ✅ | `title` and `description` are set (Gateway tests) |
| L56 | Order notes are the audit trail | ✅ | One note per state change, naming source, Mercado Pago order id and payment id, plus `status_detail` on failures |

## J. Enable switches, POS redirects and receipts

| # | Lesson | Status | Where |
|---|---|---|---|
| L57 | The WooCommerce Enable checkbox is web-only | ✅ | `Settings::active() = enabled() \|\| enabled_for_pos()`. Poll, cancel, webhook and sweep are never gated |
| L58 | "Order already paid" loop | ✅ | Paid answers carry the POS-aware `redirect_url`; the JS never re-submits the form after a server completion |
| L59 | Storefront checkout dead end | 🔧 | On a plain storefront checkout (not POS, not order-pay), `process_payment()` sends the customer to the order-pay page; POS and order-pay submits keep the "not paid yet" notice |
| L60 | "Nothing happened" failures | ✅ | The panel shows the server's message; rejected requests are logged with a reason (see logging) |

## K. PHP, packaging and SDK

| # | Lesson | Status | Where |
|---|---|---|---|
| L61 | Vendored SDK collisions | ✅ | No Mercado Pago SDK (it would collide with the official Mercado Pago plugin); a thin WP HTTP client and a hand-written HMAC, with tests |
| L62 | Missing vendor folder | ✅ | No runtime Composer dependencies; the plugin's own autoloader |
| L63 | PHP minimum and syntax | ✅ | `Requires PHP: 7.4`, an activation guard, CI on 7.4 and 8.3 |
| L64 | Case-twin directories | 🔧 | CI step "No case-colliding paths" |
| L65 | Blocks bundle needs WP 6.6 | ➖ | No Blocks integration |
| L66 | Theme or admin CSS broke the layout | ✅ | Own `mptfwc-` classes; test fakes reject what browsers reject (from Mollie's harness) |

## Logging and diagnostics

Paul: "plenty of logging … collect all the information the first time so we can rapidly iterate."

| Lesson | Status | Where |
|---|---|---|
| Rejected requests left no log (payarc ≤0.1.11) | 🔧 | `AJAX request rejected` warnings with a reason code and booleans, never the token |
| Successful webhooks not logged (sumup#6) | ✅ | Every webhook is logged, with its signature result and outcome |
| Key failures hidden (stripe#124) | ✅ 🔧 | API errors are logged with status, error code and body; the diagnostics API check |
| Timing proved the cause of slowness (stripe #91/#105) | ✅ | Every API call logs `duration_ms`; webhooks and start/poll/cancel too |
| Redaction ate diagnostics (square) | ✅ | Masked by key name and secret pattern; `status`, `status_detail`, error codes and the idempotency key stay visible |
| Multi-line arrays broke the log viewer (stripe) | ✅ | Context is single-line JSON |
| No per-request correlation id | ✅ | Every line carries an 8-hex request id; the first line records plugin/WC/WP/PHP/WCPOS versions |
| Support needed screenshots | ✅ | A "Download support bundle" button: environment, settings with secrets masked, terminals, recent attempts, the last 500 log lines |

## Still to verify with an account or a terminal

L22, L24, L25, L36 and L48 above, plus three things the docs did not settle:
- the webhook signature manifest for Point orders (a rejected webhook now logs the exact manifest we computed);
- Mercado Pago's response field names;
- whether the sandbox virtual terminal is listed by `/terminals/v1/list`.
