# Garanti POS Laravel Package

A flawless, easy-to-use, and highly professional Laravel integration for Garanti BBVA Virtual POS API. Bu paket Garanti Bankası'nın sağladığı tüm Sanal POS (GVP) metodlarını eksiksiz olarak Laravel projelerinizde kullanmanızı sağlar.

## Kurulum (Installation)

1. Paketi projenize dahil edin:
```bash
composer require developertugrul/garanti-pos
```

2. Konfigürasyon dosyasını dışa aktarın:
```bash
php artisan vendor:publish --provider="Developertugrul\GarantiPos\GarantiPosServiceProvider"
```

3. `.env` dosyanıza Garanti POS bilgilerinizi ekleyin:
```env
GARANTI_POS_MODE=TEST # Canlı için PROD
GARANTI_POS_TERMINAL_ID=12345678
GARANTI_POS_PROV_USER_ID=PROVAUT
GARANTI_POS_PROV_PASSWORD=Sifreniz
GARANTI_POS_MERCHANT_ID=1234567
GARANTI_POS_STORE_KEY=3DSecureAnahtari
GARANTI_POS_CURRENCY=949 # 949: TL, 840: USD, 978: EUR
```

## Özellikler (Features)
- **Normal Satış (Non-3D):** 3D secure kullanmadan ödeme alma (Banka izni gerektirir).
- **3D Pay:** 3D doğrulamasının ardından anında ödemenin çekildiği yöntem.
- **3D OOS Pay (Ortak Ödeme Sayfası):** Müşterinin kart bilgilerini bankanın kendi güvenli sayfasında girdiği sistem.
- **3D Model (2 Adımlı):** 3D doğrulamasının alındığı ve ardından arka planda otorizasyon yapılarak tahsilatın tamamlandığı sistem.
- **İptal (Cancel):** Gün sonu alınmamış işlemlerin iptali.
- **İade (Refund):** Gün sonu alınmış işlemlerin iadesi (Kısmi iade desteklenir).
- **Ön Provizyon & Kapama (PreAuth / PostAuth):** Karttan provizyon (bloke) alma ve daha sonra bu tutarı tahsil etme.
- **Puan İşlemleri:** Kredi kartındaki puanların sorgulanması ve tahsilat için kullanılması.
- **Sipariş & Geçmiş Sorgulama:** İşlemlerin anlık banka durumlarının sorgulanması.
- **GarantiPay:** Kullanıcıların mobil uygulama üzerinden ödeme yapabilmesi için form oluşturulması.
- **CepBank:** CepBank uygulaması ile yapılan ödemelerin onaylanıp tahsil edilmesi.
- **Tekrarlı Satış (Recurring):** Düzenli abonelik benzeri tahsilatlar.
- **TCKN Doğrulama:** İşlem esnasında kimlik doğrulama.

## Dökümantasyon (Documentation)
Tüm metodlar, form yapıları, API istek ve yanıt detayları, HTML çıktıları vb. detaylı dökümantasyon için indirdiğiniz dizindeki `docs/index.html` dosyasına göz atın veya [buraya tıklayın](./docs/index.html). Dökümantasyon Bootstrap 5 ile tasarlanmış olup her bir özelliğin entegrasyonu mevcuttur.

## Kullanım Örneği (3D Secure)

```php
use Developertugrul\GarantiPos\Facades\GarantiPos;

// Form oluşturulur ve blade'e gönderilir
$formHtml = GarantiPos::build3DForm(
    ['order_id' => 'Siparis123', 'amount' => '1000', 'installment' => ''], // 10.00 TL
    ['number' => '5400...', 'expire_month' => '12', 'expire_year' => '25', 'cvv' => '123'],
    route('payment.success'),
    route('payment.error')
);

// Blade dosyanızda
{!! $formHtml !!}
```

## Lisans
MIT License.
