=== Barar Atik - TextBee SMS & OTP ===
Contributors: Atik Hosen
Tags: sms, otp, textbee, woocommerce, login with phone
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight TextBee SMS gateway for WordPress and WooCommerce: order SMS, message log, phone OTP login, password reset, phone checkout.

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
* New accounts can store the username as the local phone number (`01722032083` instead of `+8801722032083`), so the account is also matched by the local number.
* Password reset with a phone code on **wp-login.php** (lost password / reset password) and on the **WooCommerce My Account** lost password form: verify the code, choose a new password, optional automatic sign-in. The classic email reset link stays available next to it.

**Login, registration and reset anywhere (shortcodes)**

* `[barar_otp_login]`, `[barar_otp_register]` and `[barar_otp_auth]` (both forms) render the same OTP forms on any page, page builder or widget.
* Attributes: `context="wordpress|woocommerce"`, `redirect="/checkout/"`, `title="Welcome back"`.
* The shortcodes follow the settings on the **Authentication** page; every shortcode is listed with a **Copy** button on **TextBee SMS → Forms**.

**WooCommerce checkout**

* **Three switchable tabs replace the "Enable login during checkout" area**: *Phone OTP Login*, *Phone OTP Registration* and WooCommerce's own *Username / Email Login*. Each tab has its own switch, the tab labels are editable, and Phone OTP Login is what opens first. Switch the tabs off and the two phone panels are stacked as before.
* WooCommerce's own login form is reprinted verbatim inside the third tab, so its nonce, its `wc_ajax` POST and any theme template override keep working untouched.
* **Login without an email address** hides the standard email + password login form at checkout, so the phone number is the only way in.
* **Checkout sign-in popup** (optional) puts the same tabs in a dialog opened from a button above the checkout form; the page underneath is left exactly as it was and closes on the button, the backdrop or Escape.
* **Verify guests before they pay**: guests must prove the billing phone number and email address with a code before Place Order works. One code goes to both channels. The check runs on the server and the order is refused unless the code was accepted for the very phone number and email address being ordered — editing either one afterwards invalidates the proof, and no amount of frontend fiddling, refreshing or posting straight to the checkout bypasses it. Signed-in customers skip the step. Either channel can be switched off.
* **Extra content on the checkout page**: two boxes above and below the checkout form, both taking normal content and shortcodes.
* **Allow orders without an email address** makes the billing email optional and keeps the phone number required — for customers without an email address.
* **The phone number is the customer account** turns the verified phone number into the WooCommerce customer before the order is placed: a number that already has an account signs in, an unknown number gets a new account, and no email address is needed anywhere. Off by default.
* The phone number must have been proven with a code in the same browser session; a number that was only typed stays a guest order. Signing in a known number and creating a new account are separate switches, as is the role a new account receives.
* Accounts holding an administrator-level role are never signed in through the checkout, and a number linked to several accounts is never guessed at.
* Billing labels/placeholders (email, phone, first and last name) are editable from **Forms**.
* Email → SMS mirror: send a copy of the selected WooCommerce emails (invoice, note, refund, ...) to the customer's phone. Admin-facing emails are never mirrored, and orders without an email address are mirrored even though WooCommerce could not mail them.

**Form text and design**

* Every visible string of the plugin's own forms is editable: labels, placeholders, buttons, tabs and the Password / Phone OTP switches.
* Design controls for accent colour, field border, field background, label colour, button colours, corner radius, font size and form width.
* **Disable Plugin Styles** loads no plugin CSS at all and lets your theme decide how the forms look.
* Only non-default values are printed, so a fresh install looks exactly like it did before.
* Optional improvement of the standard WordPress / WooCommerce forms: a show/hide eye icon on every password field and browser autocomplete for email addresses.

**Dashboard & diagnostics**

* Redesigned tabbed dashboard: Overview, Connection, Automation, Authentication, Messages and Diagnostics — live counters, setup checklist and recent traffic in one hub.
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
5. Configure order events under **Automations**, OTP behaviour under **Authentication**, then use **Forms** for the shortcodes/checkout options and **Design** for the look of your forms.
6. Watch traffic in **Messages** or in the tabs of the **Dashboard**.

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

= How do I show the login or registration form on my own page? =

Paste `[barar_otp_login]`, `[barar_otp_register]` or `[barar_otp_auth]` into any page. Every shortcode is listed with a Copy button on **TextBee SMS → Forms**.

= Can a customer place an order without an email address? =

Yes. Turn on **Allow orders without an email address** on **Forms** — the billing email becomes optional and the phone number stays required. Enable the email → SMS mirror (or the order automations) so the customer still gets the order notification by text. Guest checkout has to stay enabled in WooCommerce for this to work.

= My theme already styles its own forms — how do I stop the plugin overriding them? =

Tick **Disable Plugin Styles** on **Design**. The plugin then loads no CSS at all and your theme decides how every field looks.

== Changelog ==

