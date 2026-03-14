<?php

declare(strict_types=1);

namespace App\Services\Setup;

use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;

/**
 * Handles channel configuration during the setup wizard.
 *
 * Collects channel selections, bot tokens, and polling intervals
 * from the user via Laravel Prompts.
 */
class SetupChannelConfigurator
{
    /**
     * Channel registry with display names and config keys.
     *
     * @var array<string, array{display: string, token_key: string|null, help: string}>
     */
    protected array $channels = [
        'discord' => [
            'display' => 'Discord',
            'token_key' => 'DISCORD_BOT_TOKEN',
            'help' => 'Get one at: https://discord.com/developers/applications',
        ],
    ];

    /**
     * Run the channel configuration step.
     *
     * @return array{channels: array<int, string>, tokens: array<string, string>, polling_intervals: array<string, int>}
     */
    public function configure(): array
    {
        info('Step 1: Messaging Channels');

        // Build channel options for multiselect
        $channelOptions = collect($this->channels)->mapWithKeys(
            fn ($channel, $id) => [$id => $channel['display']]
        )->toArray();

        $enabledChannels = multiselect(
            label: 'Which messaging channels do you want to enable?',
            options: $channelOptions,
        );

        // Allow skipping channel selection
        if (empty($enabledChannels)) {
            info('No channels selected - you can configure them later');
        }

        $tokens = [];
        $pollingIntervals = [];

        foreach ($enabledChannels as $channelId) {
            $channel = $this->channels[$channelId];

            // Collect token if required
            if ($channel['token_key']) {
                $token = password(
                    label: "Enter {$channel['display']} bot token",
                    placeholder: $channel['help'],
                    required: "{$channel['display']} bot token is required"
                );
                $tokens[$channel['token_key']] = $token;
            }

            // Ask for polling interval
            $pollingInterval = select(
                label: "Polling interval for {$channel['display']}",
                options: [
                    '1' => '1 second (real-time)',
                    '3' => '3 seconds',
                    '5' => '5 seconds (default)',
                    '10' => '10 seconds',
                    '30' => '30 seconds',
                    '60' => '1 minute',
                ],
                default: '5'
            );
            $pollingIntervals[$channelId] = (int) $pollingInterval;
        }

        return [
            'channels' => $enabledChannels,
            'tokens' => $tokens,
            'polling_intervals' => $pollingIntervals,
        ];
    }

    /**
     * Get the channel registry.
     *
     * @return array<string, array{display: string, token_key: string|null, help: string}>
     */
    public function getChannels(): array
    {
        return $this->channels;
    }
}
