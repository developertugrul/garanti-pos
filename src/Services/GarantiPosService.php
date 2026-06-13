<?php

namespace Developertugrul\GarantiPos\Services;

use Developertugrul\GarantiPos\Exceptions\GarantiPosException;

class GarantiPosService
{
    private const AUT_ROLE = 'aut';
    private const REFUND_ROLE = 'refund';
    private const OOS_ROLE = 'oos';

    private const OOS_SECURITY_LEVELS = [
        'OOS_PAY',
        '3D_OOS_PAY',
        '3D_OOS_FULL',
        '3D_OOS_HALF',
        'CUSTOM_PAY',
        'QR_PAY',
    ];

    private const MODEL_ACCEPTED_MD_STATUSES = ['1', '2', '3', '4'];

    private array $config;
    private string $endpoint;
    private string $threeDEndpoint;

    public function __construct(array $config)
    {
        $this->config = $this->withDefaults($config);
        $this->refreshEndpoints();
    }

    /**
     * Set configuration dynamically.
     */
    public function setConfig(array $config): self
    {
        $this->config = $this->withDefaults(array_merge($this->config, $config));
        $this->refreshEndpoints();

        return $this;
    }

    /**
     * Build XML from a GVP payload. This is public so integrations/tests can
     * snapshot the exact bank request without sending a live transaction.
     */
    public function buildRequestXml(array $payload): string
    {
        return XmlBuilder::build($payload);
    }

    /**
     * Pay without 3D Secure (Non-3D).
     */
    public function pay(array $orderData, array $cardData, string $type = 'sales'): array
    {
        return $this->sendRequest($this->buildTransactionPayload($type, $orderData, $cardData));
    }

    /**
     * Cancel/void a transaction. Garanti GVP expects Type=void for cancellations.
     */
    public function cancel(string $orderId, string $originalRetrefNum = '', string $amount = '1'): array
    {
        return $this->processTransaction('void', $orderId, $amount, $originalRetrefNum, self::REFUND_ROLE);
    }

    /**
     * Refund a transaction.
     */
    public function refund(string $orderId, string $amount, string $originalRetrefNum = ''): array
    {
        return $this->processTransaction('refund', $orderId, $amount, $originalRetrefNum, self::REFUND_ROLE);
    }

    /**
     * Pre-Auth (On Provizyon).
     */
    public function preAuth(array $orderData, array $cardData): array
    {
        return $this->pay($orderData, $cardData, 'preauth');
    }

    /**
     * Post-Auth (On Provizyon Kapama).
     */
    public function postAuth(string $orderId, string $amount): array
    {
        return $this->processTransaction('postauth', $orderId, $amount, '', self::AUT_ROLE);
    }

    /**
     * Post-Auth Void (On Provizyon Kapama Iptali).
     */
    public function postAuthVoid(string $orderId, string $originalRetrefNum = '', string $amount = '1'): array
    {
        return $this->processTransaction('void', $orderId, $amount, $originalRetrefNum, self::REFUND_ROLE);
    }

    /**
     * Refund Void (Iade Iptali).
     */
    public function refundVoid(string $orderId, string $originalRetrefNum = '', string $amount = '1'): array
    {
        return $this->processTransaction('void', $orderId, $amount, $originalRetrefNum, self::REFUND_ROLE);
    }

    /**
     * Point/Bonus Inquiry.
     */
    public function pointInquiry(array $cardData): array
    {
        $amount = (string)($cardData['amount'] ?? '100');
        $orderData = [
            'order_id' => $cardData['order_id'] ?? '',
            'amount' => $amount,
            'ip_address' => $cardData['ip_address'] ?? '',
            'email' => $cardData['email'] ?? '',
        ];

        return $this->sendRequest($this->buildTransactionPayload('rewardinq', $orderData, $cardData));
    }

    /**
     * SMS validation first step. Defaults to preauth per the official example;
     * pass ['type' => 'sales'] in $orderData for direct sales.
     */
    public function paySms(array $orderData, array $cardData): array
    {
        $type = (string)($orderData['type'] ?? 'preauth');
        $orderData['subtype'] = 'sms';

        return $this->sendRequest($this->buildTransactionPayload($type, $orderData, $cardData));
    }

    /**
     * SMS validation postauth step.
     */
    public function smsPostAuth(array $orderData, string $smsPassword): array
    {
        $orderData['subtype'] = 'sms';
        $orderData['verification'] = ['SMSPassword' => $smsPassword];

        return $this->sendRequest($this->buildTransactionPayload('postauth', $orderData, [], [
            'hash_card_number' => '',
        ]));
    }

    /**
     * Statement verification sale/preauth/postauth flow (SubType=extre).
     */
    public function payExtre(array $orderData, array $cardData, string $extreInfo, string $type = 'sales'): array
    {
        $orderData['subtype'] = 'extre';
        $orderData['verification'] = ['ExtreInfo' => $extreInfo];

        if (!isset($orderData['moto_ind']) && !isset($orderData['MotoInd'])) {
            $orderData['moto_ind'] = 'H';
        }

        return $this->sendRequest($this->buildTransactionPayload($type, $orderData, $cardData));
    }

    public function preAuthExtre(array $orderData, array $cardData, string $extreInfo): array
    {
        return $this->payExtre($orderData, $cardData, $extreInfo, 'preauth');
    }

    public function postAuthExtre(array $orderData, string $extreInfo, array $cardData = []): array
    {
        return $this->payExtre($orderData, $cardData, $extreInfo, 'postauth');
    }

    /**
     * DCC Payment (Dynamic Currency Conversion).
     */
    public function payDcc(array $orderData, array $cardData): array
    {
        $orderData['subtype'] = 'dcc';
        if (!isset($orderData['moto_ind']) && !isset($orderData['MotoInd'])) {
            $orderData['moto_ind'] = 'H';
        }

        if (isset($orderData['dcc_currency'])) {
            $orderData['dcc'] = ['Currency' => $orderData['dcc_currency']];
        }

        return $this->pay($orderData, $cardData);
    }

    /**
     * Generate 3D Secure Form HTML.
     */
    public function build3DForm(array $orderData, array $cardData, string $successUrl, string $errorUrl, string $type = 'sales'): string
    {
        $securityLevel = (string)($orderData['security_level'] ?? '3D_PAY');
        $role = $this->credentialRoleForForm($orderData, $securityLevel);

        return $this->build3DFormHtml($orderData, $cardData, $successUrl, $errorUrl, $type, $securityLevel, $role, 'garanti-3d-form');
    }

    /**
     * Point/Bonus usage. Official GVP uses a sales transaction with RewardList.
     */
    public function rewardUsage(array $orderData, array $cardData, string $pointAmount): array
    {
        $orderData['reward_list'] = $orderData['reward_list'] ?? [[
            'Type' => $orderData['reward_type'] ?? 'BNS',
            'UsedAmount' => $pointAmount,
        ]];

        return $this->sendRequest($this->buildTransactionPayload('sales', $orderData, $cardData));
    }

    /**
     * Order Inquiry.
     */
    public function orderInquiry(string $orderId, string $amount = '100'): array
    {
        return $this->processTransaction('orderinq', $orderId, $amount, '', self::AUT_ROLE);
    }

    /**
     * Order History Inquiry.
     */
    public function orderHistoryInquiry(string $orderId, string $amount = '100'): array
    {
        return $this->processTransaction('orderhistoryinq', $orderId, $amount, '', self::AUT_ROLE);
    }

