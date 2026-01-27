# TrafficSourceCapture

First-touch traffic source capture (UTM / referrer / search engines / AI chats) with **zero external services**.
Useful when you want to quickly save traffic source context and attach it to a lead (CRM/email/Telegram/DB).

## What it does

On the first visit, it detects the channel and details, stores them in `$_SESSION` (optionally in a cookie as a backup),
and later lets you retrieve a human-readable string or structured data for a lead.

Channels:
- `ai` — AI chats (strict UTM rules: `utm_source` from a whitelist + `utm_medium=ai_chat`, optional referrer fallback)
- `paid` — ads (any UTM / `gclid` / `yclid` / `fbclid`)
- `organic` — search (detects search engine + tries to extract the query)
- `referral` — website referral (external referrer)
- `direct` — direct visit (no referrer / internal referrer)

## Installation

```bash
composer require da41b94c/traffic-source-capture
```

## Quick start

Call once early in the request (before form handling):

```php
use Da41b94c\TrafficSource\TrafficSourceCapture;

TrafficSourceCapture::Capture();
```

When saving a lead:

```php
$TrafficText = TrafficSourceCapture::GetHuman();     // for notifications/CRM
$TrafficData = TrafficSourceCapture::GetLeadData();  // for DB/CRM
```

## AI chat UTM example

To be classified as `ai`, UTM must match the strict whitelist logic:

- `utm_source=chatgpt`
- `utm_medium=ai_chat`

Example:

`https://example.com/landing?utm_source=chatgpt&utm_medium=ai_chat&utm_campaign=leadgen`

If UTM does not pass the strict AI check, it will be classified as `paid`.

## Cookie backup and TTL

`Capture()` supports cookie backup (enabled by default) and optional TTL.

```php
// useCookieBackup=true, ttlSeconds=0 (default)
TrafficSourceCapture::Capture(null, null, true, 0);

// Example: allow overwriting first-touch after 30 days
TrafficSourceCapture::Capture(null, null, true, 2592000);
```

## What GetLeadData() returns

`GetLeadData()` returns:

- `traffic_channel` — `ai|paid|organic|referral|direct`
- `traffic_source` — a short source (e.g., utm_source or search engine name)
- `traffic_details` — human-readable string (`GetHuman()`)
- `traffic_raw` — JSON with all stored data

## Security notes

Traffic source data is **untrusted**:
- Anyone can spoof UTM parameters.
- `HTTP_REFERER` may be missing or stripped.
- Do not use this as a security mechanism.

Recommendations:
- When displaying values in an admin UI, output as plain text or escape (`htmlspecialchars`).
- Do not make access-control decisions based on traffic source.

## Requirements

- PHP `>= 7.0`
- (optional) `ext-intl` for `idn_to_ascii()` when normalizing IDN domains

## License

MIT
