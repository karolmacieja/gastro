<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Szablony treści i ikony dla KAŻDEGO powiadomienia push wychodzącego
 * z ekosystemu GastroFlowx.
 *
 * Jak to działa:
 * - Każde powiadomienie ma "typ" (klucz). Typy pochodzą z trzech źródeł:
 *   1) wbudowane w panel (urodziny, powitalne, test),
 *   2) zarejestrowane przez inne wtyczki filtrem `gfx_push_notification_types`
 *      (np. Napiwki),
 *   3) wykryte automatycznie przy pierwszej wysyłce przez `gfx_dispatch_push`
 *      (np. Grafik) - klucz powstaje z kategorii + podkategorii, dzięki czemu
 *      nawet wtyczka, która nic nie wie o szablonach, dostaje edytowalny wpis.
 * - Szablon to tytuł + treść z polami {…}. Dla typów zewnętrznych domyślny
 *   szablon to "{tytul}" / "{tresc}" = oryginalna treść bez zmian, więc nic
 *   się nie zmienia, dopóki admin sam czegoś nie edytuje.
 * - Ikona: ikona przekazana jawnie przez wtyczkę (`icon` w argumentach) >
 *   ikona ustawiona dla typu > domyślna ikona powiadomień z panelu > ikona
 *   zastępcza modułu (`fallback_icon`, np. ogólna ikona z ustawień Grafiku) >
 *   ikona aplikacji (PWA) > logo restauracji. Zasada: panel wygrywa, moduł
 *   uzupełnia luki.
 * - Ikonka paska stanu (Android, `badge`): domyślna z panelu > przekazana
 *   przez moduł (`fallback_badge`).
 */
class GFX_Push_Templates {

	const OPTION_TEMPLATES    = 'gfx_push_templates';
	const OPTION_SEEN         = 'gfx_push_seen_types';
	const OPTION_DEFAULT_ICON = 'gfx_push_default_icon_id';
	const OPTION_DEFAULT_BADGE = 'gfx_push_default_badge_id';
	const MAX_SEEN            = 100;

	/** Wspólne pola dostępne w każdym szablonie. */
	public static function common_vars() {
		return array(
			'{tytul}'             => __( 'oryginalny tytuł (przekazany przez moduł)', 'gastroflowx-hub' ),
			'{tresc}'             => __( 'oryginalna treść (przekazana przez moduł)', 'gastroflowx-hub' ),
			'{odbiorca}'          => __( 'imię i nazwisko odbiorcy', 'gastroflowx-hub' ),
			'{imie_odbiorcy}'     => __( 'imię odbiorcy', 'gastroflowx-hub' ),
			'{nazwa_restauracji}' => __( 'nazwa restauracji z Ustawień ogólnych', 'gastroflowx-hub' ),
		);
	}

	/**
	 * Typy wbudowane w panel.
	 */
	protected static function builtin_types() {
		return array(
			'birthday_wish' => array(
				'label'           => __( 'Urodziny — życzenia dla solenizanta', 'gastroflowx-hub' ),
				'category'        => __( 'Urodziny', 'gastroflowx-hub' ),
				'subcategory'     => __( 'Życzenia', 'gastroflowx-hub' ),
				'default_title'   => '🎂 Wszystkiego najlepszego!',
				'default_message' => 'Wszystkiego najlepszego, {solenizant}! Niech ten dzień będzie wyjątkowy 🎉',
				'vars'            => array( '{solenizant}' => __( 'imię i nazwisko osoby obchodzącej urodziny', 'gastroflowx-hub' ) ),
				'sample_vars'     => array( 'solenizant' => 'Anna Kowalska' ),
			),
			'birthday_team' => array(
				'label'           => __( 'Urodziny — ogłoszenie dla zespołu', 'gastroflowx-hub' ),
				'category'        => __( 'Urodziny', 'gastroflowx-hub' ),
				'subcategory'     => __( 'Ogłoszenie', 'gastroflowx-hub' ),
				'default_title'   => '🎉 Urodziny w zespole!',
				'default_message' => '{solenizant} obchodzi dziś urodziny! Nie zapomnij złożyć życzeń 🎂',
				'vars'            => array( '{solenizant}' => __( 'imię i nazwisko osoby obchodzącej urodziny', 'gastroflowx-hub' ) ),
				'sample_vars'     => array( 'solenizant' => 'Anna Kowalska' ),
			),
			'welcome'       => array(
				'label'           => __( 'Powitalne — po pierwszej zgodzie na urządzeniu', 'gastroflowx-hub' ),
				'category'        => __( 'Powitalne', 'gastroflowx-hub' ),
				'subcategory'     => null,
				// Zgodność wstecz: wcześniejsze wersje trzymały treść w Integracjach.
				'default_title'   => get_option( 'gfx_fcm_welcome_title', '' ) ? get_option( 'gfx_fcm_welcome_title' ) : '🔔 Powiadomienia włączone!',
				'default_message' => get_option( 'gfx_fcm_welcome_message', '' ) ? get_option( 'gfx_fcm_welcome_message' ) : 'Od teraz będziesz otrzymywać powiadomienia z GastroFlowx na tym urządzeniu.',
				'vars'            => array(),
			),
			'test'          => array(
				'label'           => __( 'Test — przycisk w Integracjach', 'gastroflowx-hub' ),
				'category'        => __( 'Test', 'gastroflowx-hub' ),
				'subcategory'     => null,
				'default_title'   => get_option( 'gfx_fcm_test_title', '' ) ? get_option( 'gfx_fcm_test_title' ) : '🔔 Test GastroFlowx',
				'default_message' => get_option( 'gfx_fcm_test_message', '' ) ? get_option( 'gfx_fcm_test_message' ) : 'To jest testowe powiadomienie z panelu GastroFlowx. Jeśli je widzisz, integracja z FCM działa poprawnie.',
				'vars'            => array(),
			),
		);
	}

