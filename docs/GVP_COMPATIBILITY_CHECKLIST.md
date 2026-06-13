# GVP Compatibility Checklist

Bu dosya, paketin repo icindeki resmi `Help/GVP` orneklerine gore kontrol listesidir.
Tiklenen maddeler icin en az bir yerel GVP kaniti ve paket karsiligi vardir.
`Help/GVP` icinde ornegi bulunmayan ama paket tarafinda passthrough olarak desteklenen degerler ayri notlanir.

Son kontrol: 2026-06-13

## Hash ve Guvenlik

- [x] `SecurityData = SHA1(provPassword + 9 digit terminalId)`
  - GVP kaniti: `Help/GVP/GVP/GVP_PHP/SecurityData.php`, `Help/GVP/GVP/GVP_ASP/SecurityData.asp`
  - Paket: `HashGenerator::generateSecurityData()`
  - Test: `HashGeneratorTest::testSecurityDataPadsTerminalIdToNineDigits`
- [x] Legacy XML `HashData = orderId + terminalId + cardNumber + amount + securityData`
  - GVP kaniti: `Help/GVP/GVP/GVP_PHP/XMLPay.php`, `Help/GVP/GVP/GVP_PHP/HashData.php`
  - Paket: `HashGenerator::generateHashData(..., sha1)`
  - Test: `HashGeneratorTest`, `GarantiPosServicePayloadTest`
- [x] Legacy 3D/OOS form hash `terminalId + orderId + amount + successUrl + errorUrl + txntype + installment + storeKey + securityData`
  - GVP kaniti: `Help/GVP/GVP/GVP_PHP/3DPay.php`, `Help/GVP/GVP/GVP_PHP/OOSPay.php`
  - Paket: `HashGenerator::generate3DHash(..., sha1)`, `GarantiPosService::build3DForm()`
  - Test: `HashGeneratorTest`, `GarantiPosServicePayloadTest`
- [x] GarantiPay form hash `txntype=gpdatarequest` ile hesaplanir
  - GVP kaniti: `Help/GVP/GVP/GarantiPay/preprod_3424113_30690133_GarantiPaY.HTML`
  - Paket: `GarantiPosService::buildGarantiPayForm()`
  - Test: `testGarantiPayFormUsesProvoosAndGpdatarequestHash`
- [x] 3D callback `hashparams/hash` dogrulamasi
  - GVP kaniti: `Help/GVP/GVP/Gate3DEngineCallBack.php`, `Help/GVP/GVP/GVP_PHP/Gate3DEngineCallBack.php`
  - Paket: `HashGenerator::validate3DHash()`
  - Test: `testCallbackHashparamsValidation`, `testSha512CallbackHashparamsValidation`
- [x] 3D/OOS callback `secure3dhash`, `mdstatus`, `procreturncode`, order ve tutar ayrik raporlanir
  - GVP kaniti: 3D/OOS result ornekleri ve callback dosyalari
  - Paket: `GarantiPosService::parse3DResponse()`, `pay3DModel()`
  - Test: `testParse3DResponseValidatesSecure3DHashOrderAndAmount`, `testPay3DModelRequiresMdStatusBeforeProvision`
- [x] Guncel SHA512 hash modu opsiyonel desteklenir
  - Not: repo icindeki `Help/GVP` ornekleri SHA1 kullanir; SHA512, guncel portal uyumlulugu icin ek moddur
  - Paket: `GARANTI_POS_HASH_ALGORITHM=sha512`
  - Test: `testSha512ModeBuildsCurrentPortalXmlHash`, `testSha512ModeBuildsCurrentPortal3DFormHash`

## Credential Routing

- [x] Standart XML satis, preauth, postauth, sorgular `PROVAUT`
  - GVP kaniti: satis/sorgu/preauth/postauth istek dosyalari
  - Paket: default credential role `aut`
