<?php

namespace Developertugrul\GarantiPos\Services;

class XmlBuilder
{
    private const LIST_ITEM_NAMES = [
        'AddressList' => 'Address',
        'ItemList' => 'Item',
        'CommentList' => 'Comment',
        'RewardList' => 'Reward',
        'ChequeList' => 'Cheque',
        'PaymentList' => 'Payment',
        'GPInstallments' => 'Installment',
        'BINList' => 'BIN',
        'TransactionSummList' => 'TransactionSumm',
        'SettlementInqList' => 'SettlementInq',
    ];

    /**
     * Build XML payload for Garanti API.
     *
     * @param array $data
     * @return string
     */
    public static function build(array $data): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;

        $root = $document->createElement('GVPSRequest');
        $document->appendChild($root);

        self::arrayToXml($document, $root, $data);

        return $document->saveXML();
    }

    /**
     * Convert array to XML recursively.
     *
     * @param array $data
     * @param \SimpleXMLElement $xml
     */
    private static function arrayToXml(\DOMDocument $document, \DOMElement $parent, array $data): void
    {
        foreach ($data as $key => $value) {
            $nodeName = preg_replace('/_Item_[0-9]+$/', '', (string)$key);
            $nodeName = is_numeric($nodeName) ? 'Item' : $nodeName;

            if (is_array($value)) {
                $node = $document->createElement($nodeName);
                $parent->appendChild($node);

                if (isset(self::LIST_ITEM_NAMES[$nodeName]) && self::isList($value)) {
                    foreach ($value as $item) {
                        $itemNode = $document->createElement(self::LIST_ITEM_NAMES[$nodeName]);
                        $node->appendChild($itemNode);

                        if (is_array($item)) {
                            self::arrayToXml($document, $itemNode, $item);
                        } else {
                            $itemNode->appendChild($document->createTextNode((string)$item));
                        }
                    }
                    continue;
                }

                self::arrayToXml($document, $node, $value);
            } else {
                $node = $document->createElement($nodeName);
                $node->appendChild($document->createTextNode((string)$value));
                $parent->appendChild($node);
            }
        }
    }

    private static function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }
}
