<?php

namespace Developertugrul\GarantiPos\Services;

use Developertugrul\GarantiPos\Exceptions\GarantiPosException;
use Illuminate\Support\Facades\Http;

class GarantiPosService
{
    private array $config;
    private string $endpoint;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->endpoint = $this->config['mode'] === 'PROD'
            ? 'https://sanalposprov.garanti.com.tr/VPServlet'
            : 'https://sanalposprovtest.garanti.com.tr/VPServlet';
    }

    /**
     * Set configuration dynamically.
     *
     * @param array $config
     * @return $this
     */
    public function setConfig(array $config): self
    {
        $this->config = array_merge($this->config, $config);
        $this->endpoint = $this->config['mode'] === 'PROD'
            ? 'https://sanalposprov.garanti.com.tr/VPServlet'
            : 'https://sanalposprovtest.garanti.com.tr/VPServlet';

        return $this;
    }

    /**
     * Pay without 3D Secure (Non-3D)
     *
     * @param array $orderData
     * @param array $cardData
     * @return array
     * @throws GarantiPosException
     */
    public function pay(array $orderData, array $cardData, string $type = 'sales'): array
    {
        $securityData = HashGenerator::generateSecurityData(
            $this->config['prov_password'],
            $this->config['terminal_id']
        );

        $hashData = HashGenerator::generateHashData(
            $orderData['order_id'],
            $this->config['terminal_id'],
            $cardData['number'],
            $orderData['amount'],
            $securityData
        );

        $payload = [
            'Mode' => $this->config['mode'],
            'Version' => 'v0.01',
            'Terminal' => [
                'ProvUserID' => $this->config['prov_user_id'],
                'HashData' => $hashData,
                'UserID' => $this->config['prov_user_id'],
                'ID' => $this->config['terminal_id'],
                'MerchantID' => $this->config['merchant_id'],
            ],
            'Customer' => [
                'IPAddress' => $orderData['ip_address'] ?? request()->ip(),
                'EmailAddress' => $orderData['email'] ?? '',
            ],
            'Card' => [
                'Number' => $cardData['number'],
                'ExpireDate' => $cardData['expire_month'] . $cardData['expire_year'],
                'CVV2' => $cardData['cvv'],
            ],
            'Order' => [
                'OrderID' => $orderData['order_id'],
                'GroupID' => '',
                'Description' => $orderData['description'] ?? '',
            ],
            'Transaction' => [
                'Type' => $type,
                'InstallmentCnt' => $orderData['installment'] ?? '',
                'Amount' => $orderData['amount'],
                'CurrencyCode' => $this->config['currency'],
                'CardholderPresentCode' => '0',
                'MotoInd' => 'N',
            ]
        ];

        if (isset($orderData['subtype'])) {
            $payload['Transaction']['SubType'] = $orderData['subtype'];
        }
        if (isset($orderData['address_list'])) {
            $payload['Order']['AddressList'] = $orderData['address_list'];
        }
        if (isset($orderData['item_list'])) {
            $payload['Order']['ItemList'] = $orderData['item_list'];
        }
        if (isset($orderData['comment_list'])) {
            $payload['Order']['CommentList'] = $orderData['comment_list'];
        }

        return $this->sendRequest($payload);
    }

    /**
     * Cancel a transaction
     *
     * @param string $orderId
     * @param string $originalRetrefNum
     * @return array
     * @throws GarantiPosException
     */
    public function cancel(string $orderId, string $originalRetrefNum = ''): array
    {
        return $this->processTransaction('cancel', $orderId, '1', $originalRetrefNum);
    }

    /**
     * Refund a transaction
     *
     * @param string $orderId
     * @param string $amount
     * @param string $originalRetrefNum
     * @return array
     * @throws GarantiPosException
     */
    public function refund(string $orderId, string $amount, string $originalRetrefNum = ''): array
    {
        return $this->processTransaction('refund', $orderId, $amount, $originalRetrefNum);
    }

    /**
     * Pre-Auth (Ön Provizyon)
     *
     * @param array $orderData
     * @param array $cardData
     * @return array
     * @throws GarantiPosException
     */
    public function preAuth(array $orderData, array $cardData): array
    {
        $orderData['type'] = 'preauth';
        return $this->pay($orderData, $cardData, 'preauth');
    }

    /**
     * Post-Auth (Ön Provizyon Kapama)
     *
     * @param string $orderId
     * @param string $amount
     * @return array
     * @throws GarantiPosException
     */
    public function postAuth(string $orderId, string $amount): array
    {
        return $this->processTransaction('postauth', $orderId, $amount);
    }

    /**
     * Post-Auth Void (Ön Provizyon Kapama İptali)
     *
     * @param string $orderId
     * @param string $originalRetrefNum
     * @return array
     * @throws GarantiPosException
     */
    public function postAuthVoid(string $orderId, string $originalRetrefNum = ''): array
    {
        return $this->cancel($orderId, $originalRetrefNum);
    }

    /**
     * Refund Void (İade İptali)
     *
     * @param string $orderId
     * @param string $originalRetrefNum
     * @return array
     * @throws GarantiPosException
     */
    public function refundVoid(string $orderId, string $originalRetrefNum = ''): array
    {
        return $this->cancel($orderId, $originalRetrefNum);
    }

    /**
     * Point Inquiry (Puan Sorgulama)
     *
     * @param array $cardData
     * @return array
     * @throws GarantiPosException
     */
    public function pointInquiry(array $cardData): array
    {
        $securityData = HashGenerator::generateSecurityData(
            $this->config['prov_password'],
            $this->config['terminal_id']
        );

        $hashData = HashGenerator::generateHashData(
            '',
            $this->config['terminal_id'],
            $cardData['number'],
            '1', // Dummy amount required for hash
            $securityData
        );

        $payload = [
            'Mode' => $this->config['mode'],
            'Version' => 'v0.01',
            'Terminal' => [
                'ProvUserID' => $this->config['prov_user_id'],
                'HashData' => $hashData,
                'UserID' => $this->config['prov_user_id'],
                'ID' => $this->config['terminal_id'],
                'MerchantID' => $this->config['merchant_id'],
            ],
            'Card' => [
                'Number' => $cardData['number'],
                'ExpireDate' => $cardData['expire_month'] . $cardData['expire_year'],
                'CVV2' => $cardData['cvv'] ?? '',
            ],
            'Order' => [
                'OrderID' => '',
                'GroupID' => '',
            ],
            'Transaction' => [
                'Type' => 'rewardinq',
                'Amount' => '1',
                'CurrencyCode' => $this->config['currency'],
            ]
        ];

        return $this->sendRequest($payload);
    }

    /**
     * Generic Transaction Processor (Cancel, Refund, PostAuth)
     *
     * @param string $type
     * @param string $orderId
     * @param string $amount
     * @param string $originalRetrefNum
     * @return array
     * @throws GarantiPosException
     */
    private function processTransaction(string $type, string $orderId, string $amount, string $originalRetrefNum = ''): array
    {
        $isRefundOp = in_array($type, ['cancel', 'refund', 'recurringvoid']);

        $userId   = $isRefundOp
            ? ($this->config['refund_user_id'] ?? 'PROVRFN')
            : $this->config['prov_user_id'];
        $password = $isRefundOp
            ? ($this->config['refund_password'] ?? $this->config['prov_password'])
            : $this->config['prov_password'];

        $securityData = HashGenerator::generateSecurityData(
            $password,
            $this->config['terminal_id']
        );

        $hashData = HashGenerator::generateHashData(
            $orderId,
            $this->config['terminal_id'],
            '',
            $amount,
            $securityData
        );

        $payload = [
            'Mode' => $this->config['mode'],
            'Version' => 'v0.01',
            'Terminal' => [
                'ProvUserID' => $userId,
                'HashData' => $hashData,
                'UserID' => $userId,
                'ID' => $this->config['terminal_id'],
                'MerchantID' => $this->config['merchant_id'],
            ],
            'Order' => [
                'OrderID' => $orderId,
            ],
            'Transaction' => [
                'Type' => $type,
                'Amount' => $amount,
                'CurrencyCode' => $this->config['currency'],
                'OriginalRetrefNum' => $originalRetrefNum,
            ]
        ];

        return $this->sendRequest($payload);
    }

    /**
     * SMS Validation Payment (SubType: sms)
     *
     * @param array $orderData
     * @param array $cardData
     * @return array
     * @throws GarantiPosException
     */
    public function paySms(array $orderData, array $cardData): array
    {
        $orderData['subtype'] = 'sms';
        return $this->pay($orderData, $cardData);
    }

    /**
     * DCC Payment (Dynamic Currency Conversion)
     *
     * @param array $orderData
     * @param array $cardData
     * @return array
     * @throws GarantiPosException
     */
    public function payDcc(array $orderData, array $cardData): array
    {
        $orderData['subtype'] = 'dcc';
        return $this->pay($orderData, $cardData);
    }

    /**
     * Send XML request to Garanti API
     *
     * @param array $payload
     * @return array
     * @throws GarantiPosException
     */
    private function sendRequest(array $payload): array
    {
        $xmlData = XmlBuilder::build($payload);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->endpoint);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'data=' . $xmlData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new GarantiPosException("CURL Error: $error");
        }
        curl_close($ch);

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response);
        
        if ($xml === false) {
            $errors = libxml_get_errors();
            $errorMsg = $errors[0]->message ?? 'Unknown XML parsing error';
            libxml_clear_errors();
            throw new GarantiPosException("Invalid XML Response from Garanti: " . $errorMsg . " | Raw: " . $response);
        }

        return json_decode(json_encode($xml), true);
    }

    /**
     * Parse XML response to array
     *
     * @param string $xmlString
     * @return array
     */
    private function parseXmlResponse(string $xmlString): array
    {
        $xml = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA);
        return json_decode(json_encode($xml), true);
    }

    /**
     * Generate 3D Secure Form HTML
     *
     * @param array $orderData
     * @param array $cardData
     * @param string $successUrl
     * @param string $errorUrl
     * @param string $type
     * @return string
     */
    public function build3DForm(array $orderData, array $cardData, string $successUrl, string $errorUrl, string $type = 'sales'): string
    {
        $securityData = HashGenerator::generateSecurityData(
            $this->config['prov_password'],
            $this->config['terminal_id']
        );

        $hashData = HashGenerator::generate3DHash(
            $this->config['terminal_id'],
            $orderData['order_id'],
            $orderData['amount'],
            $successUrl,
            $errorUrl,
            $type,
            $orderData['installment'] ?? '',
            $this->config['store_key'],
            $securityData
        );

        $endpoint = $this->config['mode'] === 'PROD'
            ? 'https://sanalposprov.garanti.com.tr/servlet/gt3dengine'
            : 'https://sanalposprovtest.garanti.com.tr/servlet/gt3dengine';

        $formInputs = [
            'mode' => $this->config['mode'],
            'apiversion' => 'v0.01',
            'terminalprovuserid' => $this->config['prov_user_id'],
            'terminaluserid' => $this->config['prov_user_id'],
            'terminalmerchantid' => $this->config['merchant_id'],
            'txntype' => $type,
            'txnamount' => $orderData['amount'],
            'txncurrencycode' => $this->config['currency'],
            'txninstallmentcount' => $orderData['installment'] ?? '',
            'orderid' => $orderData['order_id'],
            'terminalid' => $this->config['terminal_id'],
            'successurl' => $successUrl,
            'errorurl' => $errorUrl,
            'customeripaddress' => $orderData['ip_address'] ?? request()->ip(),
            'customeremailaddress' => $orderData['email'] ?? '',
            'secure3dsecuritylevel' => $orderData['security_level'] ?? '3D_PAY',
            'secure3dhash' => $hashData,
            'cardnumber' => $cardData['number'],
            'cardexpiredatemonth' => $cardData['expire_month'],
            'cardexpiredateyear' => $cardData['expire_year'],
            'cardcvv2' => $cardData['cvv'],
        ];

        $html = '<form id="garanti-3d-form" action="'.$endpoint.'" method="post">';
        foreach ($formInputs as $key => $value) {
            $html .= '<input type="hidden" name="'.$key.'" value="'.htmlspecialchars($value).'">';
        }
        $html .= '</form>';
        $html .= '<script>document.getElementById("garanti-3d-form").submit();</script>';

        return $html;
    }

    /**
     * Point Usage (Puan Kullanımı)
     *
     * @param array $orderData
     * @param array $cardData
     * @param string $pointAmount
     * @return array
     * @throws GarantiPosException
     */
    public function rewardUsage(array $orderData, array $cardData, string $pointAmount): array
    {
        $securityData = HashGenerator::generateSecurityData(
            $this->config['prov_password'],
            $this->config['terminal_id']
        );

        $hashData = HashGenerator::generateHashData(
            $orderData['order_id'],
            $this->config['terminal_id'],
            $cardData['number'],
            $pointAmount,
            $securityData
        );

        $payload = [
            'Mode' => $this->config['mode'],
            'Version' => 'v0.01',
            'Terminal' => [
                'ProvUserID' => $this->config['prov_user_id'],
                'HashData' => $hashData,
                'UserID' => $this->config['prov_user_id'],
                'ID' => $this->config['terminal_id'],
                'MerchantID' => $this->config['merchant_id'],
            ],
            'Customer' => [
                'IPAddress' => $orderData['ip_address'] ?? request()->ip(),
                'EmailAddress' => $orderData['email'] ?? '',
            ],
            'Card' => [
                'Number' => $cardData['number'],
                'ExpireDate' => $cardData['expire_month'] . $cardData['expire_year'],
                'CVV2' => $cardData['cvv'],
            ],
            'Order' => [
                'OrderID' => $orderData['order_id'],
            ],
            'Transaction' => [
                'Type' => 'rewardusage',
                'Amount' => $pointAmount,
                'CurrencyCode' => $this->config['currency'],
            ]
        ];

        return $this->sendRequest($payload);
    }

    /**
     * Order Inquiry (Sipariş Sorgulama)
     *
     * @param string $orderId
     * @return array
     * @throws GarantiPosException
     */
    public function orderInquiry(string $orderId): array
    {
        $securityData = HashGenerator::generateSecurityData(
            $this->config['prov_password'],
            $this->config['terminal_id']
        );

        $hashData = HashGenerator::generateHashData(
            $orderId,
            $this->config['terminal_id'],
            '',
            '1', // Dummy amount
            $securityData
        );

        $payload = [
            'Mode' => $this->config['mode'],
            'Version' => 'v0.01',
            'Terminal' => [
                'ProvUserID' => $this->config['prov_user_id'],
                'HashData' => $hashData,
                'UserID' => $this->config['prov_user_id'],
                'ID' => $this->config['terminal_id'],
                'MerchantID' => $this->config['merchant_id'],
            ],
            'Order' => [
                'OrderID' => $orderId,
            ],
            'Transaction' => [
                'Type' => 'orderinq',
                'Amount' => '1',
                'CurrencyCode' => $this->config['currency'],
            ]
        ];

        return $this->sendRequest($payload);
    }

    /**
     * Order History Inquiry (İşlem Detay Sorgulama)
     *
     * @param string $orderId
     * @return array
     * @throws GarantiPosException
     */
    public function orderHistoryInquiry(string $orderId): array
    {
        $securityData = HashGenerator::generateSecurityData(
            $this->config['prov_password'],
            $this->config['terminal_id']
        );

        $hashData = HashGenerator::generateHashData(
            $orderId,
            $this->config['terminal_id'],
            '',
            '1', // Dummy amount
            $securityData
        );

        $payload = [
            'Mode' => $this->config['mode'],
            'Version' => 'v0.01',
            'Terminal' => [
                'ProvUserID' => $this->config['prov_user_id'],
                'HashData' => $hashData,
                'UserID' => $this->config['prov_user_id'],
                'ID' => $this->config['terminal_id'],
                'MerchantID' => $this->config['merchant_id'],
            ],
            'Order' => [
                'OrderID' => $orderId,
            ],
            'Transaction' => [
                'Type' => 'orderhistoryinq',
                'Amount' => '1',
                'CurrencyCode' => $this->config['currency'],
            ]
        ];

        return $this->sendRequest($payload);
    }

    /**
     * Generate 3D Secure Ortak Ödeme Sayfası (OOS) Form HTML
     *
     * @param array $orderData
     * @param string $successUrl
     * @param string $errorUrl
     * @param string $type
     * @return string
     */
    public function build3DOOSForm(array $orderData, string $successUrl, string $errorUrl, string $type = 'sales'): string
    {
        $securityData = HashGenerator::generateSecurityData(
            $this->config['prov_password'],
            $this->config['terminal_id']
        );

        $hashData = HashGenerator::generate3DHash(
            $this->config['terminal_id'],
            $orderData['order_id'],
            $orderData['amount'],
            $successUrl,
            $errorUrl,
            $type,
            $orderData['installment'] ?? '',
            $this->config['store_key'],
            $securityData
        );

        $endpoint = $this->config['mode'] === 'PROD'
            ? 'https://sanalposprov.garanti.com.tr/servlet/gt3dengine'
            : 'https://sanalposprovtest.garanti.com.tr/servlet/gt3dengine';

        $formInputs = [
            'mode' => $this->config['mode'],
            'apiversion' => 'v0.01',
            'terminalprovuserid' => $this->config['prov_oos_user_id'] ?? 'PROVOOS',
            'terminaluserid' => $this->config['oos_user_id'] ?? 'oosuser',
            'terminalmerchantid' => $this->config['merchant_id'],
            'txntype' => $type,
            'txnamount' => $orderData['amount'],
            'txncurrencycode' => $this->config['currency'],
            'txninstallmentcount' => $orderData['installment'] ?? '',
            'orderid' => $orderData['order_id'],
            'terminalid' => $this->config['terminal_id'],
            'successurl' => $successUrl,
            'errorurl' => $errorUrl,
            'customeripaddress' => $orderData['ip_address'] ?? request()->ip(),
            'customeremailaddress' => $orderData['email'] ?? '',
            'secure3dsecuritylevel' => '3D_OOS_PAY',
            'secure3dhash' => $hashData,
        ];

        $html = '<form id="garanti-3d-oos-form" action="'.$endpoint.'" method="post">';
        foreach ($formInputs as $key => $value) {
            $html .= '<input type="hidden" name="'.$key.'" value="'.htmlspecialchars($value).'">';
        }
        $html .= '</form>';
        $html .= '<script>document.getElementById("garanti-3d-oos-form").submit();</script>';

        return $html;
    }

    /**
     * Generate Ortak Ödeme Sayfası (OOS_PAY - Non 3D) Form HTML
     *
     * @param array $orderData
     * @param string $successUrl
     * @param string $errorUrl
     * @param string $type
     * @return string
     */
    public function buildOOSForm(array $orderData, string $successUrl, string $errorUrl, string $type = 'sales'): string
    {
        $securityData = HashGenerator::generateSecurityData($this->config['prov_password'], $this->config['terminal_id']);
        $hashData = HashGenerator::generate3DHash(
            $this->config['terminal_id'], $orderData['order_id'], $orderData['amount'],
            $successUrl, $errorUrl, $type, $orderData['installment'] ?? '',
            $this->config['store_key'], $securityData
        );

        $endpoint = $this->config['mode'] === 'PROD'
            ? 'https://sanalposprov.garanti.com.tr/servlet/gt3dengine'
            : 'https://sanalposprovtest.garanti.com.tr/servlet/gt3dengine';

        $formInputs = [
            'mode' => $this->config['mode'],
            'apiversion' => 'v0.01',
            'terminalprovuserid' => $this->config['prov_oos_user_id'] ?? 'PROVOOS',
            'terminaluserid' => $this->config['oos_user_id'] ?? 'oosuser',
            'terminalmerchantid' => $this->config['merchant_id'],
            'txntype' => $type,
            'txnamount' => $orderData['amount'],
            'txncurrencycode' => $this->config['currency'],
            'txninstallmentcount' => $orderData['installment'] ?? '',
            'orderid' => $orderData['order_id'],
            'terminalid' => $this->config['terminal_id'],
            'successurl' => $successUrl,
            'errorurl' => $errorUrl,
            'customeripaddress' => $orderData['ip_address'] ?? request()->ip(),
            'customeremailaddress' => $orderData['email'] ?? '',
            'secure3dsecuritylevel' => 'OOS_PAY',
            'secure3dhash' => $hashData,
        ];

        $html = '<form id="garanti-oos-form" action="'.$endpoint.'" method="post">';
        foreach ($formInputs as $key => $value) {
            $html .= '<input type="hidden" name="'.$key.'" value="'.htmlspecialchars($value).'">';
        }
        $html .= '</form>';
        $html .= '<script>document.getElementById("garanti-oos-form").submit();</script>';

        return $html;
    }

    /**
     * Pay 3D Model Second Step (Otorizasyon)
     *
     * @param array $orderData
     * @param array $cardData
     * @param array $threeDResponse POST data from Garanti 3D success redirect
     * @return array
     * @throws GarantiPosException
     */
    public function pay3DModel(array $orderData, array $cardData, array $threeDResponse): array
    {
        if (!HashGenerator::validate3DHash($threeDResponse, $this->config['store_key'])) {
            throw new GarantiPosException('3D Secure Hash validation failed.');
        }

        $securityData = HashGenerator::generateSecurityData(
            $this->config['prov_password'],
            $this->config['terminal_id']
        );

        // Note: the Card Number must be empty here for the hash generation in step 2
        $hashData = HashGenerator::generateHashData(
            $orderData['order_id'],
            $this->config['terminal_id'],
            '',
            $orderData['amount'],
            $securityData
        );

        $payload = [
            'Mode' => $this->config['mode'],
            'Version' => 'v0.01',
            'Terminal' => [
                'ProvUserID' => $this->config['prov_user_id'],
                'HashData' => $hashData,
                'UserID' => $this->config['prov_user_id'],
                'ID' => $this->config['terminal_id'],
                'MerchantID' => $this->config['merchant_id'],
            ],
            'Customer' => [
                'IPAddress' => $orderData['ip_address'] ?? request()->ip(),
                'EmailAddress' => $orderData['email'] ?? '',
            ],
            'Card' => [
                'Number' => '',
                'ExpireDate' => '',
                'CVV2' => '',
            ],
            'Order' => [
                'OrderID' => $orderData['order_id'],
                'GroupID' => '',
                'Description' => $orderData['description'] ?? '',
            ],
            'Transaction' => [
                'Type' => 'sales',
                'InstallmentCnt' => $orderData['installment'] ?? '',
                'Amount' => $orderData['amount'],
                'CurrencyCode' => $this->config['currency'],
                'CardholderPresentCode' => '13',
                'MotoInd' => 'N',
                'Secure3D' => [
                    'AuthenticationCode' => $threeDResponse['cavv'] ?? '',
                    'SecurityLevel' => $threeDResponse['eci'] ?? '',
                    'TxnID' => $threeDResponse['xid'] ?? '',
                    'Md' => $threeDResponse['md'] ?? '',
                ]
            ]
        ];

        return $this->sendRequest($payload);
    }

    /**
     * CepBank Payment
     *
     * @param array $orderData
     * @param array $cepBankData ['gsm_number' => '...', 'payment_type' => 'K/D/V']
     * @return array
     * @throws GarantiPosException
     */
    public function payCepBank(array $orderData, array $cepBankData): array
    {
        $securityData = HashGenerator::generateSecurityData(
            $this->config['prov_password'],
            $this->config['terminal_id']
        );

        $hashData = HashGenerator::generateHashData(
            $orderData['order_id'],
            $this->config['terminal_id'],
            '',
            $orderData['amount'],
            $securityData
        );

        $payload = [
            'Mode' => $this->config['mode'],
            'Version' => 'v0.01',
            'Terminal' => [
                'ProvUserID' => $this->config['prov_user_id'],
                'HashData' => $hashData,
                'UserID' => $this->config['prov_user_id'],
                'ID' => $this->config['terminal_id'],
                'MerchantID' => $this->config['merchant_id'],
            ],
            'Customer' => [
                'IPAddress' => $orderData['ip_address'] ?? request()->ip(),
                'EmailAddress' => $orderData['email'] ?? '',
            ],
            'Card' => [
                'Number' => '',
                'ExpireDate' => '',
                'CVV2' => '',
            ],
            'Order' => [
                'OrderID' => $orderData['order_id'],
            ],
            'Transaction' => [
                'Type' => 'cepbank',
                'Amount' => $orderData['amount'],
                'CurrencyCode' => $this->config['currency'],
                'CepBank' => [
                    'GSMNumber' => $cepBankData['gsm_number'],
                    'PaymentType' => $cepBankData['payment_type'],
                ]
            ]
        ];

        return $this->sendRequest($payload);
    }

    /**
     * Generate GarantiPay Form HTML
     *
     * @param array $orderData
     * @param string $successUrl
     * @param string $errorUrl
     * @return string
     */
    public function buildGarantiPayForm(array $orderData, string $successUrl, string $errorUrl): string
    {
        $securityData = HashGenerator::generateSecurityData(
            $this->config['prov_password'],
            $this->config['terminal_id']
        );

        $hashData = HashGenerator::generate3DHash(
            $this->config['terminal_id'],
            $orderData['order_id'],
            $orderData['amount'],
            $successUrl,
            $errorUrl,
            'sales',
            $orderData['installment'] ?? '',
            $this->config['store_key'],
            $securityData
        );

        $endpoint = $this->config['mode'] === 'PROD'
            ? 'https://sanalposprov.garanti.com.tr/servlet/gt3dengine'
            : 'https://sanalposprovtest.garanti.com.tr/servlet/gt3dengine';

        $formInputs = [
            'mode' => $this->config['mode'],
            'apiversion' => 'v0.01',
            'terminalprovuserid' => $this->config['prov_oos_user_id'] ?? 'PROVOOS',
            'terminaluserid' => $this->config['oos_user_id'] ?? 'oosuser',
            'terminalmerchantid' => $this->config['merchant_id'],
            'txntype' => 'gpdatarequest',
            'txnamount' => $orderData['amount'],
            'txncurrencycode' => $this->config['currency'],
            'txninstallmentcount' => $orderData['installment'] ?? '',
            'orderid' => $orderData['order_id'],
            'terminalid' => $this->config['terminal_id'],
            'successurl' => $successUrl,
            'errorurl' => $errorUrl,
            'customeripaddress' => $orderData['ip_address'] ?? request()->ip(),
            'customeremailaddress' => $orderData['email'] ?? '',
            'secure3dsecuritylevel' => 'CUSTOM_PAY',
            'secure3dhash' => $hashData,
            'txnsubtype' => 'sales',
            'garantipay' => 'Y',
        ];

        $html = '<form id="garanti-pay-form" action="'.$endpoint.'" method="post">';
        foreach ($formInputs as $key => $value) {
            $html .= '<input type="hidden" name="'.$key.'" value="'.htmlspecialchars($value).'">';
        }
        $html .= '</form>';
        $html .= '<script>document.getElementById("garanti-pay-form").submit();</script>';

        return $html;
    }

    /**
     * Recurring Payment Setup (Tekrarlı Satış Başlatma)
     *
     * @param array $orderData
     * @param array $cardData
     * @param array $recurringData
     * @return array
     * @throws GarantiPosException
     */
    public function payRecurring(array $orderData, array $cardData, array $recurringData): array
    {
        $securityData = HashGenerator::generateSecurityData(
            $this->config['prov_password'],
            $this->config['terminal_id']
        );

        $hashData = HashGenerator::generateHashData(
            $orderData['order_id'],
            $this->config['terminal_id'],
            $cardData['number'],
            $orderData['amount'],
            $securityData
        );

        $payload = [
            'Mode' => $this->config['mode'],
            'Version' => 'v0.01',
            'Terminal' => [
                'ProvUserID' => $this->config['prov_user_id'],
                'HashData' => $hashData,
                'UserID' => $this->config['prov_user_id'],
                'ID' => $this->config['terminal_id'],
                'MerchantID' => $this->config['merchant_id'],
            ],
            'Customer' => [
                'IPAddress' => $orderData['ip_address'] ?? request()->ip(),
                'EmailAddress' => $orderData['email'] ?? '',
            ],
            'Card' => [
                'Number' => $cardData['number'],
                'ExpireDate' => $cardData['expire_month'] . $cardData['expire_year'],
                'CVV2' => $cardData['cvv'],
            ],
            'Order' => [
                'OrderID' => $orderData['order_id'],
                'Recurring' => [
                    'Type' => 'R',
                    'TotalPaymentNum' => $recurringData['total_payment_num'],
                    'FrequencyType' => $recurringData['frequency_type'], // M, W, D vs
                    'FrequencyInterval' => $recurringData['frequency_interval'],
                    'StartDate' => $recurringData['start_date'], // YYYYMMDD
                ]
            ],
            'Transaction' => [
                'Type' => 'sales',
                'Amount' => $orderData['amount'],
                'CurrencyCode' => $this->config['currency'],
            ]
        ];

        return $this->sendRequest($payload);
    }

    /**
     * Identify Inquiry (TCKN Doğrulama)
     *
     * @param array $orderData
     * @param array $cardData
     * @param string $tckn
     * @return array
     * @throws GarantiPosException
     */
    public function identifyInquiry(array $orderData, array $cardData, string $tckn): array
    {
        $securityData = HashGenerator::generateSecurityData(
            $this->config['prov_password'],
            $this->config['terminal_id']
        );

        $hashData = HashGenerator::generateHashData(
            $orderData['order_id'],
            $this->config['terminal_id'],
            $cardData['number'],
            $orderData['amount'],
            $securityData
        );

        $payload = [
            'Mode' => $this->config['mode'],
            'Version' => 'v0.01',
            'Terminal' => [
                'ProvUserID' => $this->config['prov_user_id'],
                'HashData' => $hashData,
                'UserID' => $this->config['prov_user_id'],
                'ID' => $this->config['terminal_id'],
                'MerchantID' => $this->config['merchant_id'],
            ],
            'Customer' => [
                'IPAddress' => $orderData['ip_address'] ?? request()->ip(),
                'EmailAddress' => $orderData['email'] ?? '',
            ],
            'Card' => [
                'Number' => $cardData['number'],
                'ExpireDate' => $cardData['expire_month'] . $cardData['expire_year'],
                'CVV2' => $cardData['cvv'],
            ],
            'Order' => [
                'OrderID' => $orderData['order_id'],
            ],
            'Transaction' => [
                'Type' => 'identifyinq',
                'Amount' => $orderData['amount'],
                'CurrencyCode' => $this->config['currency'],
                'Verification' => [
                    'Identity' => $tckn
                ]
            ]
        ];

        return $this->sendRequest($payload);
    }

    /**
     * Extended Credit Payment (Tüketici Kredisi / Vadeli Taksit)
     *
     * @param array $orderData
     * @param array $cardData
     * @return array
     * @throws GarantiPosException
     */
    public function payExtendedCredit(array $orderData, array $cardData): array
    {
        return $this->pay($orderData, $cardData, 'extendedcredit');
    }

    /**
     * Commercial Card Extended Credit (Ticari Kart Vadeli İşlem)
     *
     * @param array $orderData
     * @param array $cardData
     * @return array
     * @throws GarantiPosException
     */
    public function payCommercialCardExtendedCredit(array $orderData, array $cardData): array
    {
        return $this->pay($orderData, $cardData, 'commercialcardextendedcredit');
    }

    /**
     * Extended Credit Inquiry (Tüketici Kredisi Sorgulama)
     *
     * @param string $orderId
     * @return array
     * @throws GarantiPosException
     */
    public function extendedCreditInquiry(string $orderId): array
    {
        $securityData = HashGenerator::generateSecurityData(
            $this->config['prov_password'],
            $this->config['terminal_id']
        );

        $hashData = HashGenerator::generateHashData(
            $orderId,
            $this->config['terminal_id'],
            '',
            '1',
            $securityData
        );

        $payload = [
            'Mode' => $this->config['mode'],
            'Version' => 'v0.01',
            'Terminal' => [
                'ProvUserID' => $this->config['prov_user_id'],
                'HashData' => $hashData,
                'UserID' => $this->config['prov_user_id'],
                'ID' => $this->config['terminal_id'],
                'MerchantID' => $this->config['merchant_id'],
            ],
            'Order' => [
                'OrderID' => $orderId,
            ],
            'Transaction' => [
                'Type' => 'extendedcreditinq',
                'Amount' => '1',
                'CurrencyCode' => $this->config['currency'],
            ]
        ];

        return $this->sendRequest($payload);
    }

    /**
     * BIN Inquiry (BIN Sorgulama)
     *
     * @param string $binNumber
     * @return array
     * @throws GarantiPosException
     */
    public function binInquiry(string $binNumber): array
    {
        $securityData = HashGenerator::generateSecurityData(
            $this->config['prov_password'],
            $this->config['terminal_id']
        );

        $hashData = HashGenerator::generateHashData(
            '',
            $this->config['terminal_id'],
            $binNumber,
            '1',
            $securityData
        );

        $payload = [
            'Mode' => $this->config['mode'],
            'Version' => 'v0.01',
            'Terminal' => [
                'ProvUserID' => $this->config['prov_user_id'],
                'HashData' => $hashData,
                'UserID' => $this->config['prov_user_id'],
                'ID' => $this->config['terminal_id'],
                'MerchantID' => $this->config['merchant_id'],
            ],
            'Card' => [
                'Number' => $binNumber,
            ],
            'Transaction' => [
                'Type' => 'bininq',
                'Amount' => '1',
                'CurrencyCode' => $this->config['currency'],
            ]
        ];

        return $this->sendRequest($payload);
    }

    /**
     * Recurring Cancel (Tekrarlı Satış İptali)
     *
     * @param string $orderId
     * @return array
     * @throws GarantiPosException
     */
    public function recurringCancel(string $orderId): array
    {
        $userId   = $this->config['refund_user_id'] ?? 'PROVRFN';
        $password = $this->config['refund_password'] ?? $this->config['prov_password'];

        $securityData = HashGenerator::generateSecurityData(
            $password,
            $this->config['terminal_id']
        );

        $hashData = HashGenerator::generateHashData(
            $orderId,
            $this->config['terminal_id'],
            '',
            '1',
            $securityData
        );

        $payload = [
            'Mode' => $this->config['mode'],
            'Version' => 'v0.01',
            'Terminal' => [
                'ProvUserID' => $userId,
                'HashData' => $hashData,
                'UserID' => $userId,
                'ID' => $this->config['terminal_id'],
                'MerchantID' => $this->config['merchant_id'],
            ],
            'Order' => [
                'OrderID' => $orderId,
            ],
            'Transaction' => [
                'Type' => 'recurringvoid',
                'Amount' => '1',
                'CurrencyCode' => $this->config['currency'],
            ]
        ];

        return $this->sendRequest($payload);
    }

    /**
     * DCC Inquiry (DCC - Kur Sorgulama)
     *
     * @param string $orderId
     * @param string $cardNumber
     * @param string $amount
     * @return array
     * @throws GarantiPosException
     */
    public function dccInquiry(string $orderId, string $cardNumber, string $amount): array
    {
        $securityData = HashGenerator::generateSecurityData($this->config['prov_password'], $this->config['terminal_id']);
        $hashData = HashGenerator::generateHashData($orderId, $this->config['terminal_id'], $cardNumber, $amount, $securityData);

        $payload = [
            'Mode' => $this->config['mode'],
            'Version' => 'v0.01',
            'Terminal' => [
                'ProvUserID' => $this->config['prov_user_id'],
                'HashData' => $hashData,
                'UserID' => $this->config['prov_user_id'],
                'ID' => $this->config['terminal_id'],
                'MerchantID' => $this->config['merchant_id'],
            ],
            'Card' => ['Number' => $cardNumber],
            'Order' => ['OrderID' => $orderId],
            'Transaction' => [
                'Type' => 'dccinq',
                'Amount' => $amount,
                'CurrencyCode' => $this->config['currency'],
            ]
        ];

        return $this->sendRequest($payload);
    }

    /**
     * Batch Inquiry (Gün Sonu Sorgulama)
     *
     * @return array
     * @throws GarantiPosException
     */
    public function batchInquiry(): array
    {
        $securityData = HashGenerator::generateSecurityData($this->config['prov_password'], $this->config['terminal_id']);
        $hashData = HashGenerator::generateHashData('', $this->config['terminal_id'], '', '1', $securityData);

        $payload = [
            'Mode' => $this->config['mode'],
            'Version' => 'v0.01',
            'Terminal' => [
                'ProvUserID' => $this->config['prov_user_id'],
                'HashData' => $hashData,
                'UserID' => $this->config['prov_user_id'],
                'ID' => $this->config['terminal_id'],
                'MerchantID' => $this->config['merchant_id'],
            ],
            'Transaction' => [
                'Type' => 'batchinq',
                'Amount' => '1',
                'CurrencyCode' => $this->config['currency'],
            ]
        ];

        return $this->sendRequest($payload);
    }

    /**
     * Campaign Code Inquiry (Kampanya Kodu Sorgulama)
     *
     * @param string $campaignCode
     * @return array
     * @throws GarantiPosException
     */
    public function campaignCodeInquiry(string $campaignCode): array
    {
        $securityData = HashGenerator::generateSecurityData($this->config['prov_password'], $this->config['terminal_id']);
        $hashData = HashGenerator::generateHashData('', $this->config['terminal_id'], '', '1', $securityData);

        $payload = [
            'Mode' => $this->config['mode'],
            'Version' => 'v0.01',
            'Terminal' => [
                'ProvUserID' => $this->config['prov_user_id'],
                'HashData' => $hashData,
                'UserID' => $this->config['prov_user_id'],
                'ID' => $this->config['terminal_id'],
                'MerchantID' => $this->config['merchant_id'],
            ],
            'Transaction' => [
                'Type' => 'campaigncodeinq',
                'Amount' => '1',
                'CurrencyCode' => $this->config['currency'],
                'Campaign' => [
                    'Code' => $campaignCode
                ]
            ]
        ];

        return $this->sendRequest($payload);
    }

    /**
     * Order List Inquiry (Sipariş Listesi Sorgulama)
     *
     * @param string|null $startDate (YYYYMMDD)
     * @param string|null $endDate (YYYYMMDD)
     * @return array
     * @throws GarantiPosException
     */
    public function orderListInquiry(?string $startDate = null, ?string $endDate = null): array
    {
        $securityData = HashGenerator::generateSecurityData($this->config['prov_password'], $this->config['terminal_id']);
        $hashData = HashGenerator::generateHashData('', $this->config['terminal_id'], '', '1', $securityData);

        $payload = [
            'Mode' => $this->config['mode'],
            'Version' => 'v0.01',
            'Terminal' => [
                'ProvUserID' => $this->config['prov_user_id'],
                'HashData' => $hashData,
                'UserID' => $this->config['prov_user_id'],
                'ID' => $this->config['terminal_id'],
                'MerchantID' => $this->config['merchant_id'],
            ],
            'Transaction' => [
                'Type' => 'orderlistinq',
                'Amount' => '1',
                'CurrencyCode' => $this->config['currency'],
            ]
        ];

        if ($startDate && $endDate) {
            $payload['Transaction']['StartDate'] = $startDate;
            $payload['Transaction']['EndDate'] = $endDate;
        }

        return $this->sendRequest($payload);
    }

    /**
     * Recurring Update (Tekrarlı Satış Güncelleme)
     *
     * @param string $orderId
     * @param array $paymentList Array of ['PaymentNum' => X, 'Amount' => Y]
     * @return array
     * @throws GarantiPosException
     */
    public function recurringUpdate(string $orderId, array $paymentList): array
    {
        $securityData = HashGenerator::generateSecurityData($this->config['prov_password'], $this->config['terminal_id']);
        $hashData = HashGenerator::generateHashData($orderId, $this->config['terminal_id'], '', '1', $securityData);

        $payload = [
            'Mode' => $this->config['mode'],
            'Version' => 'v0.01',
            'Terminal' => [
                'ProvUserID' => $this->config['prov_user_id'],
                'HashData' => $hashData,
                'UserID' => $this->config['prov_user_id'],
                'ID' => $this->config['terminal_id'],
                'MerchantID' => $this->config['merchant_id'],
            ],
            'Order' => [
                'OrderID' => $orderId,
                'Recurring' => [
                    'PaymentList' => [] // Processed dynamically
                ]
            ],
            'Transaction' => [
                'Type' => 'recurringupdate',
                'Amount' => '1',
                'CurrencyCode' => $this->config['currency'],
            ]
        ];
        
        // Add multiple Payment nodes
        foreach ($paymentList as $idx => $payment) {
            $payload['Order']['Recurring']['PaymentList']["Payment_Item_{$idx}"] = [
                'PaymentNum' => $payment['PaymentNum'],
                'Amount' => $payment['Amount']
            ];
        }

        return $this->sendRequest($payload);
    }
}