    /**
     * Generate 3D Secure Ortak Odeme Sayfasi (OOS) Form HTML.
     */
    public function build3DOOSForm(array $orderData, string $successUrl, string $errorUrl, string $type = 'sales'): string
    {
        $orderData['security_level'] = $orderData['security_level'] ?? '3D_OOS_PAY';

        return $this->build3DFormHtml($orderData, null, $successUrl, $errorUrl, $type, $orderData['security_level'], $this->credentialRoleForForm($orderData, $orderData['security_level']), 'garanti-3d-oos-form');
    }

    /**
     * Generate Ortak Odeme Sayfasi (OOS_PAY - Non 3D) Form HTML.
     */
    public function buildOOSForm(array $orderData, string $successUrl, string $errorUrl, string $type = 'sales'): string
    {
        $orderData['security_level'] = $orderData['security_level'] ?? 'OOS_PAY';

        return $this->build3DFormHtml($orderData, null, $successUrl, $errorUrl, $type, $orderData['security_level'], $this->credentialRoleForForm($orderData, $orderData['security_level']), 'garanti-oos-form');
    }

    /**
     * Pay 3D Model Second Step (Authorization).
     */
    public function pay3DModel(array $orderData, array $cardData, array $threeDResponse): array
    {
        $validation = $this->parse3DResponse($threeDResponse, [
            'order_id' => $orderData['order_id'] ?? null,
            'amount' => $orderData['amount'] ?? null,
        ]);

        if (!$validation['hash_valid']) {
            throw new GarantiPosException('3D Secure hash validation failed.');
        }

        if ($validation['md_status'] === '' || !$validation['md_status_accepted']) {
            throw new GarantiPosException('3D Secure MD status is not acceptable: ' . ($validation['md_status'] ?: 'missing'));
        }

        if ($validation['order_matches'] === false || $validation['amount_matches'] === false) {
            throw new GarantiPosException('3D Secure callback order or amount does not match the expected payment.');
        }

        $payload = $this->buildTransactionPayload((string)($orderData['type'] ?? $validation['txntype'] ?? 'sales'), $orderData, [], [
            'hash_card_number' => '',
            'transaction' => [
                'CardholderPresentCode' => '13',
                'Secure3D' => [
                    'AuthenticationCode' => $threeDResponse['cavv'] ?? $threeDResponse['CAVV'] ?? '',
                    'SecurityLevel' => $threeDResponse['eci'] ?? $threeDResponse['ECI'] ?? '',
                    'TxnID' => $threeDResponse['xid'] ?? $threeDResponse['XID'] ?? '',
                    'Md' => $threeDResponse['md'] ?? $threeDResponse['MD'] ?? '',
                ],
            ],
        ]);

        return $this->sendRequest($payload);
    }

    /**
     * CepBank Payment.
     */
    public function payCepBank(array $orderData, array $cepBankData): array
    {
        $orderData['cepbank'] = [
            'GSMNumber' => $cepBankData['gsm_number'] ?? $cepBankData['GSMNumber'] ?? '',
            'PaymentType' => $cepBankData['payment_type'] ?? $cepBankData['PaymentType'] ?? '',
        ];

        foreach (['HashDate', 'HashValue'] as $key) {
            $snake = $this->snakeKey($key);
            if (array_key_exists($key, $cepBankData)) {
                $orderData['cepbank'][$key] = $cepBankData[$key];
            } elseif (array_key_exists($snake, $cepBankData)) {
                $orderData['cepbank'][$key] = $cepBankData[$snake];
            }
        }

        return $this->sendRequest($this->buildTransactionPayload('cepbank', $orderData, [], [
            'hash_card_number' => '',
        ]));
    }

    /**
     * Generate GarantiPay/CUSTOM_PAY Form HTML.
     */
    public function buildGarantiPayForm(array $orderData, string $successUrl, string $errorUrl): string
    {
        $orderData['security_level'] = $orderData['security_level'] ?? 'CUSTOM_PAY';
        $orderData['txnsubtype'] = $orderData['txnsubtype'] ?? 'sales';
        $orderData['garantipay'] = $orderData['garantipay'] ?? 'Y';

        return $this->build3DFormHtml($orderData, null, $successUrl, $errorUrl, 'gpdatarequest', $orderData['security_level'], $this->credentialRoleForForm($orderData, $orderData['security_level']), 'garanti-pay-form');
    }

    /**
     * GarantiPay XML data request.
     *
     * Official XML examples send Type=gpdatarequest to VPServlet with PROVAUT.
     * The hosted CUSTOM_PAY form flow is handled separately by buildGarantiPayForm().
     */
    public function garantiPayDataRequest(array $orderData): array
    {
        $orderData['txnsubtype'] = $orderData['txnsubtype'] ?? $orderData['subtype'] ?? 'sales';
        $orderData['garanti_pay'] = $this->buildGarantiPayXmlNode($orderData);

        return $this->sendRequest($this->buildTransactionPayload('gpdatarequest', $orderData, [], [
            'hash_card_number' => '',
            'role' => (string)($orderData['credential_role'] ?? self::AUT_ROLE),
        ]));
    }

    /**
     * Recurring Payment Setup.
     */
    public function payRecurring(array $orderData, array $cardData, array $recurringData): array
    {
        $orderData['recurring'] = [
            'Type' => $recurringData['type'] ?? 'R',
            'TotalPaymentNum' => $recurringData['total_payment_num'] ?? $recurringData['TotalPaymentNum'] ?? '',
            'FrequencyType' => $recurringData['frequency_type'] ?? $recurringData['FrequencyType'] ?? '',
            'FrequencyInterval' => $recurringData['frequency_interval'] ?? $recurringData['FrequencyInterval'] ?? '',
            'StartDate' => $recurringData['start_date'] ?? $recurringData['StartDate'] ?? '',
        ];

        foreach (['RecurringRetryAttemptCount', 'RetryAttemptEmail', 'PaymentList'] as $key) {
            $snake = $this->snakeKey($key);
            if (isset($recurringData[$key])) {
                $orderData['recurring'][$key] = $recurringData[$key];
            } elseif (isset($recurringData[$snake])) {
                $orderData['recurring'][$key] = $recurringData[$snake];
            }
        }

        return $this->sendRequest($this->buildTransactionPayload('sales', $orderData, $cardData));
    }

    /**
     * Identify Inquiry (TCKN dogrulama).
     */
    public function identifyInquiry(array $orderData, array $cardData, string $tckn): array
    {
        $orderData['verification'] = ['Identity' => $tckn];

        return $this->sendRequest($this->buildTransactionPayload('identifyinq', $orderData, $cardData));
    }

    /**
     * Extended Credit Payment (Tuketici Kredisi / Vadeli Taksit).
     */
    public function payExtendedCredit(array $orderData, array $cardData): array
    {
        return $this->pay($orderData, $cardData, 'extendedcredit');
    }

    /**
     * Down-payment installment sale. Official examples use Type=sales.
     */
    public function payDownPaymentSale(array $orderData, array $cardData): array
    {
        return $this->pay($orderData, $cardData, 'sales');
    }

    /**
     * Delayed sale. Official examples use Type=sales.
     */
    public function payDelayedSale(array $orderData, array $cardData): array
    {
        return $this->pay($orderData, $cardData, 'sales');
    }

