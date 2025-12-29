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
