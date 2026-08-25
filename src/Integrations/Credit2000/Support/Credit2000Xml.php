<?php

declare(strict_types=1);

namespace Nezasa\Checkout\Integrations\Credit2000\Support;

final class Credit2000Xml
{
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param  array<string, string|null>  $fields
     */
    public static function elements(array $fields): string
    {
        $xml = '';

        foreach ($fields as $name => $value) {
            $xml .= '<'.$name.'>'.self::escape((string) ($value ?? '')).'</'.$name.'>';
        }

        return $xml;
    }

    /**
     * Extract the first occurrence of a SOAP/XML tag value.
     */
    public static function tagValue(string $xml, string $tag): ?string
    {
        $pattern = '/<(?:\w+:)?'.preg_quote($tag, '/').'(?:\s[^>]*)?>(.*?)<\/(?:\w+:)?'.preg_quote($tag, '/').'>/is';

        if (! preg_match($pattern, $xml, $matches)) {
            return null;
        }

        return html_entity_decode(trim($matches[1]), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param  list<string>  $tags
     * @return array<string, string>
     */
    public static function parseTaggedValues(string $xml, array $tags): array
    {
        $out = [];

        foreach ($tags as $tag) {
            $value = self::tagValue($xml, $tag);

            if ($value !== null) {
                $out[$tag] = $value;
            }
        }

        return $out;
    }

    /**
     * Extract a payment page URL from SendParamToCredit2000 response.
     */
    public static function extractPaymentUrl(string $body): ?string
    {
        $result = self::tagValue($body, 'SendParamToCredit2000Result')
            ?? self::tagValue($body, 'SendParamsToCredit2000Result');

        if ($result !== null) {
            $result = trim($result);

            if (preg_match('#https?://[^\s<"\']+#i', $result, $matches)) {
                return html_entity_decode($matches[0], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            }

            if (str_starts_with($result, 'http')) {
                return $result;
            }
        }

        if (preg_match_all('#https?://[^\s<"\']+#i', $body, $matches)) {
            foreach ($matches[0] as $url) {
                if (str_contains($url, 'schemas.xmlsoap.org')
                    || str_contains($url, 'tempuri.org')
                    || str_contains($url, 'w3.org')) {
                    continue;
                }

                return html_entity_decode($url, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            }
        }

        return null;
    }

    /**
     * Build a 16-digit product id from an arbitrary transaction id.
     */
    public static function productId(string $transactionId): string
    {
        $digits = preg_replace('/\D+/', '', $transactionId) ?: '';
        $mixed = $digits.sprintf('%u', crc32($transactionId));

        return str_pad(substr($mixed, -16), 16, '0', STR_PAD_LEFT);
    }

    /**
     * Credit2000 amounts are integers where the last two digits are agorot/cents.
     */
    public static function amountToMinorUnits(int $subunits): string
    {
        return (string) max(0, $subunits);
    }
}
