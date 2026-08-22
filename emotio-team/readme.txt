=== Emotio Team ===
Contributors: emotiodesigngroup
Tags: team, staff, team members, salient, slider, grid, people
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.5.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Best-in-class team member management for Salient-built WordPress sites. Grid & slider layouts, live search & filtering, WPBakery + Gutenberg, profile modals, vCards and Person schema.

== Description ==

Emotio Team gives a Salient (or any WordPress) site a complete, beautifully designed team section:

**Managing the team**

* A dedicated **Team** post type with profile photo, biography, short intro, job title, email, phone, location, pronouns, fun fact and ten social networks.
* **Departments** (hierarchical) and **Skills / Tags** taxonomies.
* A portfolio-style admin list with photo thumbnails, job title, email and department columns.
* Admin **search that also matches job title, email, phone and location** — not just names.
* **Drag-and-drop reordering** straight in the list table (the custom order drives every layout).
* One-click **Duplicate** row action, a Featured flag, and a second **hover photo** per member.
* **Custom profile fields** — define extra fields once (qualifications, languages, office days…) and every member gets them, shown on profiles and modals.
* **CSV import/export** — bulk-onboard a whole team from a spreadsheet (photos fetched from URLs), or export everything for backup and bulk edits.

**Placing the team on pages**

* `[emotio_team]` shortcode, a live-preview **Gutenberg block**, a **WPBakery element** (the builder Salient ships with) and a native **Elementor widget** — all rendered by the same engine, so they always match.
* **Spotlight layout** (featured members large, the rest in a grid) and **group-by-department sections** with per-department headings.
* **Grid, slider or list** deployment. The slider is dependency-free, touch-native (CSS scroll-snap), keyboard accessible, with arrows, dots and optional autoplay.
* Filter any placement by **department, skill/tag, specific IDs or Featured** members — with **AND/OR logic** for multiple departments.
* A visual **Shortcode Generator** and **saved displays**: build a configuration once, place `[emotio_team_display id="3"]` on any number of pages, edit centrally forever.
* **Per-field card control** (department, email, phone, location each toggleable), **per-device columns** (desktop/tablet/mobile) and a per-member **Custom URL** click action.
* Optional front-end **live search** and **department filter chips** on any placement — plus standalone **Team Search Bar** and **Department Filter** elements that control any team layout on the page.
* A dedicated **"Emotio" category** in Salient's builder, with a **Team Member Card** element for single profiles.
* **Profile triggers on any element**: Salient Buttons/Images/Icons get a "Team Profile" tab, and any element with the class `etm-profile-ID` / `etm-panel-ID` (or a `#etm-profile-ID` link) opens that member's modal or panel — even on pages with no team layout.
* Built-in searchable **/team/ archive page** and individual profile pages (both optional).

**Design flexibility**

* Four card styles — Cards, Minimal, Image overlay, Circle portrait.
* Five hover effects — Lift, Zoom, Swap to hover photo, Grayscale-to-colour, None.
* Photo ratio, columns (1–6), gap, corner radius, accent and card colours — all site-wide defaults with **per-placement overrides**.
* Everything hangs off CSS custom properties (`--etm-accent`, `--etm-radius`, `--etm-gap`, `--etm-card-bg`) and typography inherits from the theme, so it looks native in Salient out of the box.
* Custom CSS box, extra-class support, and theme template overrides (`{theme}/emotio-team/…`).

**Best-in-class extras**

* **Profile modals** with full bio, contact buttons and socials — or link to full profile pages.
* **vCard download** ("Save contact") for every member.
* **schema.org Person / ItemList JSON-LD** for SEO.
* Scroll-reveal animation with stagger, honouring `prefers-reduced-motion`.
* Lazy-loaded, responsive images; assets only load on pages that use the layouts.
* Fully translation-ready and keyboard/screen-reader accessible.

**Emotio license module**

