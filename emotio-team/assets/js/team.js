/**
 * Emotio Team — front-end behaviour.
 * Live search, department filter chips, scroll-snap slider (arrows, dots,
 * optional autoplay), accessible profile modal, and scroll-reveal animation.
 * No dependencies.
 */
(function () {
	'use strict';

	var i18n = window.etmI18n || {};
	var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var modal = null;
	var lastTrigger = null;

	/* ------------------------------------------- ?etm_debug=1 overlay */

	var debugBox = null;

	function debugLog(message) {
		if (window.location.search.indexOf('etm_debug') === -1) {
			return;
		}
		if (!debugBox) {
			debugBox = document.createElement('div');
			debugBox.style.cssText = 'position:fixed;left:12px;bottom:12px;z-index:2147483647;background:#111;color:#7CFC98;font:12px/1.6 monospace;padding:10px 14px;border-radius:8px;max-width:420px;max-height:40vh;overflow:auto;box-shadow:0 8px 30px rgba(0,0,0,.5);';
			debugBox.innerHTML = '<strong style="color:#fff">Emotio Team JS v' + (i18n.version || '?') + '</strong><br>';
			(document.body || document.documentElement).appendChild(debugBox);
		}
		var line = document.createElement('div');
		line.textContent = message;
		debugBox.appendChild(line);
	}

	/* ------------------------------------------------------------ modal */

	function buildModal() {
		if (modal) {
			return modal;
		}
		modal = document.createElement('div');
		modal.className = 'etm-modal etm';
		modal.hidden = true;
		modal.setAttribute('role', 'dialog');
		modal.setAttribute('aria-modal', 'true');
		modal.innerHTML =
			'<div class="etm-modal-backdrop" data-etm-close></div>' +
			'<div class="etm-modal-dialog" role="document">' +
			'<button type="button" class="etm-modal-close" data-etm-close aria-label="' + (i18n.close || 'Close') + '">' +
			'<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="6" y1="6" x2="18" y2="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>' +
			'</button>' +
			'<div class="etm-modal-content"></div>' +
			'</div>';
		document.body.appendChild(modal);

		modal.addEventListener('click', function (e) {
			if (e.target.closest('[data-etm-close]')) {
				closeModal();
			}
		});
		document.addEventListener('keydown', function (e) {
			if (modal.hidden) {
				return;
			}
			if (e.key === 'Escape') {
				closeModal();
			} else if (e.key === 'Tab') {
				trapFocus(e);
			}
		});
		return modal;
	}

	// Lazy-load plugins rewrite img src -> data-src; resolve that on nodes
	// cloned out of the <template>, which their scripts never process.
	function fixLazyImages(root) {
		root.querySelectorAll('img').forEach(function (img) {
			var src = img.getAttribute('src') || '';
			var real = img.getAttribute('data-src') || img.getAttribute('data-lazy-src') ||
				img.getAttribute('data-original') || img.getAttribute('data-nectar-img-src') || '';
			if (real && (!src || src.indexOf('data:image') === 0 || src.indexOf('blank.') !== -1)) {
				img.setAttribute('src', real);
			}
			var srcset = img.getAttribute('data-srcset') || img.getAttribute('data-lazy-srcset');
			if (srcset) {
				img.setAttribute('srcset', srcset);
			}
			img.classList.remove('lazyload', 'lazyloading', 'lazyloaded', 'nectar-lazy');
			img.removeAttribute('loading');
		});
	}

	// Guarantee the profile photo: if the cloned markup lost its image,
	// rebuild it from the card's data-photo attribute.
	function ensurePhoto(content, item) {
		var media = content.querySelector('.etm-detail-media');
		if (!media) {
			return;
		}
		var img = media.querySelector('img');
		var photo = item.getAttribute('data-photo') || '';
		if ((!img || !img.getAttribute('src')) && photo) {
			if (img) {
				img.remove();
			}
			img = document.createElement('img');
			img.src = photo;
			img.alt = item.getAttribute('data-name') || '';
			media.appendChild(img);
		}
		if (!media.querySelector('img[src]')) {
			media.style.display = 'none';
		}
	}

	// Last-resort profile built from the visible card, for sites where an
	// optimiser strips <template> tags out of the markup entirely.
	function fallbackDetail(item) {
		var wrap = document.createElement('div');
		wrap.className = 'etm-detail-inner';
		var media = document.createElement('div');
		media.className = 'etm-detail-media';
		var photo = item.getAttribute('data-photo');
		if (photo) {
			var img = document.createElement('img');
			img.src = photo;
			img.alt = item.getAttribute('data-name') || '';
			media.appendChild(img);
		}
		var body = document.createElement('div');
		body.className = 'etm-detail-body';
		var name = document.createElement('h2');
		name.className = 'etm-detail-name';
		name.textContent = item.getAttribute('data-name') || '';
		body.appendChild(name);
		var role = item.querySelector('.etm-role');
		if (role) {
			var roleEl = document.createElement('p');
			roleEl.className = 'etm-detail-role';
			roleEl.textContent = role.textContent;
			body.appendChild(roleEl);
		}
		var bio = item.querySelector('.etm-bio');
		if (bio) {
			var bioEl = document.createElement('div');
			bioEl.className = 'etm-detail-bio';
			bioEl.textContent = bio.textContent;
			body.appendChild(bioEl);
		}
		var socials = item.querySelector('.etm-socials');
		if (socials) {
			body.appendChild(socials.cloneNode(true));
		}
		wrap.appendChild(media);
		wrap.appendChild(body);
		return wrap;
	}

	function openModal(item, instance) {
		var mode = instance.getAttribute('data-link') === 'panel' ? 'panel' : 'modal';
		var styleCss = instance.getAttribute('style') || '';
		var member = item.getAttribute('data-member');

		// Server-fetched profiles are immune to page optimisers mangling
		// the inline <template>; the template is only the offline fallback.
		if (member && i18n.ajaxUrl) {
			openRemoteProfile(member, mode, item, styleCss, function () {
				openLocalProfile(item, mode, styleCss);
			});
			return;
		}
		openLocalProfile(item, mode, styleCss);
	}

	function openLocalProfile(item, mode, styleCss) {
		buildModal();
		// Carry the instance's design tokens (accent, typography…) into the modal.
		modal.style.cssText = styleCss || '';
		modal.classList.toggle('etm-modal--drawer', mode === 'panel');

		var content = modal.querySelector('.etm-modal-content');
		content.innerHTML = '';

		var template = item.querySelector('template.etm-detail');
		if (template && template.content && template.content.querySelector('.etm-detail-inner')) {
			content.appendChild(template.content.cloneNode(true));
		} else {
			content.appendChild(fallbackDetail(item));
		}
		fixLazyImages(content);
		ensurePhoto(content, item);

		var name = modal.querySelector('.etm-detail-name');
		if (name) {
			name.id = 'etm-modal-title';
			modal.setAttribute('aria-labelledby', 'etm-modal-title');
		}

		lastTrigger = document.activeElement;
		modal.hidden = false;
		void modal.offsetWidth; // Force a layout so the open transition runs.
		modal.classList.add('is-open');
		document.body.style.overflow = 'hidden';
		modal.querySelector('.etm-modal-close').focus();
	}

	function closeModal() {
		if (!modal || modal.hidden) {
			return;
		}
		modal.classList.remove('is-open');
		var delay = reducedMotion ? 0 : 420;
		setTimeout(function () {
			modal.hidden = true;
		}, delay);
		document.body.style.overflow = '';
		if (lastTrigger && lastTrigger.focus) {
			lastTrigger.focus();
		}
	}

	function trapFocus(e) {
		var focusable = modal.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])');
		if (!focusable.length) {
			return;
		}
		var first = focusable[0];
		var last = focusable[focusable.length - 1];
		if (e.shiftKey && document.activeElement === first) {
			e.preventDefault();
			last.focus();
		} else if (!e.shiftKey && document.activeElement === last) {
			e.preventDefault();
			first.focus();
		}
	}

	/* --------------------------------------------- remote profile triggers */

	var profileCache = {};

	// Open a profile fetched from the server (triggers can live on pages
	// with no team layout at all).
	function showRemoteProfile(html, mode, styleCss) {
		buildModal();
		modal.style.cssText = styleCss || '';
		modal.classList.toggle('etm-modal--drawer', mode === 'panel');
		var content = modal.querySelector('.etm-modal-content');
		content.innerHTML = html;
		fixLazyImages(content);

		var name = modal.querySelector('.etm-detail-name');
		if (name) {
			name.id = 'etm-modal-title';
			modal.setAttribute('aria-labelledby', 'etm-modal-title');
		}

		lastTrigger = document.activeElement;
		modal.hidden = false;
		void modal.offsetWidth;
		modal.classList.add('is-open');
		document.body.style.overflow = 'hidden';
		modal.querySelector('.etm-modal-close').focus();
	}

	function openRemoteProfile(key, mode, trigger, styleCss, onFail) {
		if (profileCache[key]) {
			debugLog('profile ' + key + ': served from cache (' + mode + ')');
			showRemoteProfile(profileCache[key], mode, styleCss);
			return;
		}
		var ajaxUrl = i18n.ajaxUrl || '/wp-admin/admin-ajax.php';
		debugLog('profile ' + key + ': fetching ' + ajaxUrl);
		if (trigger) {
			trigger.classList.add('etm-loading');
		}
		fetch(ajaxUrl + '?action=etm_profile&member=' + encodeURIComponent(key))
			.then(function (response) {
				debugLog('profile ' + key + ': HTTP ' + response.status);
				return response.json();
			})
			.then(function (data) {
				if (data && data.success && data.data && data.data.html) {
					profileCache[key] = data.data.html;
					debugLog('profile ' + key + ': OK, ' + data.data.html.length + ' chars, bio=' + (data.data.html.indexOf('etm-detail-bio') !== -1 ? 'yes' : 'NO'));
					showRemoteProfile(data.data.html, mode, styleCss);
				} else if (onFail) {
					debugLog('profile ' + key + ': no HTML in response — FALLBACK to inline template');
					window.console && console.warn('Emotio Team: profile fetch returned no HTML, using inline fallback', data);
					onFail();
				}
			})
			.catch(function (err) {
				debugLog('profile ' + key + ': fetch FAILED (' + err + ') — FALLBACK to inline template');
				window.console && console.warn('Emotio Team: profile fetch failed, using inline fallback', err);
				if (onFail) {
					onFail();
				}
			})
			.finally(function () {
				if (trigger) {
					trigger.classList.remove('etm-loading');
				}
			});
	}

	// One document-level listener covers every kind of trigger:
	// data-etm-profile spans (Salient builder elements), etm-profile-* /
	// etm-panel-* classes, and #etm-profile-* / #etm-panel-* anchors.
	function resolveTrigger(start) {
		var el = start.closest('[data-etm-profile]');
		if (el) {
			return { el: el, key: el.getAttribute('data-etm-profile'), mode: el.getAttribute('data-etm-mode') === 'panel' ? 'panel' : 'modal', isLink: false };
		}
		el = start.closest('a[href*="#etm-profile-"], a[href*="#etm-panel-"]');
		if (el) {
			var match = (el.getAttribute('href') || '').match(/#etm-(profile|panel)-([\w-]+)/);
			if (match) {
				return { el: el, key: match[2], mode: match[1] === 'panel' ? 'panel' : 'modal', isLink: true };
			}
		}
		el = start.closest('[class*="etm-profile-"], [class*="etm-panel-"]');
		if (el && !el.closest('[data-etm]')) {
			var cls = (' ' + el.className + ' ').match(/\setm-(profile|panel)-([\w-]+)\s/);
			if (cls) {
				return { el: el, key: cls[2], mode: cls[1] === 'panel' ? 'panel' : 'modal', isLink: el.tagName === 'A' };
			}
		}
		return null;
	}

	function bindGlobalTriggers() {
		if (window._etmTriggersBound) {
			return;
		}
		window._etmTriggersBound = true;
		document.addEventListener('click', function (e) {
			if (!(e.target instanceof Element)) {
				return;
			}
			var trigger = resolveTrigger(e.target);
			if (!trigger || !trigger.key) {
				return;
			}
			e.preventDefault();
			openRemoteProfile(trigger.key, trigger.mode, trigger.el);
		});
		document.addEventListener('keydown', function (e) {
			if ((e.key !== 'Enter' && e.key !== ' ') || !(e.target instanceof Element)) {
				return;
			}
			var el = e.target.closest('.etm-vc-trigger[data-etm-profile]');
			if (el) {
				e.preventDefault();
				openRemoteProfile(el.getAttribute('data-etm-profile'), el.getAttribute('data-etm-mode') === 'panel' ? 'panel' : 'modal', el);
			}
		});
	}

	/* ------------------------------------ standalone search & filter */

	function remoteTarget(control) {
		var selector = control.getAttribute('data-target');
		if (selector) {
			try {
				var explicit = document.querySelector(selector);
				if (explicit && explicit.hasAttribute('data-etm')) {
					return explicit;
				}
				if (explicit) {
					return explicit.querySelector('[data-etm]');
				}
			} catch (err) { /* bad selector — fall through */ }
		}
		return document.querySelector('[data-etm]');
	}

	function initRemoteControls() {
		document.querySelectorAll('[data-etm-remote-search]').forEach(function (control) {
			if (control._etmBound) {
				return;
			}
			control._etmBound = true;
			var input = control.querySelector('input');
			var timer = null;
			input.addEventListener('input', function () {
				clearTimeout(timer);
				timer = setTimeout(function () {
					var instance = remoteTarget(control);
					if (instance && instance._etmReady) {
						instance._searchTerm = input.value;
						applyFilters(instance);
					}
				}, 120);
			});
		});

		document.querySelectorAll('[data-etm-remote-filter]').forEach(function (control) {
			if (control._etmBound) {
				return;
			}
			control._etmBound = true;
			var chips = control.querySelectorAll('.etm-chip');
			chips.forEach(function (chip) {
				chip.addEventListener('click', function () {
					chips.forEach(function (other) {
						other.classList.remove('is-active');
						other.setAttribute('aria-pressed', 'false');
					});
					chip.classList.add('is-active');
					chip.setAttribute('aria-pressed', 'true');
					var instance = remoteTarget(control);
					if (instance && instance._etmReady) {
						instance._activeFilter = chip.getAttribute('data-filter');
						applyFilters(instance);
					}
				});
			});
		});
	}

	/* -------------------------------------------------- filter + search */

	function applyFilters(instance) {
		var query = (instance._searchTerm || '').toLowerCase().trim();
		var dept = instance._activeFilter || '*';
		var items = instance.querySelectorAll('.etm-item');
		var visible = 0;

		items.forEach(function (item) {
			var matchesDept = dept === '*' || (item.getAttribute('data-departments') || '').split(/\s+/).indexOf(dept) !== -1;
			var matchesQuery = !query || (item.getAttribute('data-search') || '').indexOf(query) !== -1;
			var show = matchesDept && matchesQuery;
			item.classList.toggle('is-hidden', !show);
			if (show) {
				visible++;
			}
		});

		var empty = instance.querySelector('.etm-no-results');
		if (empty) {
			empty.hidden = visible > 0;
		}

		// Grouped layout: hide department sections with nothing to show.
		instance.querySelectorAll('.etm-group').forEach(function (group) {
			group.hidden = !group.querySelector('.etm-item:not(.is-hidden)');
		});

		if (instance._slider) {
			instance._slider.refresh();
		}
	}

	function initToolbar(instance) {
		var chips = instance.querySelectorAll('.etm-chip');
		chips.forEach(function (chip) {
			chip.addEventListener('click', function () {
				chips.forEach(function (c) {
					c.classList.remove('is-active');
					c.setAttribute('aria-pressed', 'false');
				});
				chip.classList.add('is-active');
				chip.setAttribute('aria-pressed', 'true');
				instance._activeFilter = chip.getAttribute('data-filter');
				applyFilters(instance);
			});
		});

		var search = instance.querySelector('.etm-search input');
		if (search) {
			var timer = null;
			search.addEventListener('input', function () {
				clearTimeout(timer);
				timer = setTimeout(function () {
					instance._searchTerm = search.value;
					applyFilters(instance);
				}, 120);
			});
		}
	}

	/* ---------------------------------- drag / momentum slider (Area Pro) */

	function initDragTrack(instance) {
		var viewport = instance.querySelector('.etm-viewport');
		var rail = instance.querySelector('.etm-track');
		if (!viewport || !rail) {
			return;
		}
		var prev = instance.querySelector('.etm-arrow--prev');
		var next = instance.querySelector('.etm-arrow--next');
		var x = 0, min = 0, startX = 0, lastX = 0, v = 0, down = false, raf = null, dragged = false;

		viewport.addEventListener('dragstart', function (e) {
			e.preventDefault();
		});

		function clamp() {
			min = Math.min(0, viewport.clientWidth - rail.scrollWidth);
			if (x < min) { x = min; }
			if (x > 0) { x = 0; }
		}
		function paint() {
			rail.style.transform = 'translate3d(' + x + 'px,0,0)';
			if (prev) { prev.disabled = x >= -2; }
			if (next) { next.disabled = x <= min + 2; }
		}
		function momentum() {
			if (Math.abs(v) < 0.3) { raf = null; return; }
			x += v;
			v *= 0.94;
			clamp();
			paint();
			raf = requestAnimationFrame(momentum);
		}
		function throwTo(dir) {
			if (raf) { cancelAnimationFrame(raf); }
			v = dir * Math.max(22, viewport.clientWidth * 0.05);
			raf = requestAnimationFrame(momentum);
		}

		viewport.addEventListener('pointerdown', function (e) {
			down = true;
			dragged = false;
			startX = lastX = e.clientX;
			v = 0;
			if (raf) { cancelAnimationFrame(raf); raf = null; }
			window.addEventListener('pointermove', move, { passive: false });
			window.addEventListener('pointerup', up, true);
			window.addEventListener('pointercancel', up, true);
		});
		function move(e) {
			if (!down) { return; }
			var dx = e.clientX - lastX;
			lastX = e.clientX;
			if (!dragged && Math.abs(e.clientX - startX) > 6) {
				dragged = true;
				instance._dragging = true;
			}
			if (dragged && e.cancelable) { e.preventDefault(); }
			x += dx;
			v = dx;
			clamp();
			paint();
		}
		function up() {
			window.removeEventListener('pointermove', move, { passive: false });
			window.removeEventListener('pointerup', up, true);
			window.removeEventListener('pointercancel', up, true);
			if (!down) { return; }
			down = false;
			raf = requestAnimationFrame(momentum);
			setTimeout(function () { instance._dragging = false; }, 60);
		}

		viewport.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowRight') { x -= 140; } else if (e.key === 'ArrowLeft') { x += 140; } else { return; }
			clamp();
			paint();
			e.preventDefault();
		});
		// Trackpad: two-finger horizontal scroll (and shift+wheel) drives the track.
		viewport.addEventListener('wheel', function (e) {
			var dx = Math.abs(e.deltaX) > Math.abs(e.deltaY) ? e.deltaX : (e.shiftKey ? e.deltaY : 0);
			if (!dx) { return; }
			e.preventDefault();
			if (raf) { cancelAnimationFrame(raf); raf = null; }
			v = 0;
			x -= dx;
			clamp();
			paint();
		}, { passive: false });

		if (prev) { prev.addEventListener('click', function () { throwTo(1); }); }
		if (next) { next.addEventListener('click', function () { throwTo(-1); }); }
		window.addEventListener('resize', debounce(function () { clamp(); paint(); }, 150));

		instance._slider = {
			refresh: function () { clamp(); paint(); }
		};
		clamp();
		paint();
	}

	/* ---------------------------------------------------- paged slider */

	function initSlider(instance) {
		var track = instance.querySelector('.etm-track');
		if (!track) {
			return;
		}
		var prev = instance.querySelector('.etm-arrow--prev');
		var next = instance.querySelector('.etm-arrow--next');
		var dotsWrap = instance.querySelector('.etm-dots');
		var autoplayDelay = parseInt(instance.getAttribute('data-autoplay') || '0', 10);
		var autoplayTimer = null;

		function pageWidth() {
			return track.clientWidth;
		}

		function pages() {
			return Math.max(1, Math.ceil(track.scrollWidth / pageWidth()));
		}

		function currentPage() {
			return Math.round(track.scrollLeft / pageWidth());
		}

		function goTo(page) {
			track.scrollTo({ left: page * pageWidth(), behavior: reducedMotion ? 'auto' : 'smooth' });
		}

		function renderDots() {
			if (!dotsWrap) {
				return;
			}
			var count = pages();
			var current = currentPage();
			dotsWrap.innerHTML = '';
			if (count < 2) {
				update();
				return;
			}
			for (var i = 0; i < count; i++) {
				var dot = document.createElement('button');
				dot.type = 'button';
				dot.className = 'etm-dot' + (i === current ? ' is-active' : '');
				dot.setAttribute('aria-label', (i18n.next || 'Page') + ' ' + (i + 1));
				(function (page) {
					dot.addEventListener('click', function () {
						stopAutoplay();
						goTo(page);
					});
				})(i);
				dotsWrap.appendChild(dot);
			}
			update();
		}

		function update() {
			var max = track.scrollWidth - track.clientWidth - 2;
			if (prev) {
				prev.disabled = track.scrollLeft <= 2;
			}
			if (next) {
				next.disabled = track.scrollLeft >= max;
			}
			if (dotsWrap) {
				var current = currentPage();
				dotsWrap.querySelectorAll('.etm-dot').forEach(function (dot, i) {
					dot.classList.toggle('is-active', i === current);
				});
			}
		}

		function startAutoplay() {
			if (!autoplayDelay || reducedMotion) {
				return;
			}
			stopAutoplay();
			autoplayTimer = setInterval(function () {
				var max = track.scrollWidth - track.clientWidth - 2;
				if (track.scrollLeft >= max) {
					goTo(0);
				} else {
					goTo(currentPage() + 1);
				}
			}, autoplayDelay);
		}

		function stopAutoplay() {
			if (autoplayTimer) {
				clearInterval(autoplayTimer);
				autoplayTimer = null;
			}
		}

		if (prev) {
			prev.addEventListener('click', function () {
				stopAutoplay();
				goTo(currentPage() - 1);
			});
		}
		if (next) {
			next.addEventListener('click', function () {
				stopAutoplay();
				goTo(currentPage() + 1);
			});
		}

		var scrollTimer = null;
		track.addEventListener('scroll', function () {
			clearTimeout(scrollTimer);
			scrollTimer = setTimeout(update, 80);
		}, { passive: true });

		track.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowLeft') {
				e.preventDefault();
				goTo(currentPage() - 1);
			} else if (e.key === 'ArrowRight') {
				e.preventDefault();
				goTo(currentPage() + 1);
			}
		});

		instance.addEventListener('mouseenter', stopAutoplay);
		instance.addEventListener('mouseleave', startAutoplay);
		instance.addEventListener('focusin', stopAutoplay);

		window.addEventListener('resize', debounce(renderDots, 150));

		instance._slider = { refresh: renderDots };
		renderDots();
		startAutoplay();
	}

	/* ------------------------------------------------------------ reveal */

	function initReveal(instance) {
		if (reducedMotion || !('IntersectionObserver' in window)) {
			return;
		}
		var items = instance.querySelectorAll('.etm-item');
		var observer = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (entry.isIntersecting) {
					entry.target.classList.add('etm-in');
					observer.unobserve(entry.target);
				}
			});
		}, { rootMargin: '0px 0px -8% 0px' });

		items.forEach(function (item, i) {
			item.setAttribute('data-etm-reveal', '');
			item.style.transitionDelay = (Math.min(i % 6, 5) * 60) + 'ms';
			observer.observe(item);
		});
	}

	/* ------------------------------------------------------------- init */

	function debounce(fn, wait) {
		var t = null;
		return function () {
			clearTimeout(t);
			t = setTimeout(fn, wait);
		};
	}

	function initInstance(instance) {
		if (instance._etmReady) {
			return;
		}
		instance._etmReady = true;
		instance._activeFilter = '*';
		instance._searchTerm = '';

		initToolbar(instance);
		if (instance.getAttribute('data-layout') === 'slider') {
			if (instance.getAttribute('data-slider') === 'drag') {
				initDragTrack(instance);
			} else {
				initSlider(instance);
			}
		}
		initReveal(instance);

		instance.addEventListener('click', function (e) {
			if (instance._dragging) {
				e.preventDefault();
				e.stopPropagation();
				return;
			}
			var trigger = e.target.closest('[data-etm-open]');
			if (trigger) {
				var item = trigger.closest('.etm-item');
				if (item) {
					openModal(item, instance);
				}
			}
		}, true);
	}

	var debugBooted = false;

	function initAll() {
		document.querySelectorAll('[data-etm]').forEach(initInstance);
		initRemoteControls();
		bindGlobalTriggers();

		if (!debugBooted && window.location.search.indexOf('etm_debug') !== -1) {
			debugBooted = true;
			var instances = document.querySelectorAll('[data-etm]');
			debugLog('instances: ' + instances.length);
			instances.forEach(function (instance, index) {
				debugLog('#' + (index + 1) + ' layout=' + instance.getAttribute('data-layout') + ' link=' + instance.getAttribute('data-link') + ' members=' + instance.querySelectorAll('.etm-item').length + ' data-member=' + (instance.querySelector('.etm-item[data-member]') ? 'yes' : 'MISSING (old markup!)'));
			});
			debugLog('ajaxUrl: ' + (i18n.ajaxUrl || 'MISSING (old markup!)'));
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initAll);
	} else {
		initAll();
	}

	// Re-scan for instances injected later (page builders, AJAX).
	if ('MutationObserver' in window) {
		new MutationObserver(debounce(initAll, 200)).observe(document.body || document.documentElement, {
			childList: true,
			subtree: true
		});
	}
})();