    /**
     * Utility payment block from the official request schema.
     */
    public function payUtility(array $orderData, array $cardData, array $utilityPaymentData): array
    {
        $type = (string)($orderData['type'] ?? $orderData['Type'] ?? 'sales');
        $orderData['utility_payment'] = $utilityPaymentData;

        return $this->pay($orderData, $cardData, $type);
    }

    /**
     * GSM unit sales block from the official request schema.
     */
    public function payGsmUnitSales(array $orderData, array $cardData, array $gsmUnitSalesData): array
    {
        $type = (string)($orderData['type'] ?? $orderData['Type'] ?? 'sales');
        $orderData['gsm_unit_sales'] = $gsmUnitSalesData;

        return $this->pay($orderData, $cardData, $type);
    }

    /**
     * MoneyCard block from the official request schema.
     */
    public function payMoneyCard(array $orderData, array $cardData, array $moneyCardData): array
    {
        $type = (string)($orderData['type'] ?? $orderData['Type'] ?? 'sales');
        $orderData['money_card'] = $moneyCardData;

        return $this->pay($orderData, $cardData, $type);
    }

    /**
     * Commercial Card Extended Credit.
     */
    public function payCommercialCardExtendedCredit(array $orderData, array $cardData): array
    {
        if (!isset($orderData['moto_ind']) && !isset($orderData['MotoInd'])) {
            $orderData['moto_ind'] = 'H';
        }

        if (isset($orderData['payments']) || isset($orderData['payment_list'])) {
            $orderData['commercial_card_extended_credit'] = [
                'PaymentList' => $orderData['payments'] ?? $orderData['payment_list'],
            ];
        }

        return $this->pay($orderData, $cardData, 'commercialcardextendedcredit');
    }

    /**
     * Extended Credit Inquiry.
     */
    public function extendedCreditInquiry(string $orderId, string $amount = '100'): array
    {
        return $this->processTransaction('extendedcreditinq', $orderId, $amount, '', self::AUT_ROLE);
    }

    /**
     * Settlement inquiry root block from the official request schema.
     */
    public function settlementInquiry(string $date, array $transactionSummaries = [], array $options = []): array
    {
        $credentials = $this->credentials(self::AUT_ROLE);
        $terminalId = $this->configValue('terminal_id');
        $hashAmount = (string)($options['hash_amount'] ?? '100');
        $currencyCode = (string)($options['currency'] ?? $this->configValue('currency', '949'));
        $securityData = HashGenerator::generateSecurityData($credentials['password'], $terminalId);

        $payload = [
            'Mode' => $this->mode(),
            'Version' => (string)($options['api_version'] ?? $this->configValue('api_version', 'v0.01')),
            'ChannelCode' => (string)($options['channel_code'] ?? $this->configValue('channel_code')),
            'Terminal' => [
                'ProvUserID' => $credentials['prov_user_id'],
                'HashData' => HashGenerator::generateHashData('', $terminalId, '', $hashAmount, $securityData, $this->hashAlgorithm(), $currencyCode),
                'UserID' => $credentials['user_id'],
                'ID' => $terminalId,
                'MerchantID' => $this->configValue('merchant_id'),
            ],
            'Customer' => [
                'IPAddress' => (string)($options['ip_address'] ?? ''),
                'EmailAddress' => (string)($options['email'] ?? ''),
            ],
            'Card' => [
                'Number' => '',
                'ExpireDate' => '',
                'CVV2' => '',
            ],
            'SettlementInq' => [
                'Date' => $date,
            ],
        ];

        if ($transactionSummaries !== []) {
            $payload['SettlementInq']['TransactionSummList'] = $this->normalizeListNode($transactionSummaries, 'TransactionSumm');
        }

        return $this->sendRequest($payload);
    }

    /**
     * BIN Inquiry.
     */
    public function binInquiry(string $binNumber = '', string $amount = '100'): array
    {
        $cardData = ['number' => $binNumber];
        $orderData = ['order_id' => '', 'amount' => $amount];

        return $this->sendRequest($this->buildTransactionPayload('bininq', $orderData, $cardData));
    }

    /**
     * Recurring Cancel.
     */
    public function recurringCancel(string $orderId, string $amount = '100'): array
    {
        return $this->processTransaction('recurringvoid', $orderId, $amount, '', self::AUT_ROLE);
    }

    /**
     * DCC Inquiry.
     */
    public function dccInquiry(string $orderId, string $cardNumber, string $amount): array
    {
        return $this->sendRequest($this->buildTransactionPayload('dccinq', [
            'order_id' => $orderId,
            'amount' => $amount,
            'moto_ind' => 'H',
        ], [
            'number' => $cardNumber,
        ]));
    }

    /**
     * Batch Inquiry.
     */
    public function batchInquiry(?string $batchNum = null, int $listPageNum = 1, string $orderId = '', string $amount = '100'): array
    {
        $orderData = [
            'order_id' => $orderId,
            'amount' => $amount,
            'batch_num' => $batchNum,
            'list_page_num' => $listPageNum,
        ];

        return $this->sendRequest($this->buildTransactionPayload('batchinq', $orderData, [], [
            'hash_card_number' => '',
        ]));
    }

    /**
     * Campaign Code Inquiry.
     */
    public function campaignCodeInquiry(string $campaignCode, string $amount = '100'): array
    {
        return $this->sendRequest($this->buildTransactionPayload('campaigncodeinq', [
            'order_id' => '',
            'amount' => $amount,
            'campaing_code' => $campaignCode,
        ], [], [
            'hash_card_number' => '',
        ]));
    }

    /**
     * Order List Inquiry.
     */
    public function orderListInquiry(?string $startDate = null, ?string $endDate = null, int $listPageNum = 1, string $orderId = '', string $amount = '100'): array
    {
        $orderData = [
            'order_id' => $orderId,
            'amount' => $amount,
            'list_page_num' => $listPageNum,
        ];

        if ($startDate !== null) {
            $orderData['start_date'] = $startDate;
        }
        if ($endDate !== null) {
            $orderData['end_date'] = $endDate;
        }

        return $this->sendRequest($this->buildTransactionPayload('orderlistinq', $orderData, [], [
            'hash_card_number' => '',
        ]));
    }

    /**
     * Recurring Update.
     */
    public function recurringUpdate(string $orderId, array $paymentList): array
    {
        return $this->sendRequest($this->buildTransactionPayload('recurringupdate', [
            'order_id' => $orderId,
            'amount' => '100',
            'recurring' => [
                'Type' => '',
                'PaymentList' => $paymentList,
            ],
        ], [], [
            'hash_card_number' => '',
        ]));
    }

