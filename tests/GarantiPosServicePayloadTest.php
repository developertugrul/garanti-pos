<?php

namespace Developertugrul\GarantiPos\Tests;

use Developertugrul\GarantiPos\Enums\TransactionType;
use Developertugrul\GarantiPos\Services\GarantiPosService;
use Developertugrul\GarantiPos\Services\HashGenerator;
use Developertugrul\GarantiPos\Exceptions\GarantiPosException;
use PHPUnit\Framework\TestCase;

class GarantiPosServicePayloadTest extends TestCase
{
    private function service(): CapturingGarantiPosService
    {
        return new CapturingGarantiPosService([
            'mode' => 'TEST',
            'terminal_id' => '30690133',
            'terminal_user_id' => 'DENEME',
            'merchant_id' => '3424113',
            'store_key' => '12345678',
            'currency' => '949',
            'prov_user_id' => 'PROVAUT',
            'prov_password' => 'aut-pass',
            'refund_user_id' => 'PROVRFN',
            'refund_password' => 'refund-pass',
            'prov_oos_user_id' => 'PROVOOS',
            'prov_oos_password' => 'oos-pass',
            'oos_user_id' => 'oosuser',
        ]);
    }

    private function sha512Service(): CapturingGarantiPosService
    {
        return new CapturingGarantiPosService([
            'mode' => 'TEST',
            'terminal_id' => '30691297',
            'terminal_user_id' => 'PROVAUT',
            'merchant_id' => '7000679',
            'store_key' => '12345678',
            'currency' => '949',
            'prov_user_id' => 'PROVAUT',
            'prov_password' => '123qweASD/',
            'prov_oos_user_id' => 'PROVOOS',
            'prov_oos_password' => '123qweASD/',
            'oos_user_id' => 'PROVOOS',
            'oos_form_credential_role' => 'aut',
            'hash_algorithm' => 'sha512',
            'api_version' => '512',
        ]);
    }

    public function testSha512ModeBuildsCurrentPortalXmlHash(): void
    {
        $response = $this->sha512Service()->pay([
            'order_id' => 'da4009cff27645978084d04c7accaf46',
            'amount' => '10000',
            'currency' => '949',
        ], [
            'number' => '5406697543211173',
            'expire_date' => '0323',
            'cvv' => '465',
        ]);

        $this->assertSame(
            'E88EA8FBFAECA0516911851A22D3F06C3D7CB64B830B20D1B4DEB7F4C0CCD4773AA9F872F28124F843EBB06B41D0CA137F5C61775A043CEBDA985A8E85BA0DEF',
            $response['payload']['Terminal']['HashData']
        );
        $this->assertSame('512', $response['payload']['Version']);
    }

    public function testSha512ModeBuildsCurrentPortal3DFormHash(): void
    {
        $html = $this->sha512Service()->build3DForm([
            'order_id' => 'SHA512-3D',
            'amount' => '10000',
            'currency' => '949',
            'installment' => '',
        ], ['number' => '5406697543211173'], 'https://ok', 'https://err');
        $inputs = $this->formInputs($html);
        $securityData = HashGenerator::generateSecurityData('123qweASD/', '30691297');

        $this->assertSame(
            HashGenerator::generate3DHash('30691297', 'SHA512-3D', '10000', 'https://ok', 'https://err', 'sales', '', '12345678', $securityData, 'sha512', '949'),
            $inputs['secure3dhash']
        );
        $this->assertSame('512', $inputs['apiversion']);
    }

    public function testSha512ModeCanBuildCurrentPortalOosWithProvaut(): void
    {
        $html = $this->sha512Service()->buildOOSForm([
            'order_id' => 'SHA512-OOS',
            'amount' => '100',
            'currency' => '949',
        ], 'https://ok', 'https://err');
        $inputs = $this->formInputs($html);
        $securityData = HashGenerator::generateSecurityData('123qweASD/', '30691297');

        $this->assertSame('PROVAUT', $inputs['terminalprovuserid']);
        $this->assertSame('PROVAUT', $inputs['terminaluserid']);
        $this->assertSame('OOS_PAY', $inputs['secure3dsecuritylevel']);
        $this->assertSame(
            HashGenerator::generate3DHash('30691297', 'SHA512-OOS', '100', 'https://ok', 'https://err', 'sales', '', '12345678', $securityData, 'sha512', '949'),
            $inputs['secure3dhash']
        );
    }

