# Emotio License Client — integration guide

`class-emotio-license-client.php` is a single-file, product-agnostic version of
the licensing client that ships inside Emotio Team. Drop it into any Emotio
product to give it the same License screen, activation flow, daily
re-validation and 14-day offline grace period — all validated against the
Emotio License Server (`/wp-json/emotio/v1/license`).

## Wiring it into AI Schema Pro

1. Copy `class-emotio-license-client.php` into the AI Schema Pro plugin
   (e.g. `includes/`).

2. In the main plugin file:

```php
require_once __DIR__ . '/includes/class-emotio-license-client.php';

$aisp_license = new Emotio_License_Client( array(
    'product'     => 'ai-schema-pro',          // already registered on the server
    'label'       => 'AI Schema Pro',
    'option'      => 'aisp_license',           // where state is stored
    'menu_parent' => 'options-general.php',    // or AI Schema Pro's own menu slug
    'version'     => AISP_VERSION,
) );

register_deactivation_hook( __FILE__, array( $aisp_license, 'unschedule' ) );
```

3. Gate premium behaviour anywhere in AI Schema Pro:

```php
if ( apply_filters( 'emotio_is_licensed_ai-schema-pro', false ) ) {
    // premium feature
}
```

4. Agency deployments can pre-seed the key in `wp-config.php`:

```php
define( 'EMOTIO_LICENSE_KEY_AI_SCHEMA_PRO', 'EMO-XXXX-XXXX-XXXX-XXXX' );
```

Point at a staging server with the `emotio_license_api_url` filter or the
`api_url` config key.

## Server side

`ai-schema-pro` is a built-in product on the Emotio License Server — just
create licenses under **Licenses → Add License** and pick *AI Schema Pro* as
the product. The same customer can hold separate keys per product, each with
its own seat count and expiry.

## API contract (for any other client)

`POST /wp-json/emotio/v1/license` with form fields
`action` (`activate` | `deactivate` | `check`), `key`, `site`, `product`,
`version`. JSON response:

```json
{ "success": true, "status": "valid", "expires": "2027-08-21",
  "customer": "Acme Ltd", "message": "" }
```

`status` is one of `valid`, `expired`, `invalid`, `deactivated`. A `check`
self-heals a missing activation when a seat is free; seat-limit and disabled
licenses come back as `invalid` with a human-readable `message`.
