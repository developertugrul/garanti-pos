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
            if (is_array($value)) {
                if (is_numeric($key)) {
                    $key = 'item' . $key;
                }
                $subnode = $xml->addChild($key);
                self::arrayToXml($value, $subnode);
            } else {
                // To avoid issues with special characters
                $xml->addChild($key, htmlspecialchars((string) $value));
            }
        }
    }
}
