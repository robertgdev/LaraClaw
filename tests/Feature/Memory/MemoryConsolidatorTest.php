<?php

use App\DTOs\MemoryConsolidationDTO;
use App\Enums\ChannelEnum;
use App\Enums\EpisodicEventTypeEnum;
use App\Models\Memory;
use App\Services\Memory\MemoryConsolidator;
use App\Services\Memory\MemoryRelevanceScorer;

beforeEach(function () {
    $this->scorer = new MemoryRelevanceScorer;
    $this->consolidator = new MemoryConsolidator($this->scorer);
    $this->senderId = 'test-consolidator-user';
    $this->channel = ChannelEnum::TELEGRAM;

    Memory::forSender($this->senderId, $this->channel)->delete();
});

function createMemory(string $senderId, ChannelEnum $channel, array $overrides = []): Memory
{
    return Memory::create(array_merge([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'sender_id' => $senderId,
        'channel' => $channel,
        'event_type' => EpisodicEventTypeEnum::FACT_STORED,
        'content' => 'Test memory',
        'importance' => 0.6,
        'access_count' => 0,
        'last_accessed_at' => now(),
    ], $overrides));
}

afterEach(function () {
    Memory::forSender($this->senderId, $this->channel)->delete();
});

describe('MemoryConsolidator', function () {
    describe('consolidate', function () {
        it('returns MemoryConsolidationDTO with all three counts', function () {
            $result = $this->consolidator->consolidate($this->senderId, $this->channel);

            expect($result)->toBeInstanceOf(MemoryConsolidationDTO::class)
                ->and($result->decayed)->toBeInt()
                ->and($result->pruned)->toBeInt()
                ->and($result->merged)->toBeInt();
        });

        it('returns zeros when nothing to consolidate', function () {
            $result = $this->consolidator->consolidate($this->senderId, $this->channel);

            expect($result->decayed)->toBe(0)
                ->and($result->pruned)->toBe(0)
                ->and($result->merged)->toBe(0);
        });
    });

    describe('decayImportance', function () {
        it('decays old unaccessed memories', function () {
            $memory = createMemory($this->senderId, $this->channel, [
                'content' => 'Old memory',
                'importance' => 0.6,
                'last_accessed_at' => now()->subDays(10),
            ]);

            $decayed = $this->consolidator->decayImportance($this->senderId, $this->channel);

            expect($decayed)->toBeGreaterThanOrEqual(1);

            $memory->refresh();
            expect((float) $memory->importance)->toBeLessThan(0.6);
        });

        it('does not decay recently accessed memories', function () {
            $memory = createMemory($this->senderId, $this->channel, [
                'content' => 'Recent memory',
                'importance' => 0.6,
            ]);

            $decayed = $this->consolidator->decayImportance($this->senderId, $this->channel);

            expect($decayed)->toBe(0);

            $memory->refresh();
            expect((float) $memory->importance)->toBe(0.6);
        });
    });

    describe('mergeDuplicates', function () {
        it('merges highly similar entries', function () {
            // Nearly identical strings: 9 of 10 tokens shared = Jaccard 0.9 > 0.8 threshold
            createMemory($this->senderId, $this->channel, [
                'content' => 'User strongly prefers dark mode for their code editors every day',
            ]);

            createMemory($this->senderId, $this->channel, [
                'content' => 'User strongly prefers dark mode for their code editors every time',
            ]);

            $beforeCount = Memory::forSender($this->senderId, $this->channel)->count();
            expect($beforeCount)->toBe(2);

            $merged = $this->consolidator->mergeDuplicates($this->senderId, $this->channel);

            expect($merged)->toBe(1);

            $remaining = Memory::forSender($this->senderId, $this->channel)->count();
            expect($remaining)->toBe(1);
        });

        it('does not merge dissimilar entries', function () {
            createMemory($this->senderId, $this->channel, [
                'content' => 'User lives in Philippines',
            ]);

            createMemory($this->senderId, $this->channel, [
                'event_type' => EpisodicEventTypeEnum::CORRECTION,
                'content' => 'Always use TypeScript strict mode',
                'importance' => 0.9,
            ]);

            $merged = $this->consolidator->mergeDuplicates($this->senderId, $this->channel);

            expect($merged)->toBe(0);
            expect(Memory::forSender($this->senderId, $this->channel)->count())->toBe(2);
        });
    });
});
