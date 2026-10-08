# Mercado Pago Terminal for WooCommerce 1.0.0

Mercado Pago Point payments on **WooCommerce POS Pro 2.0 or newer**. Requires WooCommerce, WordPress 6.0+, and PHP 7.4+. Pro includes Free; do not install the standalone Free plugin as a dependency.

This is the `next` / 2.0 integration. The `main` / 0.x line is separate. This release has automated, transport-faked verification, **not live Mercado Pago or hardware certification**.

## Setup

1. Activate WooCommerce, WooCommerce POS Pro 2.0+, and this extension. Without compatible Pro, the extension shows a notice and registers no gateway or provider.
2. Open **WooCommerce → Settings → Payments → Mercado Pago Terminal**. Enter the access token and select the matching test/live label. The token itself determines the Mercado Pago environment; changing the label does not change credentials. Finish outstanding payments before replacing a token.
3. In your Mercado Pago integration, subscribe to Order notifications using the URL shown beside **Webhook secret**:
   `https://your-store.example/wp-json/wcpos/v2/payments/webhook?provider=mercadopago`
   Paste the corresponding signature secret. **Empty secrets and invalid signatures are rejected with HTTP 401.** Polling and Pro's reconciliation do not depend on webhook delivery.
4. Use the gateway's credential check and terminals table. For a STANDALONE terminal, select **Switch to PDV**, then restart the terminal. Only PDV terminals are available for payment.
5. Enable the gateway in **POS settings** and configure Pro's reader selection, default reader, and allowed readers. Old extension-specific terminal settings no longer control selection.

## Taking payments

The app's terminal flow and the eligible order-pay page use the same provider adapter. The order-pay panel belongs to Pro and appears for the POS webview or an authenticated POS user with the required order permissions. It is not a storefront-customer terminal UI. Leave the web-checkout enable switch off for POS-only use: as specified, gateway availability checks only WooCommerce availability and token presence, so enabling it can expose a method to storefront customers who cannot use the panel.

Pro/Free own the ledger, payment UUID, reader curation, polling, locking, reconciliation, settlement, and redirects. Create requests use the payment UUID as their idempotency key and `wcpos_<UUID>` as their external reference. No automatic transport retries are made by this extension.

Point auto-captures; cashier prompts and manual capture are unsupported. API cancellation is requested, not presumed complete. If the terminal already picked up the order, cancel on the terminal or wait for provider expiry. The default expiry is five minutes (`mptfwc_order_expiration_time`).

The adapter reports the order's currency when supplied by Mercado Pago, falling back to the store currency when absent. **Configure the store and seller account to use the same currency**; a provider currency mismatch fails settlement.

## Refunds

Use WooCommerce's **Refund via Mercado Pago Terminal** action. Pro binds the operation to that WooCommerce refund and records the provider result. Full refunds send no payload; partial refunds use the MP payment reference, fetching it from the MP order when needed. Historical 0.x sales remain refundable by their WooCommerce transaction ID (`ORD…`). The transaction ID remains the MP **order** ID; `PAY…` is a separate payment reference.

Provider refusals remain errors; do not assume a pending refund has succeeded. Check Mercado Pago before retrying an ambiguous refund or recording money returned another way.

## Upgrading from 0.x

A one-time upgrade adopts non-final legacy attempts into Pro's ledger without creating a second provider order. Old `_mptfwc_*` metadata remains inert. Adoption requires a known MP order ID; a legacy indeterminate create with no returned MP ID cannot be adopted by this routine. Adoption processes up to 25 orders per `init`, saving its offset until a short page completes the upgrade. Failed orders are logged (order ID and error code) and skipped, not automatically retried; review these failures for manual reconciliation. The old reconciliation cron is cleared. Pro now owns the payment panel, reader settings, support bundle, and reconciliation.

### Behavior changes / regressions

- Pro 2.0 is mandatory; standalone 0.x operation is removed.
- Unsigned notifications are no longer processed. Replace the old AJAX webhook URL with the Pro REST URL above.
- Extension reader controls, browser logs, log-level settings, payment AJAX endpoints, and support-bundle endpoint are removed. Use Pro and WooCommerce's equivalents.
- The old `mptfwc_order_payload` customization filter is removed; `mptfwc_order_expiration_time` remains.
- `refunded` is a completed-payment observation, not an instruction to create another WooCommerce refund. External terminal-side refunds require merchant reconciliation.

## Reporting a problem

Open this gateway's WooCommerce settings page and use the **Support bundle** row added by Pro. Attach that bundle to your report, along with the order and approximate payment time. Review the bundle before sharing it.

API requests and responses are logged once at debug level using WooCommerce logging, source **`mercadopago-terminal`**, with Pro redaction and without the request Authorization header. Ledger events use Pro's logging. Configure log retention and verbosity in WooCommerce, not this extension.

## What is untested

No seller sandbox account or physical Point terminal was exercised for this release. Real API error/response shapes, idempotency retention, Point webhook signatures, regional currency behavior, real declines, terminal-side cancellation, PDV switching/restart, receipt behavior, and live refunds remain unverified. Automated tests are not payment-provider certification. Credential replacement during an existing payment is not covered by the mode-label isolation scenario.

## Development

The WordPress tests require a composer-installed Pro `next` checkout at `../woocommerce-pos-pro`, with Free vendored by Pro. `.wp-env.json` mounts WooCommerce, Pro, and this extension. From a running environment, derive the mounted extension directory from the checkout name with `$(basename "$PWD")` (as the package scripts do). The default checkout name is `mercadopago-terminal-for-woocommerce`:

```sh
npx wp-env run --env-cwd="wp-content/plugins/$(basename "$PWD")" tests-cli -- vendor/bin/phpunit -c phpunit.xml.dist --filter 'Tests\\Conformance\\'
npx wp-env run --env-cwd="wp-content/plugins/$(basename "$PWD")" tests-cli -- vendor/bin/phpunit -c phpunit.xml.dist --filter 'Tests\\Includes\\'
```

Always select with `--filter`, not a directory argument. To record **missing** goldens once, insert `env WCPOS_RECORD_TRANSCRIPTS=1` before `vendor/bin/phpunit`. Review the JSON in `tests/includes/Conformance/transcripts`, rerun without recording, then commit it. CI never records.

The fixture uses the real gateway, adapter, HTTP client, Pro handlers, and Free ledger. Only Mercado Pago HTTP is faked. Transcript `webhook` means the authoritative GET triggered by a signed notification; `fetch` means an ordinary poll/refund lookup. Currency mismatch changes the store currency after create to exercise the fallback for responses that omit currency; unit tests also cover explicit provider currency mismatches. The adoption scenario models an old provider action by removing its new-style external reference. Prompts, manual capture, and final cancellation are explicitly skipped.