	/**
	 * Pełna lista typów (wbudowane + zarejestrowane + wykryte).
	 *
	 * Wtyczki mogą dopisać własne typy:
	 *   add_filter( 'gfx_push_notification_types', function ( $types ) {
	 *       $types['grafik_zmiana'] = array(
	 *           'label' => 'Grafik — zmiana w grafiku', 'category' => 'Grafik',
	 *           'default_title' => '{tytul}', 'default_message' => '{tresc}',
	 *           'vars' => array( '{data}' => 'data zmiany' ),
	 *       );
	 *       return $types;
	 *   } );
	 * i przekazać 'type' => 'grafik_zmiana' (+ opcjonalnie 'vars') do `gfx_dispatch_push`.
	 */
	public static function types() {
		$types = self::builtin_types();

		$seen = get_option( self::OPTION_SEEN, array() );
		if ( is_array( $seen ) ) {
			foreach ( $seen as $key => $info ) {
				if ( isset( $types[ $key ] ) ) {
					continue;
				}
				$label   = $info['category'] . ( ! empty( $info['subcategory'] ) ? ' — ' . $info['subcategory'] : '' );
				$types[ $key ] = array(
					'label'       => $label,
					'category'    => $info['category'],
					'subcategory' => $info['subcategory'] ?? null,
					'discovered'  => true,
				);
			}
		}

		$types = (array) apply_filters( 'gfx_push_notification_types', $types );

		foreach ( $types as $key => &$t ) {
			$t = wp_parse_args(
				$t,
				array(
					'label'              => $key,
					'category'           => __( 'Inne', 'gastroflowx-hub' ),
					'subcategory'        => null,
					'default_title'      => '{tytul}',
					'default_message'    => '{tresc}',
					'vars'               => array(),
					'sample_vars'        => array(),
					'content_managed_by' => '', // Jeśli ustawione - treść edytuje się w innym miejscu (np. moduł Napiwki).
					'fallback_icon'      => '', // Ikona modułu - do podglądu i testu w panelu.
					'fallback_badge'     => '',
					'test_url'           => null,
					'discovered'         => false,
				)
			);
		}
		unset( $t );

		return $types;
	}

	/**
	 * Klucz typu dla argumentów `gfx_dispatch_push`.
	 */
	public static function resolve_key( array $args ) {
		if ( ! empty( $args['type'] ) ) {
			return sanitize_key( $args['type'] );
		}
		$cat = (string) ( $args['category'] ?? '' );
		$sub = (string) ( $args['subcategory'] ?? '' );
		$key = sanitize_key( remove_accents( $cat . ( '' !== $sub ? '_' . $sub : '' ) ) );
		return $key ? 'auto_' . $key : 'auto_inne';
	}

