(function ($) {
	'use strict';

	var panel;
	var timeline;
	var loaded = false;
	var open = false;
	var closeTimer = null;
	var hoverMode =
		typeof window.matchMedia === 'function' &&
		window.matchMedia('(hover: hover)').matches;

	function init() {
		if (typeof WhoChangedBar === 'undefined') {
			return;
		}

		panel = document.getElementById('whochanged-ab-panel');
		if (!panel) {
			return;
		}

		timeline = panel.querySelector('[data-whochanged-ab-timeline]');
		var sub = panel.querySelector('.whochanged-ab-panel__subtitle');
		if (sub && WhoChangedBar.subtitle) {
			sub.textContent = WhoChangedBar.subtitle;
		}

		$('#wp-admin-bar-whochanged-history').attr('aria-expanded', 'false');
	}

	function getTrigger() {
		return $('#wp-admin-bar-whochanged-history > a.ab-item').first();
	}

	function cancelScheduledClose() {
		if (closeTimer) {
			window.clearTimeout(closeTimer);
			closeTimer = null;
		}
	}

	function scheduleClose() {
		cancelScheduledClose();
		closeTimer = window.setTimeout(function () {
			closeTimer = null;
			setOpen(false);
		}, 220);
	}

	function positionPanel() {
		var $trigger = getTrigger();
		if (!$trigger.length || !panel) {
			return;
		}

		var r = $trigger[0].getBoundingClientRect();
		panel.style.top = r.bottom + 'px';
		panel.style.left = r.left + 'px';

		var w = panel.offsetWidth || 360;
		var maxLeft = window.innerWidth - w - 8;
		if (r.left > maxLeft) {
			panel.style.left = Math.max(8, maxLeft) + 'px';
		}
	}

	function setOpen(state) {
		open = state;
		if (!panel) {
			return;
		}

		if (state) {
			panel.removeAttribute('hidden');
			panel.classList.add('is-open');
			$('#wp-admin-bar-whochanged-history').attr('aria-expanded', 'true');
		} else {
			panel.setAttribute('hidden', 'hidden');
			panel.classList.remove('is-open');
			$('#wp-admin-bar-whochanged-history').attr('aria-expanded', 'false');
		}
	}

	function openPanel() {
		cancelScheduledClose();
		positionPanel();
		setOpen(true);
		if (!loaded) {
			loadEvents();
		}
	}

	function renderEvents(events) {
		if (!timeline) {
			return;
		}

		timeline.textContent = '';

		if (!events || !events.length) {
			var p = document.createElement('p');
			p.className = 'whochanged-ab-empty';
			p.textContent = WhoChangedBar.empty;
			timeline.appendChild(p);
			return;
		}

		events.forEach(function (ev) {
			var wrap = document.createElement('div');
			wrap.className = 'whochanged-ab-event';

			var meta = document.createElement('div');
			meta.className = 'whochanged-ab-event__meta';
			meta.textContent = ev.meta || '';

			var text = document.createElement('div');
			text.className = 'whochanged-ab-event__text';
			text.textContent = ev.text || '';

			wrap.appendChild(meta);
			wrap.appendChild(text);
			timeline.appendChild(wrap);
		});
	}

	function loadEvents() {
		if (!timeline || typeof WhoChangedBar === 'undefined') {
			return;
		}

		timeline.innerHTML = '';
		var loading = document.createElement('p');
		loading.className = 'whochanged-ab-loading';
		loading.textContent = WhoChangedBar.loading;
		timeline.appendChild(loading);

		$.post(WhoChangedBar.ajaxUrl, {
			action: 'whochanged_admin_bar_events',
			nonce: WhoChangedBar.nonce
		})
			.done(function (res) {
				if (res && res.success && res.data && Array.isArray(res.data.events)) {
					renderEvents(res.data.events);
					loaded = true;
				} else {
					renderEvents([]);
					loaded = true;
				}
			})
			.fail(function () {
				renderEvents([]);
				loaded = true;
			});
	}

	$(init);

	if (hoverMode) {
		$(document).on('mouseenter', '#wp-admin-bar-whochanged-history', function () {
			if (typeof WhoChangedBar === 'undefined' || !panel) {
				return;
			}
			openPanel();
		});

		$(document).on('mouseleave', '#wp-admin-bar-whochanged-history', function () {
			scheduleClose();
		});

		$(document).on('mouseenter', '#whochanged-ab-panel', function () {
			cancelScheduledClose();
		});

		$(document).on('mouseleave', '#whochanged-ab-panel', function () {
			scheduleClose();
		});
	} else {
		$(document).on('click', '#wp-admin-bar-whochanged-history > a.ab-item', function (e) {
			if (typeof WhoChangedBar === 'undefined' || !panel) {
				return;
			}

			if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey) {
				return;
			}

			e.preventDefault();
			e.stopPropagation();

			if (open) {
				setOpen(false);
				return;
			}

			openPanel();
		});

		$(document).on('click', function (e) {
			if (!open || !panel) {
				return;
			}

			var t = e.target;
			if (panel.contains(t)) {
				return;
			}
			if (t.closest && t.closest('#wp-admin-bar-whochanged-history')) {
				return;
			}

			setOpen(false);
		});
	}

	$(document).on('click', '[data-whochanged-ab-reload]', function (e) {
		e.preventDefault();
		cancelScheduledClose();
		loaded = false;
		loadEvents();
		if (!open && panel) {
			positionPanel();
			setOpen(true);
		}
	});

	$(document).on('keydown', function (e) {
		if (e.key === 'Escape' && open) {
			cancelScheduledClose();
			setOpen(false);
		}
	});

	$(window).on('resize', function () {
		if (open) {
			positionPanel();
		}
	});

})(jQuery);