    public function testCurrentPortalCommercialCardFormTypeIsPassedThrough(): void
    {
        $html = $this->sha512Service()->buildOOSForm([
            'order_id' => 'SHA512-COMMERCIAL',
            'amount' => '100',
            'currency' => '949',
        ], 'https://ok', 'https://err', TransactionType::COMMERCIAL_CARD);
        $inputs = $this->formInputs($html);
        $securityData = HashGenerator::generateSecurityData('123qweASD/', '30691297');

        $this->assertSame('commercialcard', $inputs['txntype']);
        $this->assertSame(
            HashGenerator::generate3DHash('30691297', 'SHA512-COMMERCIAL', '100', 'https://ok', 'https://err', 'commercialcard', '', '12345678', $securityData, 'sha512', '949'),
            $inputs['secure3dhash']
        );
    }

    public function testGarantiPayFormUsesProvoosAndGpdatarequestHash(): void
    {
        $html = $this->service()->buildGarantiPayForm([
            'order_id' => 'GP-1',
            'amount' => '100',
            'installments' => [
                ['number' => '2', 'amount' => '100'],
            ],
            'items' => [
                ['number' => '1', 'product_id' => 'A1', 'product_code' => 'A1', 'quantity' => '1', 'price' => '100', 'total_amount' => '100'],
            ],
        ], 'https://ok', 'https://err');

        $inputs = $this->formInputs($html);
        $securityData = HashGenerator::generateSecurityData('oos-pass', '30690133');
        $expectedHash = HashGenerator::generate3DHash('30690133', 'GP-1', '100', 'https://ok', 'https://err', 'gpdatarequest', '', '12345678', $securityData);

        $this->assertSame('PROVOOS', $inputs['terminalprovuserid']);
        $this->assertSame('oosuser', $inputs['terminaluserid']);
        $this->assertSame('CUSTOM_PAY', $inputs['secure3dsecuritylevel']);
        $this->assertSame('gpdatarequest', $inputs['txntype']);
        $this->assertSame('sales', $inputs['txnsubtype']);
        $this->assertSame('Y', $inputs['garantipay']);
        $this->assertSame($expectedHash, $inputs['secure3dhash']);
        $this->assertSame('1', $inputs['totallinstallmentcount']);
        $this->assertSame('A1', $inputs['orderitemproductid1']);
    }

    public function testGarantiPayXmlDataRequestBuildsOfficialBlock(): void
    {
        $response = $this->service()->garantiPayDataRequest([
            'order_id' => 'GPXML-1',
            'amount' => '100',
            'company_name' => 'GARANTI TEST',
            'bnsuseflag' => 'Y',
            'return_server_url' => 'https://server-return',
            'return_url' => 'https://return',
            'tckn' => '11111111110',
            'gsm_number' => '5350000000',
            'total_installment_count' => '2',
            'installments' => [
                ['Installmentnumber' => '1', 'Installmentamount' => '50', 'Installmentratewithreward' => '0'],
                ['Installmentnumber' => '2', 'Installmentamount' => '50', 'Installmentratewithreward' => '0'],
            ],
        ]);
        $payload = $response['payload'];

        $this->assertSame('PROVAUT', $payload['Terminal']['ProvUserID']);
        $this->assertSame('gpdatarequest', $payload['Transaction']['Type']);
        $this->assertSame('sales', $payload['Transaction']['SubType']);
        $this->assertSame('https://server-return', $payload['Transaction']['ReturnServerUrl']);
        $this->assertSame('Y', $payload['Transaction']['GarantiPaY']['bnsuseflag']);
        $this->assertSame('N', $payload['Transaction']['GarantiPaY']['fbbuseflag']);
        $this->assertSame('GARANTI TEST', $payload['Transaction']['GarantiPaY']['CompanyName']);
        $this->assertSame('11111111110', $payload['Transaction']['GarantiPaY']['TCKN']);
        $this->assertSame('5350000000', $payload['Transaction']['GarantiPaY']['GSMNumber']);
        $this->assertCount(2, $payload['Transaction']['GarantiPaY']['GPInstallments']);
        $this->assertStringContainsString('<GarantiPaY>', $response['xml']);
        $this->assertStringContainsString('<GPInstallments><Installment>', $response['xml']);
    }