- [x] Iptal/iade/iade iptali/postauth void `PROVRFN`
  - GVP kaniti: `Help/GVP/GVP/Dokumantasyon/*/Refund.html`, `Void.html`, iade/iptal ornekleri
  - Paket: `cancel()`, `refund()`, `refundVoid()`, `postAuthVoid()`
  - Test: `testVoidOperationsUseProvrfnAndOfficialVoidType`
- [x] Legacy OOS ve GarantiPay/CUSTOM_PAY form `PROVOOS`
  - GVP kaniti: `Help/GVP/GVP/GVP_PHP/OOSPay.php`, `Help/GVP/GVP/GarantiPay/preprod_3424113_30690133_GarantiPaY.HTML`
  - Paket: `prov_oos_user_id`, `prov_oos_password`, `oos_user_id`
  - Test: `testOosFormUsesOosPasswordForHash`, `testGarantiPayFormUsesProvoosAndGpdatarequestHash`
- [x] `recurringvoid` resmi ornekteki gibi `PROVAUT`
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/Bekleyen Tekrarlì Satìƒlarìn ÿptali/Bekleyen_ÿƒlemlerin_iptali.txt`
  - Paket: `recurringCancel()`
  - Test: `testVoidOperationsUseProvrfnAndOfficialVoidType`

## XML Islemleri

- [x] Satis `Type=sales`
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/Satìƒ (sales)/Satìƒ_istek.txt`
  - Paket: `pay()`
- [x] Taksitli satis `InstallmentCnt`
  - GVP kaniti: `Help/GVP/GVP/Dokumantasyon/Türkçe/Taksit.html`
  - Paket: `pay(['installment' => ...], ...)`
- [x] Pesinatli taksitli satis `Type=sales`, `DownPaymentRate`
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/Peƒinatlì_Taksitli_Satìƒ/Peƒinatlì_Taksitli_Satìƒ.txt`
  - Paket: `payDownPaymentSale()`, generic `pay()`
  - Test: `testSpecialXmlBlocksArePlacedUnderOfficialNodes`
- [x] Otelemeli satis `Type=sales`, `DelayDayCount`
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/Ötelemeli Satìƒ/Ötelemeli_Satìƒ.txt`
  - Paket: `payDelayedSale()`, generic `pay()`
  - Test: `testSpecialXmlBlocksArePlacedUnderOfficialNodes`
- [x] Preauth `Type=preauth`
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/Önotorizasyon/Önotorizasyon_istek.txt`
  - Paket: `preAuth()`
- [x] Postauth `Type=postauth`
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/Ön.Oto.Kapama/Satìƒ_istek.txt`
  - Paket: `postAuth()`
- [x] Postauth void `Type=void`
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/Ön.Oto Kapamanìn ÿptali/Ön_Oto_Kapama_ÿptal.txt`
  - Paket: `postAuthVoid()`
- [x] Refund `Type=refund`
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/ÿade/ÿade_istek.txt`
  - Paket: `refund()`
- [x] Cancel/refund void `Type=void`
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/ÿptal/ÿptal_istek.txt`, `ÿadenin_ÿptali_istek.txt`
  - Paket: `cancel()`, `refundVoid()`
  - Test: `testVoidOperationsUseProvrfnAndOfficialVoidType`
- [x] Puan sorgu `Type=rewardinq`
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/Bonus kullanìmì ve sorgulama/Bonus_sorgulama/Bonus_Sorgu_istek.txt`
  - Paket: `pointInquiry()`
- [x] Puan kullanim `Type=sales` + `RewardList/Reward`
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/Bonus kullanìmì ve sorgulama/Bonus_Kullanìmlì_Satìƒ/Bonus_Kullanìm_istek.txt`
  - Paket: `rewardUsage()`, generic `pay()` with `reward_list`
  - Test: `testRewardUsageIsSalesWithRewardList`
- [x] Firma Bonus/FBB ve coklu reward satirlari
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/Bonus kullanìmì ve sorgulama/Firma Bonus/FBB_Kullanìm_istek.txt`
  - Paket: `RewardList` liste destegi
