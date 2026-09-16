<?php

namespace lameco\rankroute\services\text;

/**
 * The tag skeleton of an HTML string: every opening, closing and void tag in document
 * order, with its name and attributes, plus every piece of markup a browser does not show
 * as text (comments, raw-text element content), and none of the visible text.
 *
 * Two HTML strings with an equal skeleton differ only in their visible text, which is the
 * one thing the text flow allows a client to change. A DOM-free tokenizer on purpose: a
 * DOM parser repairs broken markup, and a repair would hide exactly the change we need to
 * see. Where it matters for smuggling markup, the tokenizer follows the WHATWG tokenizer.
 *
 * Normalisation, so equivalent spellings of the same tag compare equal:
 * - tag and attribute names are lowercased;
 * - attribute values are entity-decoded (`&amp;` equals `&`), attributes are sorted by
 *   name (order carries no meaning in HTML), a duplicate attribute keeps its first value;
 * - void elements (`<br>`, `<br/>`, `<br />`) are one token, a self-closed non-void
 *   element (`<span/>`) is an opening plus a closing token.
 *
 * Compared exactly, never normalised (review rules R4, R5):
 * - comments, including their content, ended the way browsers end them (`<!-->`,
 *   `<!--->`, `-->`, `--!>`); CDATA sections, doctypes and other bogus comments
 *   (`<!…>`, `<?…>`, `</ …>`), which end at the first `>`;
 * - the content of raw-text elements (`script`, `style`, `textarea`, `title`, `xmp`,
 *   `iframe`, `noembed`, `noframes`, `noscript`).
 *
 * Craft reference tags in attribute values (`{entry:6@1:url||https://…}`) are compared
 * exactly by default (R3). Only in relaxed mode, used for the canonical-vs-draft structure
 * snapshot, is the fallback URL after `||` dropped, because Craft rewrites it on save.
 */
