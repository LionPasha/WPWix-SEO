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

		$allowed_models = array( 'gemini-2.0-flash', 'gemini-2.5-flash', 'gemini-2.5-pro' );
		$model          = sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) );

		$clean = array(
			'api_key'         => sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) ),
			'model'           => in_array( $model, $allowed_models, true ) ? $model : 'gemini-2.0-flash',
			'custom_model'    => sanitize_text_field( wp_unslash( $_POST['custom_model'] ?? '' ) ),
			'brand_tone'      => sanitize_textarea_field( wp_unslash( $_POST['brand_tone'] ?? '' ) ),
			'language'        => sanitize_text_field( wp_unslash( $_POST['language'] ?? 'tr' ) ),
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
			'gemini-2.0-flash' => __( 'Gemini 2.0 Flash — hızlı, toplu üretim için (varsayılan)', 'wpwix-seo' ),
			'gemini-2.5-flash' => __( 'Gemini 2.5 Flash — daha kaliteli', 'wpwix-seo' ),
			'gemini-2.5-pro'   => __( 'Gemini 2.5 Pro — en kaliteli', 'wpwix-seo' ),
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
							<input type="password" id="wpwix-api-key" name="api_key" class="regular-text" value="<?php echo esc_attr( $settings['api_key'] ); ?>" autocomplete="off" />
							<button type="button" class="button" id="wpwix-test-connection"><?php esc_html_e( 'Bağlantıyı Test Et', 'wpwix-seo' ); ?></button>
							<span id="wpwix-test-result"></span>
							<p class="description">
								<?php
								printf(
									/* translators: %s: Google AI Studio link */
									esc_html__( 'Key almak için: %s (ücretsiz katman mevcuttur).', 'wpwix-seo' ),
									'<a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">aistudio.google.com</a>'
								);
								?>
							</p>
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
}
