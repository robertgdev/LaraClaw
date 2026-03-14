<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\DTOs\IntentClassificationDTO;
use function Safe\preg_match;
use function Safe\preg_split;
use function Safe\preg_replace;

/**
 * Detects skill gaps by analysing user intent and message content.
 *
 * Determines whether a message likely requires a skill that isn't
 * currently installed, and extracts search terms for skill discovery.
 */
class SkillGapDetector
{
    /**
     * Action-oriented intents that suggest a skill might be needed.
     */
    protected const ACTION_INTENTS = [
        'automation',
        'create',
        'execute',
        'generate',
        'browse',
    ];

    /**
     * Action verbs that suggest a skill might be needed.
     */
    protected const ACTION_VERBS = [
        'generate',
        'create',
        'browse',
        'open',
        'schedule',
        'send',
        'automate',
        'edit',
        'convert',
        'parse',
        'extract',
        'download',
        'upload',
    ];

    protected float $gapDetectionThreshold;

    public function __construct(float $gapDetectionThreshold = 0.5)
    {
        $this->gapDetectionThreshold = $gapDetectionThreshold;
    }

    /**
     * Determine if the message likely requires a skill.
     */
    public function isSkillRequired(
        string $message,
        IntentClassificationDTO $classification
    ): bool {
        // Already has a good skill match
        if ($classification->matchedSkill && $classification->skillConfidence >= $this->gapDetectionThreshold) {
            return false;
        }

        // Check for action-oriented intents
        if (in_array($classification->intent, self::ACTION_INTENTS)) {
            return true;
        }

        // Check for action verbs in message
        $lowerMessage = strtolower($message);
        foreach (self::ACTION_VERBS as $verb) {
            if (str_contains($lowerMessage, $verb)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract a search term from the message.
     */
    public function extractSearchTerm(
        string $message,
        IntentClassificationDTO $classification
    ): string {
        // Use keywords from classification if available
        if (! empty($classification->keywords)) {
            $keywords = array_slice($classification->keywords, 0, 3);

            return implode(' ', $keywords);
        }

        // Try to extract from common patterns
        $patterns = [
            '/generate\s+(?:an?\s+)?(\w+)/i',
            '/create\s+(?:a\s+)?(\w+)/i',
            '/browse\s+(?:the\s+)?(\w+)/i',
            '/open\s+(?:the\s+)?(\w+)/i',
            '/schedule\s+(?:a\s+)?(\w+)/i',
            '/edit\s+(?:the\s+)?(\w+)/i',
            '/convert\s+(?:a\s+)?(\w+)/i',
            '/parse\s+(?:the\s+)?(\w+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches)) {
                return $matches[1];
            }
        }

        // Fallback: use intent as search term
        if (! empty($classification->intent) && $classification->intent !== 'unknown') {
            return $classification->intent;
        }

        // Last resort: extract nouns from message
        return $this->extractNouns($message);
    }

    /**
     * Extract potential nouns from a message as a fallback search term.
     */
    public function extractNouns(string $message): string
    {
        $stopWords = ['that', 'this', 'with', 'from', 'have', 'will', 'would', 'could', 'should', 'about', 'please', 'want', 'need', 'like'];

        $words = preg_split('/\s+/', strtolower($message));
        $nouns = [];

        foreach ($words as $word) {
            $word = preg_replace('/[^a-z]/', '', $word);
            if (strlen($word) >= 4 && ! in_array($word, $stopWords)) {
                $nouns[] = $word;
            }
        }

        return implode(' ', array_slice($nouns, 0, 2));
    }
}
