<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integracja logowania huba z uwierzytelnianiem dwuskładnikowym Wordfence
 * (moduł Wordfence Login Security — w pełnej wtyczce Wordfence albo
 * samodzielnej „Wordfence Login Security”).
 *
 * Zasada: kod jest wymagany tylko od użytkowników, którzy mają 2FA włączone
 * w Wordfence. Weryfikację wykonuje Wordfence (pole `wfls-token` w żądaniu,
 * jak w jego formularzu na wp-login.php). Jeżeli Wordfence nie sprawdza kodu
 * przy logowaniu przez REST, hub weryfikuje kod sam funkcją Wordfence.
 * Gdy nie da się zweryfikować kodu — logowanie jest blokowane (nigdy nie
 * wpuszczamy konta z 2FA bez poprawnego kodu).
 */
class GFX_Two_Factor {

	/** Czy aktywny jest moduł Wordfence Login Security. */
	public static function available() {
		return class_exists( '\WordfenceLS\Controller_Users' );
	}

	/** Czy użytkownik ma włączone 2FA w Wordfence. */
	public static function user_has_2fa( $user ) {
		if ( ! $user instanceof WP_User || ! self::available() ) {
			return false;
		}
		try {
			$ctl = \WordfenceLS\Controller_Users::shared();
			if ( method_exists( $ctl, 'has_2fa_active' ) ) {
				return (bool) $ctl->has_2fa_active( $user );
			}
		} catch ( \Throwable $e ) {
			error_log( 'GastroFlowx 2FA: ' . $e->getMessage() ); // phpcs:ignore
		}
		return false;
	}

	/** Czy da się samodzielnie zweryfikować kod funkcją Wordfence. */
	public static function can_validate() {
		return class_exists( '\WordfenceLS\Controller_TOTP' ) && method_exists( '\WordfenceLS\Controller_TOTP', 'shared' )
			&& method_exists( \WordfenceLS\Controller_TOTP::shared(), 'validate_2fa' );
	}

	/** Weryfikacja kodu przez Wordfence (TOTP z aplikacji). */
	public static function validate( $user, $code ) {
		if ( ! self::can_validate() ) {
			return null; // nie wiadomo — wywołujący blokuje logowanie
		}
		try {
			return (bool) \WordfenceLS\Controller_TOTP::shared()->validate_2fa( $user, $code );
		} catch ( \Throwable $e ) {
			error_log( 'GastroFlowx 2FA: ' . $e->getMessage() ); // phpcs:ignore
			return false;
		}
	}

	/** Błąd z Wordfence Login Security (kody „wfls_…”). */
	public static function is_wfls_error( $err ) {
		return is_wp_error( $err ) && 0 === strpos( (string) $err->get_error_code(), 'wfls_' );
	}

	/** Błąd oznaczający „podaj kod” (a nie „zły kod”). */
	public static function is_code_required_error( $err ) {
		if ( ! self::is_wfls_error( $err ) ) {
			return false;
		}
		$code = (string) $err->get_error_code();
		return false !== strpos( $code, 'twofactor' ) && false !== strpos( $code, 'required' );
	}

	/** Ustawia kod tak, jak wysyła go formularz Wordfence na wp-login.php. */
	public static function set_request_token( $code ) {
		if ( null === $code ) {
			unset( $_POST['wfls-token'], $_REQUEST['wfls-token'] ); // phpcs:ignore
			return;
		}
		$_POST['wfls-token']    = $code; // phpcs:ignore
		$_REQUEST['wfls-token'] = $code; // phpcs:ignore
	}

	/** Klucz pamięci kroku 1 (czy Wordfence sam egzekwuje kod przy tym logowaniu). */
	public static function state_key( $user ) {
		return 'gfx_2fa_' . md5( $user->ID . '|' . wp_salt( 'auth' ) );
	}

	/** Treść błędu Wordfence bez HTML (np. „<strong>BŁĄD</strong>: …”). */
	public static function plain_message( $err ) {
		$msg = trim( wp_strip_all_tags( $err->get_error_message() ) );
		return $msg ? $msg : __( 'Nie udało się zalogować.', 'gastroflowx-hub' );
	}
}
