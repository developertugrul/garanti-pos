# Garanti BBVA Virtual POS Laravel Package

Garanti BBVA GVP sanal POS entegrasyonu için Laravel uyumlu composer paketi. Paket, repo içindeki resmi `Help/GVP` örneklerine göre XML, 3D form, OOS, GarantiPay, puan, DCC, SMS, ekstre doğrulama, TCKN, CepBank, utility/GSM/MoneyCard, recurring ve sorgu akışlarını üretir.

**Author:** [https://tugrulyildirim.com](https://tugrulyildirim.com/)

## Kurulum

```bash
composer require developertugrul/garanti-pos
php artisan vendor:publish --provider="Developertugrul\GarantiPos\GarantiPosServiceProvider"
```

`.env` örneği:

```env
GARANTI_POS_MODE=TEST
GARANTI_POS_TERMINAL_ID=12345678
GARANTI_POS_TERMINAL_USER_ID=DENEME
GARANTI_POS_MERCHANT_ID=1234567
GARANTI_POS_STORE_KEY=3DSecureAnahtari
GARANTI_POS_CURRENCY=949

# PROVAUT: satış, 3D, preauth/postauth, sorgular, recurring void
GARANTI_POS_PROV_USER_ID=PROVAUT
GARANTI_POS_PROV_PASSWORD=Sifreniz

# PROVRFN: void/iptal, iade, iade iptali
GARANTI_POS_REFUND_USER_ID=PROVRFN
GARANTI_POS_REFUND_PASSWORD=

# PROVOOS: OOS, 3D OOS, GarantiPay/CUSTOM_PAY
GARANTI_POS_PROV_OOS_USER_ID=PROVOOS
GARANTI_POS_PROV_OOS_PASSWORD=
GARANTI_POS_OOS_USER_ID=oosuser
GARANTI_POS_OOS_FORM_CREDENTIAL_ROLE=oos

GARANTI_POS_API_VERSION=v0.01
GARANTI_POS_CHANNEL_CODE=
GARANTI_POS_HASH_ALGORITHM=sha1
```

`GARANTI_POS_REFUND_PASSWORD` veya `GARANTI_POS_PROV_OOS_PASSWORD` boş bırakılırsa paket `GARANTI_POS_PROV_PASSWORD` değerini kullanır. OOS ve GarantiPay hash'i, formda gönderilen `terminalprovuserid=PROVOOS` kullanıcısının şifresiyle hesaplanır.

## Kritik GVP Uyumluluk Notları

- İptal işlemleri bankaya `Type=void` olarak gider. Public API geriye uyum için `cancel()`, `postAuthVoid()` ve `refundVoid()` adlarını korur.
- Bekleyen tekrarlı satış iptali `Type=recurringvoid` olarak, resmi örnekteki gibi `PROVAUT` kullanıcısıyla gider.
- GarantiPay formu `txntype=gpdatarequest`, `txnsubtype=sales`, `secure3dsecuritylevel=CUSTOM_PAY` ve `terminalprovuserid=PROVOOS` üretir. XML DataRequest akışı için `garantiPayDataRequest()` kullanılır ve resmi örnekteki gibi `PROVAUT` ile VPServlet'e gider.
- Legacy `Help/GVP` OOS/CUSTOM_PAY formları `PROVOOS` kullanır; bu yüzden default `GARANTI_POS_OOS_FORM_CREDENTIAL_ROLE=oos` kalır. Güncel Garanti BBVA developer portalındaki `apiversion=512` ortak ödeme form örneği `PROVAUT` gösterdiği için o akışta `GARANTI_POS_OOS_FORM_CREDENTIAL_ROLE=aut` kullanılabilir.
- Puan kullanımı ayrı bir `rewardusage` tipiyle değil, resmi örnekteki gibi `Type=sales` ve `RewardList/Reward` bloğuyla gönderilir.
- 3D/OOS formları `3Dalanlar.txt` içindeki item, address, comment, reward, cheque, utility, GSM ve MoneyCard alanlarını isimli array alanlarıyla veya gerektiğinde `form_fields` passthrough ile destekler.
- Repo içindeki `Help/GVP` örnekleri legacy SHA1 hash kullanır ve paket varsayılanı buna göre `GARANTI_POS_HASH_ALGORITHM=sha1` kalır. Garanti BBVA'nın güncel developer portalındaki SHA512 akışı için `GARANTI_POS_HASH_ALGORITHM=sha512`, genellikle `GARANTI_POS_API_VERSION=512` ve bankanın verdiği güncel endpoint değerleri kullanılmalıdır.
- `validate3DHash()` callback `hashparams/hash` doğrulamasını yapar. `parse3DResponse()` ayrıca `secure3dhash`, `mdstatus`, `procreturncode`, sipariş ve tutar kontrollerini ayrı ayrı raporlar.
- Varsayılan testler canlı bankaya istek atmaz; payload/XML ve hash üretimi fixture testleriyle doğrulanır.

## Kullanım Örnekleri

### 3D Pay

```php
use Developertugrul\GarantiPos\Facades\GarantiPos;

$formHtml = GarantiPos::build3DForm(
    ['order_id' => 'Siparis123', 'amount' => '1000', 'installment' => '', 'security_level' => '3D_PAY'],
    ['number' => '5400...', 'expire_month' => '12', 'expire_year' => '25', 'cvv' => '123'],
    route('payment.success'),
    route('payment.error')
);
```

### 3D OOS

```php
$formHtml = GarantiPos::build3DOOSForm(
    ['order_id' => 'Siparis123', 'amount' => '1000'],
    route('payment.success'),
    route('payment.error')
);
```

### GarantiPay

```php
$formHtml = GarantiPos::buildGarantiPayForm([
    'order_id' => 'Siparis123',
    'amount' => '1000',
    'company_name' => 'Magaza',
    'bnsuseflag' => 'Y',
    'installments' => [
        ['number' => '2', 'amount' => '1000'],
    ],
    'items' => [
        ['number' => '1', 'product_id' => 'SKU-1', 'product_code' => 'SKU-1', 'quantity' => '1', 'price' => '1000', 'total_amount' => '1000'],
    ],
], route('payment.success'), route('payment.error'));
```

GarantiPay XML DataRequest:

```php
$response = GarantiPos::garantiPayDataRequest([
    'order_id' => 'Siparis123',
    'amount' => '1000',
    'company_name' => 'Magaza',
    'bnsuseflag' => 'Y',
    'return_server_url' => route('payment.garantipay.server'),
    'return_url' => route('payment.garantipay.return'),
    'tckn' => '11111111110',
    'gsm_number' => '5XXXXXXXXX',
    'total_installment_count' => '2',
    'installments' => [
        ['number' => '2', 'amount' => '1000', 'ratewithreward' => '0'],
    ],
]);
```

### 3D Model İkinci Adım

```php
$check = GarantiPos::parse3DResponse($request->all(), [
    'order_id' => $order->number,
    'amount' => $order->amount_minor,
]);

if (!$check['hash_valid'] || !$check['md_status_accepted']) {
    abort(400);
}

$response = GarantiPos::pay3DModel(
    ['order_id' => $order->number, 'amount' => $order->amount_minor],
    [],
    $request->all()
);
```

### Puan Kullanımı

```php
$response = GarantiPos::rewardUsage(
    ['order_id' => 'Siparis123', 'amount' => '1000', 'reward_type' => 'BNS'],
    ['number' => '428220...', 'expire_month' => '05', 'expire_year' => '28', 'cvv' => '123'],
    '100'
);
```

### Özel İşlemler

```php
GarantiPos::paySms(['order_id' => 'SMS-1', 'amount' => '100'], $cardData);
GarantiPos::smsPostAuth(['order_id' => 'SMS-1', 'amount' => '100'], '123456');
GarantiPos::payExtre(['order_id' => 'EXTRE-1', 'amount' => '100'], $cardData, '123456789');
GarantiPos::preAuthExtre(['order_id' => 'EXTRE-2', 'amount' => '1'], $cardData, '123456789');
GarantiPos::postAuthExtre(['order_id' => 'EXTRE-3', 'amount' => '1'], '123456789', $cardData);
GarantiPos::payDcc(['order_id' => 'DCC-1', 'amount' => '10000', 'dcc_currency' => '840'], $cardData);
GarantiPos::identifyInquiry(['order_id' => 'TCKN-1', 'amount' => '100'], $cardData, '11111111110');
GarantiPos::buildOOSForm(
    ['order_id' => 'COMMERCIAL-1', 'amount' => '100'],
    route('payment.success'),
    route('payment.error'),
    \Developertugrul\GarantiPos\Enums\TransactionType::COMMERCIAL_CARD
);
GarantiPos::payDownPaymentSale([
    'order_id' => 'DOWN-1',
    'amount' => '1100',
    'down_payment_rate' => '10',
    'installment' => '2',
    'moto_ind' => 'Y',
], $cardData);
GarantiPos::payDelayedSale([
    'order_id' => 'DELAY-1',
    'amount' => '100',
    'delay_day_count' => '10',
    'installment' => '2',
    'moto_ind' => 'Y',
], $cardData);
GarantiPos::payCepBank(['order_id' => 'CEP-1', 'amount' => '100'], [
    'gsm_number' => '5XXXXXXXXX',
    'payment_type' => 'K',
    'hash_date' => '20240613',
    'hash_value' => '...',
]);
GarantiPos::payUtility(['order_id' => 'UTIL-1', 'amount' => '100'], $cardData, [
    'type' => 'F',
    'subscriber_code' => 'SUB-1',
    'invoice_id' => 'INV-1',
]);
GarantiPos::payGsmUnitSales(['order_id' => 'GSM-1', 'amount' => '100'], $cardData, [
    'unit_id' => 'UNIT-1',
    'quantity' => '2',
    'amount' => '50',
]);
GarantiPos::payMoneyCard(['order_id' => 'MONEY-1', 'amount' => '100'], $cardData, [
    'invoice_amount' => '100',
    'migros_cc_discount_amount' => '10',
    'payment_amount' => '90',
]);
GarantiPos::settlementInquiry('20240601', [
    ['currency_code' => '949', 'type' => 'sales', 'count' => '1', 'amount' => '100'],
]);
```

Formlarda resmi alan adlarına karşılık gelen isimli anahtarlar da desteklenir:

```php
$formHtml = GarantiPos::build3DForm([
    'order_id' => 'FORM-1',
    'amount' => '1000',
    'utility_pay_invoice_id' => 'INV-1',
    'gsm_quantity' => '2',
    'money_invoice' => '1000',
    'reward_list' => [
        ['type' => 'BNS', 'used_amount' => '100', 'gained_amount' => '0'],
    ],
    'cheque_list' => [
        ['type' => 'P', 'amount' => '50', 'count' => '1'],
    ],
], $cardData, route('payment.success'), route('payment.error'));
```

## Test

```bash
composer install
vendor/bin/phpunit
```

WSL/XAMPP ortamında:

```bash
cmd.exe /c "cd /d C:\xampp\htdocs\libs\garanti-pos && C:\xampp\php\php.exe vendor\bin\phpunit --configuration phpunit.xml.dist"
```

## Lisans

MIT License.
