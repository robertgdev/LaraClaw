<?php

declare(strict_types=1);

namespace App\Services\Setup;

use App\Services\ProviderMapper;
use Prism\Prism\Facades\Prism;

use function Safe\putenv;

/**
 * Tests LLM provider connections during setup.
 *
 * Extracted from LaraClawSetupCommand to be testable
 * and reusable (e.g., from health-check endpoints).
 */
class LlmConnectionTester
{
    /**
     * Test the LLM connection by sending a simple prompt.
     *
     * @param  string  $providerId  The provider identifier (e.g., 'anthropic')
     * @param  string  $modelId  The model identifier (e.g., 'claude-3-5-haiku-20241022')
     * @param  string|null  $apiKey  Optional API key to temporarily set
     * @param  string|null  $apiKeyEnvName  The env var name for the API key
     * @return array{success: bool, response: string|null, error: string|null}
     */
    public function test(string $providerId, string $modelId, ?string $apiKey = null, ?string $apiKeyEnvName = null): array
    {
        $originalValue = null;

        // Temporarily set the API key if provided
        if ($apiKeyEnvName && $apiKey) {
            $originalValue = $_ENV[$apiKeyEnvName] ?? null;
            $_ENV[$apiKeyEnvName] = $apiKey;
            putenv("{$apiKeyEnvName}={$apiKey}");
        }

        try {
            $response = $this->sendTestMessage($providerId, $modelId);

            return [
                'success' => true,
                'response' => $response,
                'error' => null,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'response' => null,
                'error' => $e->getMessage(),
            ];
        } finally {
            // Restore original environment
            if ($apiKeyEnvName) {
                if ($originalValue !== null) {
                    $_ENV[$apiKeyEnvName] = $originalValue;
                    putenv("{$apiKeyEnvName}={$originalValue}");
                } else {
                    unset($_ENV[$apiKeyEnvName]);
                    putenv($apiKeyEnvName);
                }
            }
        }
    }

    /**
     * Send a test message to the LLM.
     */
    protected function sendTestMessage(string $providerId, string $modelId): string
    {
        $providerEnum = ProviderMapper::resolve($providerId);

        $prism = Prism::text()
            ->using($providerEnum, $modelId)
            ->withPrompt('Say "Hello! I am ready to assist you." in exactly 10 words or less.')
            ->withSystemPrompt('You are a helpful assistant. Respond very briefly.');

        $response = $prism->asText();

        return $response->text;
    }
}
