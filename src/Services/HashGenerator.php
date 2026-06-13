<?php

namespace Developertugrul\GarantiPos\Services;

class HashGenerator
{
    public const HASH_ALGORITHM_LEGACY_SHA1 = 'sha1';
    public const HASH_ALGORITHM_SHA512 = 'sha512';

    /**
     * Normalize a GVP amount for hash calculations.
     */
    public static function normalizeAmount(string $amount): string
    {
        return str_replace(['.', ','], '', $amount);
    }

    /**
     * Normalize terminal ID to the 9 digit value used in SecurityData.
     */
    public static function normalizeTerminalIdForSecurity(string $terminalId): string
    {
        return str_pad($terminalId, 9, '0', STR_PAD_LEFT);
    }

    /**
     * Generate Security Data for Garanti API.
     *
     * @param string $provPassword
     * @param string $terminalId
     * @return string
     */
    public static function generateSecurityData(string $provPassword, string $terminalId): string
    {
        return strtoupper(sha1($provPassword . self::normalizeTerminalIdForSecurity($terminalId)));
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
    public static function generateHashData(
        string $orderId,
        string $terminalId,
        string $cardNumber,
        string $amount,
        string $securityData,
        string $algorithm = self::HASH_ALGORITHM_LEGACY_SHA1,
        string $currencyCode = ''
    ): string
    {
        $amount = self::normalizeAmount($amount);

        if (self::normalizeHashAlgorithm($algorithm) === self::HASH_ALGORITHM_SHA512) {
            return strtoupper(hash('sha512', $orderId . $terminalId . $cardNumber . $amount . $currencyCode . $securityData));
        }

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
        string $securityData,
        string $algorithm = self::HASH_ALGORITHM_LEGACY_SHA1,
        string $currencyCode = ''
    ): string {
        $amount     = self::normalizeAmount($amount);

        if (self::normalizeHashAlgorithm($algorithm) === self::HASH_ALGORITHM_SHA512) {
            return strtoupper(hash('sha512', $terminalId . $orderId . $amount . $currencyCode . $successUrl . $errorUrl . $type . $installmentCnt . $storeKey . $securityData));
        }

        return strtoupper(sha1($terminalId . $orderId . $amount . $successUrl . $errorUrl . $type . $installmentCnt . $storeKey . $securityData));
    }

    /**
     * Validate the secure3dhash value returned by 3D Model style callbacks.
     */
    public static function validateSecure3DHash(
        array $postData,
        string $storeKey,
        string $provPassword,
        string $terminalId,
        string $algorithm = 'auto'
    ): bool {
        $data = array_change_key_case($postData, CASE_LOWER);
        $returnedHash = $data['secure3dhash'] ?? '';

        if ($returnedHash === '') {
            return false;
        }

        $callbackTerminalId = (string)($data['clientid'] ?? $data['terminalid'] ?? $terminalId);
        $securityData = self::generateSecurityData($provPassword, $callbackTerminalId);
        $currencyCode = (string)($data['txncurrencycode'] ?? $data['currencycode'] ?? '');
        $algorithms = self::candidateAlgorithms($algorithm, $returnedHash);

        foreach ($algorithms as $candidate) {
            $calculatedHash = self::generate3DHash(
                $callbackTerminalId,
                (string)($data['orderid'] ?? $data['oid'] ?? ''),
                (string)($data['txnamount'] ?? ''),
                (string)($data['successurl'] ?? ''),
                (string)($data['errorurl'] ?? ''),
                (string)($data['txntype'] ?? ''),
                (string)($data['txninstallmentcount'] ?? ''),
                $storeKey,
                $securityData,
                $candidate,
                $currencyCode
            );

            if (hash_equals(strtoupper($returnedHash), $calculatedHash)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate 3D Hash from Garanti 3D Callback
     *
     * @param array $postData The $_POST array from Garanti Callback
     * @param string $storeKey
     * @return bool
     */
    public static function validate3DHash(array $postData, string $storeKey, string $algorithm = 'auto'): bool
    {
        $responseHashparams = $postData['hashparams'] ?? '';
        // Base64 contains '+' chars; application/x-www-form-urlencoded decodes '+' as space.
        // Restore them so the comparison is not broken by Laravel/$_POST decoding.
        $responseHash = str_replace(' ', '+', $postData['hash'] ?? '');

        if (empty($responseHashparams) || empty($responseHash)) {
            return false;
        }

        $digestData    = "";
        $postDataLower = array_change_key_case($postData, CASE_LOWER);
        $paramList     = explode(":", $responseHashparams);

        foreach ($paramList as $param) {
            $digestData .= $postDataLower[strtolower($param)] ?? '';
        }

        $digestData .= $storeKey;
        $algorithms = self::candidateAlgorithms($algorithm, $responseHash);
        foreach ($algorithms as $candidate) {
            if ($candidate === self::HASH_ALGORITHM_SHA512) {
                $hashCalculated = strtoupper(hash('sha512', $digestData));
                if (hash_equals(strtoupper($responseHash), $hashCalculated)) {
                    return true;
                }
                continue;
            }

            $hashCalculated = base64_encode(pack('H*', sha1($digestData)));
            if (hash_equals($responseHash, $hashCalculated)) {
                return true;
            }
        }

        return false;
    }

    public static function normalizeHashAlgorithm(string $algorithm): string
    {
        $algorithm = strtolower(trim($algorithm));

        return in_array($algorithm, [self::HASH_ALGORITHM_SHA512, '512'], true)
            ? self::HASH_ALGORITHM_SHA512
            : self::HASH_ALGORITHM_LEGACY_SHA1;
    }

    private static function candidateAlgorithms(string $algorithm, string $returnedHash): array
    {
        $algorithm = strtolower(trim($algorithm));

        if ($algorithm !== 'auto') {
            return [self::normalizeHashAlgorithm($algorithm)];
        }

        if (preg_match('/^[a-f0-9]{128}$/i', $returnedHash) === 1) {
            return [self::HASH_ALGORITHM_SHA512, self::HASH_ALGORITHM_LEGACY_SHA1];
        }

        return [self::HASH_ALGORITHM_LEGACY_SHA1, self::HASH_ALGORITHM_SHA512];
    }
}
