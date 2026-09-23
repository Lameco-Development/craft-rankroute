<?php

namespace lameco\rankroute\services\text;

/**
 * The deterministic rewrites the `rankroute/text-flow/smoke` command sends instead of an
 * LLM's: visible, reversible by eye, and always valid for the import.
 */
final class SmokeRewrite
{
    public const MARK = ' ✓';

    private const BLOCK_ELEMENTS = [
        'address', 'blockquote', 'caption', 'dd', 'div', 'dt', 'figcaption', 'h1', 'h2', 'h3',
        'h4', 'h5', 'h6', 'li', 'p', 'pre', 'td', 'th',
    ];

    /**
     * Plain: append the mark, shortening the value first when `maxLength` would be exceeded.
     */
    public static function plain(string $value, ?int $maxLength): string
    {
        if ($maxLength !== null && mb_strlen($value) + mb_strlen(self::MARK) > $maxLength) {
            $value = rtrim(mb_substr($value, 0, max(1, $maxLength - mb_strlen(self::MARK))));
        }

        return $value . self::MARK;
    }

    /**
     * HTML: append the mark to the last non-whitespace text node of each block element
     * (or of the whole value when it has no block elements). Tags are left byte for byte.
     */
    public static function html(string $html): string
    {
        $parts = preg_split('/(<!--.*?-->|<[^>]*>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return $html;
        }

        $lastText = null;
        $marked = false;

        foreach ($parts as $index => $part) {
            if ($part !== '' && $part[0] === '<') {
                if (preg_match('/^<\/([a-zA-Z0-9]+)/', $part, $match) && in_array(strtolower($match[1]), self::BLOCK_ELEMENTS, true)) {
                    if ($lastText !== null) {
                        $parts[$lastText] = rtrim($parts[$lastText]) . self::MARK . self::trailingWhitespace($parts[$lastText]);
                        $marked = true;
                    }
                    $lastText = null;
                }

                continue;
            }

            if (trim(html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\u{00A0}") !== '') {
                $lastText = $index;
            }
        }

        if (!$marked && $lastText !== null) {
            $parts[$lastText] = rtrim($parts[$lastText]) . self::MARK . self::trailingWhitespace($parts[$lastText]);
        }

        return implode('', $parts);
    }

    /**
     * The same HTML with the first `href` value changed, or null when there is none.
     */
    public static function withChangedHref(string $html): ?string
    {
        $changed = preg_replace('/(\shref\s*=\s*["\']?)/i', '$1https://rankroute.invalid/probe', $html, 1, $count);

        return $count > 0 ? $changed : null;
    }

    private static function trailingWhitespace(string $text): string
    {
        return substr($text, strlen(rtrim($text)));
    }
}
