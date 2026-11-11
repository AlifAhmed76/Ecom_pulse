<?php

namespace App\Services\Chat;

use App\Models\KnowledgeDocument;

class Retriever
{
    /**
     * Stopwords skipped so common grammar fillers never create false-positive matches.
     *
     * @var list<string>
     */
    private const STOPWORDS = [
        'the', 'is', 'are', 'was', 'were', 'am', 'be', 'been', 'a', 'an', 'and', 'or', 'of', 'in', 'on', 'at', 'to', 'for', 'with',
        'from', 'by', 'as', 'it', 'this', 'that', 'these', 'those', 'my', 'your', 'our', 'their', 'me', 'you', 'we', 'they',
        'how', 'what', 'when', 'where', 'which', 'who', 'why', 'do', 'does', 'did', 'can', 'could', 'will', 'would', 'please',
        'pls', 'plz', 'sir', 'apu', 'vai', 'bhai', 'hi', 'hello', 'hey', 'assalamualaikum', 'salam',
        'ki', 'koto', 'kobe', 'ache', 'ase', 'naki', 'kore', 'hoy', 'hobe', 'amar', 'apnar', 'apnader', 'ei', 'ta', 'ti', 'te', 'er',
        'ami', 'tumi', 'amra', 'apni', 'kivabe', 'kemne', 'keno', 'chai', 'lagbe', 'bolen', 'janan', 'debo', 'nibo', 'dite', 'nite',
        'korte', 'hoye', 'parbo', 'pabo', 'jay', 'jabe', 'niye', 'diye', 'eita', 'ota', 'seta',
        'কেমন', 'কি', 'কী', 'কত', 'কবে', 'আছে', 'হয়', 'হবে', 'আমার', 'আপনার', 'আপনাদের', 'এই', 'এবং', 'আর', 'জন্য',
        'কিভাবে', 'কেমনে', 'কেন', 'চাই', 'লাগবে', 'বলেন', 'জানান', 'দেবো', 'নিবো', 'দিতে', 'নিতে', 'করতে', 'পাবো', 'যায়', 'যাবে', 'নিয়ে', 'দিয়ে', 'দেয়ার', 'নিয়ম',
    ];

    /**
     * Multilingual Bangla / Banglish / English synonym concepts for Bangladesh COD commerce.
     *
     * @var array<string, list<string>>
     */
    private const CONCEPT_SYNONYMS = [
        'delivery' => ['delivery', 'deliver', 'shipping', 'ship', 'courier', 'dispatch', 'pathao', 'steadfast', 'redx', 'dhaka', 'outside', 'parcel', 'kobe', 'ashbe', 'asbe', 'chole', 'time', 'days', 'দিন', 'ডেলিভারি', 'কুরিয়ার', 'পাঠানো', 'সময়', 'ঢাকা', 'বাইরে', 'পার্সেল'],
        'charge' => ['charge', 'fee', 'cost', 'price', 'rate', 'taka', 'tk', 'bdt', 'চার্জ', 'খরচ', 'ফি', 'দাম', 'টাকা'],
        'return' => ['return', 'refund', 'exchange', 'replace', 'replacement', 'back', 'ferot', 'pherot', 'badal', 'রিটার্ন', 'ফেরত', 'বদল', 'এক্সচেঞ্জ', 'পাল্টানো'],
        'payment' => ['cod', 'cash', 'payment', 'pay', 'bkash', 'nagad', 'rocket', 'card', 'prepaid', 'advance', 'পেমেন্ট', 'ক্যাশ', 'বিকাশ', 'নগদ', 'অগ্রিম'],
        'warranty' => ['warranty', 'guarantee', 'authentic', 'original', 'genuine', 'ওয়ারেন্টি', 'গ্যারান্টি', 'আসল', 'অরিজিনাল'],
        'size' => ['size', 'chart', 'measurement', 'fit', 'chest', 'length', 'সাইজ', 'মাপ'],
        'cancel' => ['cancel', 'cancellation', 'batil', 'বাতিল'],
        'pickup' => ['pickup', 'shop', 'store', 'branch', 'showroom', 'address', 'location', 'ঠিকানা', 'শোরুম', 'দোকান'],
    ];