= 1.2.0 =
* Checkout verification has its own timing settings in the Checkout tab: resend countdown (default 60 seconds) and code validity (default 1 minute). The general OTP timing is no longer shared with it.
* Clearer wording of the two audience switches: guests (default on) and logged-in customers (default off, so a logged-in shopper never sees the popup).
* The verification popup is now a real centred dialog over a blurred page. Its layout CSS is printed with the popup, so it no longer falls back to a plain block at the bottom of the checkout when the stylesheet is missing (cache/optimiser plugins), and it is moved to <body> so theme containers cannot break it.
* The popup shows one code field, a validity countdown with a progress bar, a resend countdown and (optionally) your own labels. The resend countdown no longer disappears when its label is empty.
* After a failed send the popup stays on the code step with a working resend button.
* Place Order always opens the code popup first (as soon as the billing phone is filled in); other missing fields are reported by WooCommerce after the code was accepted. The server still refuses the order without an accepted code.
* Labels: the plugin no longer has any built-in wording. Every label, tab, button, heading and note is whatever you type under Texts; a field left empty is hidden. This includes the billing field labels on the checkout (phone, email, first and last name).
* New settings fields for the checkout "Verify" button, the "Edit" button and the email tab, which could not be edited before.
* Security: checkout sign-in / account creation by phone number now ALWAYS requires a number proven with a code. The "only for a number proven with a code" switch was removed because turning it off let anyone sign in as any customer by typing their number.
* Security: the checkout code must reach the phone by SMS whenever the phone number is being verified. A code that only arrived by email can no longer verify a phone number (for example while the SMS gateway was down).
* Security: checkout code requests are now limited per phone number and per IP (previously only per phone + email pair, which an attacker could reset by changing the email).
* Security: a customer editing their own billing phone can no longer take over, or block, another customer's phone sign-in. A code-verified number outranks an editable billing phone.
* A checkout verification now pays for one order; the next order asks for a new code.
* New daily cleanup of the message log (90 days by default, filter barar_atik_log_retention_days).
* Uninstall now really removes the plugin's transients.
* Settings are cached per request (faster pages).
* Fixed a corrupted SMS character list in the admin counter; removed unused functions and a stray comment.
* Checkout verification now also applies when WooCommerce is set to require an account (previously it silently skipped the code in that case).
* The Checkout settings tab now lists, in red, every reason why the code step would not appear (master switch off, Checkout block in use, SMS credentials missing, ...).
* New filter barar_atik_client_ip for sites behind a proxy or CDN.

= 1.1.0 =
* Checkout authentication is now three switchable tabs — Phone OTP Login, Phone OTP Registration and WooCommerce's own Username / Email Login — in the "Enable login during checkout" area, with Phone OTP Login active by default. WooCommerce's login markup is captured and reprinted inside its tab, so its nonce, its AJAX POST and theme template overrides keep working.
* Optional checkout sign-in popup holding the same tabs; closes on the button, the backdrop or Escape and leaves the page behind it untouched.
* Optional guest verification before Place Order: one code is sent to the billing phone number and the billing email address, and the order is refused server side unless the code was accepted for the exact details being ordered. Editing either detail invalidates the proof.
* Two new checkout content boxes (above and below the form) that take normal content and shortcodes.
* Phone OTP is now the default tab everywhere the plugin offers authentication, including password reset (where the email reset link is the alternative).
* Fixed the OTP panels fighting over WooCommerce's own login form: inside the checkout tabs the phone panels no longer hide the username + password form that belongs to the neighbouring tab.
* Fixed the Place Order button re-enabling itself after a totals refresh, and the verification box losing its verified state every time WooCommerce replaced the order summary.
* New tabbed dashboard (Overview, Connection, Automation, Authentication, Messages, Diagnostics) with live counters, setup checklist and recent traffic in one hub.
* Password reset with a phone code on wp-login.php and on the WooCommerce My Account lost password form, including an optional automatic sign-in afterwards.
* Shortcodes `[barar_otp_login]`, `[barar_otp_register]` and `[barar_otp_auth]` with `context`, `redirect` and `title` attributes, listed with Copy buttons on the new **Forms** page.
* Phone login, phone registration, "login without an email address" and "order without an email address" at the WooCommerce checkout; editable checkout labels and placeholders.
* New "the phone number is the customer account" checkout feature (off by default): the phone number proven with a code signs the WooCommerce customer in, or creates a phone-only account, so a store can take orders with no email address at all.
* Email → SMS mirror: send selected WooCommerce emails as a text message (never admin-facing ones, and also when the order has no email address at all).
* Every visible string of the plugin forms is now editable (labels, placeholders, buttons, tabs, switches) from the **Forms** page.
* New **Design** page: accent, border, surface, label and button colours, corner radius, font size, form width and a **Disable Plugin Styles** switch. Only non-default values are printed.
* Phone numbers can be stored as the local username (`01722032083` instead of `+8801722032083`).
* Show/hide eye icons and browser email autocomplete on the standard WordPress and WooCommerce password/email fields (optional).
* Email fields now use `type="email"`, `autocomplete="email"` and `inputmode="email"` so mobile keyboards and browser suggestions work properly.

= 1.0.0 =
* Initial release: TextBee connection, WooCommerce order SMS automations with macros, message center, webhook status updates, phone OTP login/registration for WordPress and WooCommerce.
* Fixed OTP verification failing with "Something went wrong" on WooCommerce 9.4+ (the `wc_create_new_customer()` signature changed in WooCommerce 9.4 — the plugin now supports both call signatures and isolates the AJAX handlers from stray output/PHP errors).
* New admin dashboard with counters, setup checklist and recent traffic; shared navigation across all plugin screens.
* New options: phone as username, registration system (automatic / WooCommerce / WordPress), configurable registration fields (email, first name, last name: required / optional / hidden), editable phone label, post-login redirect mode (default / previous page / custom URL) and inline OTP injection inside the WooCommerce login and register forms.
* CSV export of the message log and a "last authentication error" card on the Diagnostics page.
