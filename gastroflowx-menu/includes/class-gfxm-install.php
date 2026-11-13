<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aktywacja / migracje: tabele (dbDelta), rola „Menedżer menu”, domyślne
 * opcje oraz jednorazowy import dań z trzech wersji menu (kwiecień).
 */
class GFXM_Install {

	public static function dishes_table() {
		global $wpdb;
		return $wpdb->prefix . 'gfxm_dishes';
	}

	public static function versions_table() {
		global $wpdb;
		return $wpdb->prefix . 'gfxm_versions';
	}

	public static function activate() {
		self::create_tables();
		self::register_role();
		self::default_options();
		self::seed();
		try {
			self::import_default_logos();
		} catch ( \Throwable $e ) {
			error_log( 'GastroFlowx Menu: import logo: ' . $e->getMessage() ); // phpcs:ignore
		}
		update_option( 'gfxm_db_version', GFXM_DB_VERSION );
	}

	/**
	 * Migracja „na żywo” (np. podmiana plików bez ponownej aktywacji).
	 * Uruchamiana na 'init' (NIE na plugins_loaded) — import logo tworzy
	 * załączniki, a wp_insert_attachment() wymaga zainicjowanego $wp_rewrite.
	 * Całość w try/catch: błąd migracji nigdy nie może położyć witryny.
	 */
	public static function maybe_upgrade() {
		$from = get_option( 'gfxm_db_version' );
		if ( $from === GFXM_DB_VERSION ) {
			return;
		}
		// Wersję zapisujemy od razu — nawet przy błędzie migracja nie
		// będzie ponawiana przy każdym żądaniu.
		update_option( 'gfxm_db_version', GFXM_DB_VERSION );
		try {
			self::create_tables();
			self::register_role();
			self::default_options();
			if ( $from && version_compare( $from, '1.1.0', '<' ) ) {
				self::migrate_110();
			}
			if ( $from && version_compare( $from, '1.3.0', '<' ) ) {
				self::migrate_130();
			}
			if ( $from && version_compare( $from, '1.6.0', '<' ) ) {
				self::migrate_160();
			}
			self::import_default_logos();
		} catch ( \Throwable $e ) {
			error_log( 'GastroFlowx Menu: błąd migracji: ' . $e->getMessage() ); // phpcs:ignore
		}
	}

	/**
	 * 1.0.0 → 1.1.0: układ dwukolumnowy wg wzoru. Kategorie domyślne dostają
	 * nowe nazwy, podtytuły, kolumny i pozycje; własne kategorie — kolumny
	 * naprzemiennie. Układ strony wraca do wartości ze wzoru.
	 */
	public static function migrate_110() {
		$defaults = array();
		foreach ( self::default_categories() as $c ) {
			$defaults[ $c['id'] ] = $c;
		}
		$old_names = array( 'Na początek', 'Przystawki', 'Dania główne', 'Słodkie desery' );
		$cats      = get_option( 'gfxm_categories', array() );
		foreach ( (array) $cats as $i => $c ) {
			if ( isset( $defaults[ $c['id'] ] ) && in_array( $c['name_pl'], $old_names, true ) ) {
				$cats[ $i ] = $defaults[ $c['id'] ];
				continue;
			}
			$cats[ $i ] = array_merge( array( 'sub_pl' => '', 'sub_en' => '', 'column' => 0 === $i % 2 ? 'left' : 'right', 'align' => 'top' ), $c );
		}
		update_option( 'gfxm_categories', array_values( $cats ) );

		$s           = get_option( 'gfxm_settings', array() );
		$def         = self::default_settings();
		$s['layout'] = $def['layout'];
		foreach ( array( 'header', 'footer' ) as $k ) {
			$s[ $k ] = array_merge( $def[ $k ], array( 'id' => isset( $s[ $k ]['id'] ) ? (int) $s[ $k ]['id'] : 0 ) );
		}
		update_option( 'gfxm_settings', $s );
	}

