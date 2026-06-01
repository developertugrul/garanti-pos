<?php

namespace Developertugrul\GarantiPos\Services;

class XmlBuilder
{
    /**
     * Build XML payload for Garanti API.
     *
     * @param array $data
     * @return string
     */
    public static function build(array $data): string
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><GVPSRequest></GVPSRequest>');
        
        self::arrayToXml($data, $xml);
        
        return $xml->asXML();
    }

    /**
     * Convert array to XML recursively.
     *
     * @param array $data
     * @param \SimpleXMLElement $xml
     */
    private static function arrayToXml(array $data, \SimpleXMLElement $xml): void
    {
        foreach ($data as $key => $value) {
            // Support for arrays of identical node names like "Payment_Item_1" -> "Payment"
            $nodeName = preg_replace('/_Item_[0-9]+$/', '', (string)$key);

            if (is_array($value)) {
                if (is_numeric($nodeName)) {
                    $nodeName = 'item' . $nodeName;
                }
                $subnode = $xml->addChild($nodeName);
                self::arrayToXml($value, $subnode);
            } else {
                // To avoid issues with special characters
                $xml->addChild($nodeName, htmlspecialchars((string) $value));
            }
        }
    }
}