    /**
     * Parse and verify 3D/OOS callback data.
     */
    public function parse3DResponse(array $postData, array $expected = []): array
    {
        $data = array_change_key_case($postData, CASE_LOWER);
        $role = $this->credentialRoleFromProvUser((string)($data['terminalprovuserid'] ?? ''));
        $credentials = $this->credentials($role);

        $hashSource = 'missing';
        $hashValid = false;

        if (!empty($data['hashparams']) && !empty($data['hash'])) {
            $hashSource = 'hashparams';
            $hashValid = HashGenerator::validate3DHash($postData, $this->configValue('store_key'), 'auto');
        } elseif (!empty($data['secure3dhash'])) {
            $hashSource = 'secure3dhash';
            $hashValid = HashGenerator::validateSecure3DHash(
                $postData,
                $this->configValue('store_key'),
                $credentials['password'],
                $this->configValue('terminal_id'),
                'auto'
            );
        }

        $orderId = (string)($data['orderid'] ?? $data['oid'] ?? '');
        $amount = (string)($data['txnamount'] ?? $data['amount'] ?? '');
        $mdStatus = (string)($data['mdstatus'] ?? '');
        $procReturnCode = (string)($data['procreturncode'] ?? $data['procreturnCode'] ?? '');

        $orderMatches = null;
        if (($expected['order_id'] ?? null) !== null) {
            $orderMatches = $orderId === (string)$expected['order_id'];
        }

        $amountMatches = null;
        if (($expected['amount'] ?? null) !== null) {
            $amountMatches = HashGenerator::normalizeAmount($amount) === HashGenerator::normalizeAmount((string)$expected['amount']);
        }

        $errors = [];
        if (!$hashValid) {
            $errors[] = 'hash_invalid';
        }
        if ($orderMatches === false) {
            $errors[] = 'order_mismatch';
        }
        if ($amountMatches === false) {
            $errors[] = 'amount_mismatch';
        }
        if ($mdStatus !== '' && !in_array($mdStatus, self::MODEL_ACCEPTED_MD_STATUSES, true)) {
            $errors[] = 'md_status_rejected';
        }

        return [
            'hash_valid' => $hashValid,
            'hash_source' => $hashSource,
            'approved' => $procReturnCode === '00',
            'procreturncode' => $procReturnCode,
            'md_status' => $mdStatus,
            'md_status_accepted' => $mdStatus === '' || in_array($mdStatus, self::MODEL_ACCEPTED_MD_STATUSES, true),
            'order_id' => $orderId,
            'amount' => $amount,
            'txntype' => (string)($data['txntype'] ?? ''),
            'order_matches' => $orderMatches,
            'amount_matches' => $amountMatches,
            'errors' => $errors,
        ];
    }

    private function processTransaction(string $type, string $orderId, string $amount, string $originalRetrefNum = '', string $role = self::AUT_ROLE): array
    {
        $orderData = [
            'order_id' => $orderId,
            'amount' => $amount,
            'original_retref_num' => $originalRetrefNum,
        ];

        return $this->sendRequest($this->buildTransactionPayload($type, $orderData, [], [
            'role' => $role,
            'hash_card_number' => '',
        ]));
    }

    private function buildTransactionPayload(string $type, array $orderData, array $cardData = [], array $options = []): array
    {
        $role = (string)($options['role'] ?? self::AUT_ROLE);
        $credentials = $this->credentials($role);
        $terminalId = $this->configValue('terminal_id');
        $orderId = (string)($orderData['order_id'] ?? $orderData['OrderID'] ?? '');
        $amount = (string)($orderData['amount'] ?? $orderData['Amount'] ?? $options['amount'] ?? '100');
        $currencyCode = (string)($orderData['currency'] ?? $orderData['CurrencyCode'] ?? $this->configValue('currency', '949'));
        $hashCardNumber = (string)($options['hash_card_number'] ?? $cardData['number'] ?? $cardData['Number'] ?? '');
        $hashAmount = (string)($options['hash_amount'] ?? $amount);
        $securityData = HashGenerator::generateSecurityData($credentials['password'], $terminalId);
        $hashData = HashGenerator::generateHashData($orderId, $terminalId, $hashCardNumber, $hashAmount, $securityData, $this->hashAlgorithm(), $currencyCode);

        $payload = [
            'Mode' => $this->mode(),
            'Version' => (string)($orderData['api_version'] ?? $orderData['Version'] ?? $this->configValue('api_version', 'v0.01')),
            'ChannelCode' => (string)($orderData['channel_code'] ?? $orderData['ChannelCode'] ?? $this->configValue('channel_code')),
            'Terminal' => [
                'ProvUserID' => $credentials['prov_user_id'],
                'HashData' => $hashData,
                'UserID' => $credentials['user_id'],
                'ID' => $terminalId,
                'MerchantID' => $this->configValue('merchant_id'),
            ],
            'Customer' => [
                'IPAddress' => $this->customerIp($orderData),
                'EmailAddress' => (string)($orderData['email'] ?? $orderData['EmailAddress'] ?? ''),
            ],
            'Card' => [
                'Number' => (string)($cardData['number'] ?? $cardData['Number'] ?? ''),
                'ExpireDate' => $this->expireDate($cardData),
                'CVV2' => (string)($cardData['cvv'] ?? $cardData['CVV2'] ?? ''),
            ],
            'Order' => $this->buildOrderNode($orderData, $orderId),
            'Transaction' => $this->buildTransactionNode($type, $orderData, $amount),
        ];

        if (isset($options['transaction']) && is_array($options['transaction'])) {
            $payload['Transaction'] = $this->mergeRecursiveDistinct($payload['Transaction'], $options['transaction']);
        }

        return $payload;
    }

    private function buildOrderNode(array $orderData, string $orderId): array
    {
        $order = [
            'OrderID' => $orderId,
            'GroupID' => (string)($orderData['group_id'] ?? $orderData['GroupID'] ?? ''),
        ];

        if (isset($orderData['description'])) {
            $order['Description'] = (string)$orderData['description'];
        }
        if (isset($orderData['order_description'])) {
            $order['Description'] = (string)$orderData['order_description'];
        }
        if (isset($orderData['start_date'])) {
            $order['StartDate'] = (string)$orderData['start_date'];
        }
        if (isset($orderData['end_date'])) {
            $order['EndDate'] = (string)$orderData['end_date'];
        }

        $addresses = $orderData['addresses'] ?? $orderData['address_list'] ?? null;
        if ($addresses !== null) {
            $order['AddressList'] = $this->normalizeAddressListNode($addresses);
        }

        $items = $orderData['items'] ?? $orderData['item_list'] ?? null;
        if ($items !== null) {
            $order['ItemList'] = $this->normalizeListNode($items, 'Item');
        }

        $comments = $orderData['comments'] ?? $orderData['comment_list'] ?? null;
        if ($comments !== null) {
            $order['CommentList'] = $this->normalizeListNode($comments, 'Comment');
        }

        $recurring = $orderData['recurring'] ?? $orderData['Recurring'] ?? null;
        if (is_array($recurring)) {
            if (isset($recurring['PaymentList'])) {
                $recurring['PaymentList'] = $this->normalizeListNode($recurring['PaymentList'], 'Payment');
            } elseif (isset($recurring['payment_list'])) {
                $recurring['PaymentList'] = $this->normalizeListNode($recurring['payment_list'], 'Payment');
                unset($recurring['payment_list']);
            }
            $order['Recurring'] = $this->normalizeKeys($recurring);
        }

        return $order;
    }