- [x] DCC sorgu `Type=dccinq`
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/DCC/DCC_Sorgu_istek.txt`
  - Paket: `dccInquiry()`
- [x] DCC satis `SubType=dcc`, `DCC/Currency`, `OriginalRetrefNum`
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/DCC/DCC_Satìƒ_istek.txt`
  - Paket: `payDcc()`, generic `pay()`
  - Test: `testSpecialXmlBlocksArePlacedUnderOfficialNodes`
- [x] SMS dogrulama ilk adim `preauth/sales + SubType=sms`
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/SMS_dogrulama/Sms_Dogrulama_istek.txt`
  - Paket: `paySms()`
- [x] SMS postauth `postauth + SubType=sms + Verification/SMSPassword`
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/SMS_dogrulama/Sms_Dogrulama_postauth.txt`
  - Paket: `smsPostAuth()`
  - Test: `testSpecialXmlBlocksArePlacedUnderOfficialNodes`
- [x] Ekstre dogrulama `SubType=extre + Verification/ExtreInfo`
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/Ekstre_Doºrulama/extre_doºrulamalì_istek.txt`, `Önoto_istek.txt`, `Ön_Oto_Kapama_istek.txt`
  - Paket: `payExtre()`, `preAuthExtre()`, `postAuthExtre()`
  - Test: `testSpecialXmlBlocksArePlacedUnderOfficialNodes`
- [x] TCKN dogrulama `Type=identifyinq + Verification/Identity`
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/TCKN_Doºrulama/TCKN_DOºrulama_ÿstek.txt`
  - Paket: `identifyInquiry()`
- [x] CepBank `CepBank/GSMNumber/PaymentType/HashDate/HashValue`
  - GVP kaniti: `Help/GVP/GVP/GVP_PHP/CepBank.php`, `Help/GVP/GVP/Dokumantasyon/Türkçe/request.xml`
  - Paket: `payCepBank()`
  - Test: `testSpecialXmlBlocksArePlacedUnderOfficialNodes`
- [x] UtilityPayment
  - GVP kaniti: `Help/GVP/GVP/Dokumantasyon/Türkçe/request.xml`, `Help/GVP/GVP/Dokumantasyon/English/request.xml`
  - Paket: `payUtility()`, generic `pay()`
  - Test: `testSpecialXmlBlocksArePlacedUnderOfficialNodes`
- [x] GSMUnitSales
  - GVP kaniti: `Help/GVP/GVP/Dokumantasyon/Türkçe/request.xml`, `Help/GVP/GVP/Dokumantasyon/English/request.xml`
  - Paket: `payGsmUnitSales()`, generic `pay()`
  - Test: `testSpecialXmlBlocksArePlacedUnderOfficialNodes`
- [x] MoneyCard
  - GVP kaniti: `Help/GVP/GVP/Dokumantasyon/Türkçe/request.xml`, `Help/GVP/GVP/Dokumantasyon/English/request.xml`
  - Paket: `payMoneyCard()`, generic `pay()`
  - Test: `testSpecialXmlBlocksArePlacedUnderOfficialNodes`
- [x] ItemList/Item
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/Ürün Bilgisi Gönderimi/Ürün_Bilgisi_Gönderimi_Satìƒ_istek.txt`
  - Paket: `items` / `item_list`, `XmlBuilder`
  - Test: `XmlBuilderTest`, form field testleri
- [x] AddressList/Address, `GsmNumber` dahil
  - GVP kaniti: `Help/GVP/GVP/Dokumantasyon/Türkçe/request.xml`, `Adres_Bilgisi_gönderimi.txt`
  - Paket: `addresses` / `address_list`
  - Test: `testAddressListUsesOfficialGsmNumberNode`
- [x] CommentList/Comment
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/Özel Alan Gönderimi/Özel_Alan_Kullanìm_istek.txt`
  - Paket: `comments` / `comment_list`
  - Test: `XmlBuilderTest`, form field testleri
