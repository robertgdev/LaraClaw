<?php

use App\Enums\ChannelEnum;
use App\Enums\EpisodicEventTypeEnum;
use App\Models\Conversation;
use App\Models\Memory;

beforeEach(function () {
    $this->senderId = 'test-model-user';
    $this->channel = ChannelEnum::TELEGRAM;

    // Clean up any existing test data (including soft-deleted)
    Memory::withTrashed()->forSender($this->senderId, $this->channel)->forceDelete();
});

afterEach(function () {
    Memory::withTrashed()->forSender($this->senderId, $this->channel)->forceDelete();
});

/** Helper to create a Memory without specifying an id */
function makeMemory(string $senderId, ChannelEnum $channel, array $extra = []): Memory
{
    return Memory::create(array_merge([
        'sender_id'   => $senderId,
        'channel'     => $channel,
        'event_type'  => 'fact_stored',
        'content'     => 'Test content',
        'importance'  => 0.5,
        'access_count' => 0,
        'last_accessed_at' => now(),
    ], $extra));
}

describe('Memory Model', function () {
    describe('casts and attributes', function () {
        it('auto-assigns an integer primary key', function () {
            $memory = makeMemory($this->senderId, $this->channel);
            expect($memory->id)->toBeInt()->toBeGreaterThan(0);
        });

        it('casts channel to ChannelEnum', function () {
            $memory = makeMemory($this->senderId, $this->channel);

            expect($memory->channel)->toBeInstanceOf(ChannelEnum::class)
                ->and($memory->channel)->toBe(ChannelEnum::TELEGRAM);
        });

        it('casts event_type to EpisodicEventTypeEnum', function () {
            $memory = makeMemory($this->senderId, $this->channel, [
                'event_type' => 'correction',
                'importance' => 0.9,
            ]);

            expect($memory->event_type)->toBeInstanceOf(EpisodicEventTypeEnum::class)
                ->and($memory->event_type)->toBe(EpisodicEventTypeEnum::CORRECTION);
        });

        it('casts importance as decimal with 2 places', function () {
            $memory = makeMemory($this->senderId, $this->channel, ['importance' => 0.856]);

            expect((float) $memory->importance)->toBe(0.86);
        });

        it('casts timestamps as datetime', function () {
            $memory = makeMemory($this->senderId, $this->channel);

            // Laravel 12 uses CarbonImmutable by default
            expect($memory->created_at)->toBeInstanceOf(\Carbon\CarbonImmutable::class)
                ->and($memory->last_accessed_at)->toBeInstanceOf(\Carbon\CarbonImmutable::class);
        });
    });

    describe('conversation_id FK', function () {
        it('is nullable and defaults to null', function () {
            $memory = makeMemory($this->senderId, $this->channel);
            expect($memory->conversation_id)->toBeNull();
        });

        it('can be set to a valid conversation id', function () {
            $conv = Conversation::create([
                'conversation_id' => 'conv-' . uniqid(),
                'channel'         => ChannelEnum::WEBSOCKET,
                'sender'          => 'user',
                'sender_id'       => 'ws_test',
                'is_active'       => true,
            ]);

            $memory = makeMemory($this->senderId, $this->channel, [
                'conversation_id' => $conv->id,
            ]);

            expect($memory->conversation_id)->toBe($conv->id);
            expect($memory->conversation)->not->toBeNull();
            expect($memory->conversation->id)->toBe($conv->id);
        });
    });

    describe('soft deletes', function () {
        it('soft-deletes a memory and hides it from default queries', function () {
            $memory = makeMemory($this->senderId, $this->channel);
            $id = $memory->id;

            $memory->delete();

            // Default query should NOT include it
            $result = Memory::find($id);
            expect($result)->toBeNull();
        });

        it('includes soft-deleted memories via withTrashed()', function () {
            $memory = makeMemory($this->senderId, $this->channel);
            $id = $memory->id;
            $memory->delete();

            $result = Memory::withTrashed()->find($id);
            expect($result)->not->toBeNull();
            expect($result->deleted_at)->not->toBeNull();
        });

        it('can be force-deleted permanently', function () {
            $memory = makeMemory($this->senderId, $this->channel);
            $id = $memory->id;
            $memory->forceDelete();

            $result = Memory::withTrashed()->find($id);
            expect($result)->toBeNull();
        });

        it('can be restored after soft-delete', function () {
            $memory = makeMemory($this->senderId, $this->channel);
            $id = $memory->id;
            $memory->delete();

            Memory::withTrashed()->find($id)->restore();

            $result = Memory::find($id);
            expect($result)->not->toBeNull();
            expect($result->deleted_at)->toBeNull();
        });

        it('default forSender scope excludes soft-deleted memories', function () {
            $m1 = makeMemory($this->senderId, $this->channel, ['content' => 'keep']);
            $m2 = makeMemory($this->senderId, $this->channel, ['content' => 'delete']);
            $m2->delete();

            $results = Memory::forSender($this->senderId, $this->channel)->get();
            expect($results)->toHaveCount(1);
            expect($results->first()->content)->toBe('keep');
        });
    });

    describe('cascade soft-delete from conversation', function () {
        it('soft-deletes related memories when a conversation is soft-deleted', function () {
            $conv = Conversation::create([
                'conversation_id' => 'cascade-' . uniqid(),
                'channel'         => ChannelEnum::WEBSOCKET,
                'sender'          => 'user',
                'sender_id'       => 'ws_test',
                'is_active'       => true,
            ]);

            $m1 = makeMemory($this->senderId, $this->channel, ['conversation_id' => $conv->id]);
            $m2 = makeMemory($this->senderId, $this->channel, ['conversation_id' => $conv->id]);

            // Soft-delete the conversation
            $conv->delete();

            // Memories should now be soft-deleted
            expect(Memory::find($m1->id))->toBeNull();
            expect(Memory::find($m2->id))->toBeNull();

            // But still visible via withTrashed
            expect(Memory::withTrashed()->find($m1->id))->not->toBeNull();
            expect(Memory::withTrashed()->find($m2->id))->not->toBeNull();
        });

        it('does not soft-delete memories of other conversations', function () {
            $conv1 = Conversation::create([
                'conversation_id' => 'cascade-c1-' . uniqid(),
                'channel'         => ChannelEnum::WEBSOCKET,
                'sender'          => 'user',
                'sender_id'       => 'ws_test',
                'is_active'       => true,
            ]);
            $conv2 = Conversation::create([
                'conversation_id' => 'cascade-c2-' . uniqid(),
                'channel'         => ChannelEnum::WEBSOCKET,
                'sender'          => 'user',
                'sender_id'       => 'ws_test',
                'is_active'       => true,
            ]);

            $m1 = makeMemory($this->senderId, $this->channel, ['conversation_id' => $conv1->id]);
            $m2 = makeMemory($this->senderId, $this->channel, ['conversation_id' => $conv2->id]);

            // Only delete conv1
            $conv1->delete();

            expect(Memory::find($m1->id))->toBeNull();     // m1 should be soft-deleted
            expect(Memory::find($m2->id))->not->toBeNull(); // m2 should still exist
        });

        it('does not soft-delete memories with null conversation_id when a conversation is deleted', function () {
            $conv = Conversation::create([
                'conversation_id' => 'cascade-null-' . uniqid(),
                'channel'         => ChannelEnum::WEBSOCKET,
                'sender'          => 'user',
                'sender_id'       => 'ws_test',
                'is_active'       => true,
            ]);

            // Memory not linked to any conversation
            $orphan = makeMemory($this->senderId, $this->channel);

            $conv->delete();

            // Orphan memory should still exist
            expect(Memory::find($orphan->id))->not->toBeNull();
        });
    });

    describe('scopes', function () {
        beforeEach(function () {
            makeMemory($this->senderId, $this->channel, [
                'event_type'       => 'correction',
                'content'          => 'High importance correction',
                'importance'       => 0.9,
                'last_accessed_at' => now(),
                'created_at'       => now(),
            ]);

            makeMemory($this->senderId, $this->channel, [
                'event_type'       => 'fact_stored',
                'content'          => 'Low importance fact',
                'importance'       => 0.1,
                'last_accessed_at' => now()->subDays(10),
                'created_at'       => now()->subDays(10),
            ]);

            makeMemory('other-user', ChannelEnum::DISCORD, [
                'content'          => 'Other user fact',
                'last_accessed_at' => now(),
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
            $memory = makeMemory($this->senderId, $this->channel, [
                'access_count'     => 0,
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
            $memory = makeMemory($this->senderId, $this->channel, ['importance' => 0.8]);

            $memory->decayImportance(0.9); // 10% decay

            $memory->refresh();
            expect((float) $memory->importance)->toBe(0.72);
        });

        it('decayImportance does not go below minimum threshold', function () {
            $memory = makeMemory($this->senderId, $this->channel, ['importance' => 0.04]);

            $memory->decayImportance(0.5);

            $memory->refresh();
            // Should not update because it's below 0.05 threshold
            expect((float) $memory->importance)->toBe(0.04);
        });
    });

    describe('Scout integration', function () {
        it('toSearchableArray returns expected fields', function () {
            $memory = new Memory([
                'sender_id'  => $this->senderId,
                'channel'    => $this->channel,
                'event_type' => EpisodicEventTypeEnum::FACT_STORED,
                'content'    => 'Test content',
                'outcome'    => 'Test outcome',
            ]);

            $array = $memory->toSearchableArray();

            expect($array)->toHaveKeys(['id', 'sender_id', 'channel', 'content', 'outcome', 'event_type'])
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

        it('shouldBeSearchable returns false for soft-deleted memories', function () {
            $memory = makeMemory($this->senderId, $this->channel, ['importance' => 0.8]);
            $memory->delete();
            $memory->refresh();
            expect($memory->shouldBeSearchable())->toBeFalse();
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
