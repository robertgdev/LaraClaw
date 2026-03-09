<?php

namespace Database\Factories;

use App\Enums\ChannelEnum;
use App\Enums\PairingStatusEnum;
use App\Models\PairingEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PairingEntry>
 */
class PairingEntryFactory extends Factory
{
    protected $model = PairingEntry::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel' => fake()->randomElement(ChannelEnum::cases()),
            'sender_id' => (string) fake()->randomNumber(8),
            'sender' => fake()->name(),
            'code' => PairingEntry::generateUniqueCode(),
            'status' => PairingStatusEnum::PENDING,
            'created_at' => now(),
            'last_seen_at' => now(),
            'approved_at' => null,
            'approved_code' => null,
        ];
    }

    /**
     * Create an approved pairing entry.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PairingStatusEnum::APPROVED,
            'approved_at' => now(),
            'approved_code' => $attributes['code'] ?? PairingEntry::generateUniqueCode(),
        ]);
    }

    /**
     * Create a pending pairing entry.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PairingStatusEnum::PENDING,
        ]);
    }

    /**
     * Create a pairing entry for Telegram.
     */
    public function telegram(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => ChannelEnum::TELEGRAM,
        ]);
    }

    /**
     * Create a pairing entry for Discord.
     */
    public function discord(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => ChannelEnum::DISCORD,
        ]);
    }

    /**
     * Create a pairing entry for WhatsApp.
     */
    public function whatsapp(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => ChannelEnum::WHATSAPP,
        ]);
    }

    /**
     * Create a pairing entry for a specific sender.
     */
    public function forSender(string $senderId, string $senderName = 'Test User'): static
    {
        return $this->state(fn (array $attributes) => [
            'sender_id' => $senderId,
            'sender' => $senderName,
        ]);
    }

    /**
     * Create a pairing entry with a specific code.
     */
    public function withCode(string $code): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => $code,
        ]);
    }
}