- [x] Sabit/degisken recurring satis `Order/Recurring`
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/Tekrarlì Satìƒ/Sabit_Tutarlì_Tekrarlì_Satìƒ.txt`, `Deºiƒken_Tutarlì_Tekrarlì_Satìƒ.txt`
  - Paket: `payRecurring()`, generic `pay()`
- [x] Recurring update `Type=recurringupdate`, `Recurring/PaymentList`
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/Bekleyen Tekrarlayan Satìƒ Tutar Deºiƒikliºi/Tekrarlì_ÿƒlem_Tekrar_tutarlarìnìn_deºiƒtirilmesi.txt`
  - Paket: `recurringUpdate()`
- [x] Recurring void `Type=recurringvoid`
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/Bekleyen Tekrarlì Satìƒlarìn ÿptali/Bekleyen_ÿƒlemlerin_iptali.txt`
  - Paket: `recurringCancel()`
  - Test: `testVoidOperationsUseProvrfnAndOfficialVoidType`
- [x] Tuketici kredisi/vadeli taksit `Type=extendedcredit`
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/Tüketici Kredisi/Tüketici_Kredisi_istek.txt`, `Vadeli_Taksit_istek.txt`
  - Paket: `payExtendedCredit()`
- [x] Extended credit inquiry `Type=extendedcreditinq`
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/Tüketici Kredisi Sorgulama/Tüketici_Kredisi_Sorgu_istek.txt`
  - Paket: `extendedCreditInquiry()`
- [x] Ticari kart vadeli islem `Type=commercialcardextendedcredit`
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/Ticari Kart ÿƒlemi/Ticari_Kart_Vadeli_iƒlem_istek.txt`
  - Paket: `payCommercialCardExtendedCredit()`
  - Test: `testSpecialXmlBlocksArePlacedUnderOfficialNodes`
- [x] Guncel ortak odeme formu ortak kart `txntype=commercialcard`
  - Not: `Help/GVP` XML ornegi `commercialcardextendedcredit`; guncel developer portal ortak odeme formu `commercialcard` form tipini de belirtir.
  - Paket: `TransactionType::COMMERCIAL_CARD`, `buildOOSForm(..., TransactionType::COMMERCIAL_CARD)`
  - Test: `testCurrentPortalCommercialCardFormTypeIsPassedThrough`
- [x] GarantiPay XML DataRequest `Type=gpdatarequest`, `SubType=sales`, `GarantiPaY`
  - GVP kaniti: `Help/GVP/GVP/GarantiPay/preprod - DataRequest.html`, `Help/GVP/GVP/GarantiPay/GarantipayXML.asp`
  - Paket: `garantiPayDataRequest()`
  - Test: `testGarantiPayXmlDataRequestBuildsOfficialBlock`

## Sorgular

- [x] Order inquiry `Type=orderinq`
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/ÿƒlem Sorgu/ÿƒlem_Sorgu_istek.txt`
  - Paket: `orderInquiry()`
- [x] Order history/detail inquiry `Type=orderhistoryinq`
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/ÿƒlem Detay Sorgu/ÿƒlem_Detay_Sorgu_istek.txt`
  - Paket: `orderHistoryInquiry()`
- [x] Order list/date range inquiry `Type=orderlistinq`; `StartDate/EndDate` Order altinda, `ListPageNum` Transaction altinda
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/Tarih Aralìºì ÿƒlem Sorgu/Tarih_aralìºì_ile_iƒlem_sorgu.txt`
  - Paket: `orderListInquiry()`
  - Test: `testInquiryPayloadsUseOfficialFieldLocations`
- [x] Batch/gunsonu inquiry `Type=batchinq`; `BatchNum/ListPageNum` Transaction altinda
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/Günsonu sorgulama/Gunsonu_Sorgulama_Istek.txt`
  - Paket: `batchInquiry()`
  - Test: `testInquiryPayloadsUseOfficialFieldLocations`
