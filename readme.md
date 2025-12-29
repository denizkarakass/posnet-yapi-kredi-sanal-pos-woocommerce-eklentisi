# WooCommerce Posnet (Yapı Kredi) 3D Secure Gateway (Ücretsiz)

Bu repo, **WooCommerce için Yapı Kredi Posnet Sanal POS / 3D Secure** entegrasyonunu **ücretsiz** paylaşmak amacıyla hazırlanmıştır.

> Not: Bu proje Yapı Kredi ile resmi olarak bağlantılı değildir. Kullanım tamamen sizin sorumluluğunuzdadır.

---

## Özellikler

- WooCommerce ödeme yöntemi olarak Posnet ekler
- **3D Secure** akışı (oosRequestData → Banka 3D → oosResolveMerchantData → oosTranData)
- Kart bilgilerini **site üzerinde alma** (merchant page) desteği
- Debug log desteği (**kart/CVV maskelenir**)
- Para birimi mapleme: `TRY → TL`, `USD → US`, `EUR → EU`

---

## Önemli Güvenlik / PCI Notu

Bu entegrasyon **kart numarası ve CVV’yi sizin sitenizde** topluyorsa:
- PCI kapsamı artar (SAQ-A yerine daha ağır SAQ’lar gündeme gelebilir).
- Kart/CVV **asla**:
  - DB’ye yazılmamalı
  - loglara düşmemeli
  - e-posta ile gönderilmemeli
  - cache’lenmemeli

Bu kodda:
- Kart/CVV **order meta’ya kaydedilmez**
- Debug log’da `ccno` ve `cvc` **maskelenir**

Yine de canlıya çıkmadan önce PCI gereksinimlerini kontrol edin.

---

## Gereksinimler

- WordPress + WooCommerce
- PHP 7.4+ (öneri: 8.x)
- HTTPS (zorunlu)
- Yapı Kredi Posnet’ten alınmış MID/TID/PosnetID/ENCKEY ve test/üretim servis URL’leri

---

## Kurulum

### 1) Plugin klasörünü yerleştir
`wp-content/plugins/` altına bu repo klasörünü koyun:

Örnek:

### 2) WordPress’ten eklentiyi etkinleştir
WP Admin → Eklentiler → **WooCommerce Posnet Gateway - Custom** → Etkinleştir

### 3) Kalıcı bağlantıları yenile
WP Admin → Ayarlar → Kalıcı Bağlantılar → **Kaydet**

(Bu, `wc-api=posnet_redirect` ve `wc-api=posnet_return` endpoint’leri için pratikte gerekli oluyor.)

---

## Yapılandırma (WooCommerce)

WooCommerce → Ayarlar → Ödemeler → **Posnet (Yapı Kredi)**

Aşağıdaki alanları doldurun:

- MID (Üye işyeri no)
- TID (Terminal no)
- Posnet ID
- ENCKEY
- XML_SERVICE_URL (test/üretim)
- OOS_TDS_SERVICE_URL (test/üretim)
- Debug log (isteğe bağlı)

> Güvenlik: Bu bilgileri GitHub’a **asla** commit etmeyin.

---

## Test Ortamı

- Bankanın verdiği **test kartları** ile deneyin.
- SKT formatına dikkat:
  - Banka dokümanlarında SKT bazen `YYAA` şeklinde gelir (örn `2902`)
  - Checkout’ta kullanıcı `AA/YY` girer: `02/29`
- CVV testte bankanın verdiği kurala göre değişebilir. Emin değilseniz Posnet Support’tan netleştirin.

---

## Nasıl Çalışır? (Akış)

1) Kullanıcı checkout’ta kart bilgilerini girer  
2) Plugin `oosRequestData` ile Posnet XML servisine gider, `posnetData/posnetData2/digest` alır  
3) Kullanıcı bankanın 3D sayfasına yönlenir (`YKBPaymentService`)  
4) Banka geri dönüşünde:
   - `oosResolveMerchantData` ile doğrulama yapılır
   - `oosTranData` ile finansallaştırma yapılır
5) `approved=1` ise sipariş **payment_complete** olur

---

## Debug / Loglar

WooCommerce → Durum → Günlükler

Kaynak adı: `posnet`

Aşağıdaki loglar kritik:
- `POSNET oosRequestData response(raw): ...`
- `POSNET oosResolveMerchantData response(raw): ...`
- `POSNET oosTranData response(raw): ...`

> Kart bilgileri maskelenir (ccno/cvc).

---

## Sık Karşılaşılan Sorunlar

### 1) “CurrencyCode hatalı”
- Posnet `currencyCode` alanı bazı kurulumlarda **TL/US/EU** bekler.
- WooCommerce para biriminizin `TRY` olduğundan emin olun.

### 2) “CardHolderName hatalı”
- `cardHolderName` alanı bazı kurulumlarda format hassas olabilir.
- Kod, Türkçe karakterleri ASCII’ye indirerek gönderecek şekilde düzenlenmiştir.

### 3) Banka sayfası açılıyor ama sonunda “menşeli banka/satıcı reddetti”
- Bu genelde `oosResolveMerchantData` veya `oosTranData` adımında `approved != 1` demektir.
- Loglardan `respCode/respText` kontrol edin.
- Test kart / CVV / SKT uyumsuzluğu sık görülür.
- MID/TID/PosnetID yetkilerini ve IP tanımını Posnet Support’a teyit ettirin.

---

## Canlı Ortama Geçiş

- Bankanın istediği şekilde test işlemlerini tamamlayın.
- Posnet Support’a:
  - test işlem bilgilerini (sipariş no, tutar, tarih-saat, varsa referans/hostlogkey)
  - IP tanımı bilgisini
  - UAT’ta başarılı akış doğrulamasını
  gönderin.
- Onay sonrası üretim URL’leri ve üretim parametreleri gelir.

---

## Repo Hijyeni / Güvenlik

Önerilen:
- `wp-config.php` veya `env` ile anahtar yönetimi
- Debug log’u üretimde kapatmak
- Güvenlik taraması (WAF, rate limit, bot koruması)

`gitignore` önerisi:

---

## Katkı / PR

- Issue açabilirsiniz (hata, iyileştirme, yeni özellik)
- PR’ler:
  - Kart verisi güvenliği / log maskeleme
  - Taksite girme desteği
  - Daha iyi UI/validation
  - Webhook/return güvenliği

---

## Lisans

Öneri: MIT License  

---

## Sorumluluk Reddi

Bu yazılım “olduğu gibi” sağlanır. Banka/pos servisleri, PCI gereksinimleri ve mevzuata uyumluluk kullanıcı sorumluluğundadır.
