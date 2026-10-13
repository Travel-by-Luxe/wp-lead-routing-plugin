/* TBL Lead Routing - the visitor's side.
 *
 * On a page with a routed Terrific Form: shows "Please wait" while the forms
 * plugin's tf_submit request is running (the server is asking the leads app
 * which travel designer takes the lead), then either lets the form's own
 * thank-you redirect happen (redirect mode) or stays on the page and opens the
 * travel designer's Calendly in a popup (popup mode).
 *
 * The forms plugin is not changed: its submit goes through window.fetch, so
 * this wraps fetch, recognises that one request, and reads `data.tbl_routing`
 * that the add-on's PHP side added to the reply.
 *
 * On the thank-you page: fills [tbl_calendly] and [tbl_travel_designer] from
 * the `tbl_cal` / `tbl_td` URL parameters, or from this tab's last routing. A
 * thank-you page the site builds itself gets the calendar added (Settings →
 * "Thank-you page built by the site").
 */
(function () {
	'use strict';
	if (typeof window === 'undefined' || !window.document) { return; }

	var cfg     = window.TBL_LR || {};
	var forms   = cfg.forms || {};
	var STORE   = 'tbl_lr_routing';
	var MAX_AGE = 60 * 60 * 1000; // a stored routing is good for an hour
	var CAL_JS  = 'https://assets.calendly.com/assets/external/widget.js';
	var CAL_CSS = 'https://assets.calendly.com/assets/external/widget.css';

	/* ------------------------------------------------------------ Helpers */

	function isCalendly(url) {
		if (!url || typeof url !== 'string') { return false; }
		try {
			var u = new URL(url);
			return u.protocol === 'https:' && /(^|\.)calendly\.com$/.test(u.hostname);
		} catch (e) { return false; }
	}

	function prefillOf(r) {
		var out = {};
		var p = (r && r.prefill) || {};
		if (p.name && typeof p.name === 'string') { out.name = p.name; }
		if (p.email && typeof p.email === 'string') { out.email = p.email; }
		return out;
	}

	/** Calendly reads name/email from its URL too: used when its widget cannot load. */
	function withPrefill(url, prefill) {
		try {
			var u = new URL(url);
			if (prefill.name && !u.searchParams.has('name')) { u.searchParams.set('name', prefill.name); }
			if (prefill.email && !u.searchParams.has('email')) { u.searchParams.set('email', prefill.email); }
			return u.toString();
		} catch (e) { return url; }
	}

	/** The site's Calendly colours (Settings); a link's own parameters win. */
	function themed(url) {
		var theme = cfg.theme || {};
		try {
			var u = new URL(url);
			Object.keys(theme).forEach(function (key) {
				if (theme[key] && !u.searchParams.has(key)) { u.searchParams.set(key, theme[key]); }
			});
			return u.toString();
		} catch (e) { return url; }
	}

	function save(r) {
		try {
			window.sessionStorage.setItem(STORE, JSON.stringify({
				calendly_url:  r.calendly_url,
				td_first_name: r.td_first_name || '',
				prefill:       prefillOf(r),
				at:            Date.now()
			}));
		} catch (e) { /* private mode or blocked storage: the URL parameters still work */ }
	}

	function stored() {
		try {
			var r = JSON.parse(window.sessionStorage.getItem(STORE) || 'null');
			if (r && typeof r === 'object' && Date.now() - (r.at || 0) < MAX_AGE) { return r; }
		} catch (e) { /* ignore */ }
		return null;
	}

	/** Loads Calendly's widget once, on demand. Rejects after 8 s. */
	var calendly = null;
	function loadCalendly() {
		if (window.Calendly) { return Promise.resolve(window.Calendly); }
		if (calendly) { return calendly; }
		calendly = new Promise(function (resolve, reject) {
			if (!document.querySelector('link[href="' + CAL_CSS + '"]')) {
				var link = document.createElement('link');
				link.rel = 'stylesheet';
				link.href = CAL_CSS;
				document.head.appendChild(link);
			}
			if (!document.querySelector('script[src="' + CAL_JS + '"]')) {
				var script = document.createElement('script');
				script.src = CAL_JS;
				script.async = true;
				document.head.appendChild(script);
			}
			var waited = 0;
			(function check() {
				if (window.Calendly) { resolve(window.Calendly); return; }
				if ((waited += 100) > 8000) { reject(new Error('Calendly did not load')); return; }
				setTimeout(check, 100);
			})();
		});
		calendly.catch(function () { calendly = null; });
		return calendly;
	}

	/* ------------------------------------------------------- "Please wait" */

	var overlay = null;
	var safety  = null;

	function showOverlay(text, ms) {
		if (!overlay) {
			overlay = document.createElement('div');
			overlay.className = 'tbl-lr-overlay';
			overlay.setAttribute('role', 'status');
			overlay.setAttribute('aria-live', 'polite');
			overlay.innerHTML = '<div class="tbl-lr-box"><span class="tbl-lr-spinner" aria-hidden="true"></span><p class="tbl-lr-text"></p></div>';
			document.body.appendChild(overlay);
		}
		overlay.querySelector('.tbl-lr-text').textContent = text;
		// Force a style flush so the fade-in runs on a freshly added overlay.
		void overlay.offsetWidth;
		overlay.classList.add('is-visible');
		keepFor(ms);
	}

	/** Never leave a visitor stuck behind the overlay. */
	function keepFor(ms) {
		clearTimeout(safety);
		safety = setTimeout(hideOverlay, ms);
	}

	function hideOverlay() {
		clearTimeout(safety);
		if (overlay) { overlay.classList.remove('is-visible'); }
	}

	// Back button from the thank-you page restores this page as it was left.
	window.addEventListener('pageshow', function (e) { if (e.persisted) { hideOverlay(); } });

	/* ------------------------------------------------------ The submission */

	function param(body, key) {
		try {
			if (body && typeof body.get === 'function') { return body.get(key); }
			if (typeof body === 'string') { return new URLSearchParams(body).get(key); }
		} catch (e) { /* not a form body */ }
		return null;
	}

	function openPopup(url, prefill) {
		loadCalendly().then(function (Calendly) {
			Calendly.initPopupWidget({ url: url, prefill: prefill });
		}, function () {
			window.location.href = withPrefill(url, prefill);
		});
	}

	/**
	 * Reads the routing from the forms plugin's reply and decides what the
	 * visitor sees next. Returns the Response the forms plugin should get.
	 */
	function handle(res, json) {
		var data = json && json.success === true && json.data && typeof json.data === 'object' ? json.data : null;
		var r    = data && data.tbl_routing && typeof data.tbl_routing === 'object' ? data.tbl_routing : null;
		if (!res.ok || !r || !isCalendly(r.calendly_url)) {
			// Nothing to show: the form behaves exactly as it does without the add-on.
			hideOverlay();
			return res;
		}
		save(r);

		if (r.mode !== 'popup' && data.redirect) {
			// Redirect mode: the calendar is already on the thank-you URL, so
			// keep "Please wait" up until the browser leaves this page.
			keepFor(8000);
			return res;
		}

		// Popup mode (or a form without a Redirect action): stay here. The forms
		// plugin then shows its own inline thank-you, and the calendar opens on top.
		data.redirect = '';
		hideOverlay();
		var url = themed(r.calendly_url);
		var prefill = prefillOf(r);
		setTimeout(function () { openPopup(url, prefill); }, 300);

		var headers = new Headers(res.headers);
		headers.delete('content-length');
		headers.delete('content-encoding');
		return new Response(JSON.stringify(json), { status: res.status, statusText: res.statusText, headers: headers });
	}

	if (typeof window.fetch === 'function' && typeof window.Response === 'function') {
		var nativeFetch = window.fetch;
		window.fetch = function (input, init) {
			var body = init && init.body;
			if (param(body, 'action') !== 'tf_submit') {
				return nativeFetch.apply(window, arguments);
			}
			var id   = param(body, 'post_id') || '';
			var form = Object.prototype.hasOwnProperty.call(forms, id) ? forms[id] : null;
			if (form) {
				// The server waits up to 20 s for the leads app; 30 s covers the rest.
				showOverlay(form.wait_text || cfg.waitText || 'Please wait…', 30000);
				if (form.mode === 'popup') { loadCalendly().catch(function () {}); }
			}
			// Every tf_submit reply is read, not only the forms listed above: a
			// cached page may predate a form being switched on.
			return nativeFetch.apply(window, arguments).then(function (res) {
				return res.clone().json().then(
					function (json) {
						try { return handle(res, json); } catch (e) { hideOverlay(); return res; }
					},
					function () { hideOverlay(); return res; }
				);
			}, function (err) {
				hideOverlay();
				throw err;
			});
		};
	}

	/* ---------------------------------------------------- Thank-you page */

	/**
	 * A thank-you page the site builds itself has no [tbl_calendly] on it. For a
	 * visitor who was just routed, the calendar goes in the site's own Calendly
	 * spot (#calendly_widget) or, failing that, in a section after the element
	 * Settings name. Everyone else sees the page exactly as before.
	 */
	function addCalendarSpot(td) {
		if (!cfg.thanksAfter || document.querySelector('.tbl-calendly')) { return; }
		var own = document.getElementById('calendly_widget');
		if (own) {
			// A new element, so the site's own script finds no spot to fill with the team calendar.
			var spot = document.createElement('div');
			spot.className = (own.className ? own.className + ' ' : '') + 'tbl-calendly';
			own.parentNode.replaceChild(spot, own);
			return;
		}
		var anchor = null;
		try { anchor = document.querySelector(cfg.thanksAfter); } catch (e) { /* not a valid selector */ }
		if (!anchor || !anchor.parentNode) { return; }
		var section = document.createElement('section');
		section.className = 'tbl-lr-book';
		var title = document.createElement('h2');
		title.className = 'tbl-lr-book-title';
		title.textContent = 'Want to skip ahead? Book a time with ' + (td || 'your Travel Designer') + '.';
		var lead = document.createElement('p');
		lead.className = 'tbl-lr-book-lead';
		lead.textContent = "If you'd like to talk about your trip sooner, pick a time below.";
		var calendar = document.createElement('div');
		calendar.className = 'tbl-calendly';
		calendar.style.height = '700px';
		section.appendChild(title);
		section.appendChild(lead);
		section.appendChild(calendar);
		anchor.parentNode.insertBefore(section, anchor.nextSibling);
	}

	function thankYou() {
		var qs   = new URLSearchParams(window.location.search);
		var last = stored();
		var fromUrl = qs.get('tbl_cal');
		var url  = isCalendly(fromUrl) ? fromUrl : (last && isCalendly(last.calendly_url) ? last.calendly_url : '');
		var td   = qs.get('tbl_td') || (last && last.td_first_name) || '';
		var prefill = prefillOf(last);

		if (url) { addCalendarSpot(td); }

		var calendars = document.querySelectorAll('.tbl-calendly');
		var names     = document.querySelectorAll('.tbl-travel-designer');
		if (!calendars.length && !names.length) { return; }

		if (td) {
			Array.prototype.forEach.call(names, function (el) { el.textContent = td; });
		}

		Array.prototype.forEach.call(calendars, function (el) {
			var target = url || el.getAttribute('data-fallback') || cfg.fallback || '';
			if (!isCalendly(target)) { el.style.display = 'none'; return; }
			target = themed(target);
			loadCalendly().then(function (Calendly) {
				Calendly.initInlineWidget({ url: target, parentElement: el, prefill: prefill });
			}, function () {
				var a = document.createElement('a');
				a.className = 'tbl-calendly-link';
				a.href = withPrefill(target, prefill);
				a.textContent = 'Book your call';
				el.style.height = 'auto';
				el.appendChild(a);
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', thankYou);
	} else {
		thankYou();
	}
})();