- [x] BIN inquiry `Type=bininq`
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/Bin sorgulama/BINInquiry.html`
  - Paket: `binInquiry()`
- [x] Campaign code inquiry `Type=campaigncodeinq`, resmi `CampaingCode` yazimi
  - GVP kaniti: `Help/GVP/GVP/ÿƒlemler_Açìklamalar_Örnekler/Kampanya Kodu Sorgulama/gata.txt`
  - Paket: `campaignCodeInquiry()`
  - Test: `testInquiryPayloadsUseOfficialFieldLocations`
- [x] Settlement inquiry root `SettlementInq`
  - GVP kaniti: `Help/GVP/GVP/Dokumantasyon/Türkçe/request.xml`, `Help/GVP/GVP/Dokumantasyon/English/request.xml`
  - Paket: `settlementInquiry()`
  - Test: `testSettlementInquiryBuildsRootSettlementBlock`

## 3D/OOS/Form Akislari

- [x] `3D_PAY`
  - GVP kaniti: `Help/GVP/GVP/GVP_PHP/3DPay.php`
  - Paket: `build3DForm(..., security_level=3D_PAY)`
- [x] `3D_FULL`, `3D_HALF`
  - GVP kaniti: `Help/GVP/GVP/GVP_PHP/3DPay.php`, `Help/GVP/GVP/Dokumantasyon/English/3D_TEST.html`
  - Paket: `build3DForm()` ile `security_level` passthrough
- [ ] `3D` generic security level
  - Not: `Help/GVP` orneklerinde acik `3D` secenegi bulunmadi; paket `security_level` degerini passthrough olarak gonderebilir.
  - Paket: `build3DForm(..., ['security_level' => '3D'])`
- [x] `OOS_PAY`
  - GVP kaniti: `Help/GVP/GVP/GVP_PHP/OOSPay.php`
  - Paket: `buildOOSForm()`
- [x] `3D_OOS_PAY`, `3D_OOS_FULL`, `3D_OOS_HALF`
  - GVP kaniti: `Help/GVP/GVP/GVP_PHP/3DOOSPay.php`, `3Dalanlar.txt`
  - Paket: `build3DOOSForm()` ve `security_level` passthrough
- [x] `CUSTOM_PAY` / GarantiPay hosted form
  - GVP kaniti: `Help/GVP/GVP/GarantiPay/preprod_3424113_30690133_GarantiPaY.HTML`
  - Paket: `buildGarantiPayForm()`
  - Test: `testGarantiPayFormUsesProvoosAndGpdatarequestHash`
- [ ] `QR_PAY` generic form seviyesi
  - Not: `Help/GVP` icinde acik `QR_PAY` ornegi bulunmadi; plan kapsaminda generic passthrough olarak desteklenir, banka/terminal onayi gerekir.
  - Paket: `security_level=QR_PAY` desteklenir
- [x] Form ekstra alanlari: item, address, comment, reward, cheque, utility, GSM, MoneyCard, recurring
  - GVP kaniti: `3Dalanlar.txt`, `request.xml`
  - Paket: `mergeFormFields()`, `form_fields` passthrough
  - Test: `testOfficialFormFieldFamiliesAreNamedInputs`

## XML Uretimi

- [x] Tekrarlayan listeler dogru node ile uretilir
  - Kapsam: `AddressList/Address`, `ItemList/Item`, `CommentList/Comment`, `RewardList/Reward`, `ChequeList/Cheque`, `PaymentList/Payment`, `GPInstallments/Installment`, `TransactionSummList/TransactionSumm`
  - Paket: `XmlBuilder`
  - Test: `XmlBuilderTest::testBuildsRepeatedOfficialListNodes`
- [x] XML degerleri cift encode edilmez
  - Paket: `DOMDocument` tabanli `XmlBuilder`
  - Test: `XmlBuilderTest::testXmlValuesAreEscapedOnce`

## Canli Test Durumu

- [ ] Canli banka provizyon testi
  - Durum: Gercek merchant/terminal credential olmadigi icin calistirilmadi.
  - Beklenen manuel smoke: 3D form aciliyor mu, GarantiPay banka sayfasi aciliyor mu, XML test ortaminda `procreturncode=00` donuyor mu, callback hash/order/amount/mdstatus kontrolleri geciyor mu.
