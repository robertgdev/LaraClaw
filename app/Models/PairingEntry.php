<?php

namespace App\Models;

use App\Enums\ChannelEnum;
use App\Enums\PairingStatusEnum;
use Database\Factories\PairingEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PairingEntry extends Model
{
    /** @use HasFactory<PairingEntryFactory> */
    use HasFactory;

    protected $fillable = [
        'channel',
        'sender_id',
        'sender',
        'code',
        'status',
        'created_at',
        'last_seen_at',
        'approved_at',
        'approved_code',
    ];

    protected $casts = [
        'channel' => ChannelEnum::class,
        'status' => PairingStatusEnum::class,
        'created_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    // ==========================================
    // Relationships
    // ==========================================

    /**
     * Get all messages from this paired sender.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class, 'sender_id', 'sender_id');
    }

    /**
     * Get all conversations from this paired sender.
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'sender_id', 'sender_id');
    }

    /**
     * Scope for pending entries.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', PairingStatusEnum::PENDING);
    }

    /**
     * Scope for approved entries.
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', PairingStatusEnum::APPROVED);
    }

    /**
     * Scope for specific channel.
     */
    public function scopeForChannel(Builder $query, ChannelEnum $channel): Builder
    {
        return $query->where('channel', $channel);
    }

    /**
     * Check if entry is approved.
     */
    public function isApproved(): bool
    {
        return $this->status === PairingStatusEnum::APPROVED;
    }

    /**
     * Approve this entry.
     */
    public function approve(): void
    {
        $this->update([
            'status' => PairingStatusEnum::APPROVED,
            'approved_at' => now(),
            'approved_code' => $this->code,
        ]);
    }

    /**
     * Update last seen timestamp.
     */
    public function updateLastSeen(): void
    {
        $this->update(['last_seen_at' => now()]);
    }

    /**
     * Generate a unique pairing code.
     */
    public static function generateUniqueCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';

        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        // Ensure uniqueness
        while (static::where('code', $code)->exists()) {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        }

        return $code;
    }

    /**
     * Find or create a pending entry for a sender.
     */
    public static function findOrCreateForSender(ChannelEnum $channel, string $senderId, string $sender): self
    {
        $entry = static::where('channel', $channel)
            ->where('sender_id', $senderId)
            ->first();

        if ($entry) {
            $entry->updateLastSeen();

            // Update sender name if changed
            if ($entry->sender !== $sender) {
                $entry->update(['sender' => $sender]);
            }

            return $entry;
        }

        return static::create([
            'channel' => $channel,
            'sender_id' => $senderId,
            'sender' => $sender,
            'code' => static::generateUniqueCode(),
            'status' => PairingStatusEnum::PENDING,
            'created_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    /**
     * Approve a pairing by code.
     */
    public static function approveByCode(string $code): ?self
    {
        $code = strtoupper(trim($code));

        $entry = static::where('code', $code)
            ->where('status', PairingStatusEnum::PENDING)
            ->first();

        if (! $entry) {
            return null;
        }

        $entry->approve();

        return $entry;
    }

    /**
     * Check if a sender is paired.
     */
    public static function isSenderPaired(ChannelEnum $channel, string $senderId): bool
    {
        return static::where('channel', $channel)
            ->where('sender_id', $senderId)
            ->where('status', PairingStatusEnum::APPROVED)
            ->exists();
    }

    /**
     * Ensure sender is paired, return check result.
     *
     * @return array{
     *     approved: bool,
     *     code: string,
     *     isNewPending: bool
     * }
     */
    public static function ensureSenderPaired(ChannelEnum $channel, string $senderId, string $sender): array
    {
        // Check for approved entry
        $approved = static::where('channel', $channel)
            ->where('sender_id', $senderId)
            ->where('status', PairingStatusEnum::APPROVED)
            ->first();

        if ($approved) {
            // Update sender name if changed
            if ($approved->sender !== $sender) {
                $approved->update(['sender' => $sender]);
            }

            return ['approved' => true];
        }

        // Find or create pending entry
        $entry = static::findOrCreateForSender($channel, $senderId, $sender);

        return [
            'approved' => false,
            'code' => $entry->code,
            'isNewPending' => $entry->wasRecentlyCreated,
        ];
    }
}
