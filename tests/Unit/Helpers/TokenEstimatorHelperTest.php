<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use App\Helpers\TokenEstimatorHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * @covers \App\Helpers\TokenEstimatorHelper
 */
class TokenEstimatorHelperTest extends TestCase
{
    /**
     * Test empty string returns zero tokens.
     */
    public function test_empty_string_returns_zero(): void
    {
        $this->assertSame(0, TokenEstimatorHelper::estimate(''));
        $this->assertSame(0, TokenEstimatorHelper::estimate('   '));
        $this->assertSame(0, TokenEstimatorHelper::estimate("\n\t"));
    }

    /**
     * Test word token estimation.
     *
     * Words typically tokenize to ~1 token per 4 characters.
     */
    public function test_word_token_estimation(): void
    {
        // Short words (1-4 chars) = 1 token each
        $this->assertSame(1, TokenEstimatorHelper::estimate('cat'));
        $this->assertSame(1, TokenEstimatorHelper::estimate('dog'));
        $this->assertSame(1, TokenEstimatorHelper::estimate('test'));

        // Medium words (5-8 chars) = 2 tokens each
        $this->assertSame(2, TokenEstimatorHelper::estimate('hello'));
        $this->assertSame(2, TokenEstimatorHelper::estimate('worlds'));

        // Longer words
        $this->assertSame(3, TokenEstimatorHelper::estimate('beautiful'));
        $this->assertSame(4, TokenEstimatorHelper::estimate('extraordinary'));
    }

    /**
     * Test number token estimation.
     *
     * Numbers typically tokenize smaller (~1 token per 2 digits).
     */
    public function test_number_token_estimation(): void
    {
        // Single digit = 1 token
        $this->assertSame(1, TokenEstimatorHelper::estimate('5'));

        // Two digits = 1 token
        $this->assertSame(1, TokenEstimatorHelper::estimate('42'));

        // Three digits = 2 tokens
        $this->assertSame(2, TokenEstimatorHelper::estimate('123'));

        // Four digits = 2 tokens
        $this->assertSame(2, TokenEstimatorHelper::estimate('1234'));

        // Six digits = 3 tokens
        $this->assertSame(3, TokenEstimatorHelper::estimate('123456'));
    }

    /**
     * Test punctuation token estimation.
     *
     * Each punctuation mark = 1 token.
     */
    public function test_punctuation_token_estimation(): void
    {
        $this->assertSame(1, TokenEstimatorHelper::estimate('.'));
        $this->assertSame(1, TokenEstimatorHelper::estimate('!'));
        $this->assertSame(1, TokenEstimatorHelper::estimate('?'));
        $this->assertSame(3, TokenEstimatorHelper::estimate('...'));
        $this->assertSame(5, TokenEstimatorHelper::estimate('!@#$%'));
    }

    /**
     * Test mixed content token estimation.
     */
    public function test_mixed_content_estimation(): void
    {
        // "Hello" (2 tokens) + " " (1 token) + "world" (2 tokens) + "!" (1 token)
        // Note: spaces are not matched by the regex, so they don't add tokens
        $text = 'Hello world!';
        $tokens = TokenEstimatorHelper::estimate($text);
        $this->assertGreaterThan(0, $tokens);
        $this->assertLessThan(20, $tokens); // Reasonable upper bound
    }

    /**
     * Test sentence token estimation.
     */
    public function test_sentence_estimation(): void
    {
        $sentence = 'The quick brown fox jumps over the lazy dog.';
        $tokens = TokenEstimatorHelper::estimate($sentence);

        // Should be reasonable for this 9-word sentence
        $this->assertGreaterThan(5, $tokens);
        $this->assertLessThan(30, $tokens);
    }

    /**
     * Test code content estimation.
     */
    public function test_code_content_estimation(): void
    {
        $code = '<?php echo "Hello, World!"; ?>';
        $tokens = TokenEstimatorHelper::estimate($code);

        $this->assertGreaterThan(5, $tokens);
        $this->assertLessThan(50, $tokens);
    }

    /**
     * Test estimateMultiple method.
     */
    public function test_estimate_multiple(): void
    {
        $texts = [
            'Hello',
            'World',
            '123',
        ];

        $expected = TokenEstimatorHelper::estimate('Hello')
            + TokenEstimatorHelper::estimate('World')
            + TokenEstimatorHelper::estimate('123');

        $this->assertSame($expected, TokenEstimatorHelper::estimateMultiple($texts));
    }

    /**
     * Test estimateSimple method.
     */
    public function test_estimate_simple(): void
    {
        $text = 'This is a test string with 38 characters!';

        // Default: 4 chars per token (42 chars / 4 = 10.5, ceil = 11)
        $this->assertSame(11, TokenEstimatorHelper::estimateSimple($text));

        // Custom: 2 chars per token (42 chars / 2 = 21)
        $this->assertSame(21, TokenEstimatorHelper::estimateSimple($text, 2));

        // Custom: 6 chars per token (42 chars / 6 = 7)
        $this->assertSame(7, TokenEstimatorHelper::estimateSimple($text, 6));
    }

    /**
     * Test that estimate produces reasonable results for typical text.
     */
    public function test_estimate_produces_reasonable_results(): void
    {
        // For typical English text, both methods should produce reasonable estimates
        $text = 'The quick brown fox jumps over the lazy dog.';

        $simpleEstimate = TokenEstimatorHelper::estimateSimple($text);
        $patternEstimate = TokenEstimatorHelper::estimate($text);

        // Both should be positive
        $this->assertGreaterThan(0, $simpleEstimate);
        $this->assertGreaterThan(0, $patternEstimate);

        // Both should be within a reasonable range for this 44-character sentence
        // (typically 10-15 tokens for this text)
        $this->assertLessThan(30, $patternEstimate);
        $this->assertLessThan(30, $simpleEstimate);
    }

    /**
     * Test unicode handling.
     */
    public function test_unicode_handling(): void
    {
        // Unicode letters should be handled
        $tokens = TokenEstimatorHelper::estimate('café');
        $this->assertGreaterThan(0, $tokens);

        // Emoji should be counted as punctuation (1 token each)
        $emojiTokens = TokenEstimatorHelper::estimate('😀🎉');
        $this->assertGreaterThan(0, $emojiTokens);
    }

    /**
     * Test long text performance.
     */
    public function test_long_text_performance(): void
    {
        // Generate a long text (10KB)
        $longText = str_repeat('The quick brown fox jumps over the lazy dog. ', 200);

        $start = microtime(true);
        $tokens = TokenEstimatorHelper::estimate($longText);
        $elapsed = microtime(true) - $start;

        // Should complete in under 100ms
        $this->assertLessThan(0.1, $elapsed);

        // Should produce a reasonable token count
        $this->assertGreaterThan(1000, $tokens);
    }
}
