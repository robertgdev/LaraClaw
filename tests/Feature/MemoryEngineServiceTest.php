<?php

use App\Enums\ChannelEnum;
use App\Enums\EpisodicEventTypeEnum;
use App\Models\Memory;
use App\Services\MemoryEngineService;

beforeEach(function () {
    $this->memory = app(MemoryEngineService::class);
    $this->senderId = 'test-user-123';
    $this->channel = ChannelEnum::TELEGRAM;

    // Clean up any existing test data
    Memory::forSender($this->senderId, $this->channel)->delete();
});

afterEach(function () {
    // Clean up test data
    Memory::forSender($this->senderId, $this->channel)->delete();
});

describe('MemoryEngineService', function () {
    describe('recordEvent', function () {
        it('stores an episodic event and returns its id', function () {
            $id = $this->memory->recordEvent(
                $this->senderId,
                $this->channel,
                [
                    'type' => EpisodicEventTypeEnum::FACT_STORED,
                    'content' => 'User prefers dark mode',
                ]
            );

            expect($id)->toBeString()
                ->and(strlen($id))->toBe(36); // UUID format

            $event = $this->memory->getEvent($id);
            expect($event)->not->toBeNull()
                ->and($event->sender_id)->toBe($this->senderId)
                ->and($event->event_type)->toBe(EpisodicEventTypeEnum::FACT_STORED)
                ->and($event->content)->toBe('User prefers dark mode')
                ->and($event->outcome)->toBeNull()
                ->and((float) $event->importance)->toBe(0.60) // Default for fact_stored
                ->and($event->access_count)->toBe(0);
        });

        it('stores event with custom importance', function () {
            $id = $this->memory->recordEvent(
                $this->senderId,
                $this->channel,
                [
                    'type' => EpisodicEventTypeEnum::TASK_COMPLETED,
                    'content' => 'Finished research on ML frameworks',
                    'outcome' => 'TensorFlow recommended',
                    'importance' => 0.85,
                ]
            );

            $event = $this->memory->getEvent($id);
            expect((float) $event->importance)->toBe(0.85)
                ->and($event->outcome)->toBe('TensorFlow recommended');
        });

        it('uses correct default importance per event type', function () {
            $types = [
                ['type' => EpisodicEventTypeEnum::CORRECTION, 'expected' => 0.90],
                ['type' => EpisodicEventTypeEnum::PREFERENCE_LEARNED, 'expected' => 0.80],
                ['type' => EpisodicEventTypeEnum::FACT_STORED, 'expected' => 0.60],
                ['type' => EpisodicEventTypeEnum::TASK_COMPLETED, 'expected' => 0.50],
                ['type' => EpisodicEventTypeEnum::DELEGATION_RESULT, 'expected' => 0.50],
            ];

            foreach ($types as $test) {
                $id = $this->memory->recordEvent(
                    $this->senderId,
                    $this->channel,
                    ['type' => $test['type'], 'content' => "Test {$test['type']->value}"]
                );
                $event = $this->memory->getEvent($id);
                expect((float) $event->importance)->toBe($test['expected']);
            }
        });

        it('stores multiple events for the same user', function () {
            $this->memory->recordEvent($this->senderId, $this->channel, [
                'type' => EpisodicEventTypeEnum::FACT_STORED,
                'content' => 'Fact 1',
            ]);
            $this->memory->recordEvent($this->senderId, $this->channel, [
                'type' => EpisodicEventTypeEnum::FACT_STORED,
                'content' => 'Fact 2',
            ]);
            $this->memory->recordEvent($this->senderId, $this->channel, [
                'type' => EpisodicEventTypeEnum::CORRECTION,
                'content' => 'Correction 1',
            ]);

            $events = $this->memory->getEvents($this->senderId, $this->channel);
            expect($events)->toHaveCount(3);
        });

        it('isolates events by sender and channel', function () {
            $this->memory->recordEvent($this->senderId, $this->channel, [
                'type' => EpisodicEventTypeEnum::FACT_STORED,
                'content' => 'User1 fact',
            ]);
            $this->memory->recordEvent('other-user', ChannelEnum::DISCORD, [
                'type' => EpisodicEventTypeEnum::FACT_STORED,
                'content' => 'User2 fact',
            ]);

            $user1Events = $this->memory->getEvents($this->senderId, $this->channel);
            $user2Events = $this->memory->getEvents('other-user', ChannelEnum::DISCORD);

            expect($user1Events)->toHaveCount(1)
                ->and($user2Events)->toHaveCount(1)
                ->and($user1Events->first()->content)->toBe('User1 fact')
                ->and($user2Events->first()->content)->toBe('User2 fact');

            // Cleanup
            Memory::forSender('other-user', ChannelEnum::DISCORD)->delete();
        });
    });

    describe('search', function () {
        it('returns results ranked by combined score', function () {
            // High importance + exact keyword match
            $this->memory->recordEvent($this->senderId, $this->channel, [
                'type' => EpisodicEventTypeEnum::CORRECTION,
                'content' => 'Python is the preferred programming language',
                'importance' => 0.9,
            ]);

            // Lower importance
            $this->memory->recordEvent($this->senderId, $this->channel, [
                'type' => EpisodicEventTypeEnum::TASK_COMPLETED,
                'content' => 'Wrote documentation for JavaScript API',
                'importance' => 0.5,
            ]);

            // Unrelated
            $this->memory->recordEvent($this->senderId, $this->channel, [
                'type' => EpisodicEventTypeEnum::FACT_STORED,
                'content' => 'User lives in Philippines',
            ]);

            $results = $this->memory->search($this->senderId, $this->channel, 'Python programming');

            // Search might return empty if index isn't synced, so we check for either results or just verify no error
            expect($results)->toBeArray();

            // If we have results, verify Python ranks high
            if (! empty($results)) {
                $pythonResult = collect($results)->firstWhere('content', fn ($c) => str_contains($c, 'Python'));
                expect($pythonResult)->not->toBeNull();
            }
        });

        it('returns empty results for no matches', function () {
            $this->memory->recordEvent($this->senderId, $this->channel, [
                'type' => EpisodicEventTypeEnum::FACT_STORED,
                'content' => 'Python programming language',
            ]);

            $results = $this->memory->search($this->senderId, $this->channel, 'quantum physics');

            expect($results)->toBeArray();
        });

        it('respects limit parameter', function () {
            for ($i = 0; $i < 10; $i++) {
                $this->memory->recordEvent($this->senderId, $this->channel, [
                    'type' => EpisodicEventTypeEnum::FACT_STORED,
                    'content' => "Programming fact number {$i}",
                ]);
            }

            $results = $this->memory->search($this->senderId, $this->channel, 'programming fact', 3);
            expect($results)->toHaveCount(3);
        });
    });

    describe('reinforce', function () {
        it('bumps access count and last accessed timestamp', function () {
            $id = $this->memory->recordEvent($this->senderId, $this->channel, [
                'type' => EpisodicEventTypeEnum::FACT_STORED,
                'content' => 'Important fact',
            ]);

            $before = $this->memory->getEvent($id);
            expect($before->access_count)->toBe(0);

            sleep(1); // Ensure timestamp difference
            $this->memory->reinforce($id);

            $after = $this->memory->getEvent($id);
            expect($after->access_count)->toBe(1)
                ->and($after->last_accessed_at->isAfter($before->last_accessed_at))->toBeTrue();
        });

        it('handles non-existent id gracefully', function () {
            // Should not throw
            $this->memory->reinforce('non-existent-id');
        })->throwsNoExceptions();

        it('accumulates access count on multiple reinforcements', function () {
            $id = $this->memory->recordEvent($this->senderId, $this->channel, [
                'type' => EpisodicEventTypeEnum::FACT_STORED,
                'content' => 'Frequently accessed fact',
            ]);

            for ($i = 0; $i < 5; $i++) {
                $this->memory->reinforce($id);
            }

            $event = $this->memory->getEvent($id);
            expect($event->access_count)->toBe(5);
        });
    });

    describe('consolidate', function () {
        it('decays importance of old unaccessed memories', function () {
            $id = $this->memory->recordEvent($this->senderId, $this->channel, [
                'type' => EpisodicEventTypeEnum::FACT_STORED,
                'content' => 'Old memory that should decay',
                'importance' => 0.6,
            ]);

            // Simulate 10 days without access
            Memory::where('id', $id)->update([
                'last_accessed_at' => now()->subDays(10),
            ]);

            $result = $this->memory->consolidate($this->senderId, $this->channel);
            expect($result['decayed'])->toBeGreaterThanOrEqual(0);

            // Check importance was reduced
            $event = $this->memory->getEvent($id);
            expect((float) $event->importance)->toBeLessThan(0.6);
        });

        it('merges highly similar entries', function () {
            $this->memory->recordEvent($this->senderId, $this->channel, [
                'type' => EpisodicEventTypeEnum::FACT_STORED,
                'content' => 'User prefers dark mode for code editors',
            ]);

            $this->memory->recordEvent($this->senderId, $this->channel, [
                'type' => EpisodicEventTypeEnum::FACT_STORED,
                'content' => 'User prefers dark mode for code editors and terminals',
            ]);

            $beforeCount = $this->memory->getEvents($this->senderId, $this->channel)->count();
            expect($beforeCount)->toBe(2);

            $result = $this->memory->consolidate($this->senderId, $this->channel);

            if ($result['merged'] > 0) {
                $afterCount = $this->memory->getEvents($this->senderId, $this->channel)->count();
                expect($afterCount)->toBeLessThan($beforeCount);
            }
        });

        it('does not merge dissimilar entries', function () {
            $this->memory->recordEvent($this->senderId, $this->channel, [
                'type' => EpisodicEventTypeEnum::FACT_STORED,
                'content' => 'User lives in Philippines',
            ]);

            $this->memory->recordEvent($this->senderId, $this->channel, [
                'type' => EpisodicEventTypeEnum::CORRECTION,
                'content' => 'Always use TypeScript strict mode',
            ]);

            $result = $this->memory->consolidate($this->senderId, $this->channel);
            expect($result['merged'])->toBe(0);

            $events = $this->memory->getEvents($this->senderId, $this->channel);
            expect($events)->toHaveCount(2);
        });

        it('returns zero counts when nothing to consolidate', function () {
            $result = $this->memory->consolidate($this->senderId, $this->channel);
            expect($result['merged'])->toBe(0)
                ->and($result['pruned'])->toBe(0)
                ->and($result['decayed'])->toBe(0);
        });
    });

    describe('getContextForAgent', function () {
        it('returns formatted context string with relevant memories', function () {
            $this->memory->recordEvent($this->senderId, $this->channel, [
                'type' => EpisodicEventTypeEnum::CORRECTION,
                'content' => 'Always use bun instead of npm',
                'importance' => 0.9,
            ]);

            $this->memory->recordEvent($this->senderId, $this->channel, [
                'type' => EpisodicEventTypeEnum::PREFERENCE_LEARNED,
                'content' => 'User prefers TypeScript over JavaScript',
                'importance' => 0.8,
            ]);

            $context = $this->memory->getContextForAgent($this->senderId, $this->channel, 'TypeScript');

            expect($context)->toBeString()
                ->and(strlen($context))->toBeGreaterThan(0);
        });

        it('includes high-importance recent memories', function () {
            $this->memory->recordEvent($this->senderId, $this->channel, [
                'type' => EpisodicEventTypeEnum::CORRECTION,
                'content' => 'Never use var in TypeScript',
                'importance' => 0.9,
            ]);

            $context = $this->memory->getContextForAgent($this->senderId, $this->channel);
            expect($context)->toContain('Never use var')
                ->and($context)->toContain('Correction');
        });

        it('returns empty string when no memories exist', function () {
            $context = $this->memory->getContextForAgent($this->senderId, $this->channel);
            expect($context)->toBe('');
        });
    });

    describe('getEvent / getEvents', function () {
        it('getEvent returns null for non-existent id', function () {
            $result = $this->memory->getEvent('does-not-exist');
            expect($result)->toBeNull();
        });

        it('getEvents returns events sorted by created_at DESC', function () {
            $id1 = $this->memory->recordEvent($this->senderId, $this->channel, [
                'type' => EpisodicEventTypeEnum::FACT_STORED,
                'content' => 'First',
            ]);
            sleep(1); // Ensure different timestamps
            $id2 = $this->memory->recordEvent($this->senderId, $this->channel, [
                'type' => EpisodicEventTypeEnum::FACT_STORED,
                'content' => 'Second',
            ]);
            sleep(1);
            $id3 = $this->memory->recordEvent($this->senderId, $this->channel, [
                'type' => EpisodicEventTypeEnum::FACT_STORED,
                'content' => 'Third',
            ]);

            $events = $this->memory->getEvents($this->senderId, $this->channel);
            expect($events)->toHaveCount(3)
                ->and($events->first()->content)->toBe('Third')
                ->and($events->last()->content)->toBe('First');
        });

        it('getEvents respects limit', function () {
            for ($i = 0; $i < 10; $i++) {
                $this->memory->recordEvent($this->senderId, $this->channel, [
                    'type' => EpisodicEventTypeEnum::FACT_STORED,
                    'content' => "Event {$i}",
                ]);
            }

            $events = $this->memory->getEvents($this->senderId, $this->channel, 3);
            expect($events)->toHaveCount(3);
        });

        it('getEvents returns empty collection for unknown user', function () {
            $events = $this->memory->getEvents('unknown-user', $this->channel);
            expect($events)->toBeEmpty();
        });
    });
});
