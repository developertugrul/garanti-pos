<?php

namespace Developertugrul\GarantiPos\Services;

class HashGenerator
{
    /**
     * Generate Security Data for Garanti API.
     *
     * @param string $provPassword
     * @param string $terminalId
     * @return string
     */
    public static function generateSecurityData(string $provPassword, string $terminalId): string
    {
        // Terminal ID başına 0 eklenerek 9 digite tamamlanmalıdır.
        $terminalId_ = str_pad($terminalId, 9, '0', STR_PAD_LEFT);
        
        return strtoupper(sha1($provPassword . $terminalId_));
    }

    /**
     * Generate Hash Data for Garanti API (Non-3D).
     *
     * @param string $orderId
     * @param string $terminalId
     * @param string $cardNumber
     * @param string $amount
     * @param string $securityData
     * @return string
     */
    public static function generateHashData(string $orderId, string $terminalId, string $cardNumber, string $amount, string $securityData): string
    {
        return strtoupper(sha1($orderId . $terminalId . $cardNumber . $amount . $securityData));
    }

    /**
     * Generate Hash Data for 3D Secure Form.
     *
     * @param string $terminalId
     * @param string $orderId
     * @param string $amount
     * @param string $successUrl
     * @param string $errorUrl
     * @param string $type
     * @param string $installmentCnt
     * @param string $storeKey
     * @param string $securityData
     * @return string
     */
    public static function generate3DHash(
        string $terminalId,
        string $orderId,
        string $amount,
        string $successUrl,
        string $errorUrl,
        string $type,
        string $installmentCnt,
        string $storeKey,
        string $securityData
    ): string {
        $hashString = $terminalId . $orderId . $amount . $successUrl . $errorUrl . $type . $installmentCnt . $storeKey . $securityData;
        return strtoupper(sha1($hashString));
    }
}
