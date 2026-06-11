=== WPWix SEO ===
Contributors: ahmetyuruk
Tags: seo, woocommerce, ai, schema, open graph
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Hızlı ve AI destekli WooCommerce SEO. Toplu ve otomatik: kur, Gemini key gir, "Tümünü Üret", bitti.

== Description ==

WPWix SEO, WooCommerce mağazaları için otomasyon öncelikli bir SEO eklentisidir. Frontend'e sıfır CSS/JS yükler; sadece `<head>` içine meta etiketler ve JSON-LD basar.

Otomatik çıktılar:

* Meta title & description (AI üretimi veya şablon: `%urun_adi% | %site_adi%`)
* Canonical URL
* Open Graph + Twitter Card (ürün görseli, fiyat, stok dahil)
* Tek `@graph` JSON-LD: Product + BreadcrumbList + Organization
* Otomatik noindex: arama, sepet, ödeme, hesap sayfaları

AI özellikleri (Google Gemini) ve XML sitemap sonraki sürümlerde eklenecektir.

== Installation ==

1. `wpwix-seo` klasörünü `/wp-content/plugins/` dizinine yükleyin.
2. Eklentiyi WordPress yönetim panelinden etkinleştirin (WooCommerce kurulu olmalıdır).

== Changelog ==

= 0.1.0 =
* İlk sürüm: meta title/description, canonical, robots (noindex), Open Graph, Twitter Card ve JSON-LD @graph çıktıları.
