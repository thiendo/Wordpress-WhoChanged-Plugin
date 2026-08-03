/**
 * WhoChanged Statistics charts (Chart.js).
 * Data comes from wp_localize_script( 'whochanged-stats', 'WhoChangedStats', … ).
 */
(function () {
	'use strict';

	var attempts = 0;

	function init() {
		attempts++;
		if (typeof Chart === 'undefined') {
			if (attempts < 40) {
				setTimeout(init, 150);
			}
			return;
		}

		if (typeof WhoChangedStats === 'undefined' || !WhoChangedStats) {
			return;
		}

		var colorPalette = WhoChangedStats.palette || [];
		var actions = WhoChangedStats.actions || { labels: [], values: [], centerLabel: '' };
		var users = WhoChangedStats.users || { labels: [], values: [], centerLabel: '' };
		var objects = WhoChangedStats.objects || { labels: [], values: [] };
		var days = WhoChangedStats.days || { labels: [], values: [] };
		var hours = WhoChangedStats.hours || { labels: [], values: [] };
		var weekdays = WhoChangedStats.weekdays || { labels: [], values: [] };
		var itemsLabel = WhoChangedStats.i18n && WhoChangedStats.i18n.items
			? WhoChangedStats.i18n.items
			: 'Items';

		function pickColors(count) {
			var out = [];
			var i;
			for (i = 0; i < count; i++) {
				out.push(colorPalette[i % colorPalette.length]);
			}
			return out;
		}

		function hexToRgba(hex, alpha) {
			var r = parseInt(hex.slice(1, 3), 16);
			var g = parseInt(hex.slice(3, 5), 16);
			var b = parseInt(hex.slice(5, 7), 16);
			return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
		}

		var centerTextPlugin = {
			id: 'whochangedCenterText',
			afterDraw: function (chart) {
				var total = (chart.data.datasets[0].data || []).reduce(function (a, b) {
					return a + b;
				}, 0);
				var ctx = chart.ctx;
				var area = chart.chartArea;
				var cx = (area.left + area.right) / 2;
				var cy = (area.top + area.bottom) / 2;
				ctx.save();
				ctx.textAlign = 'center';
				ctx.textBaseline = 'middle';
				ctx.font = '600 20px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
				ctx.fillStyle = '#1f2937';
				ctx.fillText(String(total), cx, cy - 8);
				ctx.font = '500 11px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
				ctx.fillStyle = '#6b7280';
				ctx.fillText(chart.data.centerLabel || '', cx, cy + 12);
				ctx.restore();
			}
		};

		function mkDoughnut(ctx, data) {
			if (!data.values || !data.values.length) {
				return null;
			}
			return new Chart(ctx, {
				type: 'doughnut',
				data: {
					labels: data.labels,
					centerLabel: data.centerLabel,
					datasets: [{
						data: data.values,
						backgroundColor: pickColors(data.values.length),
						borderColor: '#fff',
						borderWidth: 2,
						hoverOffset: 4
					}]
				},
				plugins: [centerTextPlugin],
				options: {
					responsive: true,
					maintainAspectRatio: false,
					cutout: '68%',
					plugins: {
						legend: { display: false },
						tooltip: {
							callbacks: {
								label: function (item) {
									var sum = item.dataset.data.reduce(function (a, b) {
										return a + b;
									}, 0);
									var pct = sum > 0 ? Math.round((item.parsed / sum) * 100) : 0;
									return item.label + ': ' + item.parsed + ' (' + pct + '%)';
								}
							}
						}
					}
				}
			});
		}

		function mkLine(ctx, data) {
			var gradient = ctx.createLinearGradient(0, 0, 0, 260);
			gradient.addColorStop(0, hexToRgba(colorPalette[0], 0.28));
			gradient.addColorStop(1, hexToRgba(colorPalette[0], 0.02));
			return new Chart(ctx, {
				type: 'line',
				data: {
					labels: data.labels,
					datasets: [{
						label: itemsLabel,
						data: data.values,
						borderColor: colorPalette[0],
						backgroundColor: gradient,
						fill: true,
						tension: 0.35,
						pointRadius: 0,
						pointHoverRadius: 5,
						pointBackgroundColor: colorPalette[0],
						borderWidth: 2
					}]
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					interaction: { intersect: false, mode: 'index' },
					plugins: {
						legend: { display: false }
					},
					scales: {
						x: { grid: { display: false } },
						y: { beginAtZero: true, ticks: { precision: 0 } }
					}
				}
			});
		}

		function mkBar(ctx, data, colorIndex) {
			var color = colorPalette[colorIndex % colorPalette.length];
			return new Chart(ctx, {
				type: 'bar',
				data: {
					labels: data.labels,
					datasets: [{
						data: data.values,
						backgroundColor: hexToRgba(color, 0.85),
						borderRadius: 4,
						maxBarThickness: 36
					}]
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: { legend: { display: false } },
					scales: {
						x: { grid: { display: false } },
						y: { beginAtZero: true, ticks: { precision: 0 } }
					}
				}
			});
		}

		var c1 = document.getElementById('whochangedChartActions');
		if (c1) {
			mkDoughnut(c1.getContext('2d'), actions);
		}
		var c2 = document.getElementById('whochangedChartUsers');
		if (c2) {
			mkDoughnut(c2.getContext('2d'), users);
		}
		var c3 = document.getElementById('whochangedChartDays');
		if (c3) {
			mkLine(c3.getContext('2d'), days);
		}
		var c4 = document.getElementById('whochangedChartObjects');
		if (c4) {
			mkBar(c4.getContext('2d'), objects, 0);
		}
		var c5 = document.getElementById('whochangedChartHours');
		if (c5) {
			mkBar(c5.getContext('2d'), hours, 4);
		}
		var c6 = document.getElementById('whochangedChartWeekdays');
		if (c6) {
			mkBar(c6.getContext('2d'), weekdays, 5);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