	/**
	 * Zapamiętuje typ, który przeszedł przez wysyłkę, żeby pojawił się w
	 * panelu nawet wtedy, gdy wtyczka go jawnie nie zarejestrowała.
	 */
	public static function remember( $key, array $args ) {
		$types = self::types();
		if ( isset( $types[ $key ] ) && empty( $types[ $key ]['discovered'] ) ) {
			return; // Znany, zarejestrowany typ - nie ma czego zapisywać.
		}
		$seen = get_option( self::OPTION_SEEN, array() );
		if ( ! is_array( $seen ) ) {
			$seen = array();
		}
		if ( isset( $seen[ $key ] ) ) {
			return;
		}
		if ( count( $seen ) >= self::MAX_SEEN ) {
			return;
		}
		$seen[ $key ] = array(
			'category'    => sanitize_text_field( (string) ( $args['category'] ?? __( 'Inne', 'gastroflowx-hub' ) ) ),
			'subcategory' => isset( $args['subcategory'] ) ? sanitize_text_field( (string) $args['subcategory'] ) : null,
		);
		update_option( self::OPTION_SEEN, $seen, false );
	}

	public static function forget( $key ) {
		$seen = get_option( self::OPTION_SEEN, array() );
		if ( is_array( $seen ) && isset( $seen[ $key ] ) ) {
			unset( $seen[ $key ] );
			update_option( self::OPTION_SEEN, $seen, false );
		}
		$stored = self::stored();
		if ( isset( $stored[ $key ] ) ) {
			unset( $stored[ $key ] );
			update_option( self::OPTION_TEMPLATES, $stored, false );
		}
	}

