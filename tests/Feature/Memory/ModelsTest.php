<?php

use App\Enums\ChannelEnum;
use App\Enums\EpisodicEventTypeEnum;
use App\Models\Memory;

beforeEach(function () {
    $this->senderId = 'test-model-user';
    $this->channel = ChannelEnum::TELEGRAM;

    // Clean up any existing test data
    Memory::forSender($this->senderId, $this->channel)->delete();
});

afterEach(function () {
    Memory::forSender($this->senderId, $this->channel)->delete();
});

describe('Memory Model', function () {
    describe('casts and attributes', function () {
        it('casts channel to ChannelEnum', function () {
            $memory = Memory::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'sender_id' => $this->senderId,
                'channel' => 'telegram',
                'event_type' => 'fact_stored',
                'content' => 'Test content',
                'importance' => 0.5,
            ]);

            expect($memory->channel)->toBeInstanceOf(ChannelEnum::class)
                ->and($memory->channel)->toBe(ChannelEnum::TELEGRAM);
        });

        it('casts event_type to EpisodicEventTypeEnum', function () {
            $memory = Memory::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'sender_id' => $this->senderId,
                'channel' => $this->channel,
                'event_type' => 'correction',
                'content' => 'Test content',
                'importance' => 0.9,
            ]);

            expect($memory->event_type)->toBeInstanceOf(EpisodicEventTypeEnum::class)
                ->and($memory->event_type)->toBe(EpisodicEventTypeEnum::CORRECTION);
        });

        it('casts importance as decimal with 2 places', function () {
            $memory = Memory::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'sender_id' => $this->senderId,
                'channel' => $this->channel,
                'event_type' => 'fact_stored',
                'content' => 'Test content',
                'importance' => 0.856,
            ]);

            expect((float) $memory->importance)->toBe(0.86);
        });

        it('casts timestamps as datetime', function () {
            $memory = Memory::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'sender_id' => $this->senderId,
                'channel' => $this->channel,
                'event_type' => 'fact_stored',
                'content' => 'Test content',
                'importance' => 0.5,
                'last_accessed_at' => now(),
            ]);

            // Laravel 12 uses CarbonImmutable by default
            expect($memory->created_at)->toBeInstanceOf(\Carbon\CarbonImmutable::class)
                ->and($memory->last_accessed_at)->toBeInstanceOf(\Carbon\CarbonImmutable::class);
        });
    });

    describe('scopes', function () {
        beforeEach(function () {
            // Create test data - recent memory
            Memory::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'sender_id' => $this->senderId,
                'channel' => $this->channel,
                'event_type' => 'correction',
                'content' => 'High importance correction',
                'importance' => 0.9,
                'access_count' => 0,
                'last_accessed_at' => now(),
                'created_at' => now(),
            ]);

            // Create old memory (created 10 days ago)
            Memory::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'sender_id' => $this->senderId,
                'channel' => $this->channel,
                'event_type' => 'fact_stored',
                'content' => 'Low importance fact',
                'importance' => 0.1,
                'access_count' => 0,
                'last_accessed_at' => now()->subDays(10),
                'created_at' => now()->subDays(10),
            ]);

            // Other user's memory
            Memory::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'sender_id' => 'other-user',
                'channel' => ChannelEnum::DISCORD,
                'event_type' => 'fact_stored',
                'content' => 'Other user fact',
                'importance' => 0.5,
                'access_count' => 0,
                'last_accessed_at' => now(),
                'created_at' => now(),
            ]);
        });

        it('scopeForSender filters by sender_id and channel', function () {
            $results = Memory::forSender($this->senderId, $this->channel)->get();

            expect($results)->toHaveCount(2);
            foreach ($results as $result) {
                expect($result->sender_id)->toBe($this->senderId)
                    ->and($result->channel)->toBe($this->channel);
            }
        });

        it('scopeHighImportance filters by threshold', function () {
            $results = Memory::forSender($this->senderId, $this->channel)
                ->highImportance(0.7)
                ->get();

            expect($results)->toHaveCount(1)
                ->and((float) $results->first()->importance)->toBe(0.9);
        });

        it('scopeRecent filters by days', function () {
            $results = Memory::forSender($this->senderId, $this->channel)
                ->recent(5)
                ->get();

            expect($results)->toHaveCount(1)
                ->and($results->first()->content)->toBe('High importance correction');
        });

        it('scopeForEventType filters by event type', function () {
            $results = Memory::forSender($this->senderId, $this->channel)
                ->forEventType(EpisodicEventTypeEnum::CORRECTION)
                ->get();

            expect($results)->toHaveCount(1)
                ->and($results->first()->event_type)->toBe(EpisodicEventTypeEnum::CORRECTION);
        });

        it('scopeNotAccessedFor filters by days since last access', function () {
            $results = Memory::forSender($this->senderId, $this->channel)
                ->notAccessedFor(7)
                ->get();

            expect($results)->toHaveCount(1)
                ->and($results->first()->content)->toBe('Low importance fact');
        });

        it('scopeLowImportance filters by threshold', function () {
            $results = Memory::forSender($this->senderId, $this->channel)
                ->lowImportance(0.2)
                ->get();

            expect($results)->toHaveCount(1)
                ->and((float) $results->first()->importance)->toBe(0.1);
        });

        it('scopeUnaccessed filters by zero access count', function () {
            // Update one to have access count > 0
            Memory::forSender($this->senderId, $this->channel)
                ->first()
                ->update(['access_count' => 5]);

            $results = Memory::forSender($this->senderId, $this->channel)
                ->unaccessed()
                ->get();

            expect($results)->toHaveCount(1)
                ->and($results->first()->access_count)->toBe(0);
        });
    });

    describe('helper methods', function () {
        it('reinforce increments access count and updates timestamp', function () {
            $memory = Memory::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'sender_id' => $this->senderId,
                'channel' => $this->channel,
                'event_type' => 'fact_stored',
                'content' => 'Test content',
                'importance' => 0.5,
                'access_count' => 0,
                'last_accessed_at' => now()->subDays(5),
            ]);

            $oldTimestamp = $memory->last_accessed_at;
            sleep(1); // Ensure timestamp difference
            $memory->reinforce();

            $memory->refresh();
            expect($memory->access_count)->toBe(1)
                ->and($memory->last_accessed_at->isAfter($oldTimestamp))->toBeTrue();
        });

        it('decayImportance reduces importance by factor', function () {
            $memory = Memory::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'sender_id' => $this->senderId,
                'channel' => $this->channel,
                'event_type' => 'fact_stored',
                'content' => 'Test content',
                'importance' => 0.8,
            ]);

            $memory->decayImportance(0.9); // 10% decay

            $memory->refresh();
            expect((float) $memory->importance)->toBe(0.72);
        });

        it('decayImportance does not go below minimum threshold', function () {
            $memory = Memory::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'sender_id' => $this->senderId,
                'channel' => $this->channel,
                'event_type' => 'fact_stored',
                'content' => 'Test content',
                'importance' => 0.04,
            ]);

            $memory->decayImportance(0.5);

            $memory->refresh();
            // Should not update because it's below 0.05 threshold
            expect((float) $memory->importance)->toBe(0.04);
        });
    });

    describe('Scout integration', function () {
        it('toSearchableArray returns expected fields', function () {
            $memory = new Memory([
                'id' => 'test-uuid',
                'sender_id' => $this->senderId,
                'channel' => $this->channel,
                'event_type' => EpisodicEventTypeEnum::FACT_STORED,
                'content' => 'Test content',
                'outcome' => 'Test outcome',
            ]);

            $array = $memory->toSearchableArray();

            expect($array)->toHaveKeys(['id', 'sender_id', 'channel', 'content', 'outcome', 'event_type'])
                ->and($array['id'])->toBe('test-uuid')
                ->and($array['channel'])->toBe('telegram')
                ->and($array['event_type'])->toBe('fact_stored');
        });

        it('shouldBeSearchable returns false for low importance', function () {
            $memory = new Memory(['importance' => 0.05]);
            expect($memory->shouldBeSearchable())->toBeFalse();
        });

        it('shouldBeSearchable returns true for normal importance', function () {
            $memory = new Memory(['importance' => 0.5]);
            expect($memory->shouldBeSearchable())->toBeTrue();
        });
    });
});

