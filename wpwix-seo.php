<?php
/**
 * Plugin Name:       WPWix SEO
 * Plugin URI:        https://wpwix.com
 * Description:       Hızlı ve AI destekli WooCommerce SEO. Meta etiketler, Open Graph, JSON-LD ve sitemap — frontend'e sıfır CSS/JS yüküyle.
 * Version:           0.4.0
 * Author:            Ahmet YÜRÜK
 * Author URI:        https://wpwix.com
 * Text Domain:       wpwix-seo
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * GitHub: https://github.com/LionPasha/WPWix-SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPWIX_SEO_VERSION', '0.4.0' );
define( 'WPWIX_SEO_FILE', __FILE__ );
define( 'WPWIX_SEO_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Basit autoloader: WPWix_SEO_Frontend -> includes/class-frontend.php
 */
spl_autoload_register(
	static function ( $class ) {
		$prefix = 'WPWix_SEO_';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$slug = strtolower( str_replace( '_', '-', substr( $class, strlen( $prefix ) ) ) );
		$file = WPWIX_SEO_DIR . 'includes/class-' . $slug . '.php';
		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

/**
 * Ayarları varsayılanlarla birleştirip döndürür.
 *
 * @return array<string,mixed>
 */
function wpwix_get_settings() {
	static $settings = null;
	if ( null !== $settings ) {
		return $settings;
	}

	$defaults = array(
		'api_key'         => '',
		'model'           => 'gemini-2.0-flash',
		'custom_model'    => '',
		'brand_tone'      => '',
		'language'        => 'tr',
		'title_template'  => '%urun_adi% | %site_adi%',
		'auto_on_publish' => true,
		'request_delay'   => 2,
	);

	$saved    = get_option( 'wpwixseo_settings', array() );
	$settings = wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );

	return $settings;
}

/**
 * WooCommerce yoksa uyarı gösterir, varsa bileşenleri başlatır.
 */
function wpwix_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			static function () {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html__( 'WPWix SEO çalışmak için WooCommerce eklentisine ihtiyaç duyar. Lütfen WooCommerce kurup etkinleştirin.', 'wpwix-seo' )
				);
			}
		);
		return;
	}

	WPWix_SEO_Frontend::init();
	WPWix_SEO_Analyzer::init();
	WPWix_SEO_Bulk::init();

	if ( is_admin() ) {
		WPWix_SEO_Admin::init();
	}
}
add_action( 'plugins_loaded', 'wpwix_init' );
