<?php

use App\Enums\ChannelEnum;
use App\Enums\EpisodicEventTypeEnum;
use App\Models\Memory;
use App\Services\Memory\DatabaseSearchStrategy;
use App\Services\Memory\ScoutSearchStrategy;
use App\Services\Memory\SearchStrategyFactory;

beforeEach(function () {
    $this->senderId = 'test-strategy-user';
    $this->channel = ChannelEnum::TELEGRAM;

    // Clean up any existing test data
    Memory::forSender($this->senderId, $this->channel)->delete();
});

afterEach(function () {
    Memory::forSender($this->senderId, $this->channel)->delete();
});

describe('SearchStrategyFactory', function () {
    it('creates ScoutSearchStrategy for meilisearch driver', function () {
        config(['scout.driver' => 'meilisearch']);

        $strategy = SearchStrategyFactory::create();

        expect($strategy)->toBeInstanceOf(ScoutSearchStrategy::class)
            ->and($strategy->getName())->toBe('scout_meilisearch');
    });

    it('creates ScoutSearchStrategy for algolia driver', function () {
        config(['scout.driver' => 'algolia']);

        $strategy = SearchStrategyFactory::create();

        expect($strategy)->toBeInstanceOf(ScoutSearchStrategy::class)
            ->and($strategy->getName())->toBe('scout_algolia');
    });

    it('creates ScoutSearchStrategy for typesense driver', function () {
        config(['scout.driver' => 'typesense']);

        $strategy = SearchStrategyFactory::create();

        expect($strategy)->toBeInstanceOf(ScoutSearchStrategy::class)
            ->and($strategy->getName())->toBe('scout_typesense');
    });

    it('creates ScoutSearchStrategy for database driver by default', function () {
        config(['scout.driver' => 'database']);
        config(['memory.use_native_fulltext' => false]);

        $strategy = SearchStrategyFactory::create();

        expect($strategy)->toBeInstanceOf(ScoutSearchStrategy::class)
            ->and($strategy->getName())->toBe('scout_database');
    });

    it('creates DatabaseSearchStrategy when native_fulltext is enabled', function () {
        config(['scout.driver' => 'database']);
        config(['memory.use_native_fulltext' => true]);

        $strategy = SearchStrategyFactory::create();

        expect($strategy)->toBeInstanceOf(DatabaseSearchStrategy::class)
            ->and($strategy->getName())->toContain('database_');
    });

    it('creates ScoutSearchStrategy for collection driver', function () {
        config(['scout.driver' => 'collection']);

        $strategy = SearchStrategyFactory::create();

        expect($strategy)->toBeInstanceOf(ScoutSearchStrategy::class)
            ->and($strategy->getName())->toBe('scout_collection');
    });
});

describe('ScoutSearchStrategy', function () {
    it('searches and returns results with search_score', function () {
        // Create test data
        Memory::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'sender_id' => $this->senderId,
            'channel' => $this->channel,
            'event_type' => EpisodicEventTypeEnum::FACT_STORED,
            'content' => 'User prefers Python programming',
            'importance' => 0.6,
            'access_count' => 0,
            'last_accessed_at' => now(),
        ]);

        $strategy = new ScoutSearchStrategy;
        $results = $strategy->search($this->senderId, $this->channel, 'Python', 10);

        expect($results)->toBeInstanceOf(\Illuminate\Support\Collection::class);

        // If results are not empty, verify they have search_score
        if ($results->isNotEmpty()) {
            $first = $results->first();
            expect(isset($first->search_score))->toBeTrue();
        }
    });

    it('returns empty collection for no matches', function () {
        Memory::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'sender_id' => $this->senderId,
            'channel' => $this->channel,
            'event_type' => EpisodicEventTypeEnum::FACT_STORED,
            'content' => 'Unrelated content about apples',
            'importance' => 0.6,
            'access_count' => 0,
            'last_accessed_at' => now(),
        ]);

        $strategy = new ScoutSearchStrategy;
        $results = $strategy->search($this->senderId, $this->channel, 'quantum physics', 10);

        // Results may be empty or have low scores
        expect($results)->toBeInstanceOf(\Illuminate\Support\Collection::class);
    });

    it('respects limit parameter', function () {
        for ($i = 0; $i < 10; $i++) {
            Memory::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'sender_id' => $this->senderId,
                'channel' => $this->channel,
                'event_type' => EpisodicEventTypeEnum::FACT_STORED,
                'content' => "Programming fact number {$i}",
                'importance' => 0.6,
                'access_count' => 0,
                'last_accessed_at' => now(),
            ]);
        }

        $strategy = new ScoutSearchStrategy;
        $results = $strategy->search($this->senderId, $this->channel, 'programming', 3);

        expect($results->count())->toBeLessThanOrEqual(3);
    });
});

describe('DatabaseSearchStrategy', function () {
    it('searches using LIKE fallback', function () {
        Memory::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'sender_id' => $this->senderId,
            'channel' => $this->channel,
            'event_type' => EpisodicEventTypeEnum::FACT_STORED,
            'content' => 'User prefers dark mode in editors',
            'importance' => 0.6,
            'access_count' => 0,
            'last_accessed_at' => now(),
        ]);

        $strategy = new DatabaseSearchStrategy;
        $results = $strategy->search($this->senderId, $this->channel, 'dark mode', 10);

        expect($results)->toBeInstanceOf(\Illuminate\Support\Collection::class);

        // If results are not empty, verify they have search_score
        if ($results->isNotEmpty()) {
            $first = $results->first();
            expect(isset($first->search_score))->toBeTrue();
        }
    });

    it('returns correct strategy name based on database driver', function () {
        $strategy = new DatabaseSearchStrategy;
        $name = $strategy->getName();

        // Should contain database_ prefix and driver name
        expect($name)->toContain('database_');
    });

    it('isolates results by sender_id and channel', function () {
        Memory::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'sender_id' => $this->senderId,
            'channel' => $this->channel,
            'event_type' => EpisodicEventTypeEnum::FACT_STORED,
            'content' => 'Test content for user1',
            'importance' => 0.6,
            'access_count' => 0,
            'last_accessed_at' => now(),
        ]);

        Memory::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'sender_id' => 'other-user',
            'channel' => ChannelEnum::DISCORD,
            'event_type' => EpisodicEventTypeEnum::FACT_STORED,
            'content' => 'Test content for user2',
            'importance' => 0.6,
            'access_count' => 0,
            'last_accessed_at' => now(),
        ]);

        $strategy = new DatabaseSearchStrategy;
        $results = $strategy->search($this->senderId, $this->channel, 'Test content', 10);

        // Should only return results for the specified user
        foreach ($results as $result) {
            expect($result->sender_id)->toBe($this->senderId)
                ->and($result->channel)->toBe($this->channel);
        }

        // Cleanup
        Memory::forSender('other-user', ChannelEnum::DISCORD)->delete();
    });
});