describe('EpisodicEventTypeEnum', function () {
    it('returns correct default importance for each type', function () {
        expect(EpisodicEventTypeEnum::CORRECTION->defaultImportance())->toBe(0.90)
            ->and(EpisodicEventTypeEnum::PREFERENCE_LEARNED->defaultImportance())->toBe(0.80)
            ->and(EpisodicEventTypeEnum::FACT_STORED->defaultImportance())->toBe(0.60)
            ->and(EpisodicEventTypeEnum::TASK_COMPLETED->defaultImportance())->toBe(0.50)
            ->and(EpisodicEventTypeEnum::DELEGATION_RESULT->defaultImportance())->toBe(0.50);
    });

    it('returns correct labels', function () {
        expect(EpisodicEventTypeEnum::CORRECTION->label())->toBe('⚠️ Correction')
            ->and(EpisodicEventTypeEnum::PREFERENCE_LEARNED->label())->toBe('⭐ Preference Learned')
            ->and(EpisodicEventTypeEnum::FACT_STORED->label())->toBe('📌 Fact Stored')
            ->and(EpisodicEventTypeEnum::TASK_COMPLETED->label())->toBe('✅ Task Completed')
            ->and(EpisodicEventTypeEnum::DELEGATION_RESULT->label())->toBe('🔄 Delegation Result');
    });

    it('returns correct short labels', function () {
        expect(EpisodicEventTypeEnum::CORRECTION->shortLabel())->toBe('Correction')
            ->and(EpisodicEventTypeEnum::PREFERENCE_LEARNED->shortLabel())->toBe('Preference')
            ->and(EpisodicEventTypeEnum::FACT_STORED->shortLabel())->toBe('Fact');
    });

    it('returns options array for selects', function () {
        $options = EpisodicEventTypeEnum::options();

        expect($options)->toBeArray()
            ->and($options)->toHaveKey('correction', '⚠️ Correction')
            ->and($options)->toHaveKey('preference_learned', '⭐ Preference Learned');
    });
});