* Full client for the Emotio License Manager running on emotio-design-group.co.uk (`emotio-license/v1` API): activate `EMOTIO-XXXXX-…` keys, start a 14-day free trial, per-site deactivation, site-limit display and daily re-validation.
* Responses are Ed25519-signature-verified against the license server's public key when libsodium is available.
* Offline resilience: the server's signed grace window keeps the site licensed through outages — the plugin never stops working on a client site because of a licensing hiccup.
* Define `ETM_LICENSE_KEY` in `wp-config.php` for agency deployments (locks the field and auto-activates); point at a staging server with the `ETM_LICENSE_API` constant or `etm_license_api_base` filter; gate premium behaviour on the `etm_is_licensed` filter.
* Server-side setup: add "Emotio Team | emotio-team" to the products list in the Emotio License Manager on emotio-design-group.co.uk.

== Installation ==

1. Upload the `emotio-team` folder to `/wp-content/plugins/`, or install the zip via Plugins → Add New → Upload.
2. Activate the plugin.
3. Add members under **Team → Add Member**, then place `[emotio_team]` (or the Team Members block / WPBakery element) on any page.
4. Tune defaults under **Team → Settings** — set the accent colour to your Salient accent for a seamless match.

== Frequently Asked Questions ==

= How do I show only one department? =

`[emotio_team department="design"]` — or pick the department in the block/WPBakery element. Comma-separate slugs for several.

= How do I get a slider? =

`[emotio_team layout="slider" columns="4" autoplay="yes"]`.

= Can I turn off the individual profile pages? =

Yes — Team → Settings → untick "Individual profile pages". Cards then open the modal instead.

= Can my theme override the templates? =

Copy `templates/single-team_member.php` or `templates/archive-team_member.php` into a `emotio-team/` folder inside your theme.

= What happens if my license expires? =

The plugin keeps working. An expired or missing license only surfaces a notice on the Team admin screens and switches the `etm_is_licensed` filter to false (updates and support are tied to an active license).

== Changelog ==

= 1.5.3 =
* Fixed: profile biography reliability. The bio no longer executes theme/builder shortcodes (which could stall or break the profile endpoint on some setups) — shortcode tags are stripped while their inner text is kept, so builder-authored bios render as clean paragraphs every time.
* Hardened: the profile endpoint catches any rendering error and returns a proper failure (the front end then falls back to the inline card data), and the browser console now logs a warning whenever the fallback path is used, making future diagnosis instant.


= 1.5.2 =
* Fixed: invisible white-on-white text. Sites with a white/near-white accent colour rendered job titles and the profile modal's contact buttons as white text on white — which also looked like an "empty" slide-out. Buttons and active filter chips now compute a readable text colour from the accent (--etm-on-accent), and when both the accent and card background are near-white, job titles automatically fall back to a dark neutral instead of vanishing. An explicit title colour always wins.


