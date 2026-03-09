<?php

namespace Database\Factories;

use App\Enums\ChannelEnum;
use App\Enums\MessageStatusEnum;
use App\Enums\QueueTypeEnum;
use App\Models\ConversationMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ConversationMessage>
 */
class ConversationMessageFactory extends Factory
{
    protected $model = ConversationMessage::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => (string) Str::uuid(),
            'conversation_id' => (string) Str::uuid(),
            'channel' => fake()->randomElement(ChannelEnum::cases()),
            'direction' => ConversationMessage::DIRECTION_INCOMING,
            'sender' => fake()->name(),
            'sender_id' => (string) fake()->randomNumber(8),
            'message' => fake()->paragraph(),
            'agent_id' => null,
            'provider' => null,
            'model' => null,
            'files' => [],
            'status' => MessageStatusEnum::PENDING,
            'queue_type' => QueueTypeEnum::INCOMING,
            'retry_count' => 0,
            'error_message' => null,
            'processed_at' => null,
        ];
    }

    /**
     * Create an incoming message (from user).
     */
    public function incoming(): static
    {
        return $this->state(fn (array $attributes) => [
            'direction' => ConversationMessage::DIRECTION_INCOMING,
            'queue_type' => QueueTypeEnum::INCOMING,
            'status' => MessageStatusEnum::PENDING,
        ]);
    }

    /**
     * Create an outgoing message (from agent).
     */
    public function outgoing(): static
    {
        return $this->state(fn (array $attributes) => [
            'direction' => ConversationMessage::DIRECTION_OUTGOING,
            'queue_type' => QueueTypeEnum::OUTGOING,
            'status' => MessageStatusEnum::PENDING,
        ]);
    }

    /**
     * Create a processing message.
     */
    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'queue_type' => QueueTypeEnum::PROCESSING,
            'status' => MessageStatusEnum::PROCESSING,
        ]);
    }

    /**
     * Create a completed message.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MessageStatusEnum::COMPLETED,
            'processed_at' => now(),
        ]);
    }

    /**
     * Create a failed message.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MessageStatusEnum::FAILED,
            'error_message' => fake()->sentence(),
            'retry_count' => fake()->randomDigit(),
        ]);
    }

    /**
     * Create a message for Telegram channel.
     */
    public function telegram(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => ChannelEnum::TELEGRAM,
        ]);
    }

    /**
     * Create a message for Discord channel.
     */
    public function discord(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => ChannelEnum::DISCORD,
        ]);
    }

    /**
     * Create a message for WhatsApp channel.
     */
    public function whatsapp(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => ChannelEnum::WHATSAPP,
        ]);
    }

    /**
     * Create a message for CLI channel.
     */
    public function cli(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => ChannelEnum::CLI,
        ]);
    }

    /**
     * Create a message with files.
     */
    public function withFiles(array $files = []): static
    {
        $defaultFiles = ['/tmp/test_file.pdf', '/tmp/test_image.png'];

        return $this->state(fn (array $attributes) => [
            'files' => ! empty($files) ? $files : $defaultFiles,
        ]);
    }

    /**
     * Create a message for a specific agent.
     */
    public function forAgent(string $agentId): static
    {
        return $this->state(fn (array $attributes) => [
            'agent_id' => $agentId,
        ]);
    }

    /**
     * Create a message for a specific conversation.
     */
    public function forConversation(string $conversationId): static
    {
        return $this->state(fn (array $attributes) => [
            'conversation_id' => $conversationId,
        ]);
    }

    /**
     * Create an outgoing message with provider/model info.
     */
    public function withProviderModel(string $provider, string $model): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => $provider,
            'model' => $model,
        ]);
    }
}
