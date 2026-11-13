<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wp-admin (tylko administrator): które role mają dostęp do modułu Menu
 * oraz ściąga z danymi do rejestracji modułu w GastroFlowx → Moduły.
 * Cała codzienna praca (dania, wersje, logo, PDF) odbywa się na froncie.
 */
class GFXM_Admin {

	const SLUG = 'gastroflowx-menu';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_gfxm_save_roles', array( __CLASS__, 'save' ) );
	}

	public static function menu() {
		global $admin_page_hooks;
		$parent = isset( $admin_page_hooks['gastroflowx'] ) ? 'gastroflowx' : 'options-general.php';
		add_submenu_page( $parent, 'Menu GastroFlowX', 'Menu (dostęp)', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'gastroflowx-menu' ) );
		}
		check_admin_referer( 'gfxm_save_roles' );
		$all   = array_keys( wp_roles()->get_names() );
		$roles = isset( $_POST['gfxm_roles'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['gfxm_roles'] ) ) : array();
		$roles = array_values( array_intersect( $roles, $all ) );
		update_option( 'gfxm_roles', $roles );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$names   = wp_roles()->get_names();
		$allowed = GFXM_Access::allowed_roles();
		?>
		<div class="wrap">
			<h1>Menu GastroFlowX</h1>
			<?php if ( ! empty( $_GET['updated'] ) ) : // phpcs:ignore ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Zapisano.', 'gastroflowx-menu' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Rejestracja w GastroFlowx → Moduły', 'gastroflowx-menu' ); ?></h2>
			<table class="widefat striped" style="max-width:720px">
				<tbody>
					<tr><th style="width:180px">Nazwa</th><td><code>Menu</code></td></tr>
					<tr><th>Shortcode</th><td><code>[<?php echo esc_html( GFXM_SHORTCODE ); ?>]</code></td></tr>
					<tr><th>Ikona (Font Awesome)</th><td><code>fa-book-open</code></td></tr>
					<tr><th>REST namespace</th><td><code><?php echo esc_html( GFXM_REST_NS ); ?></code></td></tr>
				</tbody>
			</table>

			<h2 style="margin-top:28px"><?php esc_html_e( 'Role z dostępem do danych modułu', 'gastroflowx-menu' ); ?></h2>
			<p><?php esc_html_e( 'Administrator ma dostęp zawsze. Widoczność zakładki w panelu ustawiasz dodatkowo w macierzy „Dostęp ról” Huba — obie rzeczy muszą się zgadzać.', 'gastroflowx-menu' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="gfxm_save_roles">
				<?php wp_nonce_field( 'gfxm_save_roles' ); ?>
				<fieldset>
					<?php foreach ( $names as $slug => $label ) :
						if ( 'administrator' === $slug ) {
							continue;
						}
						?>
						<label style="display:block;margin:6px 0">
							<input type="checkbox" name="gfxm_roles[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $allowed, true ) ); ?>>
							<?php echo esc_html( translate_user_role( $label ) ); ?> <code><?php echo esc_html( $slug ); ?></code>
						</label>
					<?php endforeach; ?>
				</fieldset>
				<?php submit_button( __( 'Zapisz dostęp', 'gastroflowx-menu' ) ); ?>
			</form>
		</div>
		<?php
	}
}