	/**
	 * → 1.3.0: układ z prowadnic Canvy + wyrównanie dolnych kategorii do
	 * dolnej prowadnicy. Logo zostają (tylko nowe wymiary), kategorie
	 * dostają „align” zamiast „anchor_y”.
	 */
	public static function migrate_130() {
		$bottom = array( 'mains', 'desserts' );
		$cats   = get_option( 'gfxm_categories', array() );
		foreach ( (array) $cats as $i => $c ) {
			$cats[ $i ]['align'] = in_array( $c['id'], $bottom, true ) ? 'bottom' : 'top';
			unset( $cats[ $i ]['anchor_y'] );
		}
		update_option( 'gfxm_categories', array_values( (array) $cats ) );

		$s   = get_option( 'gfxm_settings', array() );
		$def = self::default_settings();
		$s['layout'] = $def['layout'];
		foreach ( array( 'header', 'footer' ) as $k ) {
			$s[ $k ] = array_merge( $def[ $k ], array( 'id' => isset( $s[ $k ]['id'] ) ? (int) $s[ $k ]['id'] : 0 ) );
		}
		update_option( 'gfxm_settings', $s );
	}

	/**
	 * → 1.6.0: nowy szablon (listopad) — podtytuły Sweet Belly Sans wersalikami,
	 * stawiane względem linii bazowej nazwy kategorii (przesunięcia per
	 * kategoria), nazwy kategorii Poppins Black 22 pt. Pozostałe ustawienia
	 * (prowadnice, logo, rozmiary dań) zostają bez zmian.
	 */
	public static function migrate_160() {
		$cats = get_option( 'gfxm_categories', array() );
		foreach ( (array) $cats as $i => $c ) {
			$cats[ $i ] = array_merge( $c, self::default_sub_offsets( isset( $c['id'] ) ? $c['id'] : '' ) );
			if ( isset( $c['id'] ) && 'mains' === $c['id'] && isset( $c['sub_pl'] ) && 'dania' === $c['sub_pl'] ) {
				$cats[ $i ]['sub_pl'] = 'autorskie';
			}
		}
		update_option( 'gfxm_categories', array_values( (array) $cats ) );

		$s = get_option( 'gfxm_settings', array() );
		if ( is_array( $s ) ) {
			$def         = self::default_settings();
			$s['layout'] = array_merge(
				isset( $s['layout'] ) && is_array( $s['layout'] ) ? $s['layout'] : array(),
				array_intersect_key( $def['layout'], array_flip( array( 'heading_weight', 'head_pt', 'head_track', 'sub_font', 'sub_upper', 'sub_pt', 'sub_track' ) ) )
			);
			update_option( 'gfxm_settings', $s );
		}
	}

	/**
	 * Położenie podtytułu względem nazwy kategorii (px na A4 794 × 1123),
	 * zmierzone z szablonu InDesign: sub_dx — od początku nazwy do początku
	 * podtytułu, sub_dy — od linii bazowej nazwy do linii bazowej podtytułu
	 * (ujemne = nad nazwą), sub_pt — własny rozmiar (0 = jak w Ustawieniach).
	 */
	public static function default_sub_offsets( $id ) {
		$map = array(
			'start'    => array( 'sub_dx' => 24.56, 'sub_dy' => -18.57, 'sub_pt' => 0 ),    // NA POCZĄTEK — nad „zupy”
			'mains'    => array( 'sub_dx' => 64.49, 'sub_dy' => 12.23, 'sub_pt' => 0 ),     // AUTORSKIE — pod „główne”
			'starters' => array( 'sub_dx' => 85.39, 'sub_dy' => 12.16, 'sub_pt' => 0 ),     // ORYGINALNE — pod „przystawki”
			'desserts' => array( 'sub_dx' => 74.47, 'sub_dy' => 13.84, 'sub_pt' => 11.1 ),  // DESERY — pod „słodkie”, większe
		);
		return isset( $map[ $id ] ) ? $map[ $id ] : array( 'sub_dx' => 40, 'sub_dy' => 12.2, 'sub_pt' => 0 );
	}

