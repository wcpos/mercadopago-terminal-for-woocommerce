# Changelog

All notable changes to Mercado Pago Terminal for WooCommerce will be documented in this file.

## 0.1.0 - Unreleased

### Added

- A WooCommerce gateway that drives Mercado Pago Point Smart 1 and 2 terminals through the Orders API (`POST /v1/orders` with `type: point`).
- Settings for the access token, webhook secret, test or live mode, default terminal, enabled terminals and terminal lock. Saved secrets are never shown again.
- A terminal list with each terminal's operating mode, and a Switch to PDV button for terminals still in STANDALONE mode.
- An order-pay panel for terminal selection, payment progress, cancellation and logs, with POS-aware redirects to the receipt.
- Idempotent payment creation. The idempotency key and `external_reference` are saved before the request, so a retry after a timeout cannot charge twice.
- Verification before completion (order reference, attempt history, amount, terminal), with exactly-once completion under a per-order lock.
- Signed `order` webhooks (`x-signature` HMAC-SHA256). The order is always re-fetched, never taken from the notification.
- A 5-minute reconciliation sweep, so a paid order completes even when the webhook and the browser are both gone.
- Cancel through the API while an order is `created`. Once the terminal has it, the cashier is told to cancel on the terminal.
- Full and partial refunds from WooCommerce, with a clear message when Mercado Pago requires a refund on the terminal.
- Debug logging (default while in beta) of every API call, webhook and state change, with tokens, signatures and card numbers redacted.
- A Download support bundle button and a live API check on the settings page.