    public function testOosFormUsesOosPasswordForHash(): void
    {
        $html = $this->service()->build3DOOSForm(['order_id' => 'OOS-1', 'amount' => '100'], 'https://ok', 'https://err');
        $inputs = $this->formInputs($html);
        $securityData = HashGenerator::generateSecurityData('oos-pass', '30690133');

        $this->assertSame('PROVOOS', $inputs['terminalprovuserid']);
        $this->assertSame('3D_OOS_PAY', $inputs['secure3dsecuritylevel']);
        $this->assertSame(
            HashGenerator::generate3DHash('30690133', 'OOS-1', '100', 'https://ok', 'https://err', 'sales', '', '12345678', $securityData),
            $inputs['secure3dhash']
        );
    }

    public function testVoidOperationsUseProvrfnAndOfficialVoidType(): void
    {
        $service = $this->service();
        $response = $service->cancel('ORDER-1', '708313661999');
        $payload = $response['payload'];

        $this->assertSame('PROVRFN', $payload['Terminal']['ProvUserID']);
        $this->assertSame('void', $payload['Transaction']['Type']);
        $this->assertSame('708313661999', $payload['Transaction']['OriginalRetrefNum']);

        $recurringVoid = $service->recurringCancel('RECURRING-1', '1');
        $this->assertSame('PROVAUT', $recurringVoid['payload']['Terminal']['ProvUserID']);
        $this->assertSame('recurringvoid', $recurringVoid['payload']['Transaction']['Type']);
        $this->assertSame('1', $recurringVoid['payload']['Transaction']['Amount']);
    }

    public function testRewardUsageIsSalesWithRewardList(): void
    {
        $response = $this->service()->rewardUsage(['order_id' => 'BONUS-1', 'amount' => '1000'], [
            'number' => '4282209027132016',
            'expire_month' => '05',
            'expire_year' => '18',
        ], '100');
        $payload = $response['payload'];

        $this->assertSame('sales', $payload['Transaction']['Type']);
        $this->assertSame('1000', $payload['Transaction']['Amount']);
        $this->assertSame('BNS', $payload['Transaction']['RewardList'][0]['Type']);
        $this->assertSame('100', $payload['Transaction']['RewardList'][0]['UsedAmount']);
    }

