<?php

declare(strict_types=1);

namespace App\Services\Setup;

use function Laravel\Prompts\info;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\warning;

/**
 * Handles AI provider and model configuration during the setup wizard.
 *
 * Collects provider selection, model choice, and API key from the user.
 */
class SetupProviderConfigurator
{
    /**
     * Run the provider configuration step.
     *
     * @return array{provider: string, model: string, api_key: string|null, api_key_name: string|null}
     */
    public function configure(): array
    {
        info('Step 2: AI Provider');

        $providers = $this->getProviders();
        $textProviders = array_filter($providers, fn ($p) => $p['supports_text'] ?? true);

        // Build provider options with recommendations
        $providerOptions = collect($textProviders)->mapWithKeys(
            fn ($provider, $id) => [$id => $provider['display'].($provider['recommended'] ? ' ⭐' : '')]
        )->toArray();

        $providerId = select(
            label: 'Which AI provider?',
            options: $providerOptions,
            default: array_key_first($textProviders),
            scroll: count($providerOptions)
        );

        $provider = $providers[$providerId];

        // Show note if provider has one
        if (isset($provider['note'])) {
            warning("Note: {$provider['note']}");
        }

        // Model selection
        $modelId = select(
            label: "Which {$provider['display']} model?",
            options: $provider['models'],
            default: $provider['default_model']
        );

        // API Key (only if required)
        $apiKey = null;
        if ($provider['api_key'] !== null) {
            $apiKey = password(
                label: "Enter your API key for {$provider['display']}",
                placeholder: 'API key (will be masked)'
            );

            if (empty($apiKey)) {
                warning('No API key provided. You will need to set it manually in .env');
            }
        } else {
            info("{$provider['display']} does not require an API key");
        }

        return [
            'provider' => $providerId,
            'model' => $modelId,
            'api_key' => $apiKey,
            'api_key_name' => $provider['api_key'],
        ];
    }

    /**
     * Configure heartbeat interval.
     *
     * @return int Heartbeat interval in seconds
     */
    public function configureHeartbeat(): int
    {
        info('Step 3: Heartbeat Interval');

        $interval = select(
            label: 'How often should the agent check in proactively?',
            options: [
                '60' => '1 minute',
                '300' => '5 minutes (default)',
                '600' => '10 minutes',
                '900' => '15 minutes',
                '1800' => '30 minutes',
                '3600' => '1 hour',
            ],
            default: '300'
        );

        return (int) $interval;
    }

    /**
     * Get the provider registry from config.
     *
     * @return array<string, array{display: string, recommended: bool, models: array, default_model: string, api_key: string|null, supports_text: bool}>
     */
    public function getProviders(): array
    {
        return config('laraclaw.providers', []);
    }
}
