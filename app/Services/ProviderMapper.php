<?php

declare(strict_types=1);

namespace App\Services;

use Prism\Prism\Enums\Provider;

/**
 * Maps provider name strings to Prism Provider enum values.
 *
 * Single source of truth for provider string → enum mapping.
 * Used across AgentInvokerService, IntentClassificationService,
 * SkillClassificationService, and LaraClawSetupCommand.
 */
class ProviderMapper
{
    /**
     * Resolve a provider string to a Prism Provider enum.
     *
     * Accepts various casing and aliases (e.g., 'openai', 'open_ai', 'claude').
     * Defaults to Anthropic if the provider is unknown.
     */
    public static function resolve(string $provider): Provider
    {
        return match (strtolower($provider)) {
            'openai', 'open_ai' => Provider::OpenAI,
            'anthropic', 'claude' => Provider::Anthropic,
            'gemini', 'google' => Provider::Gemini,
            'groq' => Provider::Groq,
            'mistral' => Provider::Mistral,
            'xai', 'x-ai' => Provider::XAI,
            'ollama' => Provider::Ollama,
            'deepseek' => Provider::DeepSeek,
            default => Provider::Anthropic,
        };
    }
}
