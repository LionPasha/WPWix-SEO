# WPWix SEO

**Hızlı & AI destekli WooCommerce SEO eklentisi.** Tek tek uğraşmadan, toplu ve otomatik SEO: kur → Gemini key gir → "Tümünü Üret" → bitti.

- 🌐 [wpwix.com](https://wpwix.com) · Geliştirici: **Ahmet YÜRÜK**
- 🤖 AI: Google **Gemini** API (model seçilebilir)
- 🪶 Frontend'e **sıfır CSS/JS** — sadece `<head>` içine meta + JSON-LD

## Özellikler

### Kurulduğu anda çalışan otomatik çıktılar

| Çıktı | Detay |
|---|---|
| Meta title & description | AI üretmişse onu, yoksa şablonu kullanır: `%urun_adi% \| %site_adi%` |
| Canonical URL | Parametre temizlemeli |
| Open Graph + Twitter Card | Ürün görseli, fiyat, stok dahil |
| JSON-LD | Tek `@graph`: Product (fiyat, stok, SKU, yorum puanı) + BreadcrumbList + Organization |
| XML Sitemap | `/sitemap.xml`, 12 saatlik transient cache, robots.txt'ye otomatik eklenir |
| Otomatik noindex | Arama, sepet, ödeme, hesap sayfaları |

### AI özellikleri (Gemini)

- **Toplu üretim:** "Eksikleri Tara" → tek tıkla tüm ürünlere başlık + açıklama + odak kelime + görsel alt metni. Progress bar, durdur/devam, kaldığı yerden sürdürme. Hata alan ürün atlanır, sonda raporlanır.
- **Yeni ürün otomasyonu (opsiyonel):** Ürün yayınlandığında WP-Cron ile arka planda otomatik üretim.
- **Metabox "AI ile Doldur" butonu:** Tek ürün için manuel tetikleme.
- **JSON çıktı garantisi:** Gemini'nin `responseSchema` özelliği sayesinde her zaman geçerli JSON döner.
- **Ücretsiz katman koruması:** Ayarlanabilir istek aralığı (varsayılan 2 sn) + 429'da exponential backoff.

### Analiz & Skor

- **SEO Skoru (0-100):** Ürün kaydedildiğinde hesaplanır, meta'da tutulur — her sayfa yüklemesinde hesaplanmaz.
- **Ürün listesinde skor sütunu:** Renkli rozet (🟢 80+ / 🟡 50-79 / 🔴 <50), sıralanabilir.
- **Metabox analiz paneli:** Google snippet önizleme, karakter sayaçları (60/155), kontrol listesi.
- **Toplu analiz ekranı:** Skor dağılımı + en düşük skorlu ürünler → oradan direkt "AI ile Düzelt".

Skor sadece bilgilendirir; hiçbir şeyi engellemez veya zorlamaz.

## Kurulum

1. [Son sürümü indirin](https://github.com/LionPasha/WPWix-SEO/releases) veya repoyu `wp-content/plugins/wpwix-seo` olarak klonlayın.
2. WordPress yönetim panelinden eklentiyi etkinleştirin (**WooCommerce kurulu olmalıdır**).
3. **WPWix SEO → Ayarlar**'dan Gemini API key girin ve "Bağlantıyı Test Et" ile doğrulayın.
4. **WPWix SEO → Toplu İşlemler**'den "Eksikleri Tara" → "Tümünü Üret".

## Gemini API key alma

1. [Google AI Studio](https://aistudio.google.com/apikey)'ya gidin ve Google hesabınızla giriş yapın.
2. **Create API key** ile yeni bir key oluşturun.
3. Key'i kopyalayıp eklenti ayarlarına yapıştırın.

> Google AI Studio'nun kendi ücretsiz katmanı vardır. Google AI Pro aboneliği Gemini uygulaması tarafındadır; API kotası ayrı yönetilir — gerekirse AI Studio'da faturalandırma açılarak limitler yükseltilir.

### Model seçimi

| Model | Kullanım |
|---|---|
| `gemini-2.5-flash` | Dengeli — toplu üretim için varsayılan |
| `gemini-2.5-flash-lite` | En hızlı, ücretsiz katmanda en cömert limit |
| `gemini-2.5-pro` | En kaliteli — önemli ürünler / manuel kullanım |
| `gemini-2.0-flash` | Eski model — ücretsiz katman kotası kaldırıldı (`limit: 0` hatası verir) |
| Özel model | Yeni modelleri güncelleme beklemeden kullanın |

## Teknik notlar

- Prefix: `wpwix_`, postmeta: `_wpwixseo_*`, tek option: `wpwixseo_settings` (autoload=no). Özel tablo yok.
- API key sadece sunucu tarafında kullanılır; JS'e/REST'e asla sızmaz.
- Tüm AJAX uçları nonce + `manage_woocommerce` yetkisi ister.
- AI yanıtları kaydedilmeden önce sanitize edilir; tüm çıktılar escape edilir.
- `uninstall.php` tüm option ve meta'ları temizler — temiz kaldırma.

## Lisans

GPLv2 or later.
