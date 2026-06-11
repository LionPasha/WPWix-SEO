<?php
/**
 * Toplu işlemler: eksik tarama, toplu AI üretimi (durdur/devam),
 * skor dağılım panosu ve yeni ürün WP-Cron otomasyonu.
 *
 * Üretim AJAX adımlarıyla ürün ürün ilerler; durum wpwixseo_bulk_state
 * option'ında tutulur, böylece kaldığı yerden devam edilebilir.
 *
 * @package WPWix_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPWix_SEO_Bulk {

	const STATE_OPTION = 'wpwixseo_bulk_state';
	const CRON_HOOK    = 'wpwix_seo_auto_generate';

	public static function init() {
		// Yeni ürün otomasyonu frontend/cron isteklerinde de çalışmalı.
		add_action( 'transition_post_status', array( __CLASS__, 'maybe_schedule_auto_generate' ), 10, 3 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_generate' ) );

		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
		add_action( 'wp_ajax_wpwix_bulk_scan', array( __CLASS__, 'ajax_scan' ) );
		add_action( 'wp_ajax_wpwix_bulk_step', array( __CLASS__, 'ajax_step' ) );
		add_action( 'wp_ajax_wpwix_bulk_reset', array( __CLASS__, 'ajax_reset' ) );
	}

	public static function register_menu() {
		add_submenu_page(
			'wpwix-seo',
			__( 'Toplu İşlemler', 'wpwix-seo' ),
			__( 'Toplu İşlemler', 'wpwix-seo' ),
			WPWix_SEO_Admin::CAPABILITY,
			'wpwix-seo-bulk',
			array( __CLASS__, 'render_page' )
		);
	}

	/* ------------------------------------------------------------------
	 * Ekran
	 * ---------------------------------------------------------------- */

	public static function render_page() {
		if ( ! current_user_can( WPWix_SEO_Admin::CAPABILITY ) ) {
			return;
		}

		$dist   = self::get_score_distribution();
		$state  = self::get_state();
		$queued = count( $state['queue'] );
		$lowest = self::get_lowest_scored( 10 );
		?>
		<div class="wrap wpwix-wrap">
			<h1>WPWix SEO — <?php esc_html_e( 'Toplu İşlemler', 'wpwix-seo' ); ?></h1>

			<?php if ( ! WPWix_SEO_Gemini::is_configured() ) : ?>
				<div class="notice notice-warning"><p>
					<?php
					printf(
						/* translators: %s: settings page link */
						esc_html__( 'AI üretimi için önce %s sayfasından Gemini API key girin.', 'wpwix-seo' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=wpwix-seo' ) ) . '">' . esc_html__( 'Ayarlar', 'wpwix-seo' ) . '</a>'
					);
					?>
				</p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Skor Dağılımı', 'wpwix-seo' ); ?></h2>
			<div class="wpwix-dist">
				<span class="wpwix-dist-item">🟢 <strong><?php echo esc_html( $dist['green'] ); ?></strong> <?php esc_html_e( '(80+)', 'wpwix-seo' ); ?></span>
				<span class="wpwix-dist-item">🟡 <strong><?php echo esc_html( $dist['yellow'] ); ?></strong> <?php esc_html_e( '(50-79)', 'wpwix-seo' ); ?></span>
				<span class="wpwix-dist-item">🔴 <strong><?php echo esc_html( $dist['red'] ); ?></strong> <?php esc_html_e( '(<50)', 'wpwix-seo' ); ?></span>
				<span class="wpwix-dist-item">⚪ <strong><?php echo esc_html( $dist['unscored'] ); ?></strong> <?php esc_html_e( 'analiz edilmemiş', 'wpwix-seo' ); ?></span>
			</div>

			<h2><?php esc_html_e( 'Toplu AI Üretimi', 'wpwix-seo' ); ?></h2>
			<p>
				<button type="button" class="button" id="wpwix-bulk-scan"><?php esc_html_e( 'Eksikleri Tara', 'wpwix-seo' ); ?></button>
				<button type="button" class="button button-primary" id="wpwix-bulk-start" <?php disabled( 0 === $queued ); ?>>
					<?php echo esc_html( $state['done'] > 0 && $queued > 0 ? __( 'Devam Et', 'wpwix-seo' ) : __( 'Tümünü Üret', 'wpwix-seo' ) ); ?>
				</button>
				<button type="button" class="button" id="wpwix-bulk-stop" style="display:none"><?php esc_html_e( 'Durdur', 'wpwix-seo' ); ?></button>
			</p>
			<p id="wpwix-bulk-message" data-queued="<?php echo esc_attr( $queued ); ?>" data-total="<?php echo esc_attr( $state['total'] ); ?>" data-done="<?php echo esc_attr( $state['done'] ); ?>">
				<?php
				if ( $queued > 0 ) {
					printf(
						/* translators: 1: remaining, 2: total */
						esc_html__( 'Kuyrukta %1$d ürün bekliyor (toplam %2$d, işlenen %3$d). Devam edebilirsiniz.', 'wpwix-seo' ),
						(int) $queued,
						(int) $state['total'],
						(int) $state['done']
					);
				}
				?>
			</p>
			<div id="wpwix-bulk-progress" class="wpwix-progress" style="display:none">
				<div class="wpwix-progress-bar" id="wpwix-bulk-bar" style="width:0%"></div>
				<span class="wpwix-progress-text" id="wpwix-bulk-text"></span>
			</div>
			<div id="wpwix-bulk-report"></div>

			<h2><?php esc_html_e( 'En Düşük Skorlular', 'wpwix-seo' ); ?></h2>
			<?php if ( empty( $lowest ) ) : ?>
				<p><?php esc_html_e( 'Henüz analiz edilmiş ürün yok. Ürünler kaydedildikçe veya AI üretimi yapıldıkça skorlar oluşur.', 'wpwix-seo' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:700px">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Ürün', 'wpwix-seo' ); ?></th>
							<th><?php esc_html_e( 'Skor', 'wpwix-seo' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $lowest as $row ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( get_edit_post_link( $row->ID ) ); ?>"><?php echo esc_html( get_the_title( $row->ID ) ); ?></a></td>
								<td><span class="wpwix-score-badge <?php echo esc_attr( WPWix_SEO_Analyzer::get_color_class( (int) $row->score ) ); ?>"><?php echo esc_html( (int) $row->score ); ?></span></td>
								<td>
									<button type="button" class="button wpwix-generate" data-product="<?php echo esc_attr( $row->ID ); ?>">✨ <?php esc_html_e( 'AI ile Düzelt', 'wpwix-seo' ); ?></button>
									<span class="wpwix-generate-status"></span>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Veri
	 * ---------------------------------------------------------------- */

	/**
	 * Skor dağılımı: tek sorguyla yeşil/sarı/kırmızı sayıları.
	 *
	 * @return array{green:int,yellow:int,red:int,unscored:int}
	 */
	private static function get_score_distribution() {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin panosu, tek toplu sayım.
			"SELECT
				COALESCE( SUM( CASE WHEN pm.meta_value + 0 >= 80 THEN 1 ELSE 0 END ), 0 ) AS green,
				COALESCE( SUM( CASE WHEN pm.meta_value + 0 BETWEEN 50 AND 79 THEN 1 ELSE 0 END ), 0 ) AS yellow,
				COALESCE( SUM( CASE WHEN pm.meta_value + 0 < 50 THEN 1 ELSE 0 END ), 0 ) AS red,
				COUNT(*) AS scored
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = '_wpwixseo_score'
				AND p.post_type = 'product'
				AND p.post_status = 'publish'"
		);

		$total = (int) ( wp_count_posts( 'product' )->publish ?? 0 );

		return array(
			'green'    => (int) $row->green,
			'yellow'   => (int) $row->yellow,
			'red'      => (int) $row->red,
			'unscored' => max( 0, $total - (int) $row->scored ),
		);
	}

	/**
	 * En düşük skorlu ürünler.
	 *
	 * @param int $limit Satır sayısı.
	 * @return array<object{ID:int,score:string}>
	 */
	private static function get_lowest_scored( $limit ) {
		global $wpdb;

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin panosu.
			$wpdb->prepare(
				"SELECT p.ID, pm.meta_value AS score
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wpwixseo_score'
				WHERE p.post_type = 'product' AND p.post_status = 'publish'
				ORDER BY pm.meta_value + 0 ASC
				LIMIT %d",
				$limit
			)
		);
	}

	/**
	 * @return array{queue:int[],total:int,done:int,success:int,errors:array<int,string>}
	 */
	private static function get_state() {
		$state = get_option( self::STATE_OPTION, array() );

		return wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'queue'   => array(),
				'total'   => 0,
				'done'    => 0,
				'success' => 0,
				'errors'  => array(),
			)
		);
	}

	/**
	 * @param array $state Yeni durum.
	 */
	private static function save_state( $state ) {
		if ( false === get_option( self::STATE_OPTION ) ) {
			add_option( self::STATE_OPTION, $state, '', 'no' );
		} else {
			update_option( self::STATE_OPTION, $state );
		}
	}

	/* ------------------------------------------------------------------
	 * AJAX
	 * ---------------------------------------------------------------- */

	private static function verify_request() {
		check_ajax_referer( 'wpwix_seo_admin', 'nonce' );

		if ( ! current_user_can( WPWix_SEO_Admin::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Yetkiniz yok.', 'wpwix-seo' ) ) );
		}
	}

	/**
	 * SEO title veya description meta'sı eksik ürünleri bulup kuyruğa alır.
	 */
	public static function ajax_scan() {
		self::verify_request();

		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery -- tek seferlik tarama, admin isteğiyle.
					'relation' => 'OR',
					array(
						'key'     => '_wpwixseo_title',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => '_wpwixseo_desc',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		self::save_state(
			array(
				'queue'   => array_map( 'intval', $ids ),
				'total'   => count( $ids ),
				'done'    => 0,
				'success' => 0,
				'errors'  => array(),
			)
		);

		wp_send_json_success(
			array(
				'count'   => count( $ids ),
				/* translators: %d: product count */
				'message' => sprintf( __( '%d üründe SEO bilgisi eksik.', 'wpwix-seo' ), count( $ids ) ),
			)
		);
	}

	/**
	 * Kuyruktan tek ürün işler. JS, ayarlardaki istek aralığı kadar bekleyip
	 * tekrar çağırır; hata alan ürün atlanır ve rapora eklenir.
	 */
	public static function ajax_step() {
		self::verify_request();

		$state = self::get_state();

		if ( empty( $state['queue'] ) ) {
			wp_send_json_success(
				array(
					'finished' => true,
					'done'     => $state['done'],
					'total'    => $state['total'],
					'success'  => $state['success'],
					'errors'   => $state['errors'],
				)
			);
		}

		$product_id = (int) array_shift( $state['queue'] );
		$data       = WPWix_SEO_Gemini::generate_for_product( $product_id );

		if ( is_wp_error( $data ) ) {
			$state['errors'][ $product_id ] = $data->get_error_message();
		} else {
			WPWix_SEO_Gemini::apply_to_product( $product_id, $data );
			$state['success']++;
		}

		$state['done']++;
		self::save_state( $state );

		wp_send_json_success(
			array(
				'finished' => empty( $state['queue'] ),
				'done'     => $state['done'],
				'total'    => $state['total'],
				'success'  => $state['success'],
				'errors'   => $state['errors'],
				'current'  => get_the_title( $product_id ),
			)
		);
	}

	/**
	 * Kuyruğu sıfırlar (yeni tarama öncesi temiz başlangıç).
	 */
	public static function ajax_reset() {
		self::verify_request();
		delete_option( self::STATE_OPTION );
		wp_send_json_success();
	}

	/* ------------------------------------------------------------------
	 * Yeni ürün otomasyonu (WP-Cron)
	 * ---------------------------------------------------------------- */

	/**
	 * Ürün ilk kez yayınlandığında arka plan üretimi zamanlar.
	 *
	 * @param string  $new_status Yeni durum.
	 * @param string  $old_status Eski durum.
	 * @param WP_Post $post       Post.
	 */
	public static function maybe_schedule_auto_generate( $new_status, $old_status, $post ) {
		if ( 'product' !== $post->post_type || 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}

		if ( ! wpwix_get_settings()['auto_on_publish'] || ! WPWix_SEO_Gemini::is_configured() ) {
			return;
		}

		// Zaten SEO bilgisi varsa (örn. tekrar yayınlama) dokunma.
		if ( '' !== get_post_meta( $post->ID, '_wpwixseo_title', true ) ) {
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK, array( $post->ID ) ) ) {
			wp_schedule_single_event( time() + 15, self::CRON_HOOK, array( $post->ID ) );
		}
	}

	/**
	 * Cron işleyici: üret ve uygula.
	 *
	 * @param int $product_id Ürün ID.
	 */
	public static function cron_generate( $product_id ) {
		$data = WPWix_SEO_Gemini::generate_for_product( $product_id );
		if ( ! is_wp_error( $data ) ) {
			WPWix_SEO_Gemini::apply_to_product( $product_id, $data );
		}
	}
}
