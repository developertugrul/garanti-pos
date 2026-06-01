# Garanti POS Laravel Package

A flawless, easy-to-use, and highly professional Laravel integration for Garanti BBVA Virtual POS API.

## Özellikler (Features)
- Non-3D Satış
- 3D Secure (3D Pay & 3D Model) Satış
- İptal (Cancel)
- İade (Refund)
- Ön Provizyon ve Kapama (PreAuth & PostAuth)
- Puan Sorgulama (Point Inquiry)

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
GARANTI_POS_MODE=TEST # Veya PROD
GARANTI_POS_TERMINAL_ID=12345678
GARANTI_POS_PROV_USER_ID=PROVAUT
GARANTI_POS_PROV_PASSWORD=Sifreniz
GARANTI_POS_MERCHANT_ID=1234567
GARANTI_POS_STORE_KEY=3DSecureAnahtari
```

## Dökümantasyon (Documentation)
Tüm metodlar ve detaylı kullanım örnekleri için lütfen indirdiğiniz klasördeki `/docs/index.html` dosyasına göz atın veya [tıklayın](./docs/index.html).

## Kullanım Örneği

```php
use Developertugrul\GarantiPos\Facades\GarantiPos;

// 3D'siz Satış İşlemi
$response = GarantiPos::pay([
    'order_id' => 'Siparis123',
    'amount' => '100', // 1.00 TL
], [
    'number' => '5400111122223333',
    'expire_month' => '12',
    'expire_year' => '25',
    'cvv' => '123'
]);

// 3D Secure Formu Oluşturma
$formHtml = GarantiPos::build3DForm($orderData, $cardData, 'https://site.com/basarili', 'https://site.com/hata');
```

## Lisans
MIT License.
