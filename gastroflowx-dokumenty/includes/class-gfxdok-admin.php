<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wp-admin: przypisanie ról (dostęp / zarządzanie), statystyki Archiwum,
 * gotowy shortcode do wklejenia w GastroFlowx → Moduły.
 *
 * Blokada wp-admin dla nie-administratorów: jeśli Hub (GFX_Access) jest
 * aktywny, to on już ją realizuje; w przeciwnym razie robi to ta wtyczka —
 * identyczną logiką (z wyjątkiem admin-ajax.php).
 */
class GFXDOK_Admin {

	const PAGE = 'gastroflowx-dokumenty';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_gfxdok_save_access', array( __CLASS__, 'handle_save' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( GFXDOK_PLUGIN_FILE ), array( __CLASS__, 'action_links' ) );

		add_action( 'plugins_loaded', function () {
			if ( ! class_exists( 'GFX_Access' ) ) {
				add_action( 'admin_init', array( __CLASS__, 'block_wp_admin' ) );
				add_filter( 'show_admin_bar', array( __CLASS__, 'hide_admin_bar' ) );
			}
		}, 20 );
	}

	public static function block_wp_admin() {
		if ( wp_doing_ajax() || current_user_can( 'administrator' ) ) {
			return;
		}
		// admin-post.php nie jest używane przez pracowników tego modułu.
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	public static function hide_admin_bar( $show ) {
		if ( is_user_logged_in() && ! current_user_can( 'administrator' ) ) {
			return false;
		}
		return $show;
	}

	protected static function parent_slug() {
		global $admin_page_hooks;
		return isset( $admin_page_hooks['gastroflowx'] ) ? 'gastroflowx' : 'options-general.php';
	}

	public static function page_url() {
		return 'gastroflowx' === self::parent_slug()
			? admin_url( 'admin.php?page=' . self::PAGE )
			: admin_url( 'options-general.php?page=' . self::PAGE );
	}

	public static function menu() {
		add_submenu_page(
			self::parent_slug(),
			__( 'Dokumenty', 'gastroflowx-dokumenty' ),
			__( 'Dokumenty', 'gastroflowx-dokumenty' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Ustawienia', 'gastroflowx-dokumenty' ) . '</a>' );
		return $links;
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'gastroflowx-dokumenty' ), 403 );
		}
		check_admin_referer( 'gfxdok_save_access' );

		$ra = isset( $_POST['roles_access'] ) ? (array) wp_unslash( $_POST['roles_access'] ) : array(); // phpcs:ignore
		$rm = isset( $_POST['roles_manage'] ) ? (array) wp_unslash( $_POST['roles_manage'] ) : array(); // phpcs:ignore
		$du = ! empty( $_POST['delete_on_uninstall'] );
		GFXDOK_Settings::save_access( $ra, $rm, $du );

		if ( isset( $_POST['archive_enabled_present'] ) ) {
			GFXDOK_Settings::update( array( 'archive_enabled' => ! empty( $_POST['archive_enabled'] ) ) );
		}

		wp_safe_redirect( add_query_arg( 'updated', '1', self::page_url() ) );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$roles    = GFXDOK_Settings::all_roles();
		$access   = GFXDOK_Settings::get_access();
		$settings = GFXDOK_Settings::get();
		$stats    = GFXDOK_DB::stats();
		$sc       = '[' . GFXDOK_SHORTCODE . ']';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Dokumenty GastroFlowX', 'gastroflowx-dokumenty' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Zapisano ustawienia.', 'gastroflowx-dokumenty' ); ?></p></div>
			<?php endif; ?>

			<div class="card" style="max-width:780px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Rejestracja w panelu (GastroFlowx → Moduły)', 'gastroflowx-dokumenty' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Shortcode', 'gastroflowx-dokumenty' ); ?></th>
						<td><input type="text" readonly class="regular-text code" value="<?php echo esc_attr( $sc ); ?>" onclick="this.select();"> <span class="description"><?php esc_html_e( 'w polu shortcode wpisz nazwę bez nawiasów:', 'gastroflowx-dokumenty' ); ?> <code><?php echo esc_html( GFXDOK_SHORTCODE ); ?></code></span></td></tr>
					<tr><th><?php esc_html_e( 'Sugerowana nazwa', 'gastroflowx-dokumenty' ); ?></th><td><code>Dokumenty</code></td></tr>
					<tr><th><?php esc_html_e( 'Sugerowana ikona', 'gastroflowx-dokumenty' ); ?></th><td><code>fa-file-invoice-dollar</code> <span class="description">(<?php esc_html_e( 'alternatywnie', 'gastroflowx-dokumenty' ); ?> <code>fa-receipt</code>)</span></td></tr>
				</table>
				<p class="description"><?php esc_html_e( 'Pamiętaj, aby w Hubie (Dostęp ról) zaznaczyć ten moduł tym samym rolom, którym nadajesz dostęp poniżej. Moduł zawsze sam sprawdza uprawnienia — Hub decyduje tylko o widoczności w menu.', 'gastroflowx-dokumenty' ); ?></p>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="gfxdok_save_access">
				<?php wp_nonce_field( 'gfxdok_save_access' ); ?>

				<h2><?php esc_html_e( 'Uprawnienia ról', 'gastroflowx-dokumenty' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Administrator ma zawsze pełny dostęp. „Korzysta” = tworzy raporty i przegląda Archiwum. „Zarządza” = dodatkowo zakładka Ustawienia na froncie (nazwa pliku, Archiwum, kompresja) i usuwanie dni.', 'gastroflowx-dokumenty' ); ?></p>
				<table class="widefat striped" style="max-width:780px;">
					<thead><tr><th><?php esc_html_e( 'Rola', 'gastroflowx-dokumenty' ); ?></th><th style="text-align:center;"><?php esc_html_e( 'Korzysta', 'gastroflowx-dokumenty' ); ?></th><th style="text-align:center;"><?php esc_html_e( 'Zarządza', 'gastroflowx-dokumenty' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $roles as $slug => $name ) :
						if ( 'administrator' === $slug ) {
							continue;
						} ?>
						<tr>
							<td><strong><?php echo esc_html( translate_user_role( $name ) ); ?></strong> <code><?php echo esc_html( $slug ); ?></code></td>
							<td style="text-align:center;"><input type="checkbox" name="roles_access[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $access['roles_access'], true ) ); ?>></td>
							<td style="text-align:center;"><input type="checkbox" name="roles_manage[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $access['roles_manage'], true ) ); ?>></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Archiwum', 'gastroflowx-dokumenty' ); ?></h2>
				<input type="hidden" name="archive_enabled_present" value="1">
				<label><input type="checkbox" name="archive_enabled" value="1" <?php checked( $settings['archive_enabled'] ); ?>> <?php esc_html_e( 'Zapisuj zdjęcia i wygenerowane PDF-y na serwerze (można też przełączyć na froncie w zakładce Ustawienia).', 'gastroflowx-dokumenty' ); ?></label>
				<p class="description">
					<?php
					printf(
						/* translators: 1: days, 2: images, 3: size */
						esc_html__( 'Stan archiwum: %1$d dni z PDF, %2$d zdjęć, łącznie %3$s.', 'gastroflowx-dokumenty' ),
						(int) $stats['days'],
						(int) $stats['images'],
						esc_html( size_format( $stats['bytes'] ? $stats['bytes'] : 0 ) )
					);
					?>
					<?php esc_html_e( 'Limit przesyłania plików na serwerze:', 'gastroflowx-dokumenty' ); ?> <strong><?php echo esc_html( size_format( wp_max_upload_size() ) ); ?></strong>.
				</p>

				<h2><?php esc_html_e( 'Odinstalowanie', 'gastroflowx-dokumenty' ); ?></h2>
				<label><input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( ! empty( $access['delete_on_uninstall'] ) ); ?>> <?php esc_html_e( 'Przy usuwaniu wtyczki skasuj tabele, ustawienia i WSZYSTKIE pliki Archiwum.', 'gastroflowx-dokumenty' ); ?></label>

				<?php submit_button( __( 'Zapisz ustawienia', 'gastroflowx-dokumenty' ) ); ?>
			</form>
		</div>
		<?php
	}
}
GFXDOK_Admin::init();
