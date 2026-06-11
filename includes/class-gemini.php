<?php
/**
 * Google Gemini API istemcisi.
 *
 * responseSchema ile her zaman geçerli JSON döner; 429/503'te exponential
 * backoff uygular. API key sadece sunucu tarafında kullanılır, JS'e sızmaz.
 *
 * @package WPWix_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPWix_SEO_Gemini {

	const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

	/**
	 * Kullanılacak model: özel model alanı doluysa o, yoksa dropdown seçimi.
	 *
	 * @return string
	 */
	public static function get_model() {
		$settings = wpwix_get_settings();
		return '' !== trim( $settings['custom_model'] ) ? trim( $settings['custom_model'] ) : $settings['model'];
	}

	/**
	 * API key girilmiş mi?
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$settings = wpwix_get_settings();
		return '' !== trim( $settings['api_key'] );
	}

	/**
	 * Bağlantı testi: küçük bir istek atar, model erişimini doğrular.
	 *
	 * @param string $api_key Test edilecek key (boşsa kayıtlı key).
	 * @param string $model   Test edilecek model (boşsa kayıtlı model).
	 * @return true|WP_Error
	 */
	public static function test_connection( $api_key = '', $model = '' ) {
		$payload = array(
			'contents'         => array(
				array(
					'role'  => 'user',
					'parts' => array( array( 'text' => 'Merhaba! Tek kelimeyle yanıt ver: tamam' ) ),
				),
			),
			'generationConfig' => array( 'maxOutputTokens' => 10 ),
		);

		$result = self::request( $payload, $api_key, $model, 1 );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Bir ürün için SEO alanlarını üretir.
	 *
	 * @param int $product_id Ürün ID.
	 * @return array{seo_title:string,meta_description:string,focus_keyword:string,image_alt:string}|WP_Error
	 */
	public static function generate_for_product( $product_id ) {
		if ( ! self::is_configured() ) {
			return new WP_Error( 'wpwix_no_key', __( 'Gemini API key girilmemiş. Ayarlar sayfasından key ekleyin.', 'wpwix-seo' ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'wpwix_no_product', __( 'Ürün bulunamadı.', 'wpwix-seo' ) );
		}

		$settings = wpwix_get_settings();

		$payload = array(
			'systemInstruction' => array(
				'parts' => array( array( 'text' => self::build_system_instruction( $settings ) ) ),
			),
			'contents'          => array(
				array(
					'role'  => 'user',
					'parts' => array( array( 'text' => self::build_product_prompt( $product ) ) ),
				),
			),
			'generationConfig'  => array(
				'responseMimeType' => 'application/json',
				'responseSchema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'seo_title'        => array( 'type' => 'STRING' ),
						'meta_description' => array( 'type' => 'STRING' ),
						'focus_keyword'    => array( 'type' => 'STRING' ),
						'image_alt'        => array( 'type' => 'STRING' ),
					),
					'required'   => array( 'seo_title', 'meta_description', 'focus_keyword', 'image_alt' ),
				),
				'temperature'      => 0.7,
			),
		);

		$body = self::request( $payload );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
		$data = json_decode( $text, true );

		if ( ! is_array( $data ) || empty( $data['seo_title'] ) ) {
			return new WP_Error( 'wpwix_bad_response', __( 'Gemini geçerli bir yanıt döndürmedi.', 'wpwix-seo' ) );
		}

		// AI yanıtı kaydedilmeden önce sanitize edilir ve uzunluklar sınırlanır.
		return array(
			'seo_title'        => mb_substr( sanitize_text_field( $data['seo_title'] ), 0, 70 ),
			'meta_description' => mb_substr( sanitize_text_field( $data['meta_description'] ?? '' ), 0, 170 ),
			'focus_keyword'    => mb_substr( sanitize_text_field( $data['focus_keyword'] ?? '' ), 0, 80 ),
			'image_alt'        => mb_substr( sanitize_text_field( $data['image_alt'] ?? '' ), 0, 125 ),
		);
	}

	/**
	 * Üretilen alanları ürün meta'sına yazar; öne çıkan görselin alt metni
	 * boşsa onu da doldurur. Skoru yeniden hesaplar.
	 *
	 * @param int   $product_id Ürün ID.
	 * @param array $data       generate_for_product() çıktısı.
	 */
	public static function apply_to_product( $product_id, $data ) {
		$map = array(
			'_wpwixseo_title'    => 'seo_title',
			'_wpwixseo_desc'     => 'meta_description',
			'_wpwixseo_focus_kw' => 'focus_keyword',
			'_wpwixseo_alt'      => 'image_alt',
		);

		foreach ( $map as $meta_key => $field ) {
			if ( '' !== $data[ $field ] ) {
				update_post_meta( $product_id, $meta_key, $data[ $field ] );
			}
		}

		// Mevcut alt metnin üzerine yazmayız; sadece boşsa doldururuz.
		$thumb_id = get_post_thumbnail_id( $product_id );
		if ( $thumb_id && '' !== $data['image_alt'] && '' === get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) ) {
			update_post_meta( $thumb_id, '_wp_attachment_image_alt', $data['image_alt'] );
		}

		if ( class_exists( 'WPWix_SEO_Analyzer' ) ) {
			WPWix_SEO_Analyzer::recalculate_and_save( $product_id );
		}
	}

	/**
	 * Ürün açıklamasını AI ile yazar/genişletir: 180-250 kelime, odak kelime
	 * içinde, sonuna iç bağlantı (ilk kategori) eklenir.
	 *
	 * Kaydetmez; üretilen HTML'i döndürür. Kaydetmek için apply_description().
	 *
	 * @param int $product_id Ürün ID.
	 * @return string|WP_Error wp_kses_post'tan geçmiş HTML.
	 */
	public static function generate_description( $product_id ) {
		if ( ! self::is_configured() ) {
			return new WP_Error( 'wpwix_no_key', __( 'Gemini API key girilmemiş. Ayarlar sayfasından key ekleyin.', 'wpwix-seo' ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'wpwix_no_product', __( 'Ürün bulunamadı.', 'wpwix-seo' ) );
		}

		$settings = wpwix_get_settings();
		$focus_kw = trim( (string) get_post_meta( $product_id, '_wpwixseo_focus_kw', true ) );

		$task = 'Aşağıdaki ürün için 180-250 kelimelik bir ürün açıklaması yaz.'
			. "\nKurallar:"
			. "\n- Sadece şu HTML etiketlerini kullan: <p>, <h3>, <ul>, <li>, <strong>."
			. "\n- Mevcut açıklama varsa onu temel al ve genişlet; bilgi uydurma, ürün bilgilerinde olmayan teknik iddia ekleme."
			. "\n- Satış odaklı ama doğal bir dil kullan; anahtar kelime yığması yapma.";
		if ( '' !== $focus_kw ) {
			$task .= "\n- \"" . $focus_kw . '" ifadesi metinde doğal biçimde en az 2 kez geçsin.';
		}

		$payload = array(
			'systemInstruction' => array(
				'parts' => array( array( 'text' => self::build_system_instruction( $settings ) ) ),
			),
			'contents'          => array(
				array(
					'role'  => 'user',
					'parts' => array( array( 'text' => $task . "\n\n" . self::build_product_prompt( $product ) ) ),
				),
			),
			'generationConfig'  => array(
				'responseMimeType' => 'application/json',
				'responseSchema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'description_html' => array( 'type' => 'STRING' ),
					),
					'required'   => array( 'description_html' ),
				),
				'temperature'      => 0.7,
			),
		);

		$body = self::request( $payload );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
		$data = json_decode( $text, true );

		if ( ! is_array( $data ) || empty( $data['description_html'] ) ) {
			return new WP_Error( 'wpwix_bad_response', __( 'Gemini geçerli bir yanıt döndürmedi.', 'wpwix-seo' ) );
		}

		// AI çıktısı kaydedilmeden önce izinli HTML'e indirgenir.
		$html = wp_kses_post( $data['description_html'] );

		// İç bağlantı garantisi: ilk kategoriye link eklenir.
		$terms = get_the_terms( $product_id, 'product_cat' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			$link = get_term_link( $terms[0], 'product_cat' );
			if ( ! is_wp_error( $link ) ) {
				$html .= "\n<p>" . sprintf(
					/* translators: 1: category URL, 2: category name */
					__( 'Benzer ürünler için <a href="%1$s">%2$s</a> kategorimize göz atabilirsiniz.', 'wpwix-seo' ),
					esc_url( $link ),
					esc_html( $terms[0]->name )
				) . '</p>';
			}
		}

		return $html;
	}

	/**
	 * Üretilen açıklamayı ürüne kaydeder (save_post_product tetiklenir,
	 * skor otomatik yeniden hesaplanır).
	 *
	 * @param int    $product_id Ürün ID.
	 * @param string $html       generate_description() çıktısı.
	 */
	public static function apply_description( $product_id, $html ) {
		wp_update_post(
			array(
				'ID'           => $product_id,
				'post_content' => wp_slash( $html ),
			)
		);
	}

	/**
	 * Sistem talimatı: SEO uzmanı rolü + marka tonu + dil + uzunluk kuralları.
	 *
	 * @param array $settings Eklenti ayarları.
	 * @return string
	 */
	private static function build_system_instruction( $settings ) {
		$languages = array(
			'tr' => 'Türkçe',
			'en' => 'English',
			'de' => 'Deutsch',
			'fr' => 'Français',
			'es' => 'Español',
			'ar' => 'العربية',
			'ru' => 'Русский',
		);
		$language  = $languages[ $settings['language'] ] ?? $settings['language'];

		$instruction  = 'Sen bir e-ticaret SEO uzmanısın. Verilen ürün bilgilerinden arama motorları için etkili meta veriler üretirsin.';
		$instruction .= "\nÇıktı dili: " . $language . '.';
		if ( '' !== trim( $settings['brand_tone'] ) ) {
			$instruction .= "\nMarka tonu: " . trim( $settings['brand_tone'] ) . '.';
		}
		$instruction .= "\nKurallar:"
			. "\n- seo_title: en fazla 60 karakter, tıklama çekici, ürün adını içermeli."
			. "\n- meta_description: 120-155 karakter arası, fayda odaklı, harekete geçirici."
			. "\n- focus_keyword: 1-3 kelimelik, ürünü en iyi tanımlayan arama terimi."
			. "\n- image_alt: görseli betimleyen kısa, doğal bir cümle (en fazla 125 karakter).";

		return $instruction;
	}

	/**
	 * Kullanıcı mesajı: ürün adı, kategoriler, nitelikler, fiyat, açıklamalar.
	 *
	 * @param WC_Product $product Ürün.
	 * @return string
	 */
	private static function build_product_prompt( $product ) {
		$lines   = array();
		$lines[] = 'Ürün adı: ' . $product->get_name();

		$terms = get_the_terms( $product->get_id(), 'product_cat' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			$lines[] = 'Kategoriler: ' . implode( ', ', wp_list_pluck( $terms, 'name' ) );
		}

		$attributes = array();
		foreach ( $product->get_attributes() as $attribute ) {
			$name  = wc_attribute_label( $attribute->get_name() );
			$value = $product->get_attribute( $attribute->get_name() );
			if ( '' !== $value ) {
				$attributes[] = $name . ': ' . $value;
			}
		}
		if ( $attributes ) {
			$lines[] = 'Nitelikler: ' . implode( ' | ', $attributes );
		}

		$price = $product->get_price();
		if ( '' !== $price && null !== $price ) {
			$lines[] = 'Fiyat: ' . $price . ' ' . get_woocommerce_currency();
		}

		$short = trim( wp_strip_all_tags( strip_shortcodes( $product->get_short_description() ) ) );
		if ( '' !== $short ) {
			$lines[] = 'Kısa açıklama: ' . mb_substr( $short, 0, 500 );
		}

		$long = trim( wp_strip_all_tags( strip_shortcodes( $product->get_description() ) ) );
		if ( '' !== $long ) {
			$lines[] = 'Açıklama: ' . mb_substr( $long, 0, 1500 );
		}

		return implode( "\n", $lines );
	}

	/**
	 * API isteği: 429/503'te exponential backoff ile yeniden dener.
	 *
	 * @param array  $payload     İstek gövdesi.
	 * @param string $api_key     Boşsa kayıtlı key kullanılır.
	 * @param string $model       Boşsa kayıtlı model kullanılır.
	 * @param int    $max_retries 429/503 için en fazla deneme.
	 * @return array|WP_Error Çözümlenmiş yanıt gövdesi.
	 */
	private static function request( $payload, $api_key = '', $model = '', $max_retries = 3 ) {
		$settings = wpwix_get_settings();
		$api_key  = '' !== $api_key ? $api_key : $settings['api_key'];
		$model    = '' !== $model ? $model : self::get_model();

		if ( '' === trim( $api_key ) ) {
			return new WP_Error( 'wpwix_no_key', __( 'Gemini API key girilmemiş.', 'wpwix-seo' ) );
		}

		$url   = sprintf( self::ENDPOINT, rawurlencode( $model ) );
		$delay = 2;

		for ( $attempt = 0; $attempt <= $max_retries; $attempt++ ) {
			$response = wp_remote_post(
				$url,
				array(
					'timeout' => 60,
					'headers' => array(
						'Content-Type'   => 'application/json',
						'x-goog-api-key' => $api_key,
					),
					'body'    => wp_json_encode( $payload ),
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 === $code ) {
				return is_array( $body ) ? $body : array();
			}

			// Ücretsiz katman koruması: kota aşımında bekleyip yeniden dene.
			if ( in_array( $code, array( 429, 503 ), true ) && $attempt < $max_retries ) {
				sleep( $delay );
				$delay *= 2;
				continue;
			}

			$message = $body['error']['message'] ?? sprintf( __( 'API %d hatası döndürdü.', 'wpwix-seo' ), $code );

			return new WP_Error( 'wpwix_api_error', $message, array( 'status' => $code ) );
		}

		return new WP_Error( 'wpwix_rate_limited', __( 'API kotası aşıldı, daha sonra tekrar deneyin.', 'wpwix-seo' ) );
	}
}
