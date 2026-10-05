# Mercado Pago Terminal for WooCommerce

Take card payments on a Mercado Pago **Point Smart** (1 or 2) terminal from WooCommerce and WooCommerce POS. The till sends the order total to the terminal, the customer pays there, and the order records the payment.

The plugin uses Mercado Pago's current **Orders API** for Point (`POST /v1/orders` with `type: "point"`), not the older Payment Intents API. Mercado Pago is the source of truth for every payment; WooCommerce order meta is only a cache.

> **Status: unreleased, untested on hardware.** Everything below is built from Mercado Pago's public developer documentation (read 2026-10-05) and covered by unit tests against the documented payloads. Nothing has run against a Mercado Pago account or a physical terminal yet. See [What is untested](#what-is-untested).

## Requirements

- WordPress 5.2+, WooCommerce, PHP 7.4+.
- A Mercado Pago **seller account** in a country where Point runs on the Orders API: Argentina, Brazil, Mexico, Uruguay, Colombia, Chile or Peru.
- An application under Mercado Pago → **Your integrations**, with its access token (`APP_USR-…`).
- A Point Smart terminal in **PDV** (integrated) mode. Terminals ship in STANDALONE mode.

## Setup

1. Install and activate WooCommerce and this plugin.
2. Go to **WooCommerce → Settings → Payments → Mercado Pago Terminal**.
3. Choose **Mode**: *Test* for test credentials (they drive Mercado Pago's virtual terminal), *Live* for production credentials.
4. Paste the application's **Access token**.
5. In Mercado Pago, open **Your integrations → your application → Webhooks**. Add the webhook URL shown under *Webhook secret* (`…/wp-admin/admin-ajax.php?action=mptfwc_webhook`), select the **Order (Mercado Pago)** event, save, and paste the **secret signature** back into *Webhook secret*. Without the secret, notifications are still processed but their signatures are not verified; the diagnostics panel says so.
6. Save, then reload the page: the **Default terminal** dropdown and the **Terminals** table now list the account's terminals.
7. A terminal shown in STANDALONE mode cannot receive orders. Click **Switch to PDV**, then **restart the terminal** (Mercado Pago requires the restart).
8. Optionally restrict **Enabled terminals**, and turn on **Lock terminal selection** so cashiers always use the default terminal.
9. In WooCommerce POS, enable the gateway under **POS → Settings → Checkout**. The WooCommerce → Payments switch only affects the online store.

## Checkout flow

1. The cashier picks the terminal (or it is locked to the default) and clicks **Start Terminal Payment**.
2. The plugin creates a Mercado Pago order for the order total on that terminal. The order expires on Mercado Pago's side after 5 minutes (filter `mptfwc_order_expiration_time`, ISO 8601 duration, `PT30S`–`PT3H`).
3. The panel polls every 2 seconds (`mptfwc_poll_interval_ms`). It shows *Customer is paying on the terminal…* while the order is `at_terminal`, and *Confirm the payment on the terminal* for `action_required`.
4. When Mercado Pago reports the order `processed`, the order is completed (transaction id = the Mercado Pago `ORD…` id) and the POS goes to its receipt page.
5. The webhook does the same independently, so a closed browser does not lose a payment.

### Cancelling

Mercado Pago only lets the API cancel an order **before the terminal picks it up** (status `created`). After that the cashier sees *The payment is already on the terminal. Cancel it on the terminal, or wait for it to expire.* The panel keeps polling, so a payment the customer completes anyway still finishes the order.

### Refunds

Refunds go through the normal WooCommerce refund screen (*Refund via Mercado Pago Terminal*). A refund of the whole order is sent as a full refund; anything else as a partial refund against the payment. Mercado Pago allows refunds up to 90 days after payment. If Mercado Pago refuses an API refund, the error says so: some card acquirers only allow refunds on the terminal. In that case refund on the Point terminal and record the refund in WooCommerce manually.

## Safety model

- **No double charge on retry.** The idempotency key and `external_reference` for a payment are saved on the order before the create request is sent. A retry after a timeout or transport error reuses them, so Mercado Pago returns the same order instead of making a second one. A definitive 4xx rejection discards them, because no order exists.
- **Bound to the order.** A Mercado Pago order completes a WooCommerce order only if its `external_reference` names that order, it was created by this shop for that order (attempt history), its type is `point`, its amount equals the order total, and its terminal matches the attempt.
- **Exactly once.** Completion runs under a per-order lock in `wp_options` and is idempotent on the transaction id. A second payment for an already-paid order is recorded as a conflict note, not completed again.
- **Webhooks are verified and never trusted.** The `x-signature` HMAC-SHA256 is checked with the webhook secret before anything else. Only `data.id` is read from the body; the order is always fetched from the API with the shop's token.
- **Checkout endpoints** need a per-order token or an order capability; switching a terminal to PDV needs `manage_woocommerce` and a nonce.
- Access tokens are redacted from all logs (WooCommerce → Status → Logs, source `mercadopago-terminal-for-woocommerce`).

## What is untested

Mercado Pago provides a sandbox that should cover most of the flow without hardware: test credentials, a virtual terminal (serial `SBX0000001`, e.g. `NEWLAND_N950__SBX0000001`) and `POST /v1/orders/{id}/events` to simulate `processed`, `failed`, `canceled`, `expired`, `refunded` and `action_required`, with real webhooks. It needs a Mercado Pago account, which we do not have yet, so **none of the following has been run**:

- Any call against the real API: field names in responses, error shapes, and the idempotency behaviour (format and retention window not documented).
- The webhook signature manifest (`id:<data.id>;request-id:<x-request-id>;ts:<ts>;`). It is Mercado Pago's documented standard, but its use for Point order notifications is not confirmed.
- Whether the virtual terminal appears in the terminal list and accepts the PDV switch. The plugin allows an unlisted terminal, so the sandbox device should still work.

These need a physical Point Smart terminal:

- Real card flows (chip, contactless, PIN), declines, `action_required` on a real device.
- Terminal-side cancel (`canceled_on_terminal`) and the behaviour once an order is `at_terminal`.
- The PDV ↔ STANDALONE switch and restart, and what a STANDALONE terminal does with an order.
- Receipt printing (`print_on_terminal` is `no_ticket`; filter `mptfwc_order_payload` to change it), installments, QR on the terminal.
- Which acquirers force terminal-side refunds.
- Mercado Pago's integration-quality measurement, which needs a real production payment.

## Development

```sh
composer install
composer lint
composer test       # PHPUnit, WordPress and WooCommerce stubbed
composer test:js    # checkout panel (Node 22+)
```

## References

- Point overview: https://www.mercadopago.com.mx/developers/en/docs/mp-point/overview
- Payment processing: https://www.mercadopago.com.mx/developers/en/docs/mp-point/payment-processing
- Integration test (virtual terminal, simulated statuses): https://www.mercadopago.com.mx/developers/en/docs/mp-point/integration-test
- Notifications: https://www.mercadopago.com.mx/developers/en/docs/mp-point/notifications
- Terminal operating mode: https://www.mercadopago.com.mx/developers/en/docs/mp-point/configure-terminal
- Order and transaction statuses: https://www.mercadopago.com.mx/developers/en/docs/mp-point/resources/status-order-transaction