    public function testSpecialXmlBlocksArePlacedUnderOfficialNodes(): void
    {
        $service = $this->service();

        $sms = $service->smsPostAuth(['order_id' => 'SMS-1', 'amount' => '100'], '123456');
        $this->assertSame('sms', $sms['payload']['Transaction']['SubType']);
        $this->assertSame('123456', $sms['payload']['Transaction']['Verification']['SMSPassword']);

        $extre = $service->preAuthExtre(['order_id' => 'EXTRE-1', 'amount' => '1'], [
            'number' => '5149154661209011',
        ], '123456789');
        $this->assertSame('preauth', $extre['payload']['Transaction']['Type']);
        $this->assertSame('extre', $extre['payload']['Transaction']['SubType']);
        $this->assertSame('H', $extre['payload']['Transaction']['MotoInd']);
        $this->assertSame('123456789', $extre['payload']['Transaction']['Verification']['ExtreInfo']);

        $dcc = $service->payDcc(['order_id' => 'DCC-1', 'amount' => '10000', 'dcc_currency' => '840'], [
            'number' => '5149154661209011',
        ]);
        $this->assertSame('dcc', $dcc['payload']['Transaction']['SubType']);
        $this->assertSame('H', $dcc['payload']['Transaction']['MotoInd']);
        $this->assertSame('840', $dcc['payload']['Transaction']['DCC']['Currency']);

        $utility = $service->payUtility(['order_id' => 'UTILITY-1', 'amount' => '100'], [
            'number' => '4282209027132016',
        ], [
            'type' => 'F',
            'subscriber_code' => 'SUB-1',
            'invoice_id' => 'INV-1',
        ]);
        $this->assertSame('F', $utility['payload']['Transaction']['UtilityPayment']['Type']);
        $this->assertSame('SUB-1', $utility['payload']['Transaction']['UtilityPayment']['SubscriberCode']);
        $this->assertSame('INV-1', $utility['payload']['Transaction']['UtilityPayment']['InvoiceID']);

        $gsm = $service->payGsmUnitSales(['order_id' => 'GSM-1', 'amount' => '100'], [
            'number' => '4282209027132016',
        ], [
            'unit_id' => 'UNIT-1',
            'quantity' => '2',
            'amount' => '50',
        ]);
        $this->assertSame('UNIT-1', $gsm['payload']['Transaction']['GSMUnitSales']['UnitID']);
        $this->assertSame('2', $gsm['payload']['Transaction']['GSMUnitSales']['Quantity']);
        $this->assertSame('50', $gsm['payload']['Transaction']['GSMUnitSales']['Amount']);

        $money = $service->payMoneyCard(['order_id' => 'MONEY-1', 'amount' => '100'], [
            'number' => '4282209027132016',
        ], [
            'invoice_amount' => '100',
            'migros_cc_discount_amount' => '10',
            'payment_amount' => '90',
            'extra_discount_amount' => '0',
            'product_based_discount_amount' => '0',
        ]);
        $this->assertSame('100', $money['payload']['Transaction']['MoneyCard']['InvoiceAmount']);
        $this->assertSame('10', $money['payload']['Transaction']['MoneyCard']['MigrosCCDiscountAmount']);
        $this->assertSame('90', $money['payload']['Transaction']['MoneyCard']['PaymentAmount']);

        $cepBank = $service->payCepBank(['order_id' => 'CEP-1', 'amount' => '100'], [
            'gsm_number' => '5350000000',
            'payment_type' => 'K',
            'hash_date' => '20240613',
            'hash_value' => 'ABC123',
        ]);
        $this->assertSame('5350000000', $cepBank['payload']['Transaction']['CepBank']['GSMNumber']);
        $this->assertSame('K', $cepBank['payload']['Transaction']['CepBank']['PaymentType']);
        $this->assertSame('20240613', $cepBank['payload']['Transaction']['CepBank']['HashDate']);
        $this->assertSame('ABC123', $cepBank['payload']['Transaction']['CepBank']['HashValue']);

        $downPayment = $service->payDownPaymentSale([
            'order_id' => 'DOWN-1',
            'amount' => '1100',
            'down_payment_rate' => '10',
            'installment' => '2',
            'moto_ind' => 'Y',
        ], ['number' => '4672939003398011']);
        $this->assertSame('sales', $downPayment['payload']['Transaction']['Type']);
        $this->assertSame('10', $downPayment['payload']['Transaction']['DownPaymentRate']);
        $this->assertSame('2', $downPayment['payload']['Transaction']['InstallmentCnt']);

        $delayed = $service->payDelayedSale([
            'order_id' => 'DELAY-1',
            'amount' => '100',
            'delay_day_count' => '10',
            'installment' => '2',
            'moto_ind' => 'Y',
        ], ['number' => '4282209027132016']);
        $this->assertSame('sales', $delayed['payload']['Transaction']['Type']);
        $this->assertSame('10', $delayed['payload']['Transaction']['DelayDayCount']);
        $this->assertSame('2', $delayed['payload']['Transaction']['InstallmentCnt']);

        $commercial = $service->payCommercialCardExtendedCredit([
            'order_id' => 'CC-1',
            'amount' => '2422',
            'payments' => [
                ['Number' => '1', 'DueDate' => '20110307', 'Amount' => '1211'],
                ['Number' => '2', 'DueDate' => '20110309', 'Amount' => '1211'],
            ],
        ], ['number' => '']);
        $this->assertSame('commercialcardextendedcredit', $commercial['payload']['Transaction']['Type']);
        $this->assertSame('H', $commercial['payload']['Transaction']['MotoInd']);
        $this->assertCount(2, $commercial['payload']['Transaction']['CommercialCardExtendedCredit']['PaymentList']);
    }

