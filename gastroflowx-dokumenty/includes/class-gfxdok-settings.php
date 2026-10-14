<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ustawienia modułu i logika uprawnień.
 *
 * - `gfxdok_settings` — ustawienia edytowalne z frontu (zakładka "Ustawienia",
 *   widoczna tylko dla ról zarządzających): wzorzec nazwy pliku, Archiwum,
 *   parametry kompresji i układu strony PDF.
 * - `gfxdok_access`   — przypisanie ról (wyłącznie wp-admin, administrator):
 *   kto korzysta z modułu, kto nim zarządza.
 */
class GFXDOK_Settings {

	const OPTION        = 'gfxdok_settings';
	const ACCESS_OPTION = 'gfxdok_access';

	public static function defaults() {
		return array(
			'file_pattern'     => 'raport_dobowy_{data}',
			'archive_enabled'  => false,
			'max_width'        => 1200,
			'quality'          => 0.7,
			'margin_mm'        => 10,
			'auto_orientation' => false,
			'page_footer'      => true,
		);
	}

	public static function access_defaults() {
		return array(
			'roles_access'        => array(),
			'roles_manage'        => array(),
			'delete_on_uninstall' => false,
		);
	}

	/** Wywoływane przy aktywacji — nie nadpisuje istniejących opcji. */
	public static function install_defaults() {
		if ( false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, self::defaults() );
		}
		if ( false === get_option( self::ACCESS_OPTION ) ) {
			// Na start: role kierownicze, jeśli istnieją w systemie. Resztę
			// administrator zaznacza w wp-admin → GastroFlowx → Dokumenty.
			$existing = array_keys( self::all_roles() );
			$manage   = array_values( array_intersect( array( 'manager', 'kierownik' ), $existing ) );
			add_option( self::ACCESS_OPTION, array(
				'roles_access'        => $manage,
				'roles_manage'        => $manage,
				'delete_on_uninstall' => false,
			) );
		}
	}

	public static function get() {
		$saved = get_option( self::OPTION, array() );
		$s     = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		$s['file_pattern']     = (string) $s['file_pattern'];
		$s['archive_enabled']  = (bool) $s['archive_enabled'];
		$s['auto_orientation'] = (bool) $s['auto_orientation'];
		$s['page_footer']      = (bool) $s['page_footer'];
		$s['max_width']        = (int) $s['max_width'];
		$s['quality']          = (float) $s['quality'];
		$s['margin_mm']        = (int) $s['margin_mm'];
		return $s;
	}

	public static function archive_enabled() {
		$s = self::get();
		return ! empty( $s['archive_enabled'] );
	}

	/** Walidacja i zapis ustawień przysłanych z frontu. */
	public static function update( $input ) {
		$out = self::get();

		if ( isset( $input['file_pattern'] ) ) {
			$p = sanitize_text_field( (string) $input['file_pattern'] );
			$p = preg_replace( '#[\\\\/:*?"<>|]+#u', '_', $p );
			$p = preg_replace( '/\.pdf$/i', '', trim( $p ) );
			$p = function_exists( 'mb_substr' ) ? mb_substr( $p, 0, 120 ) : substr( $p, 0, 120 );
			$out['file_pattern'] = '' !== $p ? $p : self::defaults()['file_pattern'];
		}
		if ( isset( $input['archive_enabled'] ) ) {
			$out['archive_enabled'] = rest_sanitize_boolean( $input['archive_enabled'] );
		}
		if ( isset( $input['max_width'] ) ) {
			$out['max_width'] = min( 2400, max( 600, (int) $input['max_width'] ) );
		}
		if ( isset( $input['quality'] ) ) {
			$out['quality'] = round( min( 0.95, max( 0.4, (float) $input['quality'] ) ), 2 );
		}
		if ( isset( $input['margin_mm'] ) ) {
			$out['margin_mm'] = min( 30, max( 0, (int) $input['margin_mm'] ) );
		}
		if ( isset( $input['auto_orientation'] ) ) {
			$out['auto_orientation'] = rest_sanitize_boolean( $input['auto_orientation'] );
		}
		if ( isset( $input['page_footer'] ) ) {
			$out['page_footer'] = rest_sanitize_boolean( $input['page_footer'] );
		}

		update_option( self::OPTION, $out );
		return self::get();
	}

	/* ---------------------------------------------------------------------
	 * Uprawnienia
	 * ------------------------------------------------------------------- */

	public static function get_access() {
		$saved = get_option( self::ACCESS_OPTION, array() );
		$a     = wp_parse_args( is_array( $saved ) ? $saved : array(), self::access_defaults() );
		$a['roles_access'] = array_values( array_filter( (array) $a['roles_access'], 'is_string' ) );
		$a['roles_manage'] = array_values( array_filter( (array) $a['roles_manage'], 'is_string' ) );
		return $a;
	}

	public static function save_access( $roles_access, $roles_manage, $delete_on_uninstall ) {
		$valid = array_keys( self::all_roles() );
		$ra    = array_values( array_intersect( array_map( 'sanitize_key', (array) $roles_access ), $valid ) );
		$rm    = array_values( array_intersect( array_map( 'sanitize_key', (array) $roles_manage ), $valid ) );
		// Rola zarządzająca zawsze ma też dostęp do modułu.
		$ra = array_values( array_unique( array_merge( $ra, $rm ) ) );
		update_option( self::ACCESS_OPTION, array(
			'roles_access'        => $ra,
			'roles_manage'        => $rm,
			'delete_on_uninstall' => (bool) $delete_on_uninstall,
		) );
	}

	public static function all_roles() {
		if ( ! function_exists( 'wp_roles' ) ) {
			return array();
		}
		return wp_roles()->get_names();
	}

	protected static function resolve_user( $user ) {
		return ( $user instanceof WP_User ) ? $user : wp_get_current_user();
	}

	/** Czy użytkownik może korzystać z modułu (tworzyć raporty, przeglądać Archiwum). */
	public static function user_can_access( $user = null ) {
		$user = self::resolve_user( $user );
		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			return true;
		}
		$a   = self::get_access();
		$can = (bool) array_intersect( (array) $user->roles, array_merge( $a['roles_access'], $a['roles_manage'] ) );
		return (bool) apply_filters( 'gfxdok_user_can_access', $can, $user );
	}

	/** Czy użytkownik może zmieniać ustawienia i usuwać dni / całe Archiwum. */
	public static function user_can_manage( $user = null ) {
		$user = self::resolve_user( $user );
		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			return true;
		}
		$a   = self::get_access();
		$can = (bool) array_intersect( (array) $user->roles, $a['roles_manage'] );
		return (bool) apply_filters( 'gfxdok_user_can_manage', $can, $user );
	}
}
