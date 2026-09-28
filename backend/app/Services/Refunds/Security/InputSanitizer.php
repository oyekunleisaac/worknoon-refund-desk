<?php

namespace App\Services\Refunds\Security;

use Normalizer;

/**
 * Normalises untrusted customer text before it is stored, scanned or shown to
 * a model. Unicode tricks (zero-width characters, homoglyph compatibility
 * forms, bidi overrides) are a common way to smuggle instructions past
 * pattern-based filters, so we collapse them first.
 */
class InputSanitizer
{
    public function clean(string $text): string
    {
        if (class_exists(Normalizer::class)) {
            $text = Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text;
        }

        // Zero-width + bidi control characters.
        $text = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u', '', $text) ?? $text;

        // ASCII control characters except newline and tab.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;

        // Collapse runs of blank lines / spaces.
        $text = preg_replace("/[ \t]{2,}/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Neutralise markup so customer text cannot close or forge the XML-style
     * delimiters used in model prompts.
     */
    public function forPrompt(string $text): string
    {
        return str_replace(['<', '>'], ['‹', '›'], $text);
    }
}
