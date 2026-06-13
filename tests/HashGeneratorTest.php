<?php

namespace Developertugrul\GarantiPos\Tests;

use Developertugrul\GarantiPos\Services\HashGenerator;
use PHPUnit\Framework\TestCase;

class HashGeneratorTest extends TestCase
{
    public function testSecurityDataPadsTerminalIdToNineDigits(): void
    {
        $this->assertSame(
            'A035C7247219210EEAA7117A476175AE8D96426E',
            HashGenerator::generateSecurityData('123qweASD/', '30690133')
        );
    }

    public function testGarantiPayHashUsesGpdatarequestTransactionType(): void
    {
        $securityData = HashGenerator::generateSecurityData('123qweASD/', '30690133');

        $this->assertSame(
            'E5EA0479BBCB68C6429BD6DCD572F5222E35D89A',
            HashGenerator::generate3DHash(
                '30690133',
                'a123',
                '100',
                'https://ok',
                'https://err',
                'gpdatarequest',
                '',
                '12345678',
                $securityData
            )
        );

        $this->assertNotSame(
            HashGenerator::generate3DHash('30690133', 'a123', '100', 'https://ok', 'https://err', 'sales', '', '12345678', $securityData),
            HashGenerator::generate3DHash('30690133', 'a123', '100', 'https://ok', 'https://err', 'gpdatarequest', '', '12345678', $securityData)
        );
    }

    public function testSha512XmlHashMatchesCurrentGarantiPortalExample(): void
    {
        $securityData = HashGenerator::generateSecurityData('123qweASD/', '30691297');

        $this->assertSame(
            'E88EA8FBFAECA0516911851A22D3F06C3D7CB64B830B20D1B4DEB7F4C0CCD4773AA9F872F28124F843EBB06B41D0CA137F5C61775A043CEBDA985A8E85BA0DEF',
            HashGenerator::generateHashData(
                'da4009cff27645978084d04c7accaf46',
                '30691297',
                '5406697543211173',
                '10000',
                $securityData,
                'sha512',
                '949'
            )
        );
    }

    public function testCallbackHashparamsValidation(): void
    {
        $storeKey = 'store-key';
        $postData = [
            'clientid' => '30690133',
            'oid' => 'ORDER-1',
            'authcode' => '123456',
            'procreturncode' => '00',
            'response' => 'Approved',
            'mdstatus' => '1',
            'cavv' => 'CAVV',
            'eci' => '05',
            'md' => 'MD',
            'rnd' => 'RND',
            'hashparams' => 'clientid:oid:authcode:procreturncode:response:mdstatus:cavv:eci:md:rnd:',
        ];

        $digest = '30690133ORDER-112345600Approved1CAVV05MDRND' . $storeKey;
        $postData['hash'] = base64_encode(pack('H*', sha1($digest)));

        $this->assertTrue(HashGenerator::validate3DHash($postData, $storeKey));
    }

    public function testSha512CallbackHashparamsValidation(): void
    {
        $storeKey = 'store-key';
        $postData = [
            'clientid' => '30691297',
            'oid' => 'ORDER-1',
            'procreturncode' => '00',
            'response' => 'Approved',
            'rnd' => 'RND',
            'hashparams' => 'clientid:oid:procreturncode:response:rnd:',
        ];
        $postData['hash'] = strtoupper(hash('sha512', '30691297ORDER-100ApprovedRND' . $storeKey));

        $this->assertTrue(HashGenerator::validate3DHash($postData, $storeKey, 'auto'));
    }

    public function testSha512Secure3DHashValidation(): void
    {
        $storeKey = '12345678';
        $securityData = HashGenerator::generateSecurityData('123qweASD/', '30691297');
        $postData = [
            'clientid' => '30691297',
            'orderid' => 'SHA512-CB',
            'txnamount' => '10000',
            'txncurrencycode' => '949',
            'successurl' => 'https://ok',
            'errorurl' => 'https://err',
            'txntype' => 'sales',
            'txninstallmentcount' => '',
        ];
        $postData['secure3dhash'] = HashGenerator::generate3DHash(
            '30691297',
            'SHA512-CB',
            '10000',
            'https://ok',
            'https://err',
            'sales',
            '',
            $storeKey,
            $securityData,
            'sha512',
            '949'
        );

        $this->assertTrue(HashGenerator::validateSecure3DHash($postData, $storeKey, '123qweASD/', '30691297', 'auto'));
    }
}
