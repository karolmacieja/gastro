/**
 * GastroFlowx — Push consent manager (Firebase Cloud Messaging).
 *
 * Inicjalizuje Firebase, prosi o zgodę na powiadomienia, pobiera token
 * rejestracyjny FCM i zgłasza go do REST API (`gfx/v1/push-consent`), żeby
 * ekran "Zgody użytkowników" i wysyłka mogły korzystać z REALNEGO stanu.
 * Obsługuje też powiadomienia w PIERWSZYM PLANIE (kiedy karta jest
 * aktywna) - FCM w przeciwieństwie do tła NIE wyświetla ich automatycznie.
 *
 * UWAGA na iOS (Safari): Notification.requestPermission() MUSI być
 * wywołane z bezpośredniego gestu użytkownika (kliknięcia/dotknięcia) -
 * wywołane automatycznie przy wczytaniu strony jest przez Safari po cichu
 * ignorowane (bez okienka, bez błędu, permission zostaje "default" na
 * zawsze). Dlatego: jeśli zgoda jest już podjęta (granted/denied), działamy
 * od razu bez pytania ponownie; jeśli jest "default", pokazujemy mały
 * przycisk i dopiero jego kliknięcie wywołuje requestPermission().
 *
 * UWAGA na iOS (Safari/PWA): getToken() bywa niestabilny bezpośrednio po
 * cold-starcie zainstalowanej aplikacji (Service Worker/IndexedDB nie są
 * jeszcze w pełni gotowe) - stąd retry poniżej, oraz WIDOCZNE logowanie
 * błędów (console.error, nie warn), żeby dało się to zdiagnozować.
 */
