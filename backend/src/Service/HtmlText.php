<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Converts an operator HTML fragment to plain text: block tags become line breaks, list items
 * become bullets, entities are decoded, whitespace is collapsed and the result is capped.
 */
final class HtmlText
{
    private const int MAX_CHARS = 4000;

    public static function strip(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $text = (string) preg_replace('~<\s*br\s*/?\s*>~i', "\n", $html);
        $text = (string) preg_replace('~<\s*li[^>]*>~i', "\n- ", $text);
        $text = (string) preg_replace(
            '~<\s*/\s*(p|div|section|article|header|footer|h[1-6]|ul|ol|table|tr|blockquote|pre)\s*>~i',
            "\n",
            $text,
        );
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = (string) preg_replace('~[ \t]+~', ' ', $text);
        $text = (string) preg_replace('~ ?\n ?~', "\n", $text);
        $text = (string) preg_replace('~\n{3,}~', "\n\n", $text);
        $text = trim($text);
        if (mb_strlen($text) > self::MAX_CHARS) {
            $text = mb_substr($text, 0, self::MAX_CHARS);
        }

        return $text;
    }
}
