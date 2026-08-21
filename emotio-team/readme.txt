=== Emotio Team ===
Contributors: emotiodesigngroup
Tags: team, staff, team members, salient, slider, grid, people
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
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

**Placing the team on pages**

* `[emotio_team]` shortcode, a live-preview **Gutenberg block**, and a **WPBakery element** (the builder Salient ships with) — all rendered by the same engine, so they always match.
* **Grid, slider or list** deployment. The slider is dependency-free, touch-native (CSS scroll-snap), keyboard accessible, with arrows, dots and optional autoplay.
* Filter any placement by **department, skill/tag, specific IDs or Featured** members.
* Optional front-end **live search** and **department filter chips** on any placement.
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

== Changelog ==

= 1.0.0 =
* Initial release.