    public function testAddressListUsesOfficialGsmNumberNode(): void
    {
        $response = $this->service()->pay([
            'order_id' => 'ADDRESS-1',
            'amount' => '100',
            'addresses' => [
                [
                    'type' => 'S',
                    'name' => 'Test',
                    'last_name' => 'User',
                    'gsm_number' => '5350000000',
                ],
            ],
        ], ['number' => '4282209027132016']);

        $this->assertSame('5350000000', $response['payload']['Order']['AddressList'][0]['GsmNumber']);
        $this->assertArrayNotHasKey('GSMNumber', $response['payload']['Order']['AddressList'][0]);
        $this->assertStringContainsString('<GsmNumber>5350000000</GsmNumber>', $response['xml']);
    }

    public function testOfficialFormFieldFamiliesAreNamedInputs(): void
    {
        $html = $this->service()->build3DForm([
            'order_id' => 'FORM-1',
            'amount' => '1000',
            'utility_pay_invoice_id' => 'INV-1',
            'utility_pay_subscriber_code' => 'SUB-1',
            'utility_pay_type' => 'E',
            'gsm_quantity' => '2',
            'gsm_sales_amount' => '500',
            'gsm_sales_unit_id' => 'UNIT-1',
            'money_invoice' => '1000',
            'money_payment' => '900',
            'reward_list' => [
                ['type' => 'BNS', 'used_amount' => '100', 'gained_amount' => '0'],
            ],
            'cheque_list' => [
                ['type' => 'P', 'amount' => '50', 'bitmap' => 'BITMAP', 'id' => 'CHK-1', 'count' => '1'],
            ],
            'recurring' => [
                'type' => 'R',
                'total_payment_num' => '2',
                'frequency_type' => 'M',
                'frequency_interval' => '1',
                'start_date' => '20221213',
                'payment_list' => [
                    ['amount' => '500'],
                    ['amount' => '500'],
                ],
            ],
        ], ['number' => '4282209027132016', 'cardholder_name' => 'Test User'], 'https://ok', 'https://err');

        $inputs = $this->formInputs($html);

        $this->assertSame('Test User', $inputs['cardholdername']);
        $this->assertSame('INV-1', $inputs['utilitypayinvoiceid']);
        $this->assertSame('SUB-1', $inputs['utilitypaysubscode']);
        $this->assertSame('E', $inputs['utilitypaytype']);
        $this->assertSame('2', $inputs['gsmquantity']);
        $this->assertSame('500', $inputs['gsmsalesamnt']);
        $this->assertSame('UNIT-1', $inputs['gsmsalesunitid']);
        $this->assertSame('1000', $inputs['moneyinvoice']);
        $this->assertSame('900', $inputs['moneypayment']);
        $this->assertSame('1', $inputs['txnrewardcount']);
        $this->assertSame('BNS', $inputs['txnrewardtype1']);
        $this->assertSame('100', $inputs['txnrewardusedamount1']);
        $this->assertSame('1', $inputs['txnchequecount']);
        $this->assertSame('P', $inputs['txnchequetype1']);
        $this->assertSame('50', $inputs['txnchequeamount1']);
        $this->assertSame('1', $inputs['txnchequecount1']);
        $this->assertSame('R', $inputs['recurringtype']);
        $this->assertSame('2', $inputs['totalpaymentnum']);
        $this->assertSame('M', $inputs['frequencytype']);
        $this->assertSame('1', $inputs['frequencyinterval']);
        $this->assertSame('20221213', $inputs['startdate']);
        $this->assertSame('500', $inputs['recurringamount1']);
        $this->assertSame('500', $inputs['recurringamount2']);
    }