	public static function stored() {
		$stored = get_option( self::OPTION_TEMPLATES, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Aktualny (zapisany lub domyślny) szablon typu.
	 *
	 * @return array{title:string,message:string,icon_id:int,custom:bool}
	 */
	public static function get( $key ) {
		$types  = self::types();
		$type   = $types[ $key ] ?? wp_parse_args( array(), array( 'default_title' => '{tytul}', 'default_message' => '{tresc}' ) );
		$stored = self::stored();
		$s      = $stored[ $key ] ?? array();

		$has_custom_text = isset( $s['title'] ) && '' !== $s['title'] && isset( $s['message'] ) && '' !== $s['message'];

		return array(
			'title'   => $has_custom_text ? (string) $s['title'] : (string) $type['default_title'],
			'message' => $has_custom_text ? (string) $s['message'] : (string) $type['default_message'],
			'icon_id' => isset( $s['icon_id'] ) ? absint( $s['icon_id'] ) : 0,
			'custom'  => $has_custom_text,
		);
	}

	public static function save( $key, $title, $message, $icon_id, $reset_text = false ) {
		$stored = self::stored();
		$entry  = array( 'icon_id' => absint( $icon_id ) );
		if ( ! $reset_text ) {
			$types   = self::types();
			$default = $types[ $key ] ?? array( 'default_title' => '', 'default_message' => '' );
			$title   = sanitize_text_field( $title );
			$message = sanitize_textarea_field( $message );
			// Zapisujemy treść tylko wtedy, gdy RÓŻNI się od domyślnej - dzięki
			// temu zmiana domyślnych tekstów w kolejnych wersjach wtyczki
			// dotrze do instalacji, na których nikt ich nie edytował.
			if ( $title !== $default['default_title'] || $message !== $default['default_message'] ) {
				$entry['title']   = $title;
				$entry['message'] = $message;
			}
		}
		$stored[ $key ] = $entry;
		update_option( self::OPTION_TEMPLATES, $stored, false );
	}

	/**
	 * Składa ostateczny tytuł i treść dla jednego odbiorcy.
	 *
	 * @param string $key       Klucz typu.
	 * @param string $orig_title Tytuł przekazany przez moduł.
	 * @param string $orig_msg   Treść przekazana przez moduł.
	 * @param array  $vars       Pola specyficzne dla typu (bez klamer).
	 * @param int    $user_id    Odbiorca (0 = brak).
	 * @return array{title:string,message:string}
	 */
	public static function render( $key, $orig_title, $orig_msg, array $vars = array(), $user_id = 0 ) {
		$types = self::types();
		$tpl   = self::get( $key );

		// Treść edytowana w innym miejscu (np. w module Napiwki) - panel
		// nie nadpisuje jej, wysyła dokładnie to, co przyszło.
		if ( isset( $types[ $key ] ) && ! empty( $types[ $key ]['content_managed_by'] ) ) {
			return array( 'title' => (string) $orig_title, 'message' => (string) $orig_msg );
		}

		$user       = $user_id ? get_userdata( $user_id ) : null;
		$first_name = $user ? trim( (string) $user->first_name ) : '';

		$replace = array(
			'{tytul}'             => (string) $orig_title,
			'{tresc}'             => (string) $orig_msg,
			'{odbiorca}'          => $user ? $user->display_name : '',
			'{imie_odbiorcy}'     => $first_name ? $first_name : ( $user ? $user->display_name : '' ),
			'{nazwa_restauracji}' => (string) get_option( 'gfx_restaurant_name', 'GastroFlowx' ),
		);
		foreach ( $vars as $k => $v ) {
			if ( is_scalar( $v ) ) {
				$replace[ '{' . trim( (string) $k, '{}' ) . '}' ] = (string) $v;
			}
		}

		$title   = strtr( $tpl['title'], $replace );
		$message = strtr( $tpl['message'], $replace );

		// Bezpiecznik: pusty wynik (np. ktoś zostawił samo "{cos}" bez danych)
		// zastępujemy oryginałem, żeby nie wysłać pustego powiadomienia.
		if ( '' === trim( $title ) ) {
			$title = (string) $orig_title;
		}
		if ( '' === trim( $message ) ) {
			$message = (string) $orig_msg;
		}

		return array( 'title' => $title, 'message' => $message );
	}

	/**
	 * Adres URL ikony powiadomienia.
	 */
	public static function icon_url( $key, $explicit = '', $module_fallback = '' ) {
		if ( $explicit ) {
			if ( is_numeric( $explicit ) ) {
				$url = self::attachment_url( (int) $explicit );
				if ( $url ) {
					return $url;
				}
			} else {
				return esc_url_raw( $explicit );
			}
		}

		$tpl = self::get( $key );
		if ( $tpl['icon_id'] ) {
			$url = self::attachment_url( $tpl['icon_id'] );
			if ( $url ) {
				return $url;
			}
		}

		$custom_default = self::custom_default_icon_url();
		if ( $custom_default ) {
			return $custom_default;
		}
		if ( $module_fallback ) {
			return esc_url_raw( $module_fallback );
		}
		return self::system_fallback_icon_url();
	}

	/** Domyślna ikona wybrana ręcznie w panelu ('' = nie wybrano). */
	public static function custom_default_icon_url() {
		$default_id = absint( get_option( self::OPTION_DEFAULT_ICON, 0 ) );
		return $default_id ? self::attachment_url( $default_id ) : '';
	}

	/**
	 * Ikonka paska stanu Androida (mała, jednokolorowa sylwetka).
	 */
	public static function badge_url( $module_fallback = '' ) {
		$id = absint( get_option( self::OPTION_DEFAULT_BADGE, 0 ) );
		if ( $id ) {
			$url = self::attachment_url( $id );
			if ( $url ) {
				return $url;
			}
		}
		return $module_fallback ? esc_url_raw( $module_fallback ) : '';
	}

	public static function default_icon_url() {
		$default_id = absint( get_option( self::OPTION_DEFAULT_ICON, 0 ) );
		if ( $default_id ) {
			$url = self::attachment_url( $default_id );
			if ( $url ) {
				return $url;
			}
		}
		return self::system_fallback_icon_url();
	}

	/**
	 * Ikona używana, gdy admin nie wybrał żadnej: ikona aplikacji (PWA) -
	 * najczęściej i tak kwadratowa i czytelna - a w ostateczności logo.
	 */
	public static function system_fallback_icon_url() {
		$pwa_png = get_option( 'gfx_pwa_icon_png_url', '' );
		if ( $pwa_png ) {
			return $pwa_png;
		}
		$pwa_id = absint( get_option( 'gfx_pwa_icon_id', 0 ) );
		if ( $pwa_id ) {
			$url = self::attachment_url( $pwa_id );
			if ( $url ) {
				return $url;
			}
		}
		$logo_id = absint( get_option( 'gfx_logo_id', 0 ) );
		return $logo_id ? (string) self::attachment_url( $logo_id ) : '';
	}

	/**
	 * URL obrazka w rozsądnym rozmiarze (ikona powiadomienia to ok. 192 px -
	 * nie ma sensu kazać telefonowi ściągać oryginału 4000 px).
	 */
	public static function attachment_url( $attachment_id ) {
		if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
			return '';
		}
		$src = wp_get_attachment_image_src( $attachment_id, array( 256, 256 ) );
		if ( ! $src ) {
			$src = wp_get_attachment_image_src( $attachment_id, 'full' );
		}
		return $src ? (string) $src[0] : '';
	}
}