    private function buildTransactionNode(string $type, array $orderData, string $amount): array
    {
        $transaction = [
            'Type' => $type,
            'InstallmentCnt' => (string)($orderData['installment'] ?? $orderData['InstallmentCnt'] ?? ''),
            'Amount' => $amount,
            'CurrencyCode' => (string)($orderData['currency'] ?? $orderData['CurrencyCode'] ?? $this->configValue('currency', '949')),
            'CardholderPresentCode' => (string)($orderData['cardholder_present_code'] ?? $orderData['CardholderPresentCode'] ?? '0'),
            'MotoInd' => (string)($orderData['moto_ind'] ?? $orderData['MotoInd'] ?? 'N'),
        ];

        $map = [
            'subtype' => 'SubType',
            'txnsubtype' => 'SubType',
            'firm_card_no' => 'FirmCardNo',
            'description' => 'Description',
            'transaction_description' => 'Description',
            'original_retref_num' => 'OriginalRetrefNum',
            'down_payment_rate' => 'DownPaymentRate',
            'delay_day_count' => 'DelayDayCount',
            'list_page_num' => 'ListPageNum',
            'batch_num' => 'BatchNum',
            'campaing_code' => 'CampaingCode',
            'campaign_code' => 'CampaingCode',
            'return_server_url' => 'ReturnServerUrl',
        ];

        foreach ($map as $source => $target) {
            if (array_key_exists($source, $orderData) && $orderData[$source] !== null) {
                $transaction[$target] = (string)$orderData[$source];
            }
        }

        foreach (['SubType', 'FirmCardNo', 'Description', 'OriginalRetrefNum', 'DownPaymentRate', 'DelayDayCount', 'ListPageNum', 'BatchNum', 'CampaingCode', 'ReturnServerUrl'] as $key) {
            if (array_key_exists($key, $orderData) && $orderData[$key] !== null) {
                $transaction[$key] = (string)$orderData[$key];
            }
        }

        $rewardList = $orderData['reward_list'] ?? $orderData['RewardList'] ?? null;
        if ($rewardList !== null) {
            $transaction['RewardList'] = $this->normalizeListNode($rewardList, 'Reward');
        }

        $dcc = $orderData['dcc'] ?? $orderData['DCC'] ?? null;
        if (is_array($dcc)) {
            $transaction['DCC'] = $this->normalizeKeys($dcc);
        }

        $verification = $orderData['verification'] ?? $orderData['Verification'] ?? null;
        if (is_array($verification)) {
            $transaction['Verification'] = $this->normalizeKeys($verification);
        }

        $cepBank = $orderData['cepbank'] ?? $orderData['CepBank'] ?? null;
        if (is_array($cepBank)) {
            $transaction['CepBank'] = $this->normalizeKeys($cepBank);
        }

        $chequeList = $orderData['cheque_list'] ?? $orderData['ChequeList'] ?? null;
        if ($chequeList !== null) {
            $transaction['ChequeList'] = $this->normalizeListNode($chequeList, 'Cheque');
        }

        foreach ([
            'utility_payment' => 'UtilityPayment',
            'gsm_unit_sales' => 'GSMUnitSales',
            'money_card' => 'MoneyCard',
        ] as $source => $target) {
            $block = $orderData[$source] ?? $orderData[$target] ?? null;
            if (is_array($block)) {
                $transaction[$target] = $this->normalizeKeys($block);
            }
        }

        $garantiPay = $orderData['garanti_pay'] ?? $orderData['GarantiPaY'] ?? null;
        if (is_array($garantiPay)) {
            $transaction['GarantiPaY'] = $this->buildGarantiPayXmlNode(['garanti_pay' => $garantiPay]);
        }

        $commercial = $orderData['commercial_card_extended_credit'] ?? $orderData['CommercialCardExtendedCredit'] ?? null;
        if (is_array($commercial)) {
            if (isset($commercial['PaymentList'])) {
                $commercial['PaymentList'] = $this->normalizeListNode($commercial['PaymentList'], 'Payment');
            }
            $transaction['CommercialCardExtendedCredit'] = $this->normalizeKeys($commercial);
        }

        if (isset($orderData['transaction']) && is_array($orderData['transaction'])) {
            $transaction = $this->mergeRecursiveDistinct($transaction, $this->normalizeKeys($orderData['transaction']));
        }

        return $transaction;
    }