	/**
	 * Dołączone logo Weranda (assets/img) trafiają do biblioteki mediów i są
	 * ustawiane jako nagłówek/stopka — tylko gdy pole jest puste.
	 */
	public static function import_default_logos() {
		global $wp_rewrite;
		// Bezpiecznik: bez $wp_rewrite wp_insert_attachment() kończy się fatal errorem.
		if ( ! ( $wp_rewrite instanceof WP_Rewrite ) || get_option( 'gfxm_logos_imported' ) ) {
			return;
		}
		update_option( 'gfxm_logos_imported', 1 ); // tylko jedna próba
		$s = get_option( 'gfxm_settings', array() );
		if ( ! is_array( $s ) ) {
			return;
		}
		$map     = array( 'header' => 'logo-naglowek.png', 'footer' => 'logo-stopka.png' );
		$changed = false;
		foreach ( $map as $key => $file ) {
			if ( ! empty( $s[ $key ]['id'] ) ) {
				continue;
			}
			$id = self::sideload_asset( GFXM_DIR . 'assets/img/' . $file, 'header' === $key ? 'Weranda — logo nagłówka menu' : 'Weranda — logo stopki menu' );
			if ( $id ) {
				$s[ $key ]['id'] = $id;
				$changed         = true;
			}
		}
		if ( $changed ) {
			update_option( 'gfxm_settings', $s );
		}
	}

