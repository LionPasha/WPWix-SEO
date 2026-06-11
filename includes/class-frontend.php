<?php
/**
 * Frontend <head> çıktıları: title, description, canonical, robots,
 * Open Graph, Twitter Card ve tek @graph JSON-LD.
 *
 * Frontend'e hiçbir CSS/JS yüklenmez; ek sorgu sayısı minimumda tutulur
 * (ürün nesnesi ve terimler tek seferde çekilip istek boyunca yeniden kullanılır).
 *
 * @package WPWix_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPWix_SEO_Frontend {

	/**
	 * İstek boyunca tek sefer hesaplanan değerler.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $context = null;

	public static function init() {
		if ( is_admin() ) {
			return;
		}

		add_filter( 'pre_get_document_title', array( __CLASS__, 'document_title' ), 20 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_action( 'template_redirect', array( __CLASS__, 'setup_product_page' ) );
		add_action( 'wp_head', array( __CLASS__, 'output_head' ), 5 );
	}

	/**
	 * Ürün sayfasında çakışan core/WooCommerce çıktılarını kaldırır:
	 * core rel_canonical (kendi canonical'ımızı basıyoruz) ve
	 * WooCommerce'in Product schema'sı (JSON-LD @graph içinde biz basıyoruz).
	 */
	public static function setup_product_page() {
		if ( ! is_singular( 'product' ) ) {
			return;
		}

		remove_action( 'wp_head', 'rel_canonical' );

		if ( function_exists( 'WC' ) && isset( WC()->structured_data ) ) {
			remove_action( 'wp_footer', array( WC()->structured_data, 'output_structured_data' ), 10 );
		}
	}

	/**
	 * Meta title: AI/manuel meta varsa onu, yoksa şablonu kullanır.
	 *
	 * @param string $title Mevcut başlık.
	 * @return string
	 */
	public static function document_title( $title ) {
		if ( ! is_singular( 'product' ) ) {
			return $title;
		}

		return self::get_title( get_queried_object_id() );
	}

	/**
	 * Arama, sepet, ödeme ve hesap sayfaları ile noindex işaretli
	 * ürünleri noindex,follow yapar.
	 *
	 * @param array<string,bool> $robots wp_robots direktifleri.
	 * @return array<string,bool>
	 */
	public static function robots( $robots ) {
		$noindex = false;

		if ( is_search() ) {
			$noindex = true;
		} elseif ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			$noindex = true;
		} elseif ( is_singular( 'product' ) && get_post_meta( get_queried_object_id(), '_wpwixseo_noindex', true ) ) {
			$noindex = true;
		}

		if ( $noindex ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset( $robots['nofollow'], $robots['max-image-preview'] );
		}

		return $robots;
	}

	/**
	 * Ürün sayfasında description, canonical, OG/Twitter ve JSON-LD basar.
	 */
	public static function output_head() {
		if ( ! is_singular( 'product' ) ) {
			return;
		}

		$ctx = self::get_context();
		if ( ! $ctx ) {
			return;
		}

		echo "\n<!-- WPWix SEO -->\n";

		if ( '' !== $ctx['description'] ) {
			printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $ctx['description'] ) );
		}

		printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $ctx['canonical'] ) );

		self::output_social_tags( $ctx );
		self::output_json_ld( $ctx );

		echo "<!-- /WPWix SEO -->\n";
	}

	/**
	 * Open Graph + Twitter Card etiketleri.
	 *
	 * @param array<string,mixed> $ctx Sayfa bağlamı.
	 */
	private static function output_social_tags( $ctx ) {
		$product = $ctx['product'];

		$og = array(
			'og:locale'      => get_locale(),
			'og:type'        => 'product',
			'og:title'       => $ctx['title'],
			'og:description' => $ctx['description'],
			'og:url'         => $ctx['canonical'],
			'og:site_name'   => get_bloginfo( 'name' ),
		);

		if ( $ctx['image'] ) {
			$og['og:image']        = $ctx['image']['url'];
			$og['og:image:width']  = (string) $ctx['image']['width'];
			$og['og:image:height'] = (string) $ctx['image']['height'];
			if ( '' !== $ctx['image']['alt'] ) {
				$og['og:image:alt'] = $ctx['image']['alt'];
			}
		}

		if ( '' !== $ctx['price'] ) {
			$og['product:price:amount']   = $ctx['price'];
			$og['product:price:currency'] = get_woocommerce_currency();
		}
		$og['product:availability'] = $product->is_in_stock() ? 'in stock' : 'out of stock';

		foreach ( $og as $property => $content ) {
			if ( '' === $content ) {
				continue;
			}
			$escaped = ( 'og:url' === $property || 'og:image' === $property )
				? esc_url( $content )
				: esc_attr( $content );
			printf( '<meta property="%s" content="%s" />' . "\n", esc_attr( $property ), $escaped ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above per type.
		}

		$twitter = array(
			'twitter:card'        => $ctx['image'] ? 'summary_large_image' : 'summary',
			'twitter:title'       => $ctx['title'],
			'twitter:description' => $ctx['description'],
		);
		if ( $ctx['image'] ) {
			$twitter['twitter:image'] = $ctx['image']['url'];
		}

		foreach ( $twitter as $name => $content ) {
			if ( '' === $content ) {
				continue;
			}
			$escaped = ( 'twitter:image' === $name ) ? esc_url( $content ) : esc_attr( $content );
			printf( '<meta name="%s" content="%s" />' . "\n", esc_attr( $name ), $escaped ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above per type.
		}
	}

	/**
	 * Tek @graph içinde Product + BreadcrumbList + Organization.
	 *
	 * @param array<string,mixed> $ctx Sayfa bağlamı.
	 */
	private static function output_json_ld( $ctx ) {
		$product  = $ctx['product'];
		$home_url = home_url( '/' );
		$org_id   = $home_url . '#organization';

		// Organization.
		$organization = array(
			'@type' => 'Organization',
			'@id'   => $org_id,
			'name'  => get_bloginfo( 'name' ),
			'url'   => $home_url,
		);
		$logo_id      = get_theme_mod( 'custom_logo' );
		if ( $logo_id ) {
			$logo = wp_get_attachment_image_src( $logo_id, 'full' );
			if ( $logo ) {
				$organization['logo'] = array(
					'@type' => 'ImageObject',
					'url'   => $logo[0],
				);
			}
		}

		// BreadcrumbList.
		$items    = array(
			array(
				'@type'    => 'ListItem',
				'position' => 1,
				'name'     => get_bloginfo( 'name' ),
				'item'     => $home_url,
			),
		);
		$position = 2;
		foreach ( $ctx['category_chain'] as $term ) {
			$link = get_term_link( $term, 'product_cat' );
			if ( is_wp_error( $link ) ) {
				continue;
			}
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $position++,
				'name'     => $term->name,
				'item'     => $link,
			);
		}
		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $position,
			'name'     => $product->get_name(),
		);

		$breadcrumb = array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $ctx['canonical'] . '#breadcrumb',
			'itemListElement' => $items,
		);

		// Product.
		$product_node = array(
			'@type'       => 'Product',
			'@id'         => $ctx['canonical'] . '#product',
			'name'        => $product->get_name(),
			'description' => $ctx['description'],
			'url'         => $ctx['canonical'],
		);

		$sku = $product->get_sku();
		if ( $sku ) {
			$product_node['sku'] = $sku;
		}

		if ( $ctx['image'] ) {
			$product_node['image'] = $ctx['image']['url'];
		}

		if ( '' !== $ctx['price'] ) {
			if ( $product->is_on_backorder() ) {
				$availability = 'https://schema.org/BackOrder';
			} elseif ( $product->is_in_stock() ) {
				$availability = 'https://schema.org/InStock';
			} else {
				$availability = 'https://schema.org/OutOfStock';
			}

			$product_node['offers'] = array(
				'@type'         => 'Offer',
				'url'           => $ctx['canonical'],
				'price'         => $ctx['price'],
				'priceCurrency' => get_woocommerce_currency(),
				'availability'  => $availability,
				'seller'        => array( '@id' => $org_id ),
			);
		}

		if ( $product->get_review_count() > 0 ) {
			$product_node['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => $product->get_average_rating(),
				'reviewCount' => $product->get_review_count(),
			);
		}

		$schema = array(
			'@context' => 'https://schema.org',
			'@graph'   => array( $product_node, $breadcrumb, $organization ),
		);

		// Eğik çizgiler bilinçli olarak escape'li bırakılır (<\/): içerikten
		// gelebilecek </script> ile script etiketinden kaçışı engeller.
		printf(
			'<script type="application/ld+json">%s</script>' . "\n",
			wp_json_encode( $schema, JSON_UNESCAPED_UNICODE ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode output is safe JSON.
		);
	}

	/**
	 * Sayfa bağlamını (ürün, başlık, açıklama, görsel, kategori zinciri)
	 * istek başına bir kez hesaplar.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function get_context() {
		if ( null !== self::$context ) {
			return self::$context ?: null;
		}

		$post_id = get_queried_object_id();
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			self::$context = false;
			return null;
		}

		$image = null;
		$thumb = $product->get_image_id();
		if ( $thumb ) {
			$src = wp_get_attachment_image_src( $thumb, 'full' );
			if ( $src ) {
				$image = array(
					'url'    => $src[0],
					'width'  => $src[1],
					'height' => $src[2],
					'alt'    => (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true ),
				);
			}
		}

		$price = $product->get_price();
		$price = ( '' === $price || null === $price ) ? '' : wc_format_decimal( $price, wc_get_price_decimals() );

		self::$context = array(
			'product'        => $product,
			'title'          => self::get_title( $post_id ),
			'description'    => self::get_description( $post_id, $product ),
			'canonical'      => get_permalink( $post_id ), // get_permalink parametresiz, temiz URL döndürür.
			'image'          => $image,
			'price'          => $price,
			'category_chain' => self::get_category_chain( $post_id ),
		);

		return self::$context;
	}

	/**
	 * Meta title: _wpwixseo_title varsa onu, yoksa ayarlardaki şablonu kullanır.
	 *
	 * @param int $post_id Ürün ID.
	 * @return string
	 */
	private static function get_title( $post_id ) {
		$custom = get_post_meta( $post_id, '_wpwixseo_title', true );
		if ( '' !== $custom ) {
			return wp_strip_all_tags( $custom );
		}

		$settings = wpwix_get_settings();
		$title    = str_replace(
			array( '%urun_adi%', '%site_adi%' ),
			array( get_the_title( $post_id ), get_bloginfo( 'name' ) ),
			$settings['title_template']
		);

		return wp_strip_all_tags( $title );
	}

	/**
	 * Meta description: _wpwixseo_desc → kısa açıklama → içerik (155 karaktere kırpılır).
	 *
	 * @param int        $post_id Ürün ID.
	 * @param WC_Product $product Ürün nesnesi.
	 * @return string
	 */
	private static function get_description( $post_id, $product ) {
		$custom = get_post_meta( $post_id, '_wpwixseo_desc', true );
		if ( '' !== $custom ) {
			return self::clean_text( $custom, 300 );
		}

		$fallback = $product->get_short_description();
		if ( '' === trim( wp_strip_all_tags( $fallback ) ) ) {
			$fallback = $product->get_description();
		}

		return self::clean_text( $fallback, 155 );
	}

	/**
	 * HTML/shortcode temizler, boşlukları normalize eder, kelime sınırında kırpar.
	 *
	 * @param string $text  Ham metin.
	 * @param int    $limit Maksimum karakter.
	 * @return string
	 */
	private static function clean_text( $text, $limit ) {
		$text = wp_strip_all_tags( strip_shortcodes( (string) $text ) );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );

		if ( mb_strlen( $text ) <= $limit ) {
			return $text;
		}

		$text = mb_substr( $text, 0, $limit - 1 );
		$last = mb_strrpos( $text, ' ' );
		if ( false !== $last && $last > $limit / 2 ) {
			$text = mb_substr( $text, 0, $last );
		}

		return rtrim( $text, ' .,;:' ) . '…';
	}

	/**
	 * Breadcrumb için kategori zinciri: ilk kategorinin atalarından kendisine.
	 *
	 * @param int $post_id Ürün ID.
	 * @return WP_Term[]
	 */
	private static function get_category_chain( $post_id ) {
		$terms = get_the_terms( $post_id, 'product_cat' );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return array();
		}

		$term      = $terms[0];
		$chain_ids = array_reverse( get_ancestors( $term->term_id, 'product_cat' ) );
		$chain_ids[] = $term->term_id;

		$chain = array();
		foreach ( $chain_ids as $term_id ) {
			$t = get_term( $term_id, 'product_cat' );
			if ( $t && ! is_wp_error( $t ) ) {
				$chain[] = $t;
			}
		}

		return $chain;
	}
}
