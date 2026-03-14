<?php

use App\Services\SettingsService;
use App\Services\Skills\SkillFileParser;
use App\Services\Skills\SkillIndexer;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->settings = app(SettingsService::class);
    $this->indexer = new SkillIndexer($this->settings);
    Cache::flush();
});

describe('SkillIndexer', function () {
    describe('indexSkills', function () {
        it('returns cached index when available', function () {
            // First index to populate cache
            $first = $this->indexer->refreshIndex();

            // Second call should hit cache
            $second = $this->indexer->indexSkills();

            // Both should have the same skill names (keys)
            expect(array_keys($second))->toBe(array_keys($first));
        });

        it('indexes skills from directories', function () {
            $result = $this->indexer->refreshIndex();

            expect($result)->toBeArray();
            // Each skill should be a ParsedSkillDTO
            foreach ($result as $name => $skill) {
                expect($skill)->toBeInstanceOf(\App\DTOs\ParsedSkillDTO::class)
                    ->and($skill->name)->toBeString()
                    ->and($skill->description)->toBeString()
                    ->and($skill->path)->toBeString()
                    ->and($skill->dirName)->toBeString()
                    ->and($skill->keywords)->toBeArray();
            }
        });
    });

    describe('refreshIndex', function () {
        it('clears cache and reindexes', function () {
            // Populate cache
            $this->indexer->indexSkills();

            // Refresh should clear cache
            $result = $this->indexer->refreshIndex();

            expect($result)->toBeArray();
        });
    });

    describe('getSkillsDirs', function () {
        it('returns array of directory configurations', function () {
            $dirs = $this->indexer->getSkillsDirs();

            expect($dirs)->toBeArray();
            foreach ($dirs as $dir) {
                expect($dir)
                    ->toHaveKey('type')
                    ->toHaveKey('path');
            }
        });
    });

    describe('getSkillsDir', function () {
        it('returns a string path', function () {
            $dir = $this->indexer->getSkillsDir();

            expect($dir)->toBeString();
        });
    });

    describe('resolveSkillsDirs', function () {
        it('resolves directories from filesystem', function () {
            $dirs = $this->indexer->resolveSkillsDirs();

            expect($dirs)->toBeArray();
        });
    });

    describe('integration with SkillFileParser', function () {
        it('uses custom parser when injected', function () {
            $mockParser = Mockery::mock(SkillFileParser::class);
            $mockParser->shouldReceive('parse')->andReturn(null);

            $indexer = new SkillIndexer($this->settings, $mockParser);
            $result = $indexer->refreshIndex();

            // With a parser that always returns null, we should get empty index
            expect($result)->toBeArray();
        });
    });
});
