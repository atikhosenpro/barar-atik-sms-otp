# Barar Atik - TextBee SMS & OTP

**Barar Atik - TextBee SMS & OTP** connects WordPress and WooCommerce sites to the [TextBee](https://textbee.dev) SMS gateway and provides phone-number OTP authentication.

A phone paired with the TextBee app can be used as the SMS sender for transactional messages and OTP verification.

## Features

### TextBee Connection

- Connect WordPress to TextBee using an API key and Device ID.
- API keys are masked in the admin UI and are never exposed in full through JavaScript or logs.
- Test the API connection before using the gateway.
- Send test SMS messages from the plugin.
- Displays HTTP status, batch ID, and safe error information.
- Handles common API errors including:
  - `401` — Invalid authentication
  - `400/404` — Invalid or unavailable device
  - `429` — Rate/quota limit
  - Transport/network errors
- Optional delivery webhook with HMAC-SHA256 signature verification.
- Idempotent webhook event handling.
- Create the TextBee webhook subscription directly from the plugin.

## WooCommerce SMS Automations

Send automated SMS messages based on WooCommerce order events.

### Supported Events

- New order
- Processing
- Completed
- Cancelled
- Failed

Each event can be enabled or disabled independently.

### Recipients

SMS recipients can be configured per event:

- Customer — billing phone number
- Vendor — product author's phone number
- Administrator — manual phone override with automatic fallback

Vendor and administrator messages can use separate templates. If a separate template is not configured, the main message template is used.

### Message Macros

Templates support useful order-related macros, including:

- `{order_number}`
- `{customer_name}`
- `{customer_phone}`
- `{order_total}`
- `{items}`
- `{vendor_phone}`
- `{admin_phone}`

The template editor includes click-to-insert macros, a live character counter, and an SMS segment warning.

### Order Preview

Preview templates against real WooCommerce orders before sending messages.

### Retry Handling

- Automatic retries are bounded.
- Uses Action Scheduler when available.
- Falls back to WP-Cron when Action Scheduler is unavailable.
- SMS failures never interrupt WooCommerce checkout.

### HPOS Compatibility

WooCommerce High-Performance Order Storage (HPOS) is supported through WooCommerce CRUD APIs.

## Message Center

The Message Center provides a local record of SMS activity.

### Includes

- Messages sent by the plugin
- Messages pulled from TextBee
- Direction filtering
- Status filtering
- Free-text search
- Status badges
- Manual "Refresh from TextBee" action
- Throttled refresh requests
- CSV export

### Message Statuses

Supported statuses include:

- `pending`
- `queued`
- `dispatched`
- `sent`
- `delivered`
- `failed`
- `unknown`
- `received`

Message statuses never move backwards.

Phone numbers are masked in message lists and diagnostics.

## Phone OTP Authentication

The plugin provides phone-number OTP authentication for both WordPress and WooCommerce.

OTP authentication can be enabled independently for:

- WordPress `wp-login.php`
- WordPress registration
- WooCommerce My Account login
- WooCommerce My Account registration

### WooCommerce OTP Interface

For WooCommerce My Account, OTP can appear in two ways:

1. A separate OTP panel below the native forms.
2. Directly inside the native login/register forms with a **Password / Phone OTP** switch.

Password login remains available when OTP is enabled.

### Registration Options

The plugin supports configurable phone-based registration.

Options include:

- Passwordless registration
- Automatic sign-in after registration
- Use phone number as WordPress username
- Automatic registration system selection
- WooCommerce registration
- WordPress registration
- Configurable new-user role

Registration roles are restricted to safe non-administrative roles such as:

- Subscriber
- Customer

Administrator capabilities are never granted through phone OTP registration.

### Registration Fields

The following fields can be configured independently:

- Email
- First name
- Last name

Each field can be:

- Required
- Optional
- Hidden

The phone-number field label is also editable.

### Login Redirect

After successful authentication, users can be redirected to:

- Default location
- Previous page / referer
- Custom URL

Custom redirects are validated to prevent redirects outside the current site.

## OTP Security

OTP codes are designed to minimize exposure and abuse.

- Cryptographically random verification codes
- Codes are stored only as HMAC hashes
- Single-use codes
- Expiration time
- Resend cooldown
- Per-code attempt limit
- Per-phone rate limiting
- Per-IP rate limiting
- Generic login responses to prevent account enumeration
- Duplicate-phone detection and diagnostics
- OTP codes are never written to the message log

If a phone number belongs to multiple accounts, the plugin does not guess which account should be authenticated.

Administrators can resolve duplicate phone numbers from:

**TextBee SMS → Diagnostics → Duplicate phone numbers**

## Dashboard & Diagnostics

The plugin includes a modern administration dashboard with:

- Message counters
- Authentication information
- Setup checklist
- Recent SMS traffic
- Quick access to plugin settings

The Diagnostics page provides authentication troubleshooting information, including:

- Last authentication operation
- Error message
- Error location
- Duplicate phone number detection

Sensitive information such as OTP codes and full phone numbers is not exposed unnecessarily.

## Privacy

The plugin stores the following information in your own WordPress database:

- TextBee API key
- Device ID
- Phone numbers
- Message contents
- Message delivery statuses
- Authentication-related information required by the plugin

Data is sent externally only when communication with the TextBee API is required.

Verification codes are never stored in the message log. The code is redacted from stored SMS content.

For more information about TextBee, visit:

https://textbee.dev

## Requirements

- WordPress 6.0 or later
- PHP 7.4 or later
- WooCommerce is required for WooCommerce-specific features
- TextBee account
- TextBee API key
- TextBee paired device

## Installation

### WordPress Admin

1. Download or install the plugin.
2. Go to **Plugins → Add New** in WordPress.
3. Install the plugin.
4. Activate **Barar Atik - TextBee SMS & OTP**.
5. Open **TextBee SMS → Connection**.
6. Enter your TextBee API key.
7. Enter the Device ID if you use multiple TextBee devices.
8. Click **Test Connection**.

### Manual Installation

Upload the plugin directory to:

```text
/wp-content/plugins/barar-atik-sms-otp/
```

Then activate it from:

**WordPress → Plugins**

## Initial Setup

After activation:

1. Open **TextBee SMS → Connection**.
2. Add your TextBee API key.
3. Add a Device ID if required.
4. Run **Test Connection**.
5. Send a test SMS.
6. Optionally generate a webhook secret.
7. Create the TextBee delivery webhook subscription.
8. Configure WooCommerce SMS events under **Automations**.
9. Configure phone OTP under **Authentication**.
10. Monitor messages under **Messages**.
11. Use **Diagnostics** if authentication or delivery problems occur.

## Frequently Asked Questions

### Does a queued message mean it was delivered?

No.

A queued message means TextBee accepted the message for sending. The final delivery status is received later through the delivery webhook or by using **Refresh from TextBee**.

### Where do I find the TextBee API key and Device ID?

Both are available from your TextBee dashboard.

The API key is sent using the `x-api-key` request header.

The Device ID identifies the paired phone that sends the SMS. If you only use the default device, the Device ID can be left blank.

### Why does phone login say the number belongs to multiple accounts?

The plugin does not guess between multiple accounts for security reasons.

Go to:

**TextBee SMS → Diagnostics → Duplicate phone numbers**

Then remove the phone number from the account that should not use it for phone authentication.

### Can administrators register using phone OTP?

No.

Phone OTP registration is limited to safe non-administrative roles such as Subscriber and Customer. Administrator capabilities are always rejected.

### Will SMS failures break WooCommerce checkout?

No.

SMS sending is handled through a guarded process. Failures are logged and retried within the configured retry limits without blocking the WooCommerce order process.

### Are OTP codes stored in the message log?

No.

OTP codes are redacted and are never stored as plain verification codes in the message log. Authentication codes are stored only as HMAC hashes.

### Is WooCommerce HPOS supported?

Yes.

WooCommerce order data is accessed through WooCommerce CRUD APIs, making the plugin compatible with HPOS.

## Changelog

### 1.0.0

- Initial release.
- TextBee API connection.
- TextBee device configuration.
- Connection testing.
- Test SMS functionality.
- WooCommerce order SMS automations.
- New order, processing, completed, cancelled, and failed order events.
- Customer, vendor, and administrator recipients.
- SMS template macros.
- Vendor and administrator message templates.
- Template preview using real orders.
- Automatic bounded SMS retries.
- Action Scheduler support with WP-Cron fallback.
- HPOS-compatible WooCommerce order handling.
- Message Center.
- TextBee message synchronization.
- Message status tracking.
- Delivery webhook support.
- HMAC-SHA256 webhook verification.
- CSV message log export.
- Phone OTP login and registration.
- WordPress login and registration support.
- WooCommerce My Account login and registration support.
- Password / Phone OTP switch for WooCommerce forms.
- Passwordless registration option.
- Automatic sign-in after registration.
- Phone number as username option.
- Automatic, WooCommerce, and WordPress registration systems.
- Configurable registration fields.
- Editable phone field label.
- Configurable post-login redirects.
- OTP resend cooldown and rate limiting.
- Per-code attempt limits.
- Duplicate-phone diagnostics.
- Generic authentication responses to reduce account enumeration.
- Safe registration roles.
- Authentication error diagnostics.
- Modern administration dashboard.
- Setup checklist and recent SMS traffic.
- Fixed OTP verification failures returning **"Something went wrong"** on WooCommerce 9.4+.
- Added compatibility for the changed `wc_create_new_customer()` signature in WooCommerce 9.4+.
- Isolated AJAX handlers from stray output and PHP errors.