    protected function sendRequest(array $payload): array
    {
        $xmlData = XmlBuilder::build($payload);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->endpoint);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['data' => $xmlData], '', '&'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new GarantiPosException("CURL Error: $error");
        }
        curl_close($ch);

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string((string)$response, 'SimpleXMLElement', LIBXML_NOCDATA);

        if ($xml === false) {
            $errors = libxml_get_errors();
            $errorMsg = $errors[0]->message ?? 'Unknown XML parsing error';
            libxml_clear_errors();
            throw new GarantiPosException('Invalid XML Response from Garanti: ' . $errorMsg . ' | Raw: ' . $response);
        }

        return json_decode(json_encode($xml), true) ?: [];
    }

    private function build3DFormHtml(
        array $orderData,
        ?array $cardData,
        string $successUrl,
        string $errorUrl,
        string $type,
        string $securityLevel,
        string $role,
        string $formId
    ): string {
        $credentials = $this->credentials($role);
        $terminalId = $this->configValue('terminal_id');
        $amount = (string)($orderData['amount'] ?? $orderData['Amount'] ?? '');
        $installment = (string)($orderData['installment'] ?? $orderData['InstallmentCnt'] ?? '');
        $currencyCode = (string)($orderData['currency'] ?? $this->configValue('currency', '949'));
        $securityData = HashGenerator::generateSecurityData($credentials['password'], $terminalId);
        $hashData = HashGenerator::generate3DHash(
            $terminalId,
            (string)($orderData['order_id'] ?? $orderData['OrderID'] ?? ''),
            $amount,
            $successUrl,
            $errorUrl,
            $type,
            $installment,
            $this->configValue('store_key'),
            $securityData,
            $this->hashAlgorithm(),
            $currencyCode
        );

        $inputs = [
            'mode' => $this->mode(),
            'apiversion' => (string)($orderData['api_version'] ?? $this->configValue('api_version', 'v0.01')),
            'terminalprovuserid' => $credentials['prov_user_id'],
            'terminaluserid' => $credentials['user_id'],
            'terminalmerchantid' => $this->configValue('merchant_id'),
            'txntype' => $type,
            'txnamount' => $amount,
            'txncurrencycode' => $currencyCode,
            'txninstallmentcount' => $installment,
            'orderid' => (string)($orderData['order_id'] ?? $orderData['OrderID'] ?? ''),
            'terminalid' => $terminalId,
            'successurl' => $successUrl,
            'errorurl' => $errorUrl,
            'customeripaddress' => $this->customerIp($orderData),
            'customeremailaddress' => (string)($orderData['email'] ?? ''),
            'secure3dsecuritylevel' => $securityLevel,
            'secure3dhash' => $hashData,
        ];

        if ($cardData !== null) {
            $inputs['cardnumber'] = (string)($cardData['number'] ?? '');
            $inputs['cardexpiredatemonth'] = (string)($cardData['expire_month'] ?? '');
            $inputs['cardexpiredateyear'] = (string)($cardData['expire_year'] ?? '');
            $inputs['cardcvv2'] = (string)($cardData['cvv'] ?? '');
            $cardholderName = $cardData['cardholder_name']
                ?? $cardData['holder_name']
                ?? $cardData['cardholder']
                ?? $cardData['name']
                ?? null;
            if ($cardholderName !== null) {
                $inputs['cardholdername'] = (string)$cardholderName;
            }
        }

        $inputs = $this->mergeFormFields($inputs, $orderData, $type);

        return $this->buildAutoSubmitForm($formId, $this->threeDEndpoint, $inputs);
    }

    private function mergeFormFields(array $inputs, array $orderData, string $type): array
    {
        $map = [
            'company_name' => 'companyname',
            'companyname' => 'companyname',
            'lang' => 'lang',
            'timestamp' => 'txntimestamp',
            'txntimestamp' => 'txntimestamp',
            'refresh_time' => 'refreshtime',
            'refreshtime' => 'refreshtime',
            'firm_card_no' => 'firmacardno',
            'firmacardno' => 'firmacardno',
            'cardholder' => 'cardholder',
            'cardholder_name' => 'cardholdername',
            'cardholdername' => 'cardholdername',
            'group_id' => 'ordergroupid',
            'order_description' => 'orderdescription',
            'txn_installment_period' => 'txninstallmentperiod',
            'txninstallmentperiod' => 'txninstallmentperiod',
            'down_payment_rate' => 'txndownpayrate',
            'delay_day_count' => 'txndelaydaycnt',
            'cardholder_present_code' => 'txncardholderpresentcode',
            'transaction_description' => 'txndescription',
            'moto_ind' => 'txnmotoind',
            'mobil_ind' => 'mobilind',
            'secure3d_authentication_code' => 'secure3dauthenticationcode',
            'secure3dauthenticationcode' => 'secure3dauthenticationcode',
            'secure3d_txn_id' => 'secure3dtxnid',
            'secure3dtxnid' => 'secure3dtxnid',
            'secure3d_rnd' => 'secure3drnd',
            'secure3drnd' => 'secure3drnd',
            'secure3d_record_key' => 'secure3DRecordKey',
            'secure3DRecordKey' => 'secure3DRecordKey',
            'utility_pay_invoice_id' => 'utilitypayinvoiceid',
            'utilitypayinvoiceid' => 'utilitypayinvoiceid',
            'utility_pay_subscriber_code' => 'utilitypaysubscode',
            'utilitypaysubscode' => 'utilitypaysubscode',
            'utility_pay_type' => 'utilitypaytype',
            'utilitypaytype' => 'utilitypaytype',
            'gsm_quantity' => 'gsmquantity',
            'gsmquantity' => 'gsmquantity',
            'gsm_sales_amount' => 'gsmsalesamnt',
            'gsmsalesamnt' => 'gsmsalesamnt',
            'gsm_sales_unit_id' => 'gsmsalesunitid',
            'gsmsalesunitid' => 'gsmsalesunitid',
            'money_cc_disc' => 'moneyccdisc',
            'moneyccdisc' => 'moneyccdisc',
            'money_extra_disc' => 'moneyextradisc',
            'moneyextradisc' => 'moneyextradisc',
            'money_invoice' => 'moneyinvoice',
            'moneyinvoice' => 'moneyinvoice',
            'money_payment' => 'moneypayment',
            'moneypayment' => 'moneypayment',
            'money_product_based_disc' => 'moneyproductbaseddisc',
            'moneyproductbaseddisc' => 'moneyproductbaseddisc',
            'garantipay' => 'garantipay',
            'txnsubtype' => 'txnsubtype',
            'bns_use_flag' => 'bnsuseflag',
            'bnsuseflag' => 'bnsuseflag',
            'fbb_use_flag' => 'fbbuseflag',
            'fbbuseflag' => 'fbbuseflag',
            'cheque_use_flag' => 'chequeuseflag',
            'chequeuseflag' => 'chequeuseflag',
            'mile_use_flag' => 'mileuseflag',
            'mileuseflag' => 'mileuseflag',
            'add_campaign_installment' => 'addcampaigninstallment',
            'addcampaigninstallment' => 'addcampaigninstallment',
        ];

        foreach ($map as $source => $target) {
            if (array_key_exists($source, $orderData) && $orderData[$source] !== null) {
                $inputs[$target] = (string)$orderData[$source];
            }
        }

        if ($type === 'gpdatarequest') {
            $inputs['txnsubtype'] = $inputs['txnsubtype'] ?? 'sales';
            $inputs['garantipay'] = $inputs['garantipay'] ?? 'Y';
            $inputs['lang'] = $inputs['lang'] ?? 'tr';
            $inputs['txntimestamp'] = $inputs['txntimestamp'] ?? (string)time();
            $inputs['refreshtime'] = $inputs['refreshtime'] ?? '4';
        }

        $this->appendNumberedFormList($inputs, 'orderitem', $orderData['items'] ?? $orderData['item_list'] ?? null, [
            'Number' => 'number',
            'ProductID' => 'productid',
            'ProductCode' => 'productcode',
            'Quantity' => 'quantity',
            'Price' => 'price',
            'TotalAmount' => 'totalamount',
            'Description' => 'description',
        ], null, 'Item');
        $this->appendNumberedFormList($inputs, 'orderaddress', $orderData['addresses'] ?? $orderData['address_list'] ?? null, [
            'Type' => 'type',
            'Name' => 'name',
            'LastName' => 'lastname',
            'Company' => 'company',
            'Text' => 'text',
            'District' => 'district',
            'City' => 'city',
            'PostalCode' => 'postalcode',
            'Country' => 'country',
            'PhoneNumber' => 'phonenumber',
            'GSMNumber' => 'gsmnumber',
            'FaxNumber' => 'faxnumber',
        ], null, 'Address');
        $this->appendNumberedFormList($inputs, 'ordercomment', $orderData['comments'] ?? $orderData['comment_list'] ?? null, [
            'Number' => 'number',
            'Text' => 'text',
        ], null, 'Comment');
        $this->appendNumberedFormList($inputs, 'installment', $orderData['installments'] ?? null, [
            'Installmentnumber' => 'number',
            'Installmentamount' => 'amount',
            'Installmentratewithreward' => 'ratewithreward',
        ], 'totallinstallmentcount', 'Installment');
        $this->appendNumberedFormList($inputs, 'txnreward', $orderData['rewards'] ?? $orderData['reward_list'] ?? null, [
            'Type' => 'type',
            'GainedAmount' => 'gainedamount',
            'UsedAmount' => 'usedamount',
        ], 'txnrewardcount', 'Reward');
        $this->appendNumberedFormList($inputs, 'txncheque', $orderData['cheques'] ?? $orderData['cheque_list'] ?? null, [
            'Type' => 'type',
            'Amount' => 'amount',
            'Bitmap' => 'bitmap',
            'ID' => 'id',
            'Count' => 'count',
        ], 'txnchequecount', 'Cheque');

        $this->appendRecurringFormFields($inputs, $orderData['recurring'] ?? $orderData['Recurring'] ?? null);

        if (isset($orderData['form_fields']) && is_array($orderData['form_fields'])) {
            foreach ($orderData['form_fields'] as $key => $value) {
                $inputs[(string)$key] = (string)$value;
            }
        }

        return $inputs;
    }

    private function appendNumberedFormList(array &$inputs, string $prefix, $items, array $fieldMap, ?string $countKey = null, string $itemName = 'Item'): void
    {
        if (!is_array($items)) {
            return;
        }

        $items = $this->normalizeListNode($items, $itemName);
        $countKey = $countKey ?? $prefix . 'count';
        $inputs[$countKey] = (string)count($items);

        $index = 1;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach ($fieldMap as $canonical => $suffix) {
                $value = $item[$canonical] ?? $item[lcfirst($canonical)] ?? $item[$this->snakeKey($canonical)] ?? $item[$suffix] ?? null;
                if ($value !== null) {
                    $inputs[$prefix . $suffix . $index] = (string)$value;
                }
            }
            $index++;
        }
    }

    private function appendRecurringFormFields(array &$inputs, $recurring): void
    {
        if (!is_array($recurring)) {
            return;
        }

        $normalized = $this->normalizeKeys($recurring);
        $map = [
            'Type' => 'recurringtype',
            'TotalPaymentNum' => 'totalpaymentnum',
            'FrequencyType' => 'frequencytype',
            'FrequencyInterval' => 'frequencyinterval',
            'StartDate' => 'startdate',
            'RecurringRetryAttemptCount' => 'recurringretryattemptcount',
            'RetryAttemptEmail' => 'retryattemptemail',
        ];

        foreach ($map as $source => $target) {
            if (array_key_exists($source, $normalized) && $normalized[$source] !== null) {
                $inputs[$target] = (string)$normalized[$source];
            }
        }

        $amounts = $recurring['recurring_amounts'] ?? $recurring['RecurringAmounts'] ?? null;
        if (is_array($amounts)) {
            foreach (array_values($amounts) as $index => $amount) {
                $inputs['recurringamount' . ($index + 1)] = (string)$amount;
            }
        }

        $payments = $normalized['PaymentList'] ?? null;
        if (is_array($payments)) {
            foreach ($this->normalizeListNode($payments, 'Payment') as $index => $payment) {
                if (!is_array($payment)) {
                    continue;
                }
                if (array_key_exists('Amount', $payment) && $payment['Amount'] !== null) {
                    $inputs['recurringamount' . ($index + 1)] = (string)$payment['Amount'];
                }
            }
        }
    }

    private function buildGarantiPayXmlNode(array $orderData): array
    {
        $raw = $orderData['garanti_pay'] ?? $orderData['GarantiPaY'] ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }

        $node = [
            'bnsuseflag' => 'N',
            'fbbuseflag' => 'N',
            'chequeuseflag' => 'N',
            'mileuseflag' => 'N',
        ];

        $fieldMap = [
            'bns_use_flag' => 'bnsuseflag',
            'bnsuseflag' => 'bnsuseflag',
            'fbb_use_flag' => 'fbbuseflag',
            'fbbuseflag' => 'fbbuseflag',
            'cheque_use_flag' => 'chequeuseflag',
            'chequeuseflag' => 'chequeuseflag',
            'mile_use_flag' => 'mileuseflag',
            'mileuseflag' => 'mileuseflag',
            'company_name' => 'CompanyName',
            'CompanyName' => 'CompanyName',
            'order_info' => 'OrderInfo',
            'OrderInfo' => 'OrderInfo',
            'txn_timeout_period' => 'TxnTimeOutPeriod',
            'txn_time_out_period' => 'TxnTimeOutPeriod',
            'TxnTimeOutPeriod' => 'TxnTimeOutPeriod',
            'notif_send_ind' => 'NotifSendInd',
            'NotifSendInd' => 'NotifSendInd',
            'return_url' => 'ReturnUrl',
            'ReturnUrl' => 'ReturnUrl',
            'tckn' => 'TCKN',
            'TCKN' => 'TCKN',
            'gsm_number' => 'GSMNumber',
            'gsmnumber' => 'GSMNumber',
            'GSMNumber' => 'GSMNumber',
            'installment_only_for_commercial_card' => 'InstallmentOnlyForCommercialCard',
            'InstallmentOnlyForCommercialCard' => 'InstallmentOnlyForCommercialCard',
            'total_installment_count' => 'TotalInstallmentCount',
            'TotalInstallmentCount' => 'TotalInstallmentCount',
            'total_instamenl_count' => 'TotalInstallmentCount',
            'TotalInstamenlCount' => 'TotalInstallmentCount',
            'add_campaign_installment' => 'AddCampaingInstallment',
            'add_campaing_installment' => 'AddCampaingInstallment',
            'AddCampaingInstallment' => 'AddCampaingInstallment',
        ];

        $this->applyFieldMap($node, $orderData, $fieldMap);
        $this->applyFieldMap($node, $raw, $fieldMap);

        foreach ($raw as $key => $value) {
            if (is_array($value)) {
                continue;
            }

            $target = $fieldMap[(string)$key] ?? $this->canonicalKey((string)$key);
            $node[$target] = $value;
        }

        $installments = $raw['GPInstallments']
            ?? $raw['gp_installments']
            ?? $orderData['gp_installments']
            ?? $orderData['installments']
            ?? null;
        if ($installments !== null) {
            $node['GPInstallments'] = $this->normalizeListNode($installments, 'Installment');
        }

        return $node;
    }

    private function applyFieldMap(array &$target, array $source, array $fieldMap): void
    {
        foreach ($fieldMap as $sourceKey => $targetKey) {
            if (array_key_exists($sourceKey, $source) && $source[$sourceKey] !== null) {
                $target[$targetKey] = $source[$sourceKey];
            }
        }
    }

    private function buildAutoSubmitForm(string $formId, string $action, array $inputs): string
    {
        $html = '<form id="' . htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') . '" action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '" method="post">';
        foreach ($inputs as $key => $value) {
            $html .= '<input type="hidden" name="' . htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '">';
        }
        $html .= '</form>';
        $html .= '<script>document.getElementById("' . htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') . '").submit();</script>';

        return $html;
    }

    private function credentials(string $role): array
    {
        if ($role === self::REFUND_ROLE) {
            return [
                'prov_user_id' => $this->configValue('refund_user_id', 'PROVRFN'),
                'user_id' => $this->configValue('terminal_user_id', $this->configValue('refund_user_id', 'PROVRFN')),
                'password' => $this->configValue('refund_password', $this->configValue('prov_password')),
            ];
        }

        if ($role === self::OOS_ROLE) {
            return [
                'prov_user_id' => $this->configValue('prov_oos_user_id', 'PROVOOS'),
                'user_id' => $this->configValue('oos_user_id', 'oosuser'),
                'password' => $this->configValue('prov_oos_password', $this->configValue('prov_password')),
            ];
        }

        return [
            'prov_user_id' => $this->configValue('prov_user_id', 'PROVAUT'),
            'user_id' => $this->configValue('terminal_user_id', $this->configValue('prov_user_id', 'PROVAUT')),
            'password' => $this->configValue('prov_password'),
        ];
    }

    private function credentialRoleFromProvUser(string $provUserId): string
    {
        $provUserId = strtoupper($provUserId);

        if ($provUserId === strtoupper($this->configValue('prov_oos_user_id', 'PROVOOS'))) {
            return self::OOS_ROLE;
        }
        if ($provUserId === strtoupper($this->configValue('refund_user_id', 'PROVRFN'))) {
            return self::REFUND_ROLE;
        }

        return self::AUT_ROLE;
    }

    private function credentialRoleForForm(array $orderData, string $securityLevel): string
    {
        $explicitRole = $orderData['credential_role'] ?? $orderData['form_credential_role'] ?? null;
        if ($explicitRole !== null) {
            return $this->normalizeCredentialRole((string)$explicitRole, self::OOS_ROLE);
        }

        return in_array(strtoupper($securityLevel), self::OOS_SECURITY_LEVELS, true)
            ? $this->normalizeCredentialRole($this->configValue('oos_form_credential_role', self::OOS_ROLE), self::OOS_ROLE)
            : self::AUT_ROLE;
    }

    private function normalizeCredentialRole(string $role, string $fallback): string
    {
        $role = strtolower(trim($role));

        if (in_array($role, [self::AUT_ROLE, self::OOS_ROLE, self::REFUND_ROLE], true)) {
            return $role;
        }

        return $fallback;
    }

    private function withDefaults(array $config): array
    {
        $defaults = [
            'mode' => 'TEST',
            'terminal_id' => '',
            'terminal_user_id' => '',
            'prov_user_id' => 'PROVAUT',
            'prov_password' => '',
            'refund_user_id' => 'PROVRFN',
            'refund_password' => '',
            'merchant_id' => '',
            'store_key' => '',
            'currency' => '949',
            'prov_oos_user_id' => 'PROVOOS',
            'prov_oos_password' => '',
            'oos_user_id' => 'oosuser',
            'oos_form_credential_role' => self::OOS_ROLE,
            'api_version' => 'v0.01',
            'channel_code' => '',
            'hash_algorithm' => HashGenerator::HASH_ALGORITHM_LEGACY_SHA1,
            'test_endpoint' => 'https://sanalposprovtest.garanti.com.tr/VPServlet',
            'prod_endpoint' => 'https://sanalposprov.garanti.com.tr/VPServlet',
            'test_3d_endpoint' => 'https://sanalposprovtest.garanti.com.tr/servlet/gt3dengine',
            'prod_3d_endpoint' => 'https://sanalposprov.garanti.com.tr/servlet/gt3dengine',
        ];

        $config = array_merge($defaults, $config);
        $config['terminal_user_id'] = $config['terminal_user_id'] ?: $config['prov_user_id'];
        $config['refund_password'] = $config['refund_password'] ?: $config['prov_password'];
        $config['prov_oos_password'] = $config['prov_oos_password'] ?: $config['prov_password'];

        return $config;
    }

    private function refreshEndpoints(): void
    {
        $this->endpoint = $this->mode() === 'PROD'
            ? $this->configValue('prod_endpoint')
            : $this->configValue('test_endpoint');
        $this->threeDEndpoint = $this->mode() === 'PROD'
            ? $this->configValue('prod_3d_endpoint')
            : $this->configValue('test_3d_endpoint');
    }

    private function mode(): string
    {
        return strtoupper((string)$this->configValue('mode', 'TEST')) === 'PROD' ? 'PROD' : 'TEST';
    }

    private function hashAlgorithm(): string
    {
        return HashGenerator::normalizeHashAlgorithm($this->configValue('hash_algorithm', HashGenerator::HASH_ALGORITHM_LEGACY_SHA1));
    }

    private function configValue(string $key, string $default = ''): string
    {
        return (string)($this->config[$key] ?? $default);
    }

    private function customerIp(array $orderData): string
    {
        if (!empty($orderData['ip_address'])) {
            return (string)$orderData['ip_address'];
        }
        if (!empty($orderData['IPAddress'])) {
            return (string)$orderData['IPAddress'];
        }

        return function_exists('request') ? (string)request()->ip() : '';
    }

    private function expireDate(array $cardData): string
    {
        if (isset($cardData['expire_date'])) {
            return (string)$cardData['expire_date'];
        }
        if (isset($cardData['ExpireDate'])) {
            return (string)$cardData['ExpireDate'];
        }

        return (string)($cardData['expire_month'] ?? '') . (string)($cardData['expire_year'] ?? '');
    }

    private function normalizeListNode($value, string $itemName): array
    {
        if (!is_array($value)) {
            return [];
        }

        if (isset($value[$itemName]) && is_array($value[$itemName])) {
            $value = $value[$itemName];
        }

        if (!$this->isList($value)) {
            return [$this->normalizeKeys($value)];
        }

        return array_map(function ($item) {
            return is_array($item) ? $this->normalizeKeys($item) : $item;
        }, $value);
    }

    private function normalizeAddressListNode($value): array
    {
        return array_map(function ($address) {
            if (!is_array($address)) {
                return $address;
            }

            $normalized = $this->normalizeKeys($address);
            foreach (['GSMNumber', 'gsmnumber'] as $key) {
                if (array_key_exists($key, $normalized)) {
                    $normalized['GsmNumber'] = $normalized[$key];
                    unset($normalized[$key]);
                }
            }

            return $normalized;
        }, $this->normalizeListNode($value, 'Address'));
    }

    private function normalizeKeys(array $value): array
    {
        $normalized = [];
        foreach ($value as $key => $item) {
            $newKey = is_string($key) ? $this->canonicalKey($key) : $key;
            if (is_array($item)) {
                $normalized[$newKey] = $this->isList($item)
                    ? array_map(fn ($entry) => is_array($entry) ? $this->normalizeKeys($entry) : $entry, $item)
                    : $this->normalizeKeys($item);
            } else {
                $normalized[$newKey] = $item;
            }
        }

        return $normalized;
    }

    private function canonicalKey(string $key): string
    {
        $known = [
            'type' => 'Type',
            'number' => 'Number',
            'count' => 'Count',
            'amount' => 'Amount',
            'price' => 'Price',
            'quantity' => 'Quantity',
            'currency_code' => 'CurrencyCode',
            'order_id' => 'OrderID',
            'group_id' => 'GroupID',
            'payment_num' => 'PaymentNum',
            'due_date' => 'DueDate',
            'used_amount' => 'UsedAmount',
            'gained_amount' => 'GainedAmount',
            'gsm_number' => 'GSMNumber',
            'payment_type' => 'PaymentType',
            'product_id' => 'ProductID',
            'product_code' => 'ProductCode',
            'total_amount' => 'TotalAmount',
            'last_name' => 'LastName',
            'postal_code' => 'PostalCode',
            'phone_number' => 'PhoneNumber',
            'fax_number' => 'FaxNumber',
            'total_payment_num' => 'TotalPaymentNum',
            'frequency_type' => 'FrequencyType',
            'frequency_interval' => 'FrequencyInterval',
            'start_date' => 'StartDate',
            'end_date' => 'EndDate',
            'retry_attempt_email' => 'RetryAttemptEmail',
            'recurring_retry_attempt_count' => 'RecurringRetryAttemptCount',
            'firm_card_no' => 'FirmCardNo',
            'return_server_url' => 'ReturnServerUrl',
            'subscriber_code' => 'SubscriberCode',
            'invoice_id' => 'InvoiceID',
            'unit_id' => 'UnitID',
            'hash_date' => 'HashDate',
            'hash_value' => 'HashValue',
            'invoice_amount' => 'InvoiceAmount',
            'migros_cc_discount_amount' => 'MigrosCCDiscountAmount',
            'payment_amount' => 'PaymentAmount',
            'extra_discount_amount' => 'ExtraDiscountAmount',
            'product_based_discount_amount' => 'ProductBasedDiscountAmount',
            'company_name' => 'CompanyName',
            'order_info' => 'OrderInfo',
            'txn_timeout_period' => 'TxnTimeOutPeriod',
            'txn_time_out_period' => 'TxnTimeOutPeriod',
            'notif_send_ind' => 'NotifSendInd',
            'return_url' => 'ReturnUrl',
            'tckn' => 'TCKN',
            'installment_only_for_commercial_card' => 'InstallmentOnlyForCommercialCard',
            'total_installment_count' => 'TotalInstallmentCount',
            'total_instamenl_count' => 'TotalInstallmentCount',
            'add_campaign_installment' => 'AddCampaingInstallment',
            'add_campaing_installment' => 'AddCampaingInstallment',
        ];

        if (isset($known[$key])) {
            return $known[$key];
        }

        if (strpos($key, '_') !== false) {
            return str_replace(' ', '', ucwords(str_replace('_', ' ', $key)));
        }

        return $key;
    }

    private function snakeKey(string $key): string
    {
        $snake = preg_replace('/(?<!^)[A-Z]/', '_$0', $key);

        return strtolower((string)$snake);
    }

    private function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    private function mergeRecursiveDistinct(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !$this->isList($value)) {
                $base[$key] = $this->mergeRecursiveDistinct($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