    public function testInquiryPayloadsUseOfficialFieldLocations(): void
    {
        $service = $this->service();

        $orderList = $service->orderListInquiry('24/02/2015 23:00', '25/02/2015 15:11', 1, 'GARANTI_TEST_019');
        $this->assertSame('24/02/2015 23:00', $orderList['payload']['Order']['StartDate']);
        $this->assertSame('1', $orderList['payload']['Transaction']['ListPageNum']);

        $batch = $service->batchInquiry('1680', 1, 'GARANTI_TEST_011');
        $this->assertSame('1680', $batch['payload']['Transaction']['BatchNum']);

        $campaign = $service->campaignCodeInquiry('51170390');
        $this->assertSame('51170390', $campaign['payload']['Transaction']['CampaingCode']);
    }

    public function testSettlementInquiryBuildsRootSettlementBlock(): void
    {
        $response = $this->service()->settlementInquiry('20240601', [
            ['currency_code' => '949', 'type' => 'sales', 'count' => '1', 'amount' => '100'],
        ]);
        $payload = $response['payload'];

        $this->assertArrayHasKey('SettlementInq', $payload);
        $this->assertArrayNotHasKey('Transaction', $payload);
        $this->assertSame('20240601', $payload['SettlementInq']['Date']);
        $this->assertSame('949', $payload['SettlementInq']['TransactionSummList'][0]['CurrencyCode']);
        $this->assertSame('sales', $payload['SettlementInq']['TransactionSummList'][0]['Type']);
        $this->assertStringContainsString('<SettlementInq><Date>20240601</Date>', $response['xml']);
        $this->assertStringContainsString('<TransactionSumm><CurrencyCode>949</CurrencyCode>', $response['xml']);
    }

    public function testParse3DResponseValidatesSecure3DHashOrderAndAmount(): void
    {
        $securityData = HashGenerator::generateSecurityData('aut-pass', '30690133');
        $hash = HashGenerator::generate3DHash('30690133', '3D-1', '100', 'https://ok', 'https://err', 'sales', '', '12345678', $securityData);

        $result = $this->service()->parse3DResponse([
            'terminalprovuserid' => 'PROVAUT',
            'clientid' => '30690133',
            'orderid' => '3D-1',
            'txnamount' => '100',
            'successurl' => 'https://ok',
            'errorurl' => 'https://err',
            'txntype' => 'sales',
            'txninstallmentcount' => '',
            'secure3dhash' => $hash,
            'mdstatus' => '1',
        ], ['order_id' => '3D-1', 'amount' => '100']);

        $this->assertTrue($result['hash_valid']);
        $this->assertTrue($result['md_status_accepted']);
        $this->assertTrue($result['order_matches']);
        $this->assertTrue($result['amount_matches']);
    }

    public function testPay3DModelRequiresMdStatusBeforeProvision(): void
    {
        $securityData = HashGenerator::generateSecurityData('aut-pass', '30690133');
        $hash = HashGenerator::generate3DHash('30690133', '3D-MISSING', '100', 'https://ok', 'https://err', 'sales', '', '12345678', $securityData);

        $this->expectException(GarantiPosException::class);
        $this->expectExceptionMessage('MD status');

        $this->service()->pay3DModel(['order_id' => '3D-MISSING', 'amount' => '100'], [], [
            'terminalprovuserid' => 'PROVAUT',
            'clientid' => '30690133',
            'orderid' => '3D-MISSING',
            'txnamount' => '100',
            'successurl' => 'https://ok',
            'errorurl' => 'https://err',
            'txntype' => 'sales',
            'txninstallmentcount' => '',
            'secure3dhash' => $hash,
        ]);
    }

    private function formInputs(string $html): array
    {
        preg_match_all('/<input type="hidden" name="([^"]+)" value="([^"]*)">/', $html, $matches, PREG_SET_ORDER);

        $inputs = [];
        foreach ($matches as $match) {
            $inputs[html_entity_decode($match[1])] = html_entity_decode($match[2]);
        }

        return $inputs;
    }
}

class CapturingGarantiPosService extends GarantiPosService
{
    protected function sendRequest(array $payload): array
    {
        return [
            'payload' => $payload,
            'xml' => $this->buildRequestXml($payload),
        ];
    }
}
