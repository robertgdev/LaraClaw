<?php

namespace Database\Factories;

use App\Enums\ChannelEnum;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Conversation>
 */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => (string) Str::uuid(),
            'channel' => fake()->randomElement(ChannelEnum::cases()),
            'sender' => fake()->name(),
            'sender_id' => (string) fake()->randomNumber(8),
            'team_id' => null,
            'total_messages' => 0,
            'started_at' => now(),
            'completed_at' => null,
        ];
    }

    /**
     * Create a conversation for Telegram channel.
     */
    public function telegram(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => ChannelEnum::TELEGRAM,
        ]);
    }

    /**
     * Create a conversation for Discord channel.
     */
    public function discord(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => ChannelEnum::DISCORD,
        ]);
    }

    /**
     * Create a conversation for WhatsApp channel.
     */
    public function whatsapp(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => ChannelEnum::WHATSAPP,
        ]);
    }

    /**
     * Create a conversation for CLI channel.
     */
    public function cli(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => ChannelEnum::CLI,
        ]);
    }

    /**
     * Create a conversation for a specific team.
     */
    public function forTeam(string $teamId): static
    {
        return $this->state(fn (array $attributes) => [
            'team_id' => $teamId,
        ]);
    }

    /**
     * Create an incomplete conversation (not completed).
     */
    public function incomplete(): static
    {
        return $this->state(fn (array $attributes) => [
            'completed_at' => null,
        ]);
    }

    /**
     * Create a completed conversation.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'completed_at' => now(),
        ]);
    }

    /**
     * Create a conversation with messages.
     * This creates the conversation and adds messages to it.
     */
    public function withMessages(int $userMessages = 1, int $agentResponses = 1): static
    {
        return $this->afterCreating(function (Conversation $conversation) use ($userMessages, $agentResponses) {
            // Create user messages
            for ($i = 0; $i < $userMessages; $i++) {
                ConversationMessage::factory()
                    ->incoming()
                    ->forConversation($conversation->conversation_id)
                    ->create([
                        'channel' => $conversation->channel,
                        'sender' => $conversation->sender,
                        'sender_id' => $conversation->sender_id,
                    ]);
            }

            // Create agent responses
            for ($i = 0; $i < $agentResponses; $i++) {
                ConversationMessage::factory()
                    ->outgoing()
                    ->forConversation($conversation->conversation_id)
                    ->completed()
                    ->create([
                        'channel' => $conversation->channel,
                    ]);
            }

            // Update total messages count
            $conversation->update([
                'total_messages' => $userMessages + $agentResponses,
                'completed_at' => now(),
            ]);
        });
    }
}