= 1.5.1 =
* New: choose exactly what the profile modal / slide-out shows — Team → Settings → "Profile modal / slide-out content" has checkboxes for photo, pronouns, location, biography, fun fact, custom fields, contact buttons, vCard, full-profile button and social icons (socials always render last, at the bottom). Filterable per member via etm_modal_sections.
* Fixed: profiles now load fresh from the server when opened (the inline template becomes a fallback), so optimisers that mangle hidden templates can no longer truncate the slide-out after the location line.
* Fixed: builder-authored biographies (WPBakery/Salient shortcodes in the member's content) now render as real text in the profile instead of disappearing.
* Fixed: job-title colour — colour values like "black" or "111111" (no #) are now accepted in settings and shortcodes, and the title colour is respected in the modal, slide-out, profile page and overlay card style (which previously hard-coded white).
* Improved: list layout redesigned — photo fills the card's full height on the left with details vertically centred beside it, fixing the floating-name layout.


= 1.5.0 =
* New: Shortcode Generator under Team → Shortcode Generator — build any team display visually, copy the shortcode, or save it as a reusable display.
* New: saved displays — [emotio_team_display id="3"] references a named configuration; edit it once and every page using it updates. Also available as a "Saved Team Display" element in the builder.
* New: AND/OR department logic — relation="AND" shows only members in ALL listed departments (e.g. Architecture AND Warsaw Office).
* New: per-field card visibility — show_department, show_email, show_phone, show_location put those details directly on cards (independently toggleable everywhere).
* New: Custom URL click action (link="custom") with a per-member "Custom profile URL" field — cards link out to personal sites, opening in a new tab.
* New: per-device columns — columns_tablet and columns_mobile override the automatic responsive collapse.
* New: sort by job title or ID on the front end (orderby="job_title|id").
* New: Mobile number field (vCards export it as CELL; shows in profiles and CSV), department dropdown filter above the Team admin list, and profile_url/mobile columns in CSV import/export.

= 1.4.0 =
* New: dedicated "Emotio" category in Salient's page builder with three extra elements — Team Member Card (one person's card anywhere), Team Search Bar and Team Department Filter (standalone controls that drive any team layout on the page, targetable via CSS selector).
* New: Salient's own Button, Single Image, Icon and CTA elements gain a "Team Profile" tab — pick a member and clicking that element opens their profile modal or slide-out panel.
* New: universal profile triggers on ANY element — add the class etm-profile-ID (modal) or etm-panel-ID (panel), or link to #etm-profile-ID / #etm-panel-ID (member IDs shown in the Team admin list; slugs work too). Profiles are fetched on demand, so triggers work on pages with no team layout at all. Toggle site-wide loading under Team → Settings.

= 1.3.0 =
* New: native Elementor widget (Elementor 3.5+) with the full control set — layout, slider style, grouping, design, typography — rendered by the same engine as the shortcode, block and WPBakery element.
* New: automatic plugin updates, gated on an active license/trial. Releases are read from a JSON manifest at emotio-design-group.co.uk/updates/emotio-team.json (override with ETM_UPDATE_MANIFEST or the etm_update_manifest_url filter); licensed sites see the update on the Plugins screen like any other, complete with a View-details changelog.
* New: Spotlight layout (layout="spotlight") — Featured members render as large horizontal cards with a longer intro, everyone else follows in the normal grid.
* New: group by department (group_by="department") — grid/list layouts render one titled section per department; live search hides sections with no matches, and members in several departments appear in each.

= 1.2.0 =
* New: CSV import/export under Team → Import / Export — export every member (all fields, socials, departments, tags, custom fields, photo URLs) for backup or spreadsheet editing; import upserts rows (matched by id, then email, then name), creates departments/tags on the fly, and fetches photos from photo_url / hover_photo_url into the media library. Blank template download included.
* New: custom profile fields — define any extra fields once under Team → Settings (Qualifications, Languages, Office days…); they appear on every member's edit screen, display as a tidy details list in profile modals, panels and profile pages (URLs and emails auto-link), and round-trip through CSV as cf_* columns. Unknown cf_* columns in an import register themselves automatically.

= 1.1.1 =
* Fixed: profile photo now always appears in the modal — lazy-load plugins (including Salient's lazy loading) were rewriting the image inside the hidden template so the cloned copy had no src; the profile now uses a plain image tag, de-lazies any rewritten attributes on open, and falls back to the card's photo if anything else strips it.
* Fixed: if an optimiser removes the hidden profile template entirely, the modal/panel now rebuilds the profile (photo, name, role, snippet, socials) from the visible card instead of opening empty.
* Changed: the slide-out panel photo now fills the top of the panel edge-to-edge.

= 1.1.0 =
* New: slide-out profile panel (link="panel") — a drawer that slides in from the right, as an alternative to the centred modal.
* New: drag/swipe momentum slider (slider_style="drag", now the default) matching the Emotio Area Pro track — mouse drag, touch swipe, trackpad and arrow keys; slider_style="paged" keeps the arrows-and-dots version.
* New: per-element typography and colour controls — font size and colour for Name, Job title, Snippet and Social icons, site-wide in Settings and per placement (name_size, name_color, title_size, title_color, bio_size, bio_color, social_size, social_color).
* New: element spacing control — tight / normal / spaced presets or an exact pixel value (spacing attribute), controlling the gaps between name, title, snippet and socials.
* Fixed: modal photos now reduce to fit their area, centred, never cropped.
* License module now targets the Emotio License Manager on emotio-design-group.co.uk, with Ed25519-verified responses and free trials.

= 1.0.0 =
* Initial release.
