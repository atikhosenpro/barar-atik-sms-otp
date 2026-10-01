=== Barar Atik - TextBee SMS & OTP ===
Contributors: bararatik
Tags: sms, otp, textbee, woocommerce, login with phone
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight TextBee SMS gateway for WordPress and WooCommerce: order SMS automations, message log and phone OTP login.

== Description ==

Barar Atik - TextBee SMS & OTP connects your site to the [TextBee](https://textbee.dev) SMS gateway (a phone paired with the TextBee app becomes your SMS sender) and adds phone-number OTP authentication.

**Connection**

* API key + Device ID stored safely (the key is masked in the UI and never printed in full, in JavaScript or in logs).
* Test Connection and Test SMS tools with HTTP status, batch id and safe error messages (401, 400/404 device, 429 quota, transport errors).
* Optional delivery webhook with HMAC-SHA256 signature verification (`X-Signature`), idempotent event handling and one-click subscription creation through the TextBee API.

**WooCommerce order automations**

* Events: new order, processing, completed, cancelled, failed — each with its own enable switch.
* Recipients per event: Customer (billing phone), Vendor (product author phone), Administrator (manual override with automatic fallback).
* Separate templates for vendor and administrator messages (fall back to the main message).
* Rich macro library (`{order_number}`, `{customer_name}`, `{order_total}`, `{items}` for multiple line items, `{vendor_phone}`, `{admin_phone}`, ...) with click-to-insert, live character counter and SMS segment warning.
* Order-based template preview against real orders before anything is sent.
* Bounded automatic retries (Action Scheduler when available, otherwise WP-Cron). SMS failures never interrupt checkout.
* HPOS (custom order tables) compatible — WooCommerce CRUD APIs only.

**Message center**

* Local log of everything this plugin sends plus messages pulled from TextBee.
* Filters for direction, status and free search; status badges; manual "Refresh from TextBee" (throttled).
* Statuses: pending/queued, dispatched, sent, delivered, failed, unknown, received. Statuses never move backwards.
* Phone numbers are masked in lists and diagnostics.

**Phone OTP authentication**

* Toggle OTP independently for wp-login.php login/registration and WooCommerce My Account login/registration.
* Choose how OTP appears on WooCommerce My Account: a separate panel below the forms, or injected **directly inside** the native login/register forms with a Password / Phone OTP switch.
* Password login stays available when enabled; passwordless registration and automatic sign-in after registration are optional.
* Account creation is configurable: use the phone number as the WordPress username, pick the registration system (automatic / WooCommerce / WordPress) and the new-user role.
* Registration fields are configurable: email, first and last name can each be required, optional or hidden; the phone label is editable.
* Configurable post-login redirect: default, previous page (referer) or a custom URL — always validated to stay on your site.
* Codes are random, stored only as an HMAC hash, single use, expiring, with resend cooldown, per-code attempt limit and per-phone/per-IP rate limits.
* No account enumeration on login (generic response), clear duplicate-phone errors with an admin resolver on the Diagnostics page.
* Registration uses `wp_insert_user` / `wc_create_new_customer` (compatible with the WooCommerce 9.4+ signature) with a safe, non-administrative role.

**Dashboard & diagnostics**

* Modern dashboard with live counters, a setup checklist and the latest message traffic.
* CSV export of the message log from the Message Center.
* The Diagnostics page shows the last caught authentication error (operation, message, location) for fast debugging.

**Privacy**

* The plugin stores your TextBee API key, phone numbers, message texts and delivery statuses in your own database. No data is sent anywhere except to the TextBee API.
* Verification codes are never written to the log (the code is redacted from the stored message).

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/barar-atik-sms-otp`, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' screen.
3. Open **TextBee SMS → Connection**, paste your TextBee API key (and Device ID if you use several devices), then use **Test connection**.
4. Optionally use **Generate secret & create subscription** to enable the delivery webhook.
5. Configure order events under **Automations**, OTP behaviour under **Authentication**, and watch traffic in **Messages** or on the **Dashboard**.

== Frequently Asked Questions ==

= Does a "queued" message mean it was delivered? =

No. TextBee answers that the message was accepted for sending. The final delivery state arrives later through the webhook or the **Refresh from TextBee** button on the Messages page.

= Where do I find the API key and Device ID? =

Both are in the TextBee dashboard. The API key is used as the `x-api-key` request header; the Device ID selects which paired phone sends the SMS (leave blank to use the account default device).

= Why does phone login say the number belongs to multiple accounts? =

For security the plugin never guesses between accounts. Open **Diagnostics → Duplicate phone numbers** and remove the number from the account that should not sign in with it.

= Can administrators register with phone OTP? =

No. The registration role is limited to safe roles (subscriber/customer); administrator capabilities are always rejected.

= Will SMS failures break checkout? =

No. Sending happens in a guarded flow; failures are logged and retried at most a few times, never blocking the order.

== Changelog ==

= 1.0.0 =
* Initial release: TextBee connection, WooCommerce order SMS automations with macros, message center, webhook status updates, phone OTP login/registration for WordPress and WooCommerce.
* Fixed OTP verification failing with "Something went wrong" on WooCommerce 9.4+ (the `wc_create_new_customer()` signature changed in WooCommerce 9.4 — the plugin now supports both call signatures and isolates the AJAX handlers from stray output/PHP errors).
* New admin dashboard with counters, setup checklist and recent traffic; shared navigation across all plugin screens.
* New options: phone as username, registration system (automatic / WooCommerce / WordPress), configurable registration fields (email, first name, last name: required / optional / hidden), editable phone label, post-login redirect mode (default / previous page / custom URL) and inline OTP injection inside the WooCommerce login and register forms.
* CSV export of the message log and a "last authentication error" card on the Diagnostics page.
