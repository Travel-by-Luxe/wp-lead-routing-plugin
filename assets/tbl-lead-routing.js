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
 * "Thank-you page built by the site"). When a form's budget is below the
 * booking rule (Settings → Booking calendar), the booking button and calendar
 * are left out instead, on any thank-you page and in the popup.
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

	/* ----------------------------------------------------- Booking rule */

	var BOOKING = 'tbl_lr_booking';

	/** The first number in a form value: "12000", "$12,000", "12k", or the low end of "10,000 - 20,000". */
	function numberIn(value) {
		if (Array.isArray(value)) { value = value[0]; }
		if (value === null || value === undefined) { return null; }
		var match = String(value).replace(/,/g, '').match(/(\d+(?:\.\d+)?)\s*(k)?/i);
		if (!match) { return null; }
		var number = parseFloat(match[1]) * (match[2] ? 1000 : 1);
		return isFinite(number) ? number : null;
	}

	/**
	 * Whether the submitted budget earns the booking calendar (Settings → Booking
	 * calendar): at least the minimum in total, and enough for every adult and
	 * child. true / false, or null when the answers aren't there to tell.
	 */
	function bookingVerdict(fieldsJson) {
		var rule = cfg.booking;
		if (!rule || !fieldsJson) { return null; }
		var fields;
		try { fields = JSON.parse(fieldsJson); } catch (e) { return null; }
		if (!fields || typeof fields !== 'object') { return null; }
		var budget = numberIn(fields[rule.budget]);
		var adults = numberIn(fields[rule.adults]);
		if (budget === null || adults === null) { return null; }
		var children = numberIn(fields[rule.children]) || 0;
		var needed = Math.max(rule.minTotal, rule.perAdult * adults + rule.perChild * children);
		return budget >= needed;
	}

	function rememberBooking(verdict) {
		try {
			if (verdict === null) { window.sessionStorage.removeItem(BOOKING); return; }
			window.sessionStorage.setItem(BOOKING, JSON.stringify({ ok: verdict, at: Date.now() }));
		} catch (e) { /* private mode: the calendar shows as before */ }
	}

	function bookingAllowed() {
		try {
			var b = JSON.parse(window.sessionStorage.getItem(BOOKING) || 'null');
			if (b && typeof b.ok === 'boolean' && Date.now() - (b.at || 0) < MAX_AGE) { return b.ok; }
		} catch (e) { /* ignore */ }
		return null;
	}

	/** Below the budget: the booking button and calendar go, before any calendar loads. */
	function removeBooking() {
		if (!cfg.booking || !cfg.booking.hide) { return; }
		var found = [];
		try { found = document.querySelectorAll(cfg.booking.hide); } catch (e) { return; }
		Array.prototype.forEach.call(found, function (el) {
			if (el.parentNode) { el.parentNode.removeChild(el); }
		});
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
	function handle(res, json, verdict) {
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
		// plugin then shows its own inline thank-you, and the calendar opens on top,
		// unless the budget is below the booking rule.
		data.redirect = '';
		hideOverlay();
		var url = themed(r.calendly_url);
		var prefill = prefillOf(r);
		if (verdict !== false) { setTimeout(function () { openPopup(url, prefill); }, 300); }

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
			var verdict = bookingVerdict(param(body, 'fields'));
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
						if (res.ok && json && json.success === true) { rememberBooking(verdict); }
						try { return handle(res, json, verdict); } catch (e) { hideOverlay(); return res; }
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
			// A new element, so the site's own script finds no spot to fill with the team
			// calendar. The site's box only has a min-height, so the calendar gets a holder
			// with a real height inside it, as the site's own script does.
			var box = document.createElement('div');
			box.className = own.className;
			box.setAttribute('data-tbl-lr-box', '');
			box.appendChild(styled('div', 'tbl-calendly', 'position:relative;width:100%;min-width:280px;height:700px'));
			own.parentNode.replaceChild(box, own);
			return;
		}
		var anchor = null;
		try { anchor = document.querySelector(cfg.thanksAfter); } catch (e) { /* not a valid selector */ }
		if (!anchor || !anchor.parentNode) { return; }
		// A div, not a section: sites style their sections as page bands. Styled
		// inline, since a page the site builds may not load this add-on's CSS.
		var section = styled('div', 'tbl-lr-book', 'margin:36px 0 32px;text-align:center');
		var title = styled('h2', 'tbl-lr-book-title', 'margin:0 0 8px;font-size:clamp(22px,4.4vw,26px);font-weight:500;line-height:1.3');
		title.textContent = 'Want to skip ahead? Book a time with ' + (td || 'your Travel Designer') + '.';
		var lead = styled('p', 'tbl-lr-book-lead', 'max-width:480px;margin:0 auto 24px;font-size:15px;line-height:1.6');
		lead.textContent = "If you'd like to talk about your trip sooner, pick a time below.";
		var calendar = styled('div', 'tbl-calendly', 'position:relative;width:100%;min-width:280px;height:700px;overflow:hidden;border-radius:8px;background:#fff');
		section.appendChild(title);
		section.appendChild(lead);
		section.appendChild(calendar);
		// Dress it like the element it follows, so it matches whatever page it lands on.
		copyLook(anchor.querySelector('h1, h2, h3'), title, ['fontFamily', 'fontWeight', 'color', 'letterSpacing']);
		copyLook(anchor.querySelector('p, li'), lead, ['fontFamily', 'color']);
		anchor.parentNode.insertBefore(section, anchor.nextSibling);
	}

	function styled(tag, className, css) {
		var el = document.createElement(tag);
		el.className = className;
		el.style.cssText = css;
		return el;
	}

	function copyLook(from, to, props) {
		if (!from || !window.getComputedStyle) { return; }
		var look = window.getComputedStyle(from);
		props.forEach(function (prop) { to.style[prop] = look[prop]; });
	}

	function thankYou() {
		var qs   = new URLSearchParams(window.location.search);
		var last = stored();
		var fromUrl = qs.get('tbl_cal');
		var url  = isCalendly(fromUrl) ? fromUrl : (last && isCalendly(last.calendly_url) ? last.calendly_url : '');
		var td   = qs.get('tbl_td') || (last && last.td_first_name) || '';
		var prefill = prefillOf(last);
		var names   = document.querySelectorAll('.tbl-travel-designer');
		if (td) {
			Array.prototype.forEach.call(names, function (el) { el.textContent = td; });
		}

		if (bookingAllowed() === false) {
			removeBooking();
			return;
		}

		if (url) { addCalendarSpot(td); }

		var calendars = document.querySelectorAll('.tbl-calendly');
		if (!calendars.length) { return; }

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

	// Calendly reports its content height on every screen change: fit each calendar
	// to it, so there is no scrollbar inside the calendar and no empty space below it.
	window.addEventListener('message', function (e) {
		if (e.origin !== 'https://calendly.com' || !e.data || e.data.event !== 'calendly.page_height') { return; }
		var height = parseInt((e.data.payload || {}).height, 10);
		if (!height || height < 100) { return; } // Calendly's first pings are tiny
		Array.prototype.forEach.call(document.querySelectorAll('.tbl-calendly'), function (el) {
			var frame = el.querySelector('iframe');
			if (!frame || frame.contentWindow !== e.source) { return; }
			el.style.height = height + 'px';
			if (el.parentNode && el.parentNode.hasAttribute && el.parentNode.hasAttribute('data-tbl-lr-box')) {
				el.parentNode.style.minHeight = height + 'px';
			}
		});
	});

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', thankYou);
	} else {
		thankYou();
	}
})();