    /**
     * Retrieve the best grounded snippet STRICTLY scoped to ($tenantId, $chatbotId).
     *
     * @return ?array{document: KnowledgeDocument, snippet: string, score: float, concepts: list<string>}
     */
    public function search(int $tenantId, int $chatbotId, string $question): ?array
    {
        $tokens = $this->tokenize($question);
        $concepts = $this->conceptsFor($tokens, $question);

        if ($tokens === [] && $concepts === []) {
            return null;
        }

        // Strict tenant + chatbot scoping: impossible to touch any other tenant's KB documents
        $docs = KnowledgeDocument::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('chatbot_id', $chatbotId)
            ->get();

        if ($docs->isEmpty()) {
            return null;
        }

        $best = null;
        $bestScore = 0.0;

        foreach ($docs as $doc) {
            $titleTokens = $this->tokenize((string) $doc->title);
            $bodyText = (string) $doc->body;
            $bodyTokens = $this->tokenize($bodyText);
            $docConcepts = $this->conceptsFor(array_merge($titleTokens, $bodyTokens), $doc->title.' '.$bodyText);

            $exactHits = 0;
            foreach ($tokens as $qt) {
                $inTitle = in_array($qt, $titleTokens, true);
                $inBody = in_array($qt, $bodyTokens, true);
                if ($inTitle) {
                    $exactHits += 2;
                } elseif ($inBody) {
                    $exactHits += 1;
                }
            }

            $conceptOverlap = array_values(array_intersect($concepts, $docConcepts));

            // Compute weighted relevance score
            $score = ($exactHits * 1.5) + (count($conceptOverlap) * 1.25);

            // Require real lexical/concept relevance so off-topic questions trigger handoff instead of hallucination
            $singleTopicalConcept = count($conceptOverlap) === 1
                && $conceptOverlap[0] !== 'charge'
                && count($tokens) <= 4;
            if ($exactHits === 0 && count($conceptOverlap) < 2 && ! $singleTopicalConcept) {
                continue;
            }
            if ($score < 1.25) {
                continue;
            }

            $snippet = $this->extractRelevantSnippet($bodyText, $tokens, $concepts);
            if ($snippet === '') {
                continue;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [
                    'document' => $doc,
                    'snippet' => $snippet,
                    'score' => round($score, 2),
                    'concepts' => $conceptOverlap,
                ];
            }
        }

        return $best;
    }

    /**
     * Unicode-safe tokenizer that preserves Bangla combining marks (\p{M}) cleanly.
     *
     * @return list<string>
     */
    public function tokenize(string $text): array
    {
        $lower = mb_strtolower($text, 'UTF-8');
        $parts = preg_split('/[^\p{L}\p{M}\p{N}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($parts)) {
            return [];
        }

        $tokens = [];
        foreach ($parts as $raw) {
            if (mb_strlen($raw, 'UTF-8') < 2) {
                continue;
            }
            if (in_array($raw, self::STOPWORDS, true)) {
                continue;
            }
            $tokens[] = $raw;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function conceptsFor(array $tokens, string $rawText): array
    {
        $lower = mb_strtolower($rawText, 'UTF-8');
        $matched = [];

        foreach (self::CONCEPT_SYNONYMS as $concept => $words) {
            foreach ($words as $w) {
                if (in_array($w, $tokens, true) || str_contains($lower, $w)) {
                    $matched[] = $concept;
                    break;
                }
            }
        }

        return array_values(array_unique($matched));
    }

    /**
     * Extract only the matching sentence/paragraph block from the KB document body.
     *
     * @param  list<string>  $tokens
     * @param  list<string>  $concepts
     */
    private function extractRelevantSnippet(string $body, array $tokens, array $concepts): string
    {
        $chunks = preg_split('/\r?\n+|\.(?:\s+|$)|।(?:\s+|$)/u', trim($body), -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($chunks) || $chunks === []) {
            return mb_substr(trim($body), 0, 500, 'UTF-8');
        }

        $scored = [];
        foreach ($chunks as $idx => $chunk) {
            $trimmed = trim($chunk);
            if ($trimmed === '') {
                continue;
            }
            $cTokens = $this->tokenize($trimmed);
            $cConcepts = $this->conceptsFor($cTokens, $trimmed);
            $hits = count(array_intersect($tokens, $cTokens)) * 2 + count(array_intersect($concepts, $cConcepts));

            $scored[] = [
                'idx' => $idx,
                'text' => $trimmed,
                'hits' => $hits,
            ];
        }

        usort($scored, static fn (array $a, array $b): int => $b['hits'] <=> $a['hits'] ?: $a['idx'] <=> $b['idx']);

        $selected = array_filter($scored, static fn (array $item): bool => $item['hits'] > 0);
        if ($selected === []) {
            $selected = array_slice($scored, 0, 2);
        } else {
            $selected = array_slice(array_values($selected), 0, 3);
        }

        usort($selected, static fn (array $a, array $b): int => $a['idx'] <=> $b['idx']);

        $joined = implode(' ', array_map(static fn (array $s): string => $s['text'], $selected));

        return mb_substr(trim($joined), 0, 700, 'UTF-8');
    }
}
