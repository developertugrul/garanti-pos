<?php

namespace Developertugrul\GarantiPos\Tests;

use Developertugrul\GarantiPos\Services\XmlBuilder;
use PHPUnit\Framework\TestCase;

class XmlBuilderTest extends TestCase
{
    public function testBuildsRepeatedOfficialListNodes(): void
    {
        $xml = XmlBuilder::build([
            'Order' => [
                'AddressList' => [
                    ['Type' => 'S', 'Name' => 'Ada'],
                    ['Type' => 'B', 'Name' => 'Bora'],
                ],
            ],
            'Transaction' => [
                'CommercialCardExtendedCredit' => [
                    'PaymentList' => [
                        ['Number' => '1', 'Amount' => '1211'],
                        ['Number' => '2', 'Amount' => '1211'],
                    ],
                ],
            ],
        ]);

        $document = new \DOMDocument();
        $document->loadXML($xml);
        $xpath = new \DOMXPath($document);

        $this->assertSame(2, $xpath->query('/GVPSRequest/Order/AddressList/Address')->length);
        $this->assertSame(2, $xpath->query('/GVPSRequest/Transaction/CommercialCardExtendedCredit/PaymentList/Payment')->length);
    }

    public function testXmlValuesAreEscapedOnce(): void
    {
        $xml = XmlBuilder::build(['Order' => ['Description' => 'A&B']]);

        $this->assertStringContainsString('A&amp;B', $xml);
        $this->assertStringNotContainsString('&amp;amp;', $xml);
    }
}
