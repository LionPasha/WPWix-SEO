=== WPWix SEO ===
Contributors: ahmetyuruk
Tags: seo, woocommerce, ai, schema, open graph
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.0
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

AI özellikleri (Google Gemini):

* Toplu üretim: eksikleri tara, tek tıkla hepsine başlık + açıklama + odak kelime + görsel alt metni (progress bar, durdur/devam)
* Yeni ürün yayınlandığında WP-Cron ile otomatik üretim (opsiyonel)
* Ürün metabox'ında "AI ile Doldur" butonu
* Model seçimi: gemini-2.0-flash / 2.5-flash / 2.5-pro veya özel model adı

Analiz ve skor:

* SEO skoru (0-100) — ürün kaydedildiğinde hesaplanır
* Ürün listesinde sıralanabilir renkli skor sütunu
* Metabox'ta Google snippet önizleme, karakter sayaçları ve kontrol listesi
* Toplu analiz: skor dağılımı + en düşük skorlu ürünler

== Installation ==

1. `wpwix-seo` klasörünü `/wp-content/plugins/` dizinine yükleyin.
2. Eklentiyi WordPress yönetim panelinden etkinleştirin (WooCommerce kurulu olmalıdır).

== Changelog ==

= 1.0.0 =
* XML sitemap (/sitemap.xml, transient cache, robots.txt entegrasyonu)
* Güvenlik turu ve dokümantasyon

= 0.4.0 =
* Toplu işlemler ekranı: skor dağılımı, eksik tarama, toplu AI üretimi (durdur/devam), en düşük skorlular
* Yeni ürün yayınlandığında WP-Cron otomasyonu

= 0.3.0 =
* SEO skoru (0-100), ürün metabox'ı (snippet önizleme, sayaçlar, kontrol listesi, AI butonu), liste sütunu

= 0.2.0 =
* Gemini API istemcisi (responseSchema, exponential backoff) ve ayar sayfası

= 0.1.0 =
* İlk sürüm: meta title/description, canonical, robots (noindex), Open Graph, Twitter Card ve JSON-LD @graph çıktıları.
