<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GastroFlowx → Lista zgód — ekran w wp-admin z bieżącym statusem zgody na
 * przetwarzanie danych dla każdego pracownika, oraz eksportem do PDF (do
 * wykazania rozliczalności zgodnie z RODO).
 */
class GFX_Consent_Admin {

	const CAP = 'manage_options';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_gfx_consents_export_pdf', array( $this, 'export_pdf' ) );
	}

	public function add_menu() {
		add_submenu_page(
			'gastroflowx',
			__( 'Lista zgód', 'gastroflowx-hub' ),
			__( 'Lista zgód', 'gastroflowx-hub' ),
			self::CAP,
			'gastroflowx-consents',
			array( $this, 'render' )
		);
	}

	/**
	 * Wspólne dla ekranu w adminie i eksportu PDF: status + dane każdego
	 * użytkownika, którego dotyczy przynajmniej jedno zdarzenie zgody, plus
	 * wszyscy użytkownicy, którzy jeszcze NIC nie zarejestrowali (jeszcze nie
	 * widzieli ekranu zgody, bo np. nigdy się nie zalogowali do panelu) —
	 * ci pojawiają się ze statusem „Brak danych”.
	 */
	private function build_rows() {
		$statuses = GFX_Consent::all_current_statuses();
		$by_user  = array();
		foreach ( $statuses as $row ) {
			$by_user[ (int) $row->user_id ] = $row;
		}

		$rows = array();
		foreach ( get_users( array( 'fields' => array( 'ID', 'display_name', 'user_login' ) ) ) as $u ) {
			$user = get_userdata( $u->ID );
			$row  = isset( $by_user[ $u->ID ] ) ? $by_user[ $u->ID ] : null;

			$rows[] = array(
				'user_id' => $u->ID,
				'name'    => trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name,
				'login'   => $user->user_login,
				'role'    => class_exists( 'GFX_Modules' ) ? GFX_Modules::primary_role_label( $user ) : implode( ', ', $user->roles ),
				'status'  => $row ? $row->consent_action : 'none',
				'date'    => $row ? $row->created_at : '',
				'ip'      => $row ? $row->ip_address : '',
			);
		}

		// Najpierw ci bez zgody/z wycofaną — to najważniejsze do sprawdzenia.
		usort(
			$rows,
			function ( $a, $b ) {
				$rank = array( 'none' => 0, 'withdrawn' => 1, 'agreed' => 2 );
				return $rank[ $a['status'] ] <=> $rank[ $b['status'] ];
			}
		);

		return $rows;
	}

	private function status_label( $status ) {
		switch ( $status ) {
			case 'agreed':
				return __( 'Wyrażona', 'gastroflowx-hub' );
			case 'withdrawn':
				return __( 'Wycofana', 'gastroflowx-hub' );
			default:
				return __( 'Brak danych', 'gastroflowx-hub' );
		}
	}

	private function format_date( $mysql_datetime ) {
		if ( empty( $mysql_datetime ) ) {
			return '—';
		}
		return date_i18n( 'd.m.Y H:i', strtotime( $mysql_datetime ) );
	}

	public function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$rows       = $this->build_rows();
		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=gfx_consents_export_pdf' ), 'gfx_consents_export_pdf' );
		?>
		<div class="wrap gfx-wrap">
			<h1><?php esc_html_e( 'GastroFlowx — Lista zgód', 'gastroflowx-hub' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Bieżący status zgody na przetwarzanie danych osobowych (polityka prywatności) dla każdego konta w systemie.', 'gastroflowx-hub' ); ?></p>

			<p>
				<a href="<?php echo esc_url( $export_url ); ?>" class="button button-primary" target="_blank" rel="noopener">
					⬇ <?php esc_html_e( 'Generuj PDF', 'gastroflowx-hub' ); ?>
				</a>
			</p>

			<div class="gfx-card">
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Pracownik', 'gastroflowx-hub' ); ?></th>
							<th><?php esc_html_e( 'Login', 'gastroflowx-hub' ); ?></th>
							<th><?php esc_html_e( 'Rola', 'gastroflowx-hub' ); ?></th>
							<th><?php esc_html_e( 'Status zgody', 'gastroflowx-hub' ); ?></th>
							<th><?php esc_html_e( 'Data', 'gastroflowx-hub' ); ?></th>
							<th><?php esc_html_e( 'Adres IP', 'gastroflowx-hub' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td><?php echo esc_html( $r['name'] ); ?></td>
							<td><?php echo esc_html( $r['login'] ); ?></td>
							<td><?php echo esc_html( $r['role'] ); ?></td>
							<td>
								<span class="gfx-consent-badge gfx-consent-badge--<?php echo esc_attr( $r['status'] ); ?>">
									<?php echo esc_html( $this->status_label( $r['status'] ) ); ?>
								</span>
							</td>
							<td><?php echo esc_html( $this->format_date( $r['date'] ) ); ?></td>
							<td><?php echo esc_html( $r['ip'] ? $r['ip'] : '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<style>
			.gfx-consent-badge { display:inline-block; padding:3px 10px; border-radius:10px; font-size:11px; font-weight:600; }
			.gfx-consent-badge--agreed { background:#DCFCE7; color:#166534; }
			.gfx-consent-badge--withdrawn { background:#FEE2E2; color:#991B1B; }
			.gfx-consent-badge--none { background:#F3F4F6; color:#4B5563; }
		</style>
		<?php
	}

	public function export_pdf() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'gastroflowx-hub' ), 403 );
		}
		check_admin_referer( 'gfx_consents_export_pdf' );

		require_once GFX_PLUGIN_DIR . 'vendor/autoload.php';

		$rows          = $this->build_rows();
		$restaurant     = get_option( 'gfx_restaurant_name', 'GastroFlowx' );
		$generated_at   = date_i18n( 'd.m.Y H:i' );

		ob_start();
		?>
		<style>
			body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #1F2937; }
			h1 { color: #1D4ED8; font-size: 18px; margin-bottom: 2px; }
			.sub { color: #6B7280; font-size: 11px; margin-bottom: 16px; }
			table { width: 100%; border-collapse: collapse; }
			th, td { border: 1px solid #E5E7EB; padding: 5px 7px; text-align: left; }
			th { background: #EFF6FF; color: #1D4ED8; }
			.badge { padding: 2px 8px; border-radius: 8px; font-size: 10px; font-weight: bold; }
			.agreed { background:#DCFCE7; color:#166534; }
			.withdrawn { background:#FEE2E2; color:#991B1B; }
			.none { background:#F3F4F6; color:#4B5563; }
			.footer { margin-top: 18px; font-size: 9.5px; color: #9CA3AF; }
		</style>
		<h1><?php echo esc_html( $restaurant ); ?> — <?php esc_html_e( 'Lista zgód na przetwarzanie danych', 'gastroflowx-hub' ); ?></h1>
		<p class="sub"><?php printf( esc_html__( 'Wygenerowano: %s', 'gastroflowx-hub' ), esc_html( $generated_at ) ); ?></p>
		<table>
			<tr>
				<th><?php esc_html_e( 'Pracownik', 'gastroflowx-hub' ); ?></th>
				<th><?php esc_html_e( 'Login', 'gastroflowx-hub' ); ?></th>
				<th><?php esc_html_e( 'Rola', 'gastroflowx-hub' ); ?></th>
				<th><?php esc_html_e( 'Status', 'gastroflowx-hub' ); ?></th>
				<th><?php esc_html_e( 'Data', 'gastroflowx-hub' ); ?></th>
				<th><?php esc_html_e( 'Adres IP', 'gastroflowx-hub' ); ?></th>
			</tr>
			<?php foreach ( $rows as $r ) : ?>
				<tr>
					<td><?php echo esc_html( $r['name'] ); ?></td>
					<td><?php echo esc_html( $r['login'] ); ?></td>
					<td><?php echo esc_html( $r['role'] ); ?></td>
					<td><span class="badge <?php echo esc_attr( $r['status'] ); ?>"><?php echo esc_html( $this->status_label( $r['status'] ) ); ?></span></td>
					<td><?php echo esc_html( $this->format_date( $r['date'] ) ); ?></td>
					<td><?php echo esc_html( $r['ip'] ? $r['ip'] : '-' ); ?></td>
				</tr>
			<?php endforeach; ?>
		</table>
		<p class="footer"><?php esc_html_e( 'Dokument wygenerowany automatycznie z systemu GastroFlowx na podstawie historii zgód zapisanej w bazie danych.', 'gastroflowx-hub' ); ?></p>
		<?php
		$html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . ob_get_clean() . '</body></html>';

		$options = new \Dompdf\Options();
		$options->set( 'defaultFont', 'DejaVu Sans' );
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'isHtml5ParserEnabled', true );

		$dompdf = new \Dompdf\Dompdf( $options );
		$dompdf->loadHtml( $html );
		$dompdf->setPaper( 'A4', 'landscape' );
		$dompdf->render();

		if ( ob_get_length() ) {
			ob_clean();
		}
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="lista-zgod-' . gmdate( 'Y-m-d' ) . '.pdf"' );
		echo $dompdf->output(); // phpcs:ignore WordPress.Security.EscapeOutput -- binarny strumień PDF.
		exit;
	}
}
