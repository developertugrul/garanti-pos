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
    public function pay(array $orderData, array $cardData): array
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
                'Type' => 'sales',
                'InstallmentCnt' => $orderData['installment'] ?? '',
                'Amount' => $orderData['amount'],
                'CurrencyCode' => $this->config['currency'],
                'CardholderPresentCode' => '0',
                'MotoInd' => 'N',
            ]
        ];

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
        return $this->pay($orderData, $cardData);
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
        $securityData = HashGenerator::generateSecurityData(
            $this->config['prov_password'],
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
                'Type' => $type,
                'Amount' => $amount,
                'CurrencyCode' => $this->config['currency'],
                'OriginalRetrefNum' => $originalRetrefNum,
            ]
        ];

        return $this->sendRequest($payload);
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

        $response = Http::asForm()
            ->withoutVerifying() // Because Garanti endpoints sometimes have SSL issues locally
            ->post($this->endpoint, [
                'data' => $xmlData
            ]);

        if ($response->failed()) {
            throw new GarantiPosException('Garanti POS API connection failed.');
        }

        return $this->parseXmlResponse($response->body());
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
        $json = json_encode($xml);
        return json_decode($json, true);
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
}
