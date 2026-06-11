<?php
/**
 * Admin: ayar sayfası, bağlantı testi ve asset yükleme.
 *
 * @package WPWix_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPWix_SEO_Admin {

	const CAPABILITY = 'manage_woocommerce';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_save_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wpwix_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_wpwix_generate_single', array( __CLASS__, 'ajax_generate_single' ) );

		// Metabox.
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_metabox' ) );
		add_action( 'save_post_product', array( __CLASS__, 'save_metabox' ), 10, 2 );

		// Ürün listesi skor sütunu.
		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'add_score_column' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'render_score_column' ), 10, 2 );
		add_filter( 'manage_edit-product_sortable_columns', array( __CLASS__, 'make_score_sortable' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'sort_by_score' ) );
	}

	public static function register_menu() {
		add_menu_page(
			'WPWix SEO',
			'WPWix SEO',
			self::CAPABILITY,
			'wpwix-seo',
			array( __CLASS__, 'render_settings_page' ),
			'dashicons-chart-line',
			58
		);

		add_submenu_page(
			'wpwix-seo',
			__( 'Ayarlar', 'wpwix-seo' ),
			__( 'Ayarlar', 'wpwix-seo' ),
			self::CAPABILITY,
			'wpwix-seo',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Admin JS/CSS sadece eklenti sayfalarında ve ürün düzenleme ekranında yüklenir.
	 * Frontend'e hiçbir şey yüklenmez.
	 *
	 * @param string $hook Geçerli admin sayfası.
	 */
	public static function enqueue_assets( $hook ) {
		$screen     = get_current_screen();
		$is_product = $screen && 'product' === $screen->post_type && in_array( $screen->base, array( 'post', 'edit' ), true );
		$is_plugin  = false !== strpos( $hook, 'wpwix-seo' );

		if ( ! $is_product && ! $is_plugin ) {
			return;
		}

		$url = plugin_dir_url( WPWIX_SEO_FILE );

		wp_enqueue_style( 'wpwix-seo-admin', $url . 'admin/css/admin.css', array(), WPWIX_SEO_VERSION );
		wp_enqueue_script( 'wpwix-seo-admin', $url . 'admin/js/admin.js', array(), WPWIX_SEO_VERSION, true );

		wp_localize_script(
			'wpwix-seo-admin',
			'wpwixSeo',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'wpwix_seo_admin' ),
				'requestDelay' => (int) wpwix_get_settings()['request_delay'],
				'i18n'         => array(
					'testing'    => __( 'Test ediliyor…', 'wpwix-seo' ),
					'testOk'     => __( 'Bağlantı başarılı ✅', 'wpwix-seo' ),
					'generating' => __( 'AI üretiyor…', 'wpwix-seo' ),
					'generated'  => __( 'Üretildi ve kaydedildi ✅', 'wpwix-seo' ),
					'error'      => __( 'Hata: ', 'wpwix-seo' ),
					'done'       => __( 'Tamamlandı', 'wpwix-seo' ),
					'stopped'    => __( 'Durduruldu — kaldığı yerden devam edebilirsiniz.', 'wpwix-seo' ),
				),
			)
		);
	}

	/**
	 * Ayar formu gönderildiyse doğrulayıp kaydeder.
	 */
	public static function maybe_save_settings() {
		if ( ! isset( $_POST['wpwix_settings_submit'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		check_admin_referer( 'wpwix_save_settings', 'wpwix_settings_nonce' );

		$current = wpwix_get_settings();

		$allowed_models    = array( 'gemini-2.5-flash-lite', 'gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.0-flash' );
		$allowed_languages = array( 'tr', 'en', 'de', 'fr', 'es', 'ar', 'ru' );
		$model             = sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) );
		$language          = sanitize_text_field( wp_unslash( $_POST['language'] ?? 'tr' ) );

		// Key alanı boş gönderilirse mevcut key korunur (key forma geri basılmaz).
		$posted_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
		if ( ! empty( $_POST['wpwix_remove_key'] ) ) {
			$posted_key = '';
		} elseif ( '' === $posted_key ) {
			$posted_key = $current['api_key'];
		}

		// Özel model adı: API URL'sine girdiği için güvenli karakter setiyle sınırlanır.
		$custom_model = preg_replace( '/[^a-zA-Z0-9._\-]/', '', sanitize_text_field( wp_unslash( $_POST['custom_model'] ?? '' ) ) );

		$clean = array(
			'api_key'         => $posted_key,
			'model'           => in_array( $model, $allowed_models, true ) ? $model : 'gemini-2.5-flash',
			'custom_model'    => $custom_model,
			'brand_tone'      => sanitize_textarea_field( wp_unslash( $_POST['brand_tone'] ?? '' ) ),
			'language'        => in_array( $language, $allowed_languages, true ) ? $language : 'tr',
			'title_template'  => sanitize_text_field( wp_unslash( $_POST['title_template'] ?? '%urun_adi% | %site_adi%' ) ),
			'auto_on_publish' => ! empty( $_POST['auto_on_publish'] ),
			'request_delay'   => min( 60, max( 0, absint( $_POST['request_delay'] ?? 2 ) ) ),
		);

		if ( '' === $clean['title_template'] ) {
			$clean['title_template'] = '%urun_adi% | %site_adi%';
		}

		// Option autoload=no: her sayfa yüklemesinde belleğe alınmaz.
		if ( false === get_option( 'wpwixseo_settings' ) ) {
			add_option( 'wpwixseo_settings', $clean, '', 'no' );
		} else {
			update_option( 'wpwixseo_settings', $clean );
		}

		add_action(
			'admin_notices',
			static function () {
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
					esc_html__( 'Ayarlar kaydedildi.', 'wpwix-seo' )
				);
			}
		);
	}

	public static function render_settings_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$settings = wpwix_get_settings();
		$models   = array(
			'gemini-2.5-flash'      => __( 'Gemini 2.5 Flash — dengeli, toplu üretim için (varsayılan)', 'wpwix-seo' ),
			'gemini-2.5-flash-lite' => __( 'Gemini 2.5 Flash-Lite — en hızlı, ücretsiz katmanda en cömert limit', 'wpwix-seo' ),
			'gemini-2.5-pro'        => __( 'Gemini 2.5 Pro — en kaliteli', 'wpwix-seo' ),
			'gemini-2.0-flash'      => __( 'Gemini 2.0 Flash — eski model (ücretsiz katman kotası kaldırıldı)', 'wpwix-seo' ),
		);
		$languages = array(
			'tr' => 'Türkçe',
			'en' => 'English',
			'de' => 'Deutsch',
			'fr' => 'Français',
			'es' => 'Español',
			'ar' => 'العربية',
			'ru' => 'Русский',
		);
		?>
		<div class="wrap wpwix-wrap">
			<h1>WPWix SEO — <?php esc_html_e( 'Ayarlar', 'wpwix-seo' ); ?></h1>

			<form method="post" action="">
				<?php wp_nonce_field( 'wpwix_save_settings', 'wpwix_settings_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wpwix-api-key"><?php esc_html_e( 'Gemini API Key', 'wpwix-seo' ); ?></label></th>
						<td>
							<?php
							// Güvenlik: kayıtlı key forma/HTML kaynağına asla geri basılmaz.
							$has_key     = '' !== $settings['api_key'];
							$placeholder = $has_key
								? str_repeat( '•', 12 ) . substr( $settings['api_key'], -4 )
								: __( 'API key girin', 'wpwix-seo' );
							?>
							<input type="password" id="wpwix-api-key" name="api_key" class="regular-text" value="" placeholder="<?php echo esc_attr( $placeholder ); ?>" autocomplete="new-password" />
							<button type="button" class="button" id="wpwix-test-connection"><?php esc_html_e( 'Bağlantıyı Test Et', 'wpwix-seo' ); ?></button>
							<span id="wpwix-test-result"></span>
							<p class="description">
								<?php
								if ( $has_key ) {
									esc_html_e( 'Bir key kayıtlı. Değiştirmek için yeni key girin; boş bırakırsanız mevcut key korunur. ', 'wpwix-seo' );
								}
								printf(
									/* translators: %s: Google AI Studio link */
									esc_html__( 'Key almak için: %s (ücretsiz katman mevcuttur).', 'wpwix-seo' ),
									'<a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">aistudio.google.com</a>'
								);
								?>
							</p>
							<?php if ( $has_key ) : ?>
								<label>
									<input type="checkbox" name="wpwix_remove_key" value="1" />
									<?php esc_html_e( 'Kayıtlı key\'i sil', 'wpwix-seo' ); ?>
								</label>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpwix-model"><?php esc_html_e( 'Model', 'wpwix-seo' ); ?></label></th>
						<td>
							<select id="wpwix-model" name="model">
								<?php foreach ( $models as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['model'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpwix-custom-model"><?php esc_html_e( 'Özel model adı', 'wpwix-seo' ); ?></label></th>
						<td>
							<input type="text" id="wpwix-custom-model" name="custom_model" class="regular-text" value="<?php echo esc_attr( $settings['custom_model'] ); ?>" placeholder="gemini-3.0-flash" />
							<p class="description"><?php esc_html_e( 'Doluysa yukarıdaki seçimi geçersiz kılar. Yeni modelleri güncelleme beklemeden kullanın.', 'wpwix-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpwix-brand-tone"><?php esc_html_e( 'Marka tonu', 'wpwix-seo' ); ?></label></th>
						<td>
							<textarea id="wpwix-brand-tone" name="brand_tone" class="large-text" rows="3" placeholder="<?php esc_attr_e( 'Örn: Organik gıda, samimi ve güven veren ton', 'wpwix-seo' ); ?>"><?php echo esc_textarea( $settings['brand_tone'] ); ?></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpwix-language"><?php esc_html_e( 'Çıktı dili', 'wpwix-seo' ); ?></label></th>
						<td>
							<select id="wpwix-language" name="language">
								<?php foreach ( $languages as $code => $label ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $settings['language'], $code ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpwix-title-template"><?php esc_html_e( 'Title şablonu', 'wpwix-seo' ); ?></label></th>
						<td>
							<input type="text" id="wpwix-title-template" name="title_template" class="regular-text" value="<?php echo esc_attr( $settings['title_template'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Kullanılabilir: %urun_adi%, %site_adi%. AI üretimi yoksa bu şablon kullanılır.', 'wpwix-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Yeni ürün otomasyonu', 'wpwix-seo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="auto_on_publish" value="1" <?php checked( $settings['auto_on_publish'] ); ?> />
								<?php esc_html_e( 'Ürün yayınlandığında SEO bilgilerini arka planda otomatik üret', 'wpwix-seo' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpwix-request-delay"><?php esc_html_e( 'İstek aralığı (sn)', 'wpwix-seo' ); ?></label></th>
						<td>
							<input type="number" id="wpwix-request-delay" name="request_delay" min="0" max="60" value="<?php echo esc_attr( $settings['request_delay'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Toplu üretimde istekler arası bekleme. Ücretsiz katmanda 2 sn önerilir.', 'wpwix-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Ayarları Kaydet', 'wpwix-seo' ), 'primary', 'wpwix_settings_submit' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * AJAX: bağlantı testi. Formda o an yazılı key/model ile test eder,
	 * böylece kaydetmeden önce doğrulanabilir.
	 */
	public static function ajax_test_connection() {
		check_ajax_referer( 'wpwix_seo_admin', 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Yetkiniz yok.', 'wpwix-seo' ) ) );
		}

		$api_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
		$model   = sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) );

		$result = WPWix_SEO_Gemini::test_connection( $api_key, $model );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success();
	}

	/**
	 * AJAX: tek ürün için AI üretimi (metabox butonu ve "AI ile Düzelt").
	 */
	public static function ajax_generate_single() {
		check_ajax_referer( 'wpwix_seo_admin', 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Yetkiniz yok.', 'wpwix-seo' ) ) );
		}

		$product_id = absint( $_POST['product_id'] ?? 0 );
		if ( ! $product_id || 'product' !== get_post_type( $product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Geçersiz ürün.', 'wpwix-seo' ) ) );
		}

		$data = WPWix_SEO_Gemini::generate_for_product( $product_id );
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'message' => $data->get_error_message() ) );
		}

		WPWix_SEO_Gemini::apply_to_product( $product_id, $data );

		wp_send_json_success(
			array(
				'fields' => $data,
				'score'  => (int) get_post_meta( $product_id, '_wpwixseo_score', true ),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Metabox
	 * ---------------------------------------------------------------- */

	public static function register_metabox() {
		add_meta_box(
			'wpwix-seo-metabox',
			'WPWix SEO',
			array( __CLASS__, 'render_metabox' ),
			'product',
			'normal',
			'default'
		);
	}

	/**
	 * Metabox: alanlar, snippet önizleme, sayaçlar, skor + kontrol listesi, AI butonu.
	 *
	 * @param WP_Post $post Ürün.
	 */
	public static function render_metabox( $post ) {
		wp_nonce_field( 'wpwix_save_meta', 'wpwix_meta_nonce' );

		$title    = get_post_meta( $post->ID, '_wpwixseo_title', true );
		$desc     = get_post_meta( $post->ID, '_wpwixseo_desc', true );
		$focus_kw = get_post_meta( $post->ID, '_wpwixseo_focus_kw', true );
		$alt      = get_post_meta( $post->ID, '_wpwixseo_alt', true );
		$noindex  = get_post_meta( $post->ID, '_wpwixseo_noindex', true );

		$analysis = WPWix_SEO_Analyzer::calculate( $post->ID );
		$labels   = WPWix_SEO_Analyzer::get_labels();

		$settings      = wpwix_get_settings();
		$preview_title = '' !== $title
			? $title
			: str_replace( array( '%urun_adi%', '%site_adi%' ), array( get_the_title( $post ), get_bloginfo( 'name' ) ), $settings['title_template'] );
		?>
		<div class="wpwix-metabox">
			<p class="wpwix-ai-row">
				<button type="button" class="button button-primary wpwix-generate" data-product="<?php echo esc_attr( $post->ID ); ?>">
					✨ <?php esc_html_e( 'AI ile Doldur', 'wpwix-seo' ); ?>
				</button>
				<span class="wpwix-generate-status"></span>
			</p>

			<div class="wpwix-snippet">
				<span class="wpwix-snippet-label"><?php esc_html_e( 'Google önizleme', 'wpwix-seo' ); ?></span>
				<div class="wpwix-snippet-title" id="wpwix-preview-title"><?php echo esc_html( $preview_title ); ?></div>
				<div class="wpwix-snippet-url"><?php echo esc_url( get_permalink( $post ) ); ?></div>
				<div class="wpwix-snippet-desc" id="wpwix-preview-desc"><?php echo esc_html( $desc ); ?></div>
			</div>

			<p>
				<label for="wpwix-field-title"><strong><?php esc_html_e( 'SEO Title', 'wpwix-seo' ); ?></strong>
					<span class="wpwix-counter" data-for="wpwix-field-title" data-max="60"><?php echo esc_html( mb_strlen( $title ) ); ?>/60</span>
				</label>
				<input type="text" id="wpwix-field-title" name="wpwix_title" class="large-text" value="<?php echo esc_attr( $title ); ?>" />
			</p>
			<p>
				<label for="wpwix-field-desc"><strong><?php esc_html_e( 'Meta Description', 'wpwix-seo' ); ?></strong>
					<span class="wpwix-counter" data-for="wpwix-field-desc" data-max="155"><?php echo esc_html( mb_strlen( $desc ) ); ?>/155</span>
				</label>
				<textarea id="wpwix-field-desc" name="wpwix_desc" class="large-text" rows="3"><?php echo esc_textarea( $desc ); ?></textarea>
			</p>
			<p>
				<label for="wpwix-field-kw"><strong><?php esc_html_e( 'Odak Kelime', 'wpwix-seo' ); ?></strong></label>
				<input type="text" id="wpwix-field-kw" name="wpwix_focus_kw" class="large-text" value="<?php echo esc_attr( $focus_kw ); ?>" />
			</p>
			<p>
				<label for="wpwix-field-alt"><strong><?php esc_html_e( 'Görsel Alt Metni', 'wpwix-seo' ); ?></strong></label>
				<input type="text" id="wpwix-field-alt" name="wpwix_alt" class="large-text" value="<?php echo esc_attr( $alt ); ?>" />
			</p>
			<p>
				<label>
					<input type="checkbox" name="wpwix_noindex" value="1" <?php checked( $noindex ); ?> />
					<?php esc_html_e( 'Bu ürünü arama motorlarından gizle (noindex)', 'wpwix-seo' ); ?>
				</label>
			</p>

			<div class="wpwix-analysis">
				<div class="wpwix-score-badge <?php echo esc_attr( WPWix_SEO_Analyzer::get_color_class( $analysis['score'] ) ); ?>" id="wpwix-score-badge">
					<?php echo esc_html( $analysis['score'] ); ?>/100
				</div>
				<ul class="wpwix-checklist">
					<?php foreach ( $labels as $key => $label ) : ?>
						<li><?php echo esc_html( ( ! empty( $analysis['checks'][ $key ] ) ? '✅ ' : '❌ ' ) . $label ); ?></li>
					<?php endforeach; ?>
				</ul>
				<p class="description"><?php esc_html_e( 'Skor, ürün kaydedildiğinde güncellenir. Sadece bilgilendirir; hiçbir şeyi engellemez.', 'wpwix-seo' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Metabox alanlarını kaydeder. Boş alanların meta'sı silinir (plan: boşsa kaydedilmez).
	 *
	 * @param int     $post_id Ürün ID.
	 * @param WP_Post $post    Post nesnesi.
	 */
	public static function save_metabox( $post_id, $post ) {
		if ( ! isset( $_POST['wpwix_meta_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( $_POST['wpwix_meta_nonce'] ), 'wpwix_save_meta' ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$fields = array(
			'_wpwixseo_title'    => 'wpwix_title',
			'_wpwixseo_desc'     => 'wpwix_desc',
			'_wpwixseo_focus_kw' => 'wpwix_focus_kw',
			'_wpwixseo_alt'      => 'wpwix_alt',
		);

		foreach ( $fields as $meta_key => $input ) {
			$value = sanitize_text_field( wp_unslash( $_POST[ $input ] ?? '' ) );
			if ( '' === $value ) {
				delete_post_meta( $post_id, $meta_key );
			} else {
				update_post_meta( $post_id, $meta_key, $value );
			}
		}

		if ( empty( $_POST['wpwix_noindex'] ) ) {
			delete_post_meta( $post_id, '_wpwixseo_noindex' );
		} else {
			update_post_meta( $post_id, '_wpwixseo_noindex', '1' );
		}
	}

	/* ------------------------------------------------------------------
	 * Ürün listesi skor sütunu
	 * ---------------------------------------------------------------- */

	/**
	 * @param array<string,string> $columns Mevcut sütunlar.
	 * @return array<string,string>
	 */
	public static function add_score_column( $columns ) {
		$columns['wpwix_score'] = __( 'SEO Skoru', 'wpwix-seo' );

		return $columns;
	}

	/**
	 * @param string $column  Sütun anahtarı.
	 * @param int    $post_id Ürün ID.
	 */
	public static function render_score_column( $column, $post_id ) {
		if ( 'wpwix_score' !== $column ) {
			return;
		}

		$score = get_post_meta( $post_id, '_wpwixseo_score', true );
		if ( '' === $score ) {
			echo '<span class="wpwix-score-badge wpwix-score-none">—</span>';
			return;
		}

		$score = (int) $score;
		printf(
			'<span class="wpwix-score-badge %s">%d</span>',
			esc_attr( WPWix_SEO_Analyzer::get_color_class( $score ) ),
			esc_html( $score ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- int, %d ile basılıyor.
		);
	}

	/**
	 * @param array<string,string> $columns Sıralanabilir sütunlar.
	 * @return array<string,string>
	 */
	public static function make_score_sortable( $columns ) {
		$columns['wpwix_score'] = 'wpwix_score';

		return $columns;
	}

	/**
	 * Skora göre sıralama; skoru olmayan ürünler de listede kalır.
	 *
	 * @param WP_Query $query Liste sorgusu.
	 */
	public static function sort_by_score( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'wpwix_score' !== $query->get( 'orderby' ) ) {
			return;
		}

		$query->set(
			'meta_query',
			array(
				'relation'    => 'OR',
				'score'       => array(
					'key'     => '_wpwixseo_score',
					'compare' => 'EXISTS',
					'type'    => 'NUMERIC',
				),
				'score_empty' => array(
					'key'     => '_wpwixseo_score',
					'compare' => 'NOT EXISTS',
				),
			)
		);
		$query->set( 'orderby', array( 'score' => $query->get( 'order' ) ?: 'ASC' ) );
	}
}
