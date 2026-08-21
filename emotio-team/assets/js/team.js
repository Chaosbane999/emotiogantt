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

	function openModal(item, instance) {
		var template = item.querySelector('template.etm-detail');
		if (!template) {
			return;
		}
		buildModal();
		// Carry the instance's design tokens (accent etc.) into the modal.
		modal.style.cssText = instance.getAttribute('style') || '';
		modal.querySelector('.etm-modal-content').innerHTML = '';
		modal.querySelector('.etm-modal-content').appendChild(template.content.cloneNode(true));

		var name = modal.querySelector('.etm-detail-name');
		if (name) {
			name.id = 'etm-modal-title';
			modal.setAttribute('aria-labelledby', 'etm-modal-title');
		}

		lastTrigger = document.activeElement;
		modal.hidden = false;
		document.body.style.overflow = 'hidden';
		modal.querySelector('.etm-modal-close').focus();
	}

	function closeModal() {
		if (!modal || modal.hidden) {
			return;
		}
		modal.hidden = true;
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

	/* ------------------------------------------------------------ slider */

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
			initSlider(instance);
		}
		initReveal(instance);

		instance.addEventListener('click', function (e) {
			var trigger = e.target.closest('[data-etm-open]');
			if (trigger) {
				var item = trigger.closest('.etm-item');
				if (item) {
					openModal(item, instance);
				}
			}
		});
	}

	function initAll() {
		document.querySelectorAll('[data-etm]').forEach(initInstance);
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
