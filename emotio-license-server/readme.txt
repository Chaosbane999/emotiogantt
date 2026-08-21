=== Emotio License Server ===
Contributors: emotiodesigngroup
Tags: license, licensing, api
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Central licensing for Emotio products — Emotio Team, AI Schema Pro, and anything you add next.

== Description ==

Install this on the site that answers at `licensing.emotio.co.uk` (or filter the client endpoints to wherever you host it). It provides:

* A **Licenses** admin area: create keys (auto-generated `EMO-XXXX-XXXX-XXXX-XXXX` format or your own), assign a product, customer name/email, seat count (0 = unlimited), expiry date (empty = lifetime) and active/disabled status.
* **Per-site activation tracking** with activated/last-checked timestamps and one-click seat revocation.
* Admin **search by key, customer name or email**.
* A **REST API** at `POST /wp-json/emotio/v1/license` handling `activate`, `deactivate` and `check` — the exact contract the Emotio Team plugin and the drop-in `Emotio_License_Client` speak. `check` self-heals missing activations when a seat is free; responses degrade gracefully (`valid` / `expired` / `invalid` + human-readable message). Basic per-IP rate limiting included.
* A **product registry**: `emotio-team` and `ai-schema-pro` are built in; register more under Licenses → Products or via the `els_products` filter.
* `client/class-emotio-license-client.php` — a single-file client to drop into AI Schema Pro or any future Emotio product (see `client/README.md`).

== Installation ==

1. Install and activate on your licensing site.
2. Create licenses under **Licenses → Add License** (title = customer name).
3. Send the key to the customer; client plugins activate against this server automatically.

== Changelog ==

= 1.0.0 =
* Initial release: license CPT, activations, seats, expiry, REST API, product registry, drop-in client.
