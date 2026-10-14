# TBL Lead Routing

A WordPress add-on for the **Terrific Digital** forms plugin (Terrific Forms). It sends Travel by Luxe leads to the [leads app](https://leads.travelbyluxe.com), which picks the travel designer and creates the Zendesk ticket. Then it shows the visitor that travel designer's Calendly.

Terrific Digital is not changed in any way. This add-on only listens to the hooks and requests that plugin already has.

## What the visitor sees

1. They submit a routed form.
2. A **"Please wait"** overlay appears ("Finding your travel designer…") while the server asks the leads app who takes the lead. This usually takes 1-2 seconds.
3. What happens next is chosen **per form**:
   - **Redirect** - the form's own Redirect action runs as usual. The thank-you page shows the travel designer's calendar wherever you put `[tbl_calendly]`.
   - **Popup** - the visitor stays on the page. The form shows its inline thank-you message and the travel designer's Calendly opens in a popup, with name and email already filled in.

If the leads app is slow or down, the visitor gets the **fallback Calendly link** after the "Longest wait" setting (8 seconds by default). Terrific Forms still saves the submission either way, and the leads app retries any ticket it could not create.

## Requirements

- WordPress 6.5+, PHP 7.4+
- Terrific Digital (tested with 2.51.0). The settings screen warns you when a newer version is installed.
- An API key from the leads app (Settings → API keys).

## Install

1. Download `tbl-lead-routing.zip` from the [latest release](https://github.com/Travel-by-Luxe/wp-lead-routing-plugin/releases/latest).
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, upload the ZIP and activate it.

Updates then appear under **Plugins** and **Dashboard → Updates** like any other plugin, straight from this repo's GitHub releases.

## Set up

1. **Terrific Digital → TBL Lead Routing**
   - Paste the leads app API key and save. It stays on the server and is never sent to browsers.
   - Set the **fallback Calendly link** (the booking page to show when no travel designer could be picked).
   - Optionally change the **Calendly colours**. They default to the site's thank-you page, and Calendly's cookie banner is hidden there too.
   - Click **Test connection**.
2. **On each form** (Terrific Forms → edit the form), use the **TBL Lead Routing** box:
   - Tick **Route leads from this form**.
   - Choose **Redirect** or **Popup**.
   - Optionally set a form-specific fallback link and "Please wait" text.
3. **Turn off the form's Webhook action** if it still sends to Make. Otherwise the lead gets a second ticket. The box shows a red warning while a Webhook action is still on.
4. **Redirect forms:** put these on the thank-you page:
   - `[tbl_calendly height="700"]` - the travel designer's calendar
   - `[tbl_travel_designer fallback="our team"]` - their first name, for copy like "Your travel designer, Marta, will…"
   - A thank-you page the site's own code builds (where shortcodes can't go) needs nothing: for a visitor who was just routed, the add-on puts the calendar in the page's `#calendly_widget`, or in a "Book a time with …" section after the element named under **Thank-you page built by the site** (`.ty-next-steps` by default).
5. **Booking calendar rule** (on by default): the booking button and calendar are only offered when the form's budget is at least 8,000 and covers 5,000 per adult plus 3,000 per child (amounts in the form's currency, set under **Booking calendar**). It reads the `budget`, `adults` and `children` fields of whatever form was just sent, routed or not; below it, the thank-you page leaves out the elements listed there (by default the site's "Book a time to talk" button and booking section) and a popup form doesn't open the calendar. A form without those fields, or a visit straight to the thank-you page, changes nothing.
6. **Purge the page cache** (Breeze or similar) after switching a form on or off, so the page loads the add-on's script.

Do this on the staging site first, submit a test lead with an internal email address, and check the leads app's dashboard.

## How it works

```
Browser                      WordPress (this add-on)              Leads app
───────                      ───────────────────────              ─────────
submit ──tf_submit──▶ Terrific Forms saves the submission
"Please wait"                 fires terrific_forms/submission ──▶ POST /api/v1/leads/terrific-forms
                              (waits, up to "Longest wait")  ◀── travel designer + Calendly link
                              adds data.tbl_routing to the reply,
                              tbl_cal/tbl_td to the redirect URL
redirect or popup ◀── reply
```

- Each step of a multi-step form is also forwarded (without waiting), so abandoned forms still reach the leads app.
- The payload is Terrific Forms' own webhook format. All Travel by Luxe field mapping lives in the leads app, not here.
- In redirect mode, the calendar link travels on the redirect URL (`tbl_cal`, `tbl_td`), so the thank-you page still works if the browser script cannot run.
- **Just send it** (`send`) sends the lead the same way but leaves the reply alone: no "Please wait", no calendar, the form's own message or redirect. For forms that are not sales leads, such as the partner form (the leads app leaves partner requests unassigned).

## Admin API

For looking after the add-on and its forms without wp-admin, e.g. from a script with an
[Application Password](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/)
(Users → Profile → Application Passwords). Namespace `tbl-lead-routing/v1`; every route needs a user
who may edit pages (the settings screen's right), and `/update` needs `update_plugins`.
Secrets never come back: the API key only as "set" plus its first characters, webhooks only by host.

| Route | What it does |
|---|---|
| `GET /status` | Version, site, Terrific Digital compatibility, settings, last lead sent, forms routed |
| `POST /settings` | Change settings: any of `base_url`, `api_key`, `timeout`, `fallback_calendly`, `wait_text`, `calendly_background`, `calendly_text`, `calendly_primary` (hex, blank for Calendly's own), `calendly_hide_cookie_banner`, `thanks_after` (CSS selector, blank for off), `booking_rule`, `booking_min_total`, `booking_min_adult`, `booking_min_child`, `booking_fields`, `booking_hide` |
| `POST /test` | The settings screen's "Test connection" |
| `GET /forms` | Every form: routing, its actions (webhook / redirect / dataLayer), the pages it is on, warnings |
| `GET /forms/{id}` | One form, with its fields |
| `POST /forms/{id}/routing` | Change the form's routing: `enabled`, `mode` (`redirect` / `popup` / `send`), `fallback_calendly`, `wait_text` |
| `POST /forms/{id}/actions` | Switch one of the form's actions on or off: `{"type": "webhook", "enabled": false}` (optional `index`). Point the Redirect action elsewhere: `{"type": "redirect", "page_id": 182}` or `{"type": "redirect", "url": "https://…"}` |
| `POST /forms/{id}/duplicate` | Copy a form the way the forms plugin's export/import does (new form ID), routing settings included: `title`, `status` |
| `POST /pages/{id}/duplicate` | Copy a page like Duplicate Page does (content, template, custom fields, terms), swapping its forms: `title`, `slug`, `status`, `forms: {"77": 123}` |
| `GET /snippets?page={id}` | Terrific Digital snippets, where each applies, and whether it applies to that page (code not returned) |
| `POST /snippets/{id}/pages` | Add or remove pages on a snippet limited to specific pages: `add`, `remove` |
| `POST /snippets` | Create a Terrific Digital snippet: `title`, `code`, optional `type` (`html` / `js`), `location`, `condition` (`all` / `specific` / `url_contains` / `url_not_contains`), `pages`, `url_match`, `enabled`. Defaults: HTML, before `</body>`, specific pages, off. Needs the right to post unfiltered HTML. |
| `POST /snippets/{id}` | Change any of those on a snippet, e.g. `{"enabled": false}` to turn it off |
| `GET /log` | The last 50 sends to the leads app, newest first (no names or emails) |
| `POST /cache/clear` | Clear the Breeze page cache and the object cache |
| `POST /update` | Install the newest GitHub release, like "Update now" |

```bash
curl -u "user:app password" https://example.com/wp-json/tbl-lead-routing/v1/forms
```

## Releasing

1. Bump the version in **both** places in `tbl-lead-routing.php`: the `Version:` header and `TBL_LR_VERSION`.
2. Commit and push to `main`.
3. On GitHub, open **Actions → Release → Run workflow**, enter the version (e.g. `1.0.1`) and run it. Pushing a `v1.0.1` tag does the same.

The **Release** workflow checks that the version matches the plugin file, builds `tbl-lead-routing.zip`, and publishes the GitHub release with its `vX.Y.Z` tag. Sites then see the update within about 12 hours, or right away with **Check for updates** on the Plugins screen.

## License

GPL-2.0-or-later. Bundles [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (MIT).