	private static function sideload_asset( $path, $title ) {
		if ( ! file_exists( $path ) ) {
			return 0;
		}
		$upload = wp_upload_bits( 'gfxm-' . basename( $path ), null, file_get_contents( $path ) ); // phpcs:ignore
		if ( ! empty( $upload['error'] ) ) {
			return 0;
		}
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => $title,
				'post_status'    => 'inherit',
			),
			$upload['file']
		);
		if ( ! $id || is_wp_error( $id ) ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		return (int) $id;
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$dishes  = self::dishes_table();
		$vers    = self::versions_table();

		dbDelta(
			"CREATE TABLE {$dishes} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				category_id VARCHAR(40) NOT NULL DEFAULT '',
				name_pl VARCHAR(255) NOT NULL DEFAULT '',
				name_en VARCHAR(255) NOT NULL DEFAULT '',
				desc_pl TEXT NULL,
				desc_en TEXT NULL,
				sort_order INT(11) NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY category_id (category_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$vers} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(190) NOT NULL DEFAULT '',
				lang VARCHAR(5) NOT NULL DEFAULT 'pl',
				items LONGTEXT NULL,
				extra_text TEXT NULL,
				extra_position VARCHAR(10) NOT NULL DEFAULT 'bottom',
				template VARCHAR(20) NOT NULL DEFAULT 'columns',
				created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				updated_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id)
			) {$charset};"
		);
	}

	/**
	 * Nowa rola „Menedżer menu” (slug: menu_manager). Tylko 'read' + nasze
	 * uprawnienie — wp-admin i tak jest zablokowany dla nie-administratorów.
	 * Administrator dostaje uprawnienie jawnie (choć ma dostęp zawsze).
	 */
	public static function register_role() {
		if ( ! get_role( 'menu_manager' ) ) {
			add_role(
				'menu_manager',
				'Menedżer menu',
				array(
					'read'   => true,
					GFXM_CAP => true,
				)
			);
		}
		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( GFXM_CAP ) ) {
			$admin->add_cap( GFXM_CAP );
		}
	}

	public static function default_categories() {
		// align: 'top' = układana od górnej prowadnicy (content_top) w dół,
		// 'bottom' = przyklejona do dolnej prowadnicy (content_bottom) — dzięki
		// temu ostatnie pozycje obu kolumn kończą się na tej samej linii.
		$cats = array(
			array( 'id' => 'start',    'name_pl' => 'Zupy',       'sub_pl' => 'na początek', 'name_en' => 'Soups',    'sub_en' => 'to begin',  'column' => 'left',  'align' => 'top' ),
			array( 'id' => 'starters', 'name_pl' => 'Przystawki', 'sub_pl' => 'oryginalne',  'name_en' => 'Starters', 'sub_en' => 'signature', 'column' => 'right', 'align' => 'top' ),
			array( 'id' => 'mains',    'name_pl' => 'Główne',     'sub_pl' => 'autorskie',   'name_en' => 'Mains',    'sub_en' => 'signature', 'column' => 'left',  'align' => 'bottom' ),
			array( 'id' => 'desserts', 'name_pl' => 'Słodkie',    'sub_pl' => 'desery',      'name_en' => 'Sweet',    'sub_en' => 'desserts',  'column' => 'right', 'align' => 'bottom' ),
		);
		foreach ( $cats as $i => $c ) {
			$cats[ $i ] = array_merge( $c, self::default_sub_offsets( $c['id'] ) );
		}
		return $cats;
	}

	public static function default_settings() {
		return array(
			// Logo nagłówka: prowadnice Canvy 56–186 → wysokość 130 px.
			'header' => array( 'id' => 0, 'url' => '', 'width' => 0, 'width_unit' => 'auto', 'height' => 130, 'height_unit' => 'px' ),
			// Logo stopki: prowadnice 255–540 → szerokość 285 px.
			'footer' => array( 'id' => 0, 'url' => '', 'width' => 285, 'width_unit' => 'px', 'height' => 0, 'height_unit' => 'auto' ),
			// Wszystko w px na A4 794 × 1123 — 1:1 z prowadnicami w Canvie.
			'layout' => array(
				'header_top'     => 56,   // górna krawędź logo nagłówka
				'content_top'    => 300,  // górna prowadnica treści
				'content_bottom' => 835,  // dolna prowadnica treści (tu kończą się kolumny)
				'footer_top'     => 934,  // górna krawędź logo stopki
				'left_x'         => 63,   // lewa kolumna: od
				'left_end'       => 373,  // lewa kolumna: do
				'right_x'        => 429,  // prawa kolumna: od
				'right_end'      => 718,  // prawa kolumna: do
				'heading_gap'    => 12,   // nagłówek kategorii → pierwsze danie
				'dish_gap'       => 6,    // odstęp między daniami
				'category_gap'   => 34,   // minimalny odstęp między kategoriami w kolumnie
				'fit_page'       => 1,    // zmniejsz treść (do 70%), gdy się nie mieści
				'heading_weight' => 'Black',
				'name_weight'    => 'Bold',   // Fabrikat Mono dla nazw dań: Bold albo Medium
				'head_pt'        => 22,   // Poppins Black — kategorie
				'sub_font'       => 'SweetBelly-Sans', // krój podtytułów (GFXM_PDF::sub_fonts())
				'sub_upper'      => 1,    // podtytuły wersalikami
				'sub_pt'         => 10.3, // Sweet Belly Sans — podtytuły
				'name_pt'        => 9,    // Fabrikat Mono Bold — nazwy dań
				'desc_pt'        => 9,    // Fabrikat Mono Regular — opisy
				'head_track'     => 0.05, // światło (em): nagłówki kategorii (szablon InDesign: 50)
				'sub_track'      => 0.14, // światło (em): podtytuły
				'text_track'     => 0,    // światło (em): nazwy i opisy dań — 0 daje łamanie wierszy jak we wzorze
			),
			// Szablon „Podzielona strona”: własne prowadnice i rozmiary logo
			// (obraz logo, czcionki, rozmiary pisma i światło — wspólne).
			'split'  => array(
				'header' => array( 'width' => 0, 'width_unit' => 'auto', 'height' => 130, 'height_unit' => 'px' ), // 35–165
				'footer' => array( 'width' => 0, 'width_unit' => 'auto', 'height' => 85, 'height_unit' => 'px' ),  // 995–1080
				'layout' => array(
					'header_top'     => 35,
					'content_top'    => 202,  // górna krawędź linii tekstu → litery „zupy” zaczynają się na prowadnicy 210
					'content_bottom' => 985,
					'footer_top'     => 995,
					'left_x'         => 38,   // lewa połówka: kolumna od
					'left_end'       => 368,  // lewa połówka: kolumna do
					'right_x'        => 437,  // prawa połówka: kolumna od (kopia lewej)
					'right_end'      => 767,
					'heading_gap'    => 6,    // pierwsze danie na prowadnicy 252
					'dish_gap'       => 12,
					'category_gap'   => 34,
					'cut_line'       => 1,    // cienka linia cięcia na środku strony
				),
			),
		);
	}

	public static function default_options() {
		if ( false === get_option( 'gfxm_categories' ) ) {
			update_option( 'gfxm_categories', self::default_categories() );
		}
		if ( false === get_option( 'gfxm_settings' ) ) {
			update_option( 'gfxm_settings', self::default_settings() );
		}
		if ( false === get_option( 'gfxm_roles' ) ) {
			$roles = array( 'menu_manager' );
			if ( get_role( 'manager' ) ) {
				$roles[] = 'manager';
			}
			update_option( 'gfxm_roles', $roles );
		}
	}

	/**
	 * Import dań z dostarczonych trzech wersji menu (kwiecień) + trzy gotowe
	 * „Wersje Standardowe”. Wykonywany tylko, gdy tabela dań jest pusta.
	 */
	public static function seed() {
		global $wpdb;
		$table = self::dishes_table();
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore
		if ( $count > 0 || get_option( 'gfxm_seeded' ) ) {
			return;
		}

		$dishes = array(
			// key, category, name_pl, desc_pl, name_en, desc_en
			array( 'krem', 'start', 'Krem pomidorowy', 'z mascarpone, pestkami dyni i oliwą bazyliowo-pietruszkową', 'Cream of tomato soup', 'with mascarpone, pumpkin seeds and basil-parsley oil' ),
			array( 'zupa', 'start', 'Zupa dnia', '', 'Soup of the day', '' ),

			array( 'krewetki', 'starters', 'Krewetki', 'w emulsji winno-maślanej z chorizo, pomidorkami koktajlowymi, smażonymi papryczkami padrón, cytryną, rukolą, natką pietruszki i pieczywem', 'Prawns', 'in a white wine and butter emulsion with chorizo, cherry tomatoes, fried padrón peppers, lemon, rocket, parsley and bread' ),
			array( 'tatar_wol', 'starters', 'Tatar wołowy', 'z żółtkiem, marynowanymi grzybami, kaparami, ogórkiem kiszonym, szalotką, marynowaną cebulką, majonezem truflowym, pieprzem młotkowanym, oliwą bazyliowo-pietruszkową', 'Beef tartare', 'with egg yolk, marinated mushrooms, capers, pickled cucumber, shallot, pickled onion, truffle mayonnaise, cracked pepper and basil-parsley oil' ),
			array( 'tapas', 'starters', 'Tapas (dla dwóch osób)', 'kompozycja serów z oliwkami, kaparami, winogronami, suszonymi pomidorami, pieczoną i marynowaną papryką, orzechami włoskimi, oliwą, miodem i pieczywem', 'Tapas (for two)', 'a selection of cheeses with olives, capers, grapes, sun-dried tomatoes, roasted and marinated peppers, walnuts, olive oil, honey and bread' ),
			array( 'mus', 'starters', 'Mus z pieczonej papryki, fety i orzechów włoskich', 'z labneh, oliwkami, winogronami, pomidorkami koktajlowymi, zielonymi pomidorami, marynowanymi papryczkami z natką pietruszki, cytryną i pitą', 'Roasted pepper, feta and walnut dip', 'with labneh, olives, grapes, cherry tomatoes, green tomatoes, marinated peppers, parsley, lemon and pita' ),
			array( 'placuszki', 'starters', 'Wytrawne placuszki twarogowe', 'z wędzonym łososiem, crème fraîche, ogórkiem, ziołami, pikantnym musem i kawiorem', 'Savoury quark pancakes', 'with smoked salmon, crème fraîche, cucumber, herbs, spicy mousse and caviar' ),
			array( 'tatar_los', 'starters', 'Tatar z łososia', 'z selerem naciowym, jabłkiem, ogórkiem, mandarynką, anchois, rukolą, kawiorem, oliwą, cytryną i pieczywem', 'Salmon tartare', 'with celery, apple, cucumber, mandarin, anchovies, rocket, caviar, olive oil, lemon and bread' ),

			array( 'riviera', 'mains', 'Sałata Weranda Riviera', 'z grillowanym kurczakiem, serem Korycińskim z kozieradką, glazurowanym ananasem, suszonymi pomidorami, oliwkami, winogronami, granatem, prażonymi migdałami, mieszanymi sałatami i dressingiem z nasionami maku', 'Weranda Riviera salad', 'with grilled chicken, Koryciński cheese with fenugreek, glazed pineapple, sun-dried tomatoes, olives, grapes, pomegranate, toasted almonds, mixed leaves and poppy seed dressing' ),
			array( 'golabki', 'mains', 'Gołąbki', 'zawijane w kapuście włoskiej z sosem pomidorowym, ryżem, dzikim brokułem i crème fraîche', 'Gołąbki — stuffed cabbage rolls', 'wrapped in savoy cabbage, with tomato sauce, rice, tenderstem broccoli and crème fraîche' ),
			array( 'losos', 'mains', 'Łosoś pieczony', 'z puree z białych warzyw, sosem porowym, dzikim brokułem, papryczkami padrón, sezamowymi chipsami z jarmużu i topinamburu, szczypiorkiem, prażonymi migdałami i kawiorem', 'Roasted salmon', 'with white root vegetable purée, leek sauce, tenderstem broccoli, padrón peppers, sesame kale and Jerusalem artichoke crisps, chives, toasted almonds and caviar' ),
			array( 'ravioli', 'mains', 'Ravioli z mięsem', 'z gorgonzolą, gruszką, orzechami włoskimi, porem, w kremowej emulsji maślanej z szałwią', 'Meat ravioli', 'with gorgonzola, pear, walnuts and leek in a creamy sage butter emulsion' ),
			array( 'boczniaki', 'mains', 'Boczniaki', 'z ciecierzycą w pomidorach i harissą, musem migdałowo-czosnkowym, rukolą, cebulą, kolendrą, sezamem i pitą', 'Oyster mushrooms', 'with chickpeas in tomato and harissa, almond-garlic mousse, rocket, onion, coriander, sesame and pita' ),
			array( 'wloska', 'mains', 'Sałata włoska z burratą i prosciutto crudo', 'smażonymi winogronami, oliwkami, prażonymi słonymi orzechami nerkowca, mieszanymi sałatami, rukolą, oliwą Extra Virgin i kremem balsamicznym', 'Italian salad with burrata and prosciutto crudo', 'with fried grapes, olives, toasted salted cashews, mixed leaves, rocket, extra virgin olive oil and balsamic cream' ),
			array( 'schabowy', 'mains', 'Kotlet schabowy', 'z puree ziemniaczanym, mizerią i koperkiem', 'Kotlet schabowy — breaded pork cutlet', 'with mashed potatoes, creamy cucumber salad and dill' ),
			array( 'tagliatelle', 'mains', 'Tagliatelle szpinakowe z krewetkami', 'z karczochami, pomidorkami koktajlowymi, cytryną, szalotką, czosnkiem, w emulsji winno-maślanej z natką pietruszki i pangrattato', 'Spinach tagliatelle with prawns', 'with artichokes, cherry tomatoes, lemon, shallot and garlic in a white wine and butter emulsion with parsley and pangrattato' ),
			array( 'dorada', 'mains', 'Dorada pieczona', 'z salsą z pomidorków koktajlowych i oliwek, z ziarnami wędzonej kukurydzy, rukolą, cytryną i pesto', 'Roasted sea bream', 'with cherry tomato and olive salsa, smoked corn kernels, rocket, lemon and pesto' ),
			array( 'kalifornijska', 'mains', 'Sałata kalifornijska z kurczakiem i camembertem', 'suszonymi pomidorami, grillowaną cukinią, ogórkiem, czerwoną cebulą, pestkami dyni, mieszanymi sałatami i chutney’em z rabarbaru i żurawiny', 'Californian salad with chicken and camembert', 'with sun-dried tomatoes, grilled courgette, cucumber, red onion, pumpkin seeds, mixed leaves and rhubarb-cranberry chutney' ),
			array( 'burger', 'mains', 'Burger wołowy', 'z cheddarem, boczkiem, pomidorem, ogórkiem kiszonym, czerwoną cebulą, białym sosem ogórkowym, sosem ostrygowym, sałatą i domowymi frytkami', 'Beef burger', 'with cheddar, bacon, tomato, pickled cucumber, red onion, white cucumber sauce, oyster sauce, lettuce and homemade fries' ),
			array( 'misa', 'mains', 'Misa grecka z halloumi', 'kompozycja kasz z warzywami, marynowanym burakiem i papryką, zielonymi pomidorami, wędzoną brzoskwinią, ogórkiem, prażonymi pestkami dyni, mieszanymi sałatami i śmietanowym sosem orzechowo-pietruszkowym', 'Greek bowl with halloumi', 'mixed grains with vegetables, marinated beetroot and peppers, green tomatoes, smoked peach, cucumber, toasted pumpkin seeds, mixed leaves and a creamy walnut-parsley sauce' ),

			array( 'szarlotka', 'desserts', 'Szarlotka', 'na ciepło z lodami, bitą śmietaną, sosem czekoladowym, wiórkami z czekolady, bakaliami i cynamonem', 'Apple pie', 'served warm with ice cream, whipped cream, chocolate sauce, chocolate shavings, dried fruit, nuts and cinnamon' ),
			array( 'sernik', 'desserts', 'Sernik baskijski', 'z musem kawowym i sosem amaretto', 'Basque cheesecake', 'with coffee mousse and amaretto sauce' ),
			array( 'rabarbar', 'desserts', 'Ciasto rabarbarowo-truskawkowe', 'z płatkami migdałów i sosem truskawkowym', 'Rhubarb and strawberry cake', 'with flaked almonds and strawberry sauce' ),
			array( 'ciasto', 'desserts', 'Ciasto dnia', '', 'Cake of the day', '' ),
		);

		$now = current_time( 'mysql' );
		$ids = array();
		foreach ( $dishes as $i => $d ) {
			$wpdb->insert(
				$table,
				array(
					'category_id' => $d[1],
					'name_pl'     => $d[2],
					'desc_pl'     => $d[3],
					'name_en'     => $d[4],
					'desc_en'     => $d[5],
					'sort_order'  => $i,
					'created_at'  => $now,
					'updated_at'  => $now,
				)
			);
			$ids[ $d[0] ] = (int) $wpdb->insert_id;
		}

		$pick = function ( $keys ) use ( $ids ) {
			return array_values( array_filter( array_map( function ( $k ) use ( $ids ) {
				return isset( $ids[ $k ] ) ? $ids[ $k ] : 0;
			}, $keys ) ) );
		};
		$desserts = array( 'szarlotka', 'sernik', 'rabarbar', 'ciasto' );
		$versions = array(
			'Kwiecień — wersja A' => array( array( 'krem', 'zupa' ), array( 'krewetki', 'tatar_wol' ), array( 'riviera', 'golabki', 'losos', 'ravioli' ) ),
			'Kwiecień — wersja B' => array( array( 'krem', 'zupa' ), array( 'tapas', 'mus' ), array( 'boczniaki', 'wloska', 'schabowy', 'tagliatelle' ) ),
			'Kwiecień — wersja C' => array( array( 'krem', 'zupa' ), array( 'placuszki', 'tatar_los' ), array( 'dorada', 'kalifornijska', 'burger', 'misa' ) ),
		);
		$user = get_current_user_id();
		foreach ( $versions as $name => $parts ) {
			$items = array(
				'start'    => $pick( $parts[0] ),
				'starters' => $pick( $parts[1] ),
				'mains'    => $pick( $parts[2] ),
				'desserts' => $pick( $desserts ),
			);
			$wpdb->insert(
				self::versions_table(),
				array(
					'name'           => $name,
					'lang'           => 'pl',
					'items'          => wp_json_encode( $items ),
					'extra_text'     => '',
					'extra_position' => 'bottom',
					'created_by'     => $user,
					'updated_by'     => $user,
					'created_at'     => $now,
					'updated_at'     => $now,
				)
			);
		}
		update_option( 'gfxm_seeded', 1 );
	}
}