final class HtmlSkeleton
{
    private const VOID_ELEMENTS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param',
        'source', 'track', 'wbr',
    ];

    private const RAW_TEXT_ELEMENTS = [
        'script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript',
    ];

    /**
     * @param bool $relaxedReferenceTags Drop the fallback URL of Craft reference tags
     *     (structure snapshot only; import validation is strict)
     * @return list<string> Tokens, e.g. `<a href="/x">`, `</a>`, `<br>`, `<!-- note -->`.
     */
    public static function tokens(string $html, bool $relaxedReferenceTags = false): array
    {
        $tokens = [];
        $length = strlen($html);
        $offset = 0;

        while ($offset < $length && ($lt = strpos($html, '<', $offset)) !== false) {
            if (substr_compare($html, '<!--', $lt, 4) === 0) {
                $end = self::commentEnd($html, $lt + 4);
                $tokens[] = substr($html, $lt, $end - $lt);
                $offset = $end;
                continue;
            }

            if (preg_match('/\G<\/([a-zA-Z][^\s\/>]*)/', $html, $match, 0, $lt)) {
                // End tag; browsers ignore attributes on it, but they still delimit it.
                [, , $offset] = self::parseAttributes($html, $lt + strlen($match[0]), false);
                $tokens[] = '</' . strtolower($match[1]) . '>';
                continue;
            }

            if (preg_match('/\G<([a-zA-Z][^\s\/>]*)/', $html, $match, 0, $lt)) {
                $name = strtolower($match[1]);
                [$attributes, $selfClosing, $offset] = self::parseAttributes($html, $lt + strlen($match[0]), $relaxedReferenceTags);
                $tokens[] = self::formatTag($name, $attributes);

                if ($selfClosing && !in_array($name, self::VOID_ELEMENTS, true)) {
                    $tokens[] = '</' . $name . '>';
                } elseif (!$selfClosing && in_array($name, self::RAW_TEXT_ELEMENTS, true)) {
                    $close = preg_match('/<\/' . preg_quote($name, '/') . '[\t\n\f\r \/>]/i', $html, $closeMatch, PREG_OFFSET_CAPTURE, $offset)
                        ? $closeMatch[0][1]
                        : $length;
                    $tokens[] = '#raw:' . substr($html, $offset, $close - $offset);
                    $offset = $close;
                }

                continue;
            }

            if (preg_match('/\G(?:<[!?]|<\/[^a-zA-Z])/', $html, $match, 0, $lt)) {
                // CDATA, doctype, processing instruction, `</>` or `</ …>`: a bogus comment
                // up to the first `>`.
                $gt = strpos($html, '>', $lt + 2);
                $end = $gt === false ? $length : $gt + 1;
                $tokens[] = substr($html, $lt, $end - $lt);
                $offset = $end;
                continue;
            }

            // A literal "<" in text ("a < b").
            $offset = $lt + 1;
        }

        return $tokens;
    }

    /**
     * Whether two HTML strings have the same skeleton.
     */
    public static function equals(string $a, string $b, bool $relaxedReferenceTags = false): bool
    {
        return self::tokens($a, $relaxedReferenceTags) === self::tokens($b, $relaxedReferenceTags);
    }

    /**
     * Whether a plain value contains anything that could start markup: `<` directly
     * followed by a letter, `/`, `!` or `?` (review rule R6; no closing `>` needed).
     */
    public static function containsMarkup(string $value): bool
    {
        return preg_match('/<[a-zA-Z\/!?]/', $value) === 1;
    }

    /**
     * The visible text of an HTML string: tags removed, entities decoded, non-breaking
     * spaces as spaces.
     */
    public static function textContent(string $html): string
    {
        $withoutComments = preg_replace('/<!--.*?(-->|$)/s', '', $html) ?? $html;
        $text = html_entity_decode(strip_tags($withoutComments), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return str_replace("\u{00A0}", ' ', $text);
    }

    /**
     * `{entry:6@1:url||https://…}` → `{entry:6@1:url}`. The part after `||` is the fallback
     * URL Craft writes into a reference tag each time the field is saved, resolved against
     * whatever base URL that request had; the reference itself is what renders.
     */
    public static function normaliseReferenceTags(string $value): string
    {
        return preg_replace('/\{([a-zA-Z0-9_\\\\]+:[^{}|]+)\|\|[^{}]*\}/', '{$1}', $value) ?? $value;
    }

    /**
     * Offset just after the end of a comment whose `<!--` ends right before `$start`,
     * per the WHATWG comment states: `<!-->` and `<!--->` are complete comments, otherwise
     * the first `-->` or `--!>` ends it, or the end of input.
     */
    private static function commentEnd(string $html, int $start): int
    {
        if (substr_compare($html, '>', $start, 1) === 0) {
            return $start + 1;
        }

        if (substr_compare($html, '->', $start, 2) === 0) {
            return $start + 2;
        }

        $ends = [];

        if (($close = strpos($html, '-->', $start)) !== false) {
            $ends[] = $close + 3;
        }

        if (($bang = strpos($html, '--!>', $start)) !== false) {
            $ends[] = $bang + 4;
        }

        return $ends === [] ? strlen($html) : min($ends);
    }

    /**
     * @return array{0: array<string, string>, 1: bool, 2: int} attributes, self-closing, offset after `>`
     */
    private static function parseAttributes(string $html, int $offset, bool $relaxedReferenceTags): array
    {
        $attributes = [];
        $length = strlen($html);

        while ($offset < $length) {
            if (preg_match('/\G\s*(\/?)\s*>/', $html, $match, 0, $offset)) {
                return [$attributes, $match[1] === '/', $offset + strlen($match[0])];
            }

            if (preg_match('/\G\s*([^\s"\'>\/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/', $html, $match, 0, $offset)) {
                $name = strtolower($match[1]);
                $value = $match[2] ?? '';
                if (($match[3] ?? '') !== '') {
                    $value = $match[3];
                } elseif (($match[4] ?? '') !== '') {
                    $value = $match[4];
                }

                if (!array_key_exists($name, $attributes)) {
                    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $attributes[$name] = $relaxedReferenceTags ? self::normaliseReferenceTags($value) : $value;
                }

                $offset += strlen($match[0]);
                continue;
            }

            // A stray "/" or quote inside the tag: skip it.
            ++$offset;
        }

        return [$attributes, false, $length];
    }

    /**
     * @param array<string, string> $attributes
     */
    private static function formatTag(string $name, array $attributes): string
    {
        ksort($attributes, SORT_STRING);
        $parts = [$name];

        foreach ($attributes as $attribute => $value) {
            $parts[] = $attribute . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }

        return '<' . implode(' ', $parts) . '>';
    }
}
