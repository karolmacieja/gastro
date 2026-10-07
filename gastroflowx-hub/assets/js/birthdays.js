(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var gridEl = document.getElementById('gfx-bday-grid');
		if (!gridEl || typeof GFX_BIRTHDAYS === 'undefined') {
			return;
		}

		var titleEl = document.getElementById('gfx-bday-month-title');
		var prevBtn = document.getElementById('gfx-bday-prev');
		var nextBtn = document.getElementById('gfx-bday-next');

		var today = new Date();
		var todayY = today.getFullYear();
		var todayM = today.getMonth() + 1;
		var todayD = today.getDate();

		var state = { year: todayY, month: todayM };

		function findBirthdays(month, day) {
			return GFX_BIRTHDAYS.all.filter(function (b) {
				return b.month === month && b.day === day;
			});
		}

		function escapeHtml(str) {
			var div = document.createElement('div');
			div.textContent = str;
			return div.innerHTML;
		}

		function render() {
			titleEl.textContent = GFX_BIRTHDAYS.monthNames[state.month - 1] + ' ' + state.year;
			gridEl.innerHTML = '';

			var firstOfMonth = new Date(state.year, state.month - 1, 1);
			var startWeekday = (firstOfMonth.getDay() + 6) % 7; // Poniedziałek = 0
			var daysInMonth = new Date(state.year, state.month, 0).getDate();

			var i, cell;
			for (i = 0; i < startWeekday; i++) {
				cell = document.createElement('div');
				cell.className = 'gfx-bday-cell gfx-bday-cell-empty';
				gridEl.appendChild(cell);
			}

			for (var d = 1; d <= daysInMonth; d++) {
				var weekday = (startWeekday + d - 1) % 7;
				var isSunday = weekday === 6;
				var isToday = state.year === todayY && state.month === todayM && d === todayD;
				var bdays = findBirthdays(state.month, d);

				cell = document.createElement('div');
				cell.className = 'gfx-bday-cell'
					+ (isToday ? ' is-today' : '')
					+ (bdays.length ? ' has-bday' : '')
					+ (isSunday ? ' is-sunday' : '');

				var num = document.createElement('span');
				num.className = 'gfx-bday-num';
				num.textContent = d;
				cell.appendChild(num);

				if (isToday) {
					var tag = document.createElement('span');
					tag.className = 'gfx-bday-today-tag';
					tag.textContent = GFX_BIRTHDAYS.i18n.today;
					cell.appendChild(tag);
					var dot = document.createElement('span');
					dot.className = 'gfx-bday-today-dot';
					cell.appendChild(dot);
				} else if (bdays.length) {
					var icon = document.createElement('i');
					icon.className = 'fa-solid fa-cake-candles gfx-bday-icon';
					cell.appendChild(icon);

					var tip = document.createElement('div');
					tip.className = 'gfx-bday-tooltip';
					tip.innerHTML = bdays.map(function (b) { return escapeHtml(b.name); }).join('<br>');
					cell.appendChild(tip);
				}

				gridEl.appendChild(cell);
			}

			var remainder = (startWeekday + daysInMonth) % 7;
			if (remainder !== 0) {
				var trailing = 7 - remainder;
				for (i = 0; i < trailing; i++) {
					cell = document.createElement('div');
					cell.className = 'gfx-bday-cell gfx-bday-cell-empty';
					gridEl.appendChild(cell);
				}
			}
		}

		if (prevBtn) {
			prevBtn.addEventListener('click', function () {
				state.month--;
				if (state.month < 1) { state.month = 12; state.year--; }
				render();
			});
		}
		if (nextBtn) {
			nextBtn.addEventListener('click', function () {
				state.month++;
				if (state.month > 12) { state.month = 1; state.year++; }
				render();
			});
		}

		render();

		// Modal "Dodaj datę" — zapisuje własną datę urodzenia (to samo pole,
		// co "Moje konto" i wtyczka Napiwków).
		var addBtn = document.getElementById('gfx-bday-add-btn');
		var modal = document.getElementById('gfx-bday-modal');
		var closeBtn = document.getElementById('gfx-bday-modal-close');
		var form = document.getElementById('gfx-bday-modal-form');

		if (addBtn && modal) {
			addBtn.addEventListener('click', function () { modal.classList.add('is-open'); });
		}
		if (closeBtn && modal) {
			closeBtn.addEventListener('click', function () { modal.classList.remove('is-open'); });
			modal.addEventListener('click', function (e) {
				if (e.target === modal) modal.classList.remove('is-open');
			});
		}
		if (form) {
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				var msg = document.getElementById('gfx-bday-modal-msg');
				var date = document.getElementById('gfx-bday-modal-date').value;
				if (!date) return;

				fetch(GFX_DATA.restUrl + '/account', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': GFX_DATA.nonce },
					body: JSON.stringify({ birthdate: date })
				})
					.then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
					.then(function (res) {
						msg.style.display = 'block';
						if (res.ok && res.data.success) {
							msg.className = 'gfx-bday-modal-msg ok';
							msg.textContent = GFX_BIRTHDAYS.i18n.saved;
							setTimeout(function () { window.location.reload(); }, 600);
						} else {
							msg.className = 'gfx-bday-modal-msg err';
							msg.textContent = (res.data && res.data.message) || GFX_BIRTHDAYS.i18n.error;
						}
					})
					.catch(function () {
						msg.style.display = 'block';
						msg.className = 'gfx-bday-modal-msg err';
						msg.textContent = GFX_BIRTHDAYS.i18n.error;
					});
			});
		}
	});
})();
