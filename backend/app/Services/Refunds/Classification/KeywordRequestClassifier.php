<?php

namespace App\Services\Refunds\Classification;

use App\Enums\ReasonCategory;
use App\Models\Order;
use Illuminate\Support\Str;

/**
 * Deterministic fallback used when no API key is configured or the LLM is
 * unavailable. It keeps the product usable (degraded, not down) and is
 * deliberately cautious: low confidence routes ambiguous requests to humans.
 */
class KeywordRequestClassifier
{
    /** Order matters: first match wins. */
    private const KEYWORDS = [
        'not_received' => ['never arrived', 'never received', 'not received', "hasn't arrived", 'has not arrived', "didn't arrive", 'did not arrive', "didn't receive", 'did not receive', 'lost in transit', 'where is my', 'still waiting', 'not delivered', 'never got'],
        'wrong_item' => ['wrong item', 'wrong size', 'wrong colour', 'wrong color', 'incorrect item', 'different item', 'not what i ordered', 'sent me the wrong', 'received the wrong', 'wrong product', 'wrong model'],
        'damaged' => ['damaged', 'broken', 'crack', 'dead pixel', 'defective', 'faulty', 'shattered', 'torn', 'stopped working', "doesn't work", 'does not work', "won't turn on", 'dead on arrival', 'scratched', 'dented', 'leaking', 'ripped'],
        'not_as_described' => ['not as described', 'different from the picture', 'different from the photo', 'looks nothing like', 'misleading', 'not as advertised', 'poor quality', 'fake', 'counterfeit'],
        'changed_mind' => ['changed my mind', 'change of mind', "don't want", 'do not want', "don't need", 'no longer need', 'no longer want', "doesn't fit", 'does not fit', 'too small', 'too big', "didn't like", "don't like", 'found it cheaper', 'ordered by mistake', 'return it'],
    ];

    public function classify(string $message, Order $order): Classification
    {
        $text = Str::lower($message);
        $reason = ReasonCategory::Other;

        foreach (self::KEYWORDS as $category => $phrases) {
            if (Str::contains($text, $phrases)) {
                $reason = ReasonCategory::from($category);
                break;
            }
        }

        $skus = $order->items
            ->filter(fn ($item) => Str::contains($text, Str::lower($item->sku)) || $this->mentionsName($text, $item->name))
            ->pluck('sku')
            ->values()
            ->all();

        $claimed = preg_match('/\$\s?(\d{1,6}(?:[.,]\d{1,2})?)/', $message, $m) ? (float) str_replace(',', '.', $m[1]) : null;

        return new Classification(
            reason: $reason,
            referencedSkus: $skus,
            claimedAmount: $claimed,
            summary: Str::limit(preg_replace('/\s+/', ' ', $message), 180),
            sentiment: preg_match('/!{2,}|\b(unacceptable|ridiculous|furious|terrible|worst|scam)\b/i', $message) ? 'angry' : 'calm',
            confidence: $reason === ReasonCategory::Other ? 0.3 : 0.7,
            manipulationAttempt: false,   // covered by PromptInjectionDetector in the pipeline
            manipulationNotes: null,
            inconsistencies: [],
            source: 'fallback',
            model: 'keyword-rules-v1',
        );
    }

    /** Match on any distinctive (4+ letter) word of the product name. */
    private function mentionsName(string $text, string $name): bool
    {
        $words = array_filter(
            preg_split('/[^a-z0-9]+/', Str::lower($name)),
            fn ($w) => strlen($w) >= 4,
        );

        foreach ($words as $word) {
            if (preg_match('/\b'.preg_quote($word, '/').'s?\b/', $text)) {
                return true;
            }
        }

        return false;
    }
}
