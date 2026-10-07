(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		initLogin();
		initConsentGate();
		initApp();
	});

	function initLogin() {
		var form = document.getElementById('gfx-login-form');
		if (!form) return;

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var btn = document.getElementById('gfx-login-submit');
			var err = document.getElementById('gfx-login-error');
			err.style.display = 'none';
			btn.disabled = true;
			btn.textContent = '...';

			var payload = {
				login: document.getElementById('gfx-login-username').value,
				password: document.getElementById('gfx-login-password').value,
				remember: document.getElementById('gfx-login-remember').checked
			};

			fetch(GFX_DATA.restUrl + '/login', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': GFX_DATA.nonce },
				body: JSON.stringify(payload)
			})
				.then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
				.then(function (res) {
					if (res.ok && res.data.success) {
						window.location.reload();
					} else {
						showLoginError(res.data && res.data.message ? res.data.message : GFX_DATA.i18n.loginError);
					}
				})
				.catch(function () {
					showLoginError(GFX_DATA.i18n.loginError);
				});

			function showLoginError(msg) {
				err.textContent = msg;
				err.style.display = 'block';
				btn.disabled = false;
				btn.textContent = 'Zaloguj się';
			}
		});
	}

	function initApp() {
		var app = document.getElementById('gfx-app');
		if (!app) return;

		var navBtns = app.querySelectorAll('.gfx-nav-btn, .gfx-bottom-btn[data-tab], .gfx-more-item');

		navBtns.forEach(function (btn) {
			btn.addEventListener('click', function () {
				closeMore();
				switchTab(btn.getAttribute('data-tab'));
			});
		});

		// Panel „Więcej” (telefon): moduły, które nie zmieściły się na dolnym pasku.
		var moreBtn = document.getElementById('gfx-more-btn');
		var moreSheet = document.getElementById('gfx-more-sheet');
		var moreBackdrop = document.getElementById('gfx-more-backdrop');
		var moreTabs = moreBtn ? (moreBtn.getAttribute('data-more-tabs') || '').split(',') : [];

		function openMore() {
			if (!moreSheet) return;
			moreSheet.hidden = false;
			moreBackdrop.hidden = false;
			// klatka opóźnienia, żeby zadziałała animacja wysuwania
			window.requestAnimationFrame(function () {
				moreSheet.classList.add('is-open');
				moreBackdrop.classList.add('is-open');
			});
			moreBtn.setAttribute('aria-expanded', 'true');
			document.documentElement.classList.add('gfx-more-open');
			var first = moreSheet.querySelector('.gfx-more-item');
			if (first) first.focus({ preventScroll: true });
		}

		function closeMore() {
			if (!moreSheet || moreSheet.hidden) return;
			moreSheet.classList.remove('is-open');
			moreBackdrop.classList.remove('is-open');
			moreBtn.setAttribute('aria-expanded', 'false');
			document.documentElement.classList.remove('gfx-more-open');
			setTimeout(function () {
				if (!moreSheet.classList.contains('is-open')) {
					moreSheet.hidden = true;
					moreBackdrop.hidden = true;
				}
			}, 250);
		}

		if (moreBtn && moreSheet) {
			moreBtn.addEventListener('click', function () {
				if (moreSheet.hidden) openMore(); else closeMore();
			});
			moreBackdrop.addEventListener('click', closeMore);
			document.getElementById('gfx-more-close').addEventListener('click', closeMore);
			document.addEventListener('keydown', function (e) {
				if (e.key === 'Escape') closeMore();
			});
			// Gest: przeciągnięcie panelu w dół zamyka go.
			var touchStartY = null;
			moreSheet.addEventListener('touchstart', function (e) { touchStartY = e.touches[0].clientY; }, { passive: true });
			moreSheet.addEventListener('touchend', function (e) {
				if (touchStartY !== null && e.changedTouches[0].clientY - touchStartY > 60) closeMore();
				touchStartY = null;
			}, { passive: true });
		}

		function markMore(tabId) {
			if (moreBtn) moreBtn.classList.toggle('is-active', moreTabs.indexOf(tabId) !== -1);
		}

		function switchTab(tabId) {
			var view = document.getElementById('gfx-view-' + tabId);
			if (!view) return;

			app.querySelectorAll('.gfx-view').forEach(function (v) { v.classList.remove('is-active'); });
			view.classList.add('is-active');

			navBtns.forEach(function (b) {
				b.classList.toggle('is-active', b.getAttribute('data-tab') === tabId);
			});
			markMore(tabId);

			var titleBtn = app.querySelector('.gfx-nav-btn[data-tab="' + tabId + '"] span, .gfx-bottom-btn[data-tab="' + tabId + '"] span');
			var titleEl = document.getElementById('gfx-current-title');
			if (titleBtn && titleEl) {
				titleEl.textContent = titleBtn.textContent;
			}

			// Ikona w nagłówku: prezent na zakładce Urodziny, sztućce gdzie indziej
			// (tylko gdy nie ma wgranego własnego logo restauracji).
			var brandIcon = document.querySelector('.gfx-brand-icon:not(.has-logo) i');
			if (brandIcon) {
				brandIcon.className = tabId === 'birthdays' ? 'fa-solid fa-gift' : 'fa-solid fa-utensils';
			}

			window.scrollTo({ top: 0, behavior: 'smooth' });
		}

		// Ustaw aktywny stan na starcie
		var defaultTab = app.getAttribute('data-default-tab');
		if (defaultTab) {
			navBtns.forEach(function (b) {
				b.classList.toggle('is-active', b.getAttribute('data-tab') === defaultTab);
			});
			markMore(defaultTab);
		}

		// Dolna nawigacja (mobile): sticky, ale chowa się przy scrollu w dół
		// (a zwłaszcza tuż przy końcu strony, żeby nie zasłaniać ostatnich
		// elementów) i wraca przy scrollu w górę.
		var bottomNav = document.getElementById('gfx-bottom-nav');
		if (bottomNav) {
			var lastScrollY = window.scrollY;
			var ticking = false;

			window.addEventListener('scroll', function () {
				if (ticking) return;
				ticking = true;
				window.requestAnimationFrame(function () {
					var currentY = Math.max(window.scrollY, 0);
					var doc = document.documentElement;
					var atBottom = ( window.innerHeight + currentY ) >= ( doc.scrollHeight - 4 );
					var scrollingDown = currentY > lastScrollY + 4;
					var scrollingUp = currentY < lastScrollY - 4;

					if (document.documentElement.classList.contains('gfx-more-open')) {
						// panel „Więcej” otwarty — pasek zostaje na miejscu
					} else if (atBottom || scrollingDown) {
						bottomNav.classList.add('gfx-hide');
					} else if (scrollingUp || currentY <= 0) {
						bottomNav.classList.remove('gfx-hide');
					}

					lastScrollY = currentY;
					ticking = false;
				});
			}, { passive: true });
		}

		// Wylogowanie (przycisk w sidebarze ORAZ przycisk w widoku "Moje konto")
		var logoutBtns = app.querySelectorAll('.gfx-logout');
		logoutBtns.forEach(function (btn) {
			btn.addEventListener('click', function () {
				logoutBtns.forEach(function (b) { b.disabled = true; });
				fetch(GFX_DATA.restUrl + '/logout', {
					method: 'POST',
					headers: { 'X-WP-Nonce': GFX_DATA.nonce }
				}).finally(function () {
					window.location.reload();
				});
			});
		});

		// Zdjęcie profilowe
		var avatarEditBtn = document.getElementById('gfx-avatar-edit');
		var avatarChangeLink = document.getElementById('gfx-avatar-change-link');
		var avatarInput = document.getElementById('gfx-avatar-input');
		if (avatarInput && (avatarEditBtn || avatarChangeLink)) {
			var openPicker = function () { avatarInput.click(); };
			if (avatarEditBtn) avatarEditBtn.addEventListener('click', openPicker);
			if (avatarChangeLink) avatarChangeLink.addEventListener('click', openPicker);

			avatarInput.addEventListener('change', function () {
				if (!avatarInput.files || !avatarInput.files[0]) return;
				var msg = document.getElementById('gfx-avatar-msg');
				var wrap = document.getElementById('gfx-avatar-wrap');
				var fd = new FormData();
				fd.append('file', avatarInput.files[0]);

				if (avatarEditBtn) avatarEditBtn.disabled = true;
				if (avatarChangeLink) avatarChangeLink.disabled = true;
				msg.style.display = 'none';

				fetch(GFX_DATA.restUrl + '/avatar', {
					method: 'POST',
					headers: { 'X-WP-Nonce': GFX_DATA.nonce },
					body: fd
				})
					.then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
					.then(function (res) {
						if (avatarEditBtn) avatarEditBtn.disabled = false;
						if (avatarChangeLink) avatarChangeLink.disabled = false;
						msg.style.display = 'block';
						if (res.ok && res.data.success) {
							msg.className = 'gfx-avatar-msg ok';
							msg.textContent = GFX_DATA.i18n.saved;
							var placeholder = document.getElementById('gfx-avatar-placeholder');
							var existingImg = document.getElementById('gfx-avatar-preview');
							if (existingImg) {
								existingImg.src = res.data.url;
							} else {
								var img = document.createElement('img');
								img.id = 'gfx-avatar-preview';
								img.className = 'gfx-avatar-img';
								img.src = res.data.url;
								if (placeholder) placeholder.replaceWith(img);
								else wrap.insertBefore(img, wrap.firstChild);
							}
						} else {
							msg.className = 'gfx-avatar-msg err';
							msg.textContent = (res.data && res.data.message) || 'Błąd przesyłania.';
						}
					})
					.catch(function () {
						if (avatarEditBtn) avatarEditBtn.disabled = false;
						if (avatarChangeLink) avatarChangeLink.disabled = false;
						msg.style.display = 'block';
						msg.className = 'gfx-avatar-msg err';
						msg.textContent = 'Błąd przesyłania.';
					});
			});
		}

		// Aktualizacja konta
		var accountForm = document.getElementById('gfx-account-form');
		if (accountForm) {
			accountForm.addEventListener('submit', function (e) {
				e.preventDefault();
				var msg = document.getElementById('gfx-account-msg');
				var fd = new FormData(accountForm);
				var payload = {};
				fd.forEach(function (v, k) { payload[k] = v; });

				fetch(GFX_DATA.restUrl + '/account', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': GFX_DATA.nonce },
					body: JSON.stringify(payload)
				})
					.then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
					.then(function (res) {
						msg.style.display = 'block';
						if (res.ok && res.data.success) {
							msg.className = 'gfx-account-msg ok';
							msg.textContent = GFX_DATA.i18n.saved;
						} else {
							msg.className = 'gfx-account-msg err';
							msg.textContent = (res.data && res.data.message) || 'Błąd zapisu.';
						}
					});
			});
		}
		// Wycofanie zgody na przetwarzanie danych (zakładka "Moje konto")
		var withdrawBtn = document.getElementById('gfx-consent-withdraw-btn');
		if (withdrawBtn) {
			withdrawBtn.addEventListener('click', function () {
				var msg = document.getElementById('gfx-consent-withdraw-msg');
				withdrawBtn.disabled = true;
				fetch(GFX_DATA.restUrl + '/consent/withdraw', {
					method: 'POST',
					headers: { 'X-WP-Nonce': GFX_DATA.nonce }
				}).then(function () {
					if (msg) {
						msg.style.display = 'block';
						msg.textContent = 'Zgoda wycofana — za chwilę zostaniesz przekierowany/a.';
					}
					setTimeout(function () { window.location.reload(); }, 1200);
				});
			});
		}
	}

	function initConsentGate() {
		var agreeBtn = document.getElementById('gfx-consent-agree');
		var declineBtn = document.getElementById('gfx-consent-decline');
		var declinedMsg = document.getElementById('gfx-consent-declined');
		if (!agreeBtn) return;

		agreeBtn.addEventListener('click', function () {
			agreeBtn.disabled = true;
			agreeBtn.textContent = '...';
			fetch(GFX_DATA.restUrl + '/consent/agree', {
				method: 'POST',
				headers: { 'X-WP-Nonce': GFX_DATA.nonce }
			}).then(function () {
				window.location.reload();
			});
		});

		if (declineBtn) {
			declineBtn.addEventListener('click', function () {
				if (declinedMsg) declinedMsg.style.display = 'block';
			});
		}

		// Przycisk wyloguj na ekranie zgody — poza #gfx-app, więc obsługujemy
		// go tutaj osobno (initApp() wiąże .gfx-logout tylko wewnątrz #gfx-app).
		var logoutBtn = document.querySelector('.gfx-consent-card .gfx-logout');
		if (logoutBtn) {
			logoutBtn.addEventListener('click', function () {
				logoutBtn.disabled = true;
				fetch(GFX_DATA.restUrl + '/logout', {
					method: 'POST',
					headers: { 'X-WP-Nonce': GFX_DATA.nonce }
				}).finally(function () {
					window.location.reload();
				});
			});
		}
	}
})();
