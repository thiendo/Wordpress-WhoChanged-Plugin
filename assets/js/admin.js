(function ($) {
	'use strict';

	function listUrlFromParams(params) {
		if (typeof WhoChangedAdmin === 'undefined' || !WhoChangedAdmin.adminUrl || !WhoChangedAdmin.menuSlug) {
			return '';
		}
		return WhoChangedAdmin.adminUrl + '?' + params.toString();
	}

	function setFilterNonce(params) {
		if (WhoChangedAdmin.filterNonce) {
			params.set('whochanged_filter_nonce', WhoChangedAdmin.filterNonce);
		}
	}

	function getRangeYmdFromPicker() {
		var el = document.getElementById('whochanged-range-calendar');
		if (!el || !el._flatpickr || typeof window.flatpickr === 'undefined') {
			return { df: '', dt: '' };
		}
		var fp = el._flatpickr;
		if (!fp.selectedDates || !fp.selectedDates.length) {
			return { df: '', dt: '' };
		}
		var fmt = 'Y-m-d';
		var ymd = function (d) {
			return typeof fp.formatDate === 'function' ? fp.formatDate(d, fmt) : window.flatpickr.formatDate(d, fmt);
		};
		var d0 = ymd(fp.selectedDates[0]);
		var d1 = fp.selectedDates.length > 1 ? ymd(fp.selectedDates[1]) : d0;
		if (d0 > d1) {
			var t = d0;
			d0 = d1;
			d1 = t;
		}
		return { df: d0, dt: d1 };
	}

	function initWhoChangedRangeCalendar() {
		var el = document.getElementById('whochanged-range-calendar');
		if (!el || typeof window.flatpickr === 'undefined') {
			return;
		}
		if (el._flatpickr) {
			el._flatpickr.destroy();
		}
		var df = (el.getAttribute('data-df') || '').trim();
		var dt = (el.getAttribute('data-dt') || '').trim();
		var def = [];
		if (df && dt) {
			def = df <= dt ? [df, dt] : [dt, df];
		} else if (df) {
			def = [df, df];
		} else if (WhoChangedAdmin.defaultCustomFromYmd && WhoChangedAdmin.todayYmd) {
			def = [WhoChangedAdmin.defaultCustomFromYmd, WhoChangedAdmin.todayYmd];
		}
		var cfg = {
			mode: 'range',
			dateFormat: 'Y-m-d',
			allowInput: false,
			appendTo: document.body,
			maxDate: WhoChangedAdmin.todayYmd || undefined,
			defaultDate: def.length ? def : undefined
		};
		if (WhoChangedAdmin.flatpickrLocale === 'vn' && window.flatpickr.l10ns && window.flatpickr.l10ns.vn) {
			cfg.locale = window.flatpickr.l10ns.vn;
		}
		if (WhoChangedAdmin.rangeCalendarPlaceholder) {
			el.setAttribute('placeholder', WhoChangedAdmin.rangeCalendarPlaceholder);
		}
		window.flatpickr(el, cfg);
	}

	function mergeDateRangeIntoParams(params) {
		var $preset = $('#whochanged-range-preset');
		if (!$preset.length) {
			return;
		}
		var dr = $preset.val() || 'all';
		params.delete('whochanged_date');
		params.delete('whochanged_dr');
		params.delete('whochanged_df');
		params.delete('whochanged_dt');
		if (dr === 'all') {
			return;
		}
		params.set('whochanged_dr', dr);
		if (dr === 'custom') {
			var r = getRangeYmdFromPicker();
			if (r.df) {
				params.set('whochanged_df', r.df);
			}
			if (r.dt) {
				params.set('whochanged_dt', r.dt);
			}
		}
	}

	function navigateWithBaseParams(extra) {
		showListPanelLoading();
		var params = new URLSearchParams(window.location.search);
		var currentPage = params.get('page');
		if (!currentPage) {
			currentPage = WhoChangedAdmin.menuSlug;
		}
		params.set('page', currentPage);
		params.set('paged', '1');
		setFilterNonce(params);
		if (extra && typeof extra === 'function') {
			extra(params);
		}
		window.location.href = listUrlFromParams(params);
	}

	function isModifiedNavigationClick(e) {
		return !!(e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0);
	}

	function scrollWhoChangedWrapToTopNow() {
		var wrap = document.querySelector('.whochanged-wrap');
		if (wrap && wrap.scrollIntoView) {
			wrap.scrollIntoView({ block: 'start', behavior: 'auto' });
			return;
		}
		window.scrollTo(0, 0);
	}

	$(function () {
		if ($('#whochanged-range-preset').val() === 'custom') {
			initWhoChangedRangeCalendar();
		}
	});

	$(document).on('submit', '.whochanged-filters', function (e) {
		e.preventDefault();
		if (typeof WhoChangedAdmin === 'undefined' || !WhoChangedAdmin.adminUrl || !WhoChangedAdmin.menuSlug) {
			return;
		}
		var form = e.target;
		var fd = new FormData(form);
		var params = new URLSearchParams();
		fd.forEach(function (val, key) {
			params.append(key, val);
		});
		mergeDateRangeIntoParams(params);
		params.set('paged', '1');
		var href = listUrlFromParams(params);
		if (href) {
			showListPanelLoading();
			window.location.href = href;
		}
	});

	$(document).on('change', '#whochanged-range-preset', function () {
		if (typeof WhoChangedAdmin === 'undefined' || !WhoChangedAdmin.adminUrl || !WhoChangedAdmin.menuSlug) {
			return;
		}
		var v = this.value;
		var $custom = $('.whochanged-custom-range');
		if (v === 'custom') {
			$custom.removeClass('is-collapsed');
			initWhoChangedRangeCalendar();
			return;
		}
		var cal = document.getElementById('whochanged-range-calendar');
		if (cal && cal._flatpickr) {
			cal._flatpickr.destroy();
		}
		$custom.addClass('is-collapsed');
		navigateWithBaseParams(function (params) {
			params.delete('whochanged_date');
			params.delete('whochanged_df');
			params.delete('whochanged_dt');
			if (v === 'all') {
				params.delete('whochanged_dr');
			} else {
				params.set('whochanged_dr', v);
			}
		});
	});

	$(document).on('click', '.whochanged-range-apply', function (e) {
		e.preventDefault();
		if (typeof WhoChangedAdmin === 'undefined' || !WhoChangedAdmin.adminUrl || !WhoChangedAdmin.menuSlug) {
			return;
		}
		navigateWithBaseParams(function (params) {
			params.delete('whochanged_date');
			params.set('whochanged_dr', 'custom');
			var r = getRangeYmdFromPicker();
			if (r.df) {
				params.set('whochanged_df', r.df);
			} else {
				params.delete('whochanged_df');
			}
			if (r.dt) {
				params.set('whochanged_dt', r.dt);
			} else {
				params.delete('whochanged_dt');
			}
		});
	});

	function showListPanelLoading() {
		scrollWhoChangedWrapToTopNow();
		var $panel = $('.whochanged-list-panel');
		if (!$panel.length) {
			return;
		}
		$panel.addClass('is-loading');
		$panel.find('.whochanged-tbody-inner').attr('aria-busy', 'true');
		$panel.find('.whochanged-list-loading').attr('aria-hidden', 'false');
	}

	function clearListPanelLoading() {
		var $panel = $('.whochanged-list-panel');
		$panel.removeClass('is-loading');
		$panel.find('.whochanged-tbody-inner').removeAttr('aria-busy');
		$panel.find('.whochanged-list-loading').attr('aria-hidden', 'true');
	}

	function setExportButtonsLoading($clicked) {
		var $wrap = $clicked.closest('.whochanged-pro-export-buttons');
		if (!$wrap.length || $wrap.hasClass('is-loading')) {
			return;
		}
		$wrap.addClass('is-loading').attr('aria-busy', 'true');
		$wrap.find('.whochanged-pro-export-btn').attr('aria-disabled', 'true').addClass('is-disabled');

		var original = ($clicked.text() || '').trim();
		$clicked.data('whochangedOriginalLabel', original);
		var fallbackLabel = (typeof WhoChangedAdmin !== 'undefined' && WhoChangedAdmin.exportingText) || 'Exporting...';
		var loadingLabel = ($clicked.attr('data-loading-label') || '').trim() || fallbackLabel;
		$clicked.text(loadingLabel).addClass('is-loading');

		window.setTimeout(function () {
			clearExportButtonsLoading($wrap);
		}, 1800);
	}

	function clearExportButtonsLoading($wrap) {
		if (!$wrap || !$wrap.length) {
			return;
		}
		$wrap.removeClass('is-loading').removeAttr('aria-busy');
		$wrap.find('.whochanged-pro-export-btn').each(function () {
			var $btn = $(this);
			var original = ($btn.data('whochangedOriginalLabel') || '').trim();
			if (original) {
				$btn.text(original);
			}
			$btn.removeClass('is-loading is-disabled').removeAttr('aria-disabled');
		});
	}

	window.addEventListener('pageshow', function (event) {
		if (event.persisted) {
			clearListPanelLoading();
		}
	});

	$(document).on('click', '.whochanged-pagination a.whochanged-page-btn', function (e) {
		if (isModifiedNavigationClick(e)) {
			return;
		}
		showListPanelLoading();
	});

	$(document).on('click', '.whochanged-log-tabs a.whochanged-log-tab', function (e) {
		var $a = $(this);
		if ($a.hasClass('nav-tab-active')) {
			e.preventDefault();
			return;
		}
		var href = $a.attr('href');
		if (!href) {
			return;
		}
		e.preventDefault();
		showListPanelLoading();
		var delay = 0;
		if (typeof WhoChangedAdmin !== 'undefined' && WhoChangedAdmin.tabLoadingDelayMs != null) {
			delay = parseInt(WhoChangedAdmin.tabLoadingDelayMs, 10);
			if (isNaN(delay) || delay < 0) {
				delay = 0;
			}
		}
		if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
			delay = 0;
		}
		var go = function () {
			window.location.assign(href);
		};
		if (delay > 0) {
			window.setTimeout(go, delay);
		} else {
			window.requestAnimationFrame(go);
		}
	});

	$(document).on('click', '.whochanged-pro-export-btn', function (e) {
		if (isModifiedNavigationClick(e)) {
			return;
		}
		var $btn = $(this);
		var $wrap = $btn.closest('.whochanged-pro-export-buttons');
		if ($wrap.hasClass('is-loading')) {
			e.preventDefault();
			return;
		}
		setExportButtonsLoading($btn);
	});

	$(document).on('click', '.whochanged-stat-export-pdf-btn', function () {
		var wrap = document.querySelector('.whochanged-statistics-wrap');
		if (!wrap) {
			return;
		}

		var metricsHtml = '';
		var metrics = wrap.querySelector('.whochanged-stat-metrics');
		if (metrics) {
			metricsHtml = metrics.outerHTML;
		}

		var cardsHtml = '';
		var cards = wrap.querySelectorAll('.whochanged-stat-card');
		cards.forEach(function (card) {
			var titleEl = card.querySelector('.whochanged-stat-card-title');
			var title = titleEl ? titleEl.textContent.trim() : '';
			var canvas = card.querySelector('canvas');
			var image = '';
			if (canvas && typeof canvas.toDataURL === 'function') {
				try {
					image = canvas.toDataURL('image/png');
				} catch (err) {
					image = '';
				}
			}
			cardsHtml += '<div class="whochanged-pdf-card">';
			if (title) {
				cardsHtml += '<h3>' + $('<div/>').text(title).html() + '</h3>';
			}
			if (image) {
				cardsHtml += '<img src="' + image + '" alt="">';
			}
			cardsHtml += '</div>';
		});

		var printWin = window.open('', '_blank');
		if (!printWin) {
			return;
		}

		var statsTitle = (typeof WhoChangedAdmin !== 'undefined' && WhoChangedAdmin.statsExportTitle) || 'WhoChanged Statistics';
		var statsSubtitle = (typeof WhoChangedAdmin !== 'undefined' && WhoChangedAdmin.statsExportSubtitle) || 'Generated from current filters';
		var statsTitleHtml = $('<div/>').text(statsTitle).html();
		var statsSubtitleHtml = $('<div/>').text(statsSubtitle).html();

		printWin.document.write(
			'<!doctype html><html><head><meta charset="utf-8"><title>' + statsTitleHtml + '</title>' +
			'<style>body{font-family:Arial,sans-serif;color:#111;padding:20px;}h1{margin:0 0 10px;font-size:22px;}p{color:#555;margin:0 0 14px;}' +
			'.whochanged-stat-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:0 0 16px;}' +
			'.whochanged-stat-metric{border:1px solid #ddd;border-radius:8px;padding:10px;background:#fafafa;}' +
			'.whochanged-stat-metric-label{font-size:12px;color:#666;}.whochanged-stat-metric-value{font-size:22px;font-weight:700;}' +
			'.whochanged-pdf-card{break-inside:avoid;border:1px solid #ddd;border-radius:8px;padding:10px;margin:0 0 14px;}' +
			'.whochanged-pdf-card h3{margin:0 0 10px;font-size:15px;}.whochanged-pdf-card img{width:100%;height:auto;display:block;}' +
			'@media print{body{padding:0;}}</style></head><body>' +
			'<h1>' + statsTitleHtml + '</h1><p>' + statsSubtitleHtml + '</p>' +
			metricsHtml +
			cardsHtml +
			'</body></html>'
		);
		printWin.document.close();
		printWin.focus();
		window.setTimeout(function () {
			printWin.print();
		}, 300);
	});

	function setPurgeFeedback(message, isError) {
		var $box = $('[data-whochanged-purge-feedback]');
		if (!$box.length) {
			return;
		}
		if (!message) {
			$box.removeClass('is-error is-success').text('').hide();
			return;
		}
		$box
			.toggleClass('is-error', !!isError)
			.toggleClass('is-success', !isError)
			.text(message)
			.show();
	}

	$(document).on('click', 'button[name="whochanged_pro_purge_all"]', function (e) {
		var $form = $(this).closest('form');
		var checked = $form.find('input[name="whochanged_pro_purge_checkbox"]').is(':checked');
		var confirmText = ($form.find('input[name="whochanged_pro_purge_confirm_text"]').val() || '').trim();
		var requiredText = 'PURGE ALL ACTIVITY LOGS';

		if (!checked || confirmText !== requiredText) {
			e.preventDefault();
			var invalidText = (typeof WhoChangedAdmin !== 'undefined' && WhoChangedAdmin.purgeConfirmInvalidText) || 'Purge cancelled: confirmation is invalid.';
			setPurgeFeedback(invalidText, true);
			return;
		}

		setPurgeFeedback('', false);
	});

	$(document).on('input change', 'input[name="whochanged_pro_purge_checkbox"], input[name="whochanged_pro_purge_confirm_text"]', function () {
		setPurgeFeedback('', false);
	});

	window.addEventListener('focus', function () {
		clearExportButtonsLoading($('.whochanged-pro-export-buttons.is-loading'));
	});

	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'visible') {
			clearExportButtonsLoading($('.whochanged-pro-export-buttons.is-loading'));
		}
	});

	$(document).on('click', '.whochanged-toggle, .whochanged-activity-toggle, .whochanged-more-toggle', function () {
		var $trigger = $(this);
		var rowId = $trigger.attr('aria-controls');
		var $row = $('#' + rowId);
		var isExpanded = $trigger.attr('aria-expanded') === 'true';

		if ($row.length === 0) {
			return;
		}

		if (!isExpanded) {
			$('.whochanged-details-row').attr('hidden', 'hidden');
			$('.whochanged-toggle').attr('aria-expanded', 'false').text(WhoChangedAdmin.showText);
			$('.whochanged-activity-toggle').attr('aria-expanded', 'false');
			$('.whochanged-more-toggle').attr('aria-expanded', 'false');
		}

		if (isExpanded) {
			$row.attr('hidden', 'hidden');
		} else {
			$row.removeAttr('hidden');
		}
		$trigger.attr('aria-expanded', isExpanded ? 'false' : 'true');

		if ($trigger.hasClass('whochanged-toggle')) {
			$trigger.text(isExpanded ? WhoChangedAdmin.showText : WhoChangedAdmin.hideText);
		} else if ($trigger.hasClass('whochanged-more-toggle')) {
			$('.whochanged-toggle[aria-controls="' + rowId + '"]')
				.attr('aria-expanded', isExpanded ? 'false' : 'true')
				.text(isExpanded ? WhoChangedAdmin.showText : WhoChangedAdmin.hideText);
			$('.whochanged-activity-toggle[aria-controls="' + rowId + '"]')
				.attr('aria-expanded', isExpanded ? 'false' : 'true');
		} else {
			$('.whochanged-toggle[aria-controls="' + rowId + '"]')
				.attr('aria-expanded', isExpanded ? 'false' : 'true')
				.text(isExpanded ? WhoChangedAdmin.showText : WhoChangedAdmin.hideText);
		}
	});
})(jQuery);
