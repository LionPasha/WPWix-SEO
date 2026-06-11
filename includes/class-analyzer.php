<?php
/**
 * SEO skoru hesaplama (0-100).
 *
 * Skor sadece ürün kaydedildiğinde hesaplanır ve _wpwixseo_score meta'sında
 * tutulur — frontend'de asla hesaplanmaz. Skor bilgilendirir; engellemez.
 *
 * @package WPWix_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPWix_SEO_Analyzer {

	/**
	 * Kriterler ve puanları (toplam 100).
	 *
	 * @var array<string,int>
	 */
	const WEIGHTS = array(
		'title_length'  => 10, // Meta title var ve 30-60 karakter.
		'desc_length'   => 10, // Meta description var ve 120-155 karakter.
		'kw_in_title'   => 10, // Odak kelime title'da.
		'kw_in_desc'    => 10, // Odak kelime description'da.
		'kw_in_content' => 10, // Odak kelime ürün içeriğinde.
		'kw_in_url'     => 10, // Odak kelime URL'de.
		'image_alt'     => 10, // Öne çıkan görselde alt metin.
		'content_words' => 10, // Ürün açıklaması >= 150 kelime.
		'internal_link' => 10, // İçerikte en az 1 iç bağlantı.
		'has_category'  => 5,  // En az bir kategori.
		'short_desc'    => 5,  // Kısa açıklama dolu.
	);

	public static function init() {
		// Metabox kaydından (öncelik 10) sonra çalışsın diye 50.
		add_action( 'save_post_product', array( __CLASS__, 'on_save' ), 50, 2 );
	}

	/**
	 * Ürün kaydedildiğinde skoru hesaplayıp meta'ya yazar.
	 *
	 * @param int     $post_id Ürün ID.
	 * @param WP_Post $post    Post nesnesi.
	 */
	public static function on_save( $post_id, $post ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status && 'draft' !== $post->post_status ) {
			return;
		}

		self::recalculate_and_save( $post_id );
	}

	/**
	 * Skoru hesaplar, kaydeder ve döndürür.
	 *
	 * @param int $product_id Ürün ID.
	 * @return int
	 */
	public static function recalculate_and_save( $product_id ) {
		$result = self::calculate( $product_id );
		update_post_meta( $product_id, '_wpwixseo_score', $result['score'] );

		return $result['score'];
	}

	/**
	 * Tüm kriterleri değerlendirir.
	 *
	 * @param int $product_id Ürün ID.
	 * @return array{score:int,checks:array<string,bool>}
	 */
	public static function calculate( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array(
				'score'  => 0,
				'checks' => array_fill_keys( array_keys( self::WEIGHTS ), false ),
			);
		}

		$title = (string) get_post_meta( $product_id, '_wpwixseo_title', true );
		$desc  = (string) get_post_meta( $product_id, '_wpwixseo_desc', true );
		$kw    = trim( (string) get_post_meta( $product_id, '_wpwixseo_focus_kw', true ) );

		$content      = $product->get_description();
		$content_text = trim( wp_strip_all_tags( strip_shortcodes( $content ) ) );
		$short_desc   = trim( wp_strip_all_tags( strip_shortcodes( $product->get_short_description() ) ) );
		$slug         = get_post_field( 'post_name', $product_id );

		$title_len = mb_strlen( $title );
		$desc_len  = mb_strlen( $desc );
		$words     = self::word_count( $content_text );

		$thumb_id  = $product->get_image_id();
		$thumb_alt = $thumb_id ? (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) : '';
		if ( '' === $thumb_alt ) {
			$thumb_alt = (string) get_post_meta( $product_id, '_wpwixseo_alt', true );
		}

		$home = home_url();

		$checks = array(
			'title_length'  => $title_len >= 30 && $title_len <= 60,
			'desc_length'   => $desc_len >= 120 && $desc_len <= 155,
			'kw_in_title'   => '' !== $kw && false !== mb_stripos( $title, $kw ),
			'kw_in_desc'    => '' !== $kw && false !== mb_stripos( $desc, $kw ),
			'kw_in_content' => '' !== $kw && false !== mb_stripos( $content_text, $kw ),
			'kw_in_url'     => '' !== $kw && false !== strpos( $slug, sanitize_title( $kw ) ),
			'image_alt'     => $thumb_id && '' !== $thumb_alt,
			'content_words' => $words >= 150,
			'internal_link' => false !== stripos( $content, 'href="' . $home ) || false !== stripos( $content, "href='" . $home ),
			'has_category'  => has_term( '', 'product_cat', $product_id ),
			'short_desc'    => '' !== $short_desc,
		);

		$score = 0;
		foreach ( self::WEIGHTS as $key => $points ) {
			if ( ! empty( $checks[ $key ] ) ) {
				$score += $points;
			}
		}

		return array(
			'score'  => $score,
			'checks' => $checks,
		);
	}

	/**
	 * HTML/shortcode'dan arındırılmış kelime sayısı.
	 *
	 * @param string $text Ham veya temizlenmiş metin.
	 * @return int
	 */
	public static function word_count( $text ) {
		$text = trim( wp_strip_all_tags( strip_shortcodes( (string) $text ) ) );

		return '' === $text ? 0 : count( preg_split( '/\s+/u', $text ) );
	}

	/**
	 * Kriterlerin okunur etiketleri (metabox kontrol listesi için).
	 *
	 * @return array<string,string>
	 */
	public static function get_labels() {
		return array(
			'title_length'  => __( 'Meta title var ve 30-60 karakter arası', 'wpwix-seo' ),
			'desc_length'   => __( 'Meta description var ve 120-155 karakter arası', 'wpwix-seo' ),
			'kw_in_title'   => __( 'Odak kelime title\'da geçiyor', 'wpwix-seo' ),
			'kw_in_desc'    => __( 'Odak kelime description\'da geçiyor', 'wpwix-seo' ),
			'kw_in_content' => __( 'Odak kelime ürün içeriğinde geçiyor', 'wpwix-seo' ),
			'kw_in_url'     => __( 'Odak kelime URL\'de geçiyor', 'wpwix-seo' ),
			'image_alt'     => __( 'Öne çıkan görselde alt metin var', 'wpwix-seo' ),
			'content_words' => __( 'Ürün açıklaması en az 150 kelime', 'wpwix-seo' ),
			'internal_link' => __( 'İçerikte en az 1 iç bağlantı var', 'wpwix-seo' ),
			'has_category'  => __( 'Ürün en az bir kategoriye atanmış', 'wpwix-seo' ),
			'short_desc'    => __( 'Kısa açıklama dolu', 'wpwix-seo' ),
		);
	}

	/**
	 * Skora göre renk sınıfı: yeşil 80+, sarı 50-79, kırmızı <50.
	 *
	 * @param int $score Skor.
	 * @return string
	 */
	public static function get_color_class( $score ) {
		if ( $score >= 80 ) {
			return 'wpwix-score-green';
		}
		if ( $score >= 50 ) {
			return 'wpwix-score-yellow';
		}

		return 'wpwix-score-red';
	}
}