( function () {
	if ( typeof window.gfxPushConfig === 'undefined' || typeof firebase === 'undefined' ) {
		return;
	}

	function getDeviceId() {
		try {
			var id = window.localStorage.getItem( 'gfx_push_device_id' );
			if ( ! id ) {
				id = 'dev_' + Date.now().toString( 36 ) + '_' + Math.random().toString( 36 ).slice( 2, 10 );
				window.localStorage.setItem( 'gfx_push_device_id', id );
			}
			return id;
		} catch ( e ) {
			return 'dev_session_' + Date.now().toString( 36 );
		}
	}

	var deviceId = getDeviceId();

	function reportConsent( consent, token ) {
		try {
			fetch( window.gfxPushConfig.restUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': window.gfxPushConfig.nonce,
				},
				credentials: 'same-origin',
				body: JSON.stringify( {
					device_id: deviceId,
					consent: !! consent,
					token: token || null,
				} ),
			} )
				.then( function ( res ) {
					if ( ! res.ok ) {
						return res.text().then( function ( txt ) {
							console.error( '[GastroFlowx] Zgłoszenie zgody NIEUDANE, HTTP ' + res.status + ':', txt );
						} );
					}
					console.log( '[GastroFlowx] Zgoda zgłoszona poprawnie (consent=' + consent + ', device=' + deviceId + ').' );
				} )
				.catch( function ( err ) {
					console.error( '[GastroFlowx] Zgłoszenie zgody - błąd sieci:', err && err.message, err );
				} );
		} catch ( e ) {
			console.error( '[GastroFlowx] Zgłoszenie zgody - wyjątek:', e && e.message, e );
		}
	}

	function getTokenWithRetry( attempt ) {
		attempt = attempt || 1;
		// WAŻNE: nie przekazujemy tu `serviceWorkerRegistration` jawnie -
		// na Safari/iOS przekazanie referencji do rejestracji, która może
		// nie być jeszcze w pełni "aktywna", potrafi wywołać wewnętrzne
		// problemy w SDK. Bez tego parametru Firebase samo poprawnie
		// odnajduje service workera przez navigator.serviceWorker.ready.
		return firebase
			.messaging()
			.getToken( { vapidKey: window.gfxPushConfig.vapidKey } )
			.then( function ( token ) {
				if ( ! token ) {
					throw new Error( 'getToken() zwróciło pustą wartość' );
				}
				console.log( '[GastroFlowx] Token FCM pobrany automatycznie, zgłaszam do serwera...' );
				reportConsent( true, token );
			} )
			.catch( function ( err ) {
				console.error( '[GastroFlowx] FCM getToken() nieudane (próba ' + attempt + '):', err && err.message, err );
				if ( attempt < 3 ) {
					// iOS PWA po świeżej instalacji czasem potrzebuje chwili,
					// zanim Service Worker/IndexedDB są w pełni gotowe.
					return new Promise( function ( resolve ) {
						setTimeout( function () {
							resolve( getTokenWithRetry( attempt + 1 ) );
						}, 1500 * attempt );
					} );
				}
				// Wszystkie próby nieudane - zgłaszamy jawnie brak zgody
				// (zamiast zostawiać stary, nieaktualny wpis w bazie), żeby
				// ekran "Zgody użytkowników" pokazywał prawdziwy stan.
				reportConsent( false, null );
			} );
	}

	function requestPermissionAndToken() {
		return Notification.requestPermission().then( function ( permission ) {
			if ( 'granted' !== permission ) {
				reportConsent( false, null );
				return;
			}
			return getTokenWithRetry();
		} );
	}

	/**
	 * Mały, dyskretny przycisk w rogu ekranu - jedyny sposób, żeby na
	 * Safari/iOS w ogóle pokazać systemowe okienko zgody (wymaga gestu
	 * użytkownika). Znika po kliknięciu, niezależnie od wyniku.
	 */
	function showEnableButton() {
		if ( document.getElementById( 'gfx-push-enable-btn' ) ) {
			return;
		}
		var btn = document.createElement( 'button' );
		btn.id = 'gfx-push-enable-btn';
		btn.type = 'button';
		btn.textContent = '🔔 Włącz powiadomienia';
		btn.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:99999;'
			+ 'background:#1a73e8;color:#fff;border:none;border-radius:999px;'
			+ 'padding:12px 20px;font-size:14px;font-weight:600;'
			+ 'box-shadow:0 4px 12px rgba(0,0,0,.25);cursor:pointer;';
		btn.addEventListener( 'click', function () {
			btn.remove();
			requestPermissionAndToken();
		} );
		document.body.appendChild( btn );
	}

	/**
	 * Przycisk instalacji PWA (Chrome/Edge - desktop i Android). iOS Safari
	 * NIE wspiera zdarzenia 'beforeinstallprompt' w ogóle - tam instalacja
	 * jest wyłącznie przez Udostępnij → Dodaj do ekranu początkowego, więc
	 * ten przycisk się tam po prostu nigdy nie pojawi (co jest poprawne -
	 * nie ma czym go zastąpić). Umieszczony po lewej, żeby nie nachodził na
	 * przycisk zgody na powiadomienia (prawa strona).
	 */
	var deferredInstallPrompt = null;

	function showInstallButton() {
		if ( document.getElementById( 'gfx-pwa-install-btn' ) ) {
			return;
		}
		var btn = document.createElement( 'button' );
		btn.id = 'gfx-pwa-install-btn';
		btn.type = 'button';
		btn.textContent = '⬇️ Zainstaluj aplikację';
		btn.style.cssText = 'position:fixed;bottom:20px;left:20px;z-index:99999;'
			+ 'background:#1a1a1a;color:#fff;border:none;border-radius:999px;'
			+ 'padding:12px 20px;font-size:14px;font-weight:600;'
			+ 'box-shadow:0 4px 12px rgba(0,0,0,.25);cursor:pointer;';
		btn.addEventListener( 'click', function () {
			btn.remove();
			if ( deferredInstallPrompt ) {
				deferredInstallPrompt.prompt();
				deferredInstallPrompt.userChoice.finally( function () {
					deferredInstallPrompt = null;
				} );
			}
		} );
		document.body.appendChild( btn );
	}

	window.addEventListener( 'beforeinstallprompt', function ( e ) {
		e.preventDefault();
		deferredInstallPrompt = e;
		showInstallButton();
	} );
	window.addEventListener( 'appinstalled', function () {
		var btn = document.getElementById( 'gfx-pwa-install-btn' );
		if ( btn ) {
			btn.remove();
		}
		deferredInstallPrompt = null;
	} );

	try {
		if ( ! firebase.apps || ! firebase.apps.length ) {
			firebase.initializeApp( window.gfxPushConfig.firebaseConfig );
		}
		var messaging = firebase.messaging();

		if ( ! ( 'Notification' in window ) || ! navigator.serviceWorker ) {
			return; // Przeglądarka nie wspiera Web Push - aplikacja działa dalej bez tego kanału.
		}

		navigator.serviceWorker
			.register( '/firebase-messaging-sw.js' )
			.then( function () {
				// Czekamy, aż service worker będzie FAKTYCZNIE aktywny
				// (nie tylko zarejestrowany) - dopiero potem Firebase
				// jest w stanie go poprawnie znaleźć.
				return navigator.serviceWorker.ready;
			} )
			.then( function () {
				if ( 'denied' === Notification.permission ) {
					reportConsent( false, null );
					return;
				}
				if ( 'granted' === Notification.permission ) {
					// Zgoda już podjęta wcześniej - NIE wołamy
					// requestPermission() ponownie. Na Safari, wywołane
					// poza bezpośrednim gestem użytkownika, potrafi zwrócić
					// nieprawdziwy stan (np. "default") nawet gdy realnie
					// jest "granted" - stąd bezpośrednio pobieramy token,
					// dokładnie jak w ręcznym teście, który zadziałał.
					return getTokenWithRetry();
				}
				// 'default' - decyzja jeszcze niepodjęta. Na Safari/iOS
				// wywołanie tego automatycznie jest po cichu ignorowane -
				// potrzebny prawdziwy klik/dotknięcie.
				showEnableButton();
			} )
			.catch( function ( err ) {
				console.error( '[GastroFlowx] FCM init failed:', err && err.message, err );
			} );

		// Powiadomienia z pierwszego planu (karta aktywna) - FCM Web nie
		// pokazuje ich samo, trzeba obsłużyć ręcznie. Payload przychodzi
		// jako "data" (nie "notification"), właśnie żeby uniknąć
		// podwójnego wyświetlania - patrz komentarz w GFX_Push::send_to_user().
		messaging.onMessage( function ( payload ) {
			try {
				var data = payload.data || {};
				var title = data.title || 'GastroFlowx';
				var body = data.body || '';
				if ( Notification.permission === 'granted' ) {
					navigator.serviceWorker.ready.then( function ( registration ) {
						var options = { body: body, data: { link: data.link || '/' } };
						if ( data.icon ) {
							options.icon = data.icon;
						}
						if ( data.badge ) {
							options.badge = data.badge;
						}
						registration.showNotification( title, options );
					} );
				}
			} catch ( e ) {
				// Best-effort.
			}
		} );
	} catch ( e ) {
		console.error( '[GastroFlowx] FCM setup failed:', e && e.message, e );
	}
} )();
