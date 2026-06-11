<?php
/**
 * XML Sitemap: /sitemap.xml
 *
 * Ürünler + ürün kategorileri + mağaza ve ana sayfa. Çıktı 12 saatlik
 * transient'te tutulur; ürün veya kategori değişince temizlenir.
 * noindex işaretli ürünler dahil edilmez.
 *
 * @package WPWix_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPWix_SEO_Sitemap {

	const TRANSIENT = 'wpwixseo_sitemap';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'register_query_var' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 0 );

		// Kendi sitemap'imiz varken core'unki gereksiz ve mükerrer.
		add_filter( 'wp_sitemaps_enabled', '__return_false' );

		// robots.txt'ye sitemap satırı ekle.
		add_filter( 'robots_txt', array( __CLASS__, 'add_to_robots_txt' ) );

		// Önbellek geçersiz kılma.
		add_action( 'save_post_product', array( __CLASS__, 'flush_cache' ) );
		add_action( 'deleted_post', array( __CLASS__, 'flush_cache' ) );
		add_action( 'created_product_cat', array( __CLASS__, 'flush_cache' ) );
		add_action( 'edited_product_cat', array( __CLASS__, 'flush_cache' ) );
		add_action( 'delete_product_cat', array( __CLASS__, 'flush_cache' ) );
	}

	public static function register_rewrite() {
		add_rewrite_rule( '^sitemap\.xml$', 'index.php?wpwix_sitemap=1', 'top' );
	}

	/**
	 * @param string[] $vars Sorgu değişkenleri.
	 * @return string[]
	 */
	public static function register_query_var( $vars ) {
		$vars[] = 'wpwix_sitemap';

		return $vars;
	}

	public static function flush_cache() {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * @param string $output robots.txt içeriği.
	 * @return string
	 */
	public static function add_to_robots_txt( $output ) {
		return $output . "\nSitemap: " . esc_url( home_url( '/sitemap.xml' ) ) . "\n";
	}

	public static function maybe_render() {
		if ( '1' !== get_query_var( 'wpwix_sitemap' ) ) {
			return;
		}

		$xml = get_transient( self::TRANSIENT );
		if ( false === $xml ) {
			$xml = self::build();
			set_transient( self::TRANSIENT, $xml, 12 * HOUR_IN_SECONDS );
		}

		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' ); // Sitemap'in kendisi indekslenmez.
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- URL'ler build() içinde esc_url ile kaçışlanır.
		exit;
	}

	/**
	 * Sitemap XML'ini üretir.
	 *
	 * @return string
	 */
	private static function build() {
		global $wpdb;

		$urls = array();

		// Ana sayfa ve mağaza sayfası.
		$urls[] = array( 'loc' => home_url( '/' ) );
		$shop   = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'shop' ) : 0;
		if ( $shop > 0 && 'publish' === get_post_status( $shop ) ) {
			$urls[] = array(
				'loc'     => get_permalink( $shop ),
				'lastmod' => get_post_modified_time( 'c', true, $shop ),
			);
		}

		// noindex işaretli ürünler hariç tüm yayınlanmış ürünler.
		$products = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- transient'e alınan tek toplu okuma.
			"SELECT p.ID, p.post_modified_gmt
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm
				ON pm.post_id = p.ID AND pm.meta_key = '_wpwixseo_noindex'
			WHERE p.post_type = 'product'
				AND p.post_status = 'publish'
				AND pm.meta_id IS NULL
			ORDER BY p.post_modified_gmt DESC"
		);

		foreach ( $products as $product ) {
			$urls[] = array(
				'loc'     => get_permalink( $product->ID ),
				'lastmod' => mysql2date( 'c', $product->post_modified_gmt, false ),
			);
		}

		// Ürün kategorileri (boş olmayanlar).
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
			)
		);
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) ) {
					$urls[] = array( 'loc' => $link );
				}
			}
		}

		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $urls as $url ) {
			$xml .= "\t<url>\n";
			$xml .= "\t\t<loc>" . esc_url( $url['loc'] ) . "</loc>\n";
			if ( ! empty( $url['lastmod'] ) ) {
				$xml .= "\t\t<lastmod>" . esc_html( $url['lastmod'] ) . "</lastmod>\n";
			}
			$xml .= "\t</url>\n";
		}

		$xml .= '</urlset>';

		return $xml;
	}
}
