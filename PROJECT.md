# TBL Lead Routing - developer notes

Read this before changing the add-on. The user-facing guide is README.md. This file is left out of the release ZIP.

## Ground rules

- **Never change Terrific Digital.** It is a generic plugin shared by many sites. Everything here hooks into what it already does. If something can't be done that way, find another way, or talk to the plugin's owner first.
- **Never break a form.** If anything here fails (leads app down, unexpected reply, script error), the form must behave exactly as it does without the add-on. Every code path that touches the forms plugin's reply passes it through untouched when unsure.
- **The leads app owns the mapping.** This add-on sends Terrific Forms' own webhook payload, and the leads app translates it (`src/lib/terrific-forms.ts` in Travel-by-Luxe/leads). Don't add Travel by Luxe field logic here.
- **The leads app does not control where visitors go.** The form's own Redirect action decides the thank-you page. The leads app only answers *who* takes the lead and *which calendar* to show.

## Files

| File | What it does |
| --- | --- |
| `tbl-lead-routing.php` | Header, constants, update checker, bootstraps the classes on `plugins_loaded` (priority 20, after Terrific Digital). |
| `includes/class-tbl-lr-settings.php` | Terrific Digital → TBL Lead Routing (under Settings if Terrific Digital is inactive). Option `tbl_lr_settings`. Capability `edit_pages`, matching Terrific Digital. |
| `includes/class-tbl-lr-forms.php` | The per-form box on the `terrific_form` edit screen. Post meta `_tbl_lr`. |
| `includes/class-tbl-lr-client.php` | HTTP to the leads app: rebuilds the webhook payload, sends steps (non-blocking) and the final submission (blocking). |
| `includes/class-tbl-lr-submit.php` | The submit hooks and the output buffer that adds `data.tbl_routing` to the reply. |
| `includes/class-tbl-lr-frontend.php` | Enqueues the script, and the `[tbl_calendly]` / `[tbl_travel_designer]` shortcodes. |
| `includes/class-tbl-lr-rest.php` | The admin REST API (`tbl-lead-routing/v1`): status, settings, forms and their actions, send log (option `tbl_lr_log`), cache, self-update. See README → Admin API. |
| `assets/tbl-lead-routing.js` | Overlay, popup, thank-you calendar. Plain ES5-style JS, no build step. |
| `vendor/plugin-update-checker` | YahnisElsts/plugin-update-checker v5, same copy as Terrific Digital. Several copies coexist safely. |

## What we depend on in Terrific Digital

Re-check these whenever Terrific Digital ships a new version, then bump `TBL_LR_TESTED_TD_VERSION`.

Server side (`includes/class-tf-submissions.php`):

- `do_action( 'terrific_forms/submission', $fields, $def, $post_id, $sub_id, $ctx )` fires during `tf_submit`, **before** the reply is sent.
- `do_action( 'terrific_forms/step', $fields, $def, $post_id, $sub_id, $ctx, $step )` fires during `tf_step`.
- The reply is `wp_send_json_success( array( 'submission_id', 'message', 'redirect' ) )`. We buffer output in the submission hook and rewrite that JSON in the buffer callback.
- `TF_Submissions::page_url()` is public (used for `submission_link`).
- `TF_CPT`, `tf_get_actions()` and `tf_resolve_webhook_url()` exist.

Browser side (`assets/form.js`):

- The final submit is `fetch( TF_FORM.ajax, { method: 'POST', body: URLSearchParams } )` with `action=tf_submit` and `post_id`. It calls the global `fetch` at submit time, so our wrapper is picked up whatever the script order.
- On `success === true` it redirects to `data.redirect` (after 250 ms) when set, otherwise it shows `.tf-thankyou`. Popup mode clears `data.redirect` to keep the visitor on the page.

If any of these change, the add-on degrades to "the form behaves as before": the lead still reaches the leads app as long as the PHP hooks fire.

## The reply rewrite

`TBL_LR_Submit::on_submission()` calls the leads app, stores the routing, then `ob_start()`s a callback. Terrific Forms then echoes its JSON and `wp_die()`s, which flushes the buffer through `rewrite_reply()`:

- not JSON, not `success`, or no `data` → returned as is;
- `data.redirect` set → `tbl_cal` (and `tbl_td`) appended;
- `data.tbl_routing` added: `mode`, `calendly_url`, `fallback`, `td_name`, `td_first_name`, `prefill {name, email}`, `lead_id`.

Only `calendly.com` https links are ever passed to visitors (`TBL_LR_Settings::calendly_url()` on the server, `isCalendly()` in the browser).

## Testing locally

There is no automated test suite. Test end to end:

1. A local WordPress (the SQLite integration plugin and `php -S` are enough) with Terrific Digital and this add-on.
2. The leads app running locally with an API key. Leave Zendesk unconfigured locally, or use internal test emails only. **Never create test tickets in production Zendesk.**
3. A routed form in each mode, plus a multi-step form. Check the overlay, the popup with prefill, the thank-you calendar, and the fallback when the leads app is stopped.

Run `php -l` on every PHP file before tagging. The release workflow does too.
