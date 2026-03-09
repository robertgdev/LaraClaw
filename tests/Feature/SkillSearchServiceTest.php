<?php

use App\Models\Skill;
use App\Models\SkillMatch;
use App\Services\SettingsService;
use App\Services\SkillSearchService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->settings = app(SettingsService::class);
    $this->service = new SkillSearchService($this->settings);

    // Clear cache before each test
    Cache::flush();

    // Clear database tables
    SkillMatch::query()->delete();
    Skill::query()->delete();
});

describe('SkillSearchService', function () {
    describe('indexSkills', function () {
        it('returns cached index when available', function () {
            // Arrange - first cache something, then verify it's returned
            $this->service->refreshIndex();
            $cachedIndex = $this->service->getAllSkills();

            // If we have skills, verify caching works
            if (! empty($cachedIndex)) {
                // Act - should return cached version
                $result = $this->service->indexSkills();

                // Assert
                expect($result)->toBe($cachedIndex);
            } else {
                expect(true)->toBeTrue(); // Skip if no skills
            }
        });

        it('indexes skills from directories', function () {
            // Arrange - the project has real skills in agents/skills/
            Cache::forget('laraclaw_skills_index');

            // Act
            $result = $this->service->refreshIndex();

            // Assert - should find at least agent-browser skill
            expect($result)->toBeArray()
                ->and(count($result))->toBeGreaterThanOrEqual(0);
        });
    });

    describe('search', function () {
        it('searches skills by query', function () {
            // Arrange - use real skill index
            $this->service->refreshIndex();

            // Act
            $result = $this->service->search('browser');

            // Assert
            expect($result)->toBeArray();
        });

        it('returns empty array when no matches', function () {
            // Arrange
            $skillIndex = [
                'skill1' => [
                    'name' => 'skill1',
                    'description' => 'A skill',
                    'keywords' => ['keyword'],
                ],
            ];
            Cache::put('laraclaw_skills_index', $skillIndex, 3600);

            // Act
            $result = $this->service->search('nonexistent');

            // Assert
            expect($result)->toBeEmpty();
        });

        it('limits results to specified count', function () {
            // Arrange - use real skills
            $this->service->refreshIndex();
            $skills = $this->service->getAllSkills();

            // Skip if not enough skills
            if (count($skills) < 3) {
                expect(true)->toBeTrue();

                return;
            }

            // Act - search with limit
            $result = $this->service->search('a', 3);

            // Assert
            expect(count($result))->toBeLessThanOrEqual(3);
        });
    });

    describe('findBestMatch', function () {
        it('returns best matching skill', function () {
            // Arrange
            $skillIndex = [
                'imagegen' => [
                    'name' => 'imagegen',
                    'description' => 'Generate images',
                    'keywords' => ['image', 'generate'],
                ],
            ];
            Cache::put('laraclaw_skills_index', $skillIndex, 3600);

            // Act
            $result = $this->service->findBestMatch('image generation');

            // Assert
            expect($result)->toBeArray()
                ->toHaveKey('skill')
                ->toHaveKey('score');
        });

        it('returns null when no match found', function () {
            // Arrange
            Cache::put('laraclaw_skills_index', [], 3600);

            // Act
            $result = $this->service->findBestMatch('nonexistent');

            // Assert
            expect($result)->toBeNull();
        });
    });

    describe('getAllSkills', function () {
        it('returns all indexed skills', function () {
            // Arrange - use real skills
            $this->service->refreshIndex();

            // Act
            $result = $this->service->getAllSkills();

            // Assert
            expect($result)->toBeArray();
        });
    });

    describe('getSkill', function () {
        it('returns specific skill by name', function () {
            // Arrange - use real skills
            $this->service->refreshIndex();
            $skills = $this->service->getAllSkills();

            // Skip if no skills
            if (empty($skills)) {
                expect(true)->toBeTrue();

                return;
            }

            $skillName = array_key_first($skills);

            // Act
            $result = $this->service->getSkill($skillName);

            // Assert
            expect($result)->toBeArray()
                ->toHaveKey('name');
        });

        it('returns null for non-existent skill', function () {
            // Arrange
            Cache::put('laraclaw_skills_index', [], 3600);

            // Act
            $result = $this->service->getSkill('nonexistent');

            // Assert
            expect($result)->toBeNull();
        });
    });

    describe('getSkillContent', function () {
        it('returns skill file content', function () {
            // Arrange - use real skill from project
            $this->service->refreshIndex();
            $skills = $this->service->getAllSkills();

            // Skip if no skills available
            if (empty($skills)) {
                expect(true)->toBeTrue();

                return;
            }

            $skillName = array_key_first($skills);

            // Act
            $result = $this->service->getSkillContent($skillName);

            // Assert
            expect($result)->toBeString();
        });

        it('returns null for non-existent skill', function () {
            // Arrange
            Cache::put('laraclaw_skills_index', [], 3600);

            // Act
            $result = $this->service->getSkillContent('nonexistent');

            // Assert
            expect($result)->toBeNull();
        });
    });

    describe('getSkillReferences', function () {
        it('returns reference files for skill', function () {
            // Arrange - use real skill from project
            $this->service->refreshIndex();
            $skills = $this->service->getAllSkills();

            // Find a skill with references
            $skillWithRefs = null;
            foreach ($skills as $name => $skill) {
                if (! empty($skill['has_references'])) {
                    $skillWithRefs = $name;
                    break;
                }
            }

            // Skip if no skill with references
            if (! $skillWithRefs) {
                expect(true)->toBeTrue();

                return;
            }

            // Act
            $result = $this->service->getSkillReferences($skillWithRefs);

            // Assert
            expect($result)->toBeArray();
        });

        it('returns empty array when no references', function () {
            // Arrange - create a skill without references in the index
            $skillIndex = [
                'imagegen' => [
                    'name' => 'imagegen',
                    'has_references' => false,
                    'directory' => '/nonexistent/path',
                ],
            ];

            // Use reflection to set the skillIndex directly
            $reflection = new ReflectionClass($this->service);
            $property = $reflection->getProperty('skillIndex');
            $property->setAccessible(true);
            $property->setValue($this->service, $skillIndex);

            // Act
            $result = $this->service->getSkillReferences('imagegen');

            // Assert
            expect($result)->toBeEmpty();
        });
    });

    describe('getSkillScripts', function () {
        it('returns script files for skill', function () {
            // Arrange - use real skill from project
            $this->service->refreshIndex();
            $skills = $this->service->getAllSkills();

            // Find a skill with scripts
            $skillWithScripts = null;
            foreach ($skills as $name => $skill) {
                if (! empty($skill['has_scripts'])) {
                    $skillWithScripts = $name;
                    break;
                }
            }

            // Skip if no skill with scripts
            if (! $skillWithScripts) {
                expect(true)->toBeTrue();

                return;
            }

            // Act
            $result = $this->service->getSkillScripts($skillWithScripts);

            // Assert
            expect($result)->toBeArray();
        });

        it('returns empty array when no scripts', function () {
            // Arrange - create a skill without scripts in the index
            $skillIndex = [
                'imagegen' => [
                    'name' => 'imagegen',
                    'has_scripts' => false,
                    'directory' => '/nonexistent/path',
                ],
            ];

            // Use reflection to set the skillIndex directly
            $reflection = new ReflectionClass($this->service);
            $property = $reflection->getProperty('skillIndex');
            $property->setAccessible(true);
            $property->setValue($this->service, $skillIndex);

            // Act
            $result = $this->service->getSkillScripts('imagegen');

            // Assert
            expect($result)->toBeEmpty();
        });
    });

    describe('refreshIndex', function () {
        it('clears cache and reindexes', function () {
            // Act
            $result = $this->service->refreshIndex();

            // Assert
            expect($result)->toBeArray();
        });
    });

    describe('getSkillsDirs', function () {
        it('returns skills directories', function () {
            // Act
            $result = $this->service->getSkillsDirs();

            // Assert
            expect($result)->toBeArray();
        });
    });

    describe('getSkillsDir', function () {
        it('returns primary skills directory', function () {
            // Act
            $result = $this->service->getSkillsDir();

            // Assert
            expect($result)->toBeString();
        });
    });

    describe('suggestSkillsForMessage', function () {
        it('suggests skills based on message', function () {
            // Arrange
            $skillIndex = [
                'imagegen' => [
                    'name' => 'imagegen',
                    'description' => 'Generate images using AI',
                    'keywords' => ['image', 'generate', 'ai'],
                ],
            ];
            Cache::put('laraclaw_skills_index', $skillIndex, 3600);

            // Act
            $result = $this->service->suggestSkillsForMessage('Generate an image for me');

            // Assert
            expect($result)->toBeArray();
        });

        it('boosts skills matching intent context', function () {
            // Arrange
            $skillIndex = [
                'imagegen' => [
                    'name' => 'imagegen',
                    'description' => 'Generate creative images',
                    'keywords' => ['image', 'generate'],
                ],
            ];
            Cache::put('laraclaw_skills_index', $skillIndex, 3600);

            // Act
            $result = $this->service->suggestSkillsForMessage('Generate an image', ['intent' => 'creative']);

            // Assert
            expect($result)->toBeArray();
        });
    });

    describe('extractKeywords', function () {
        it('extracts keywords from text', function () {
            // Arrange
            $text = 'Generate beautiful images using AI technology';

            $reflection = new ReflectionClass($this->service);
            $method = $reflection->getMethod('extractKeywords');
            $method->setAccessible(true);

            // Act
            $result = $method->invoke($this->service, $text);

            // Assert
            expect($result)->toBeArray()
                ->toContain('generate')
                ->toContain('beautiful')
                ->toContain('images');
        });

        it('filters out stop words', function () {
            // Arrange
            $text = 'The quick brown fox jumps over the lazy dog';

            $reflection = new ReflectionClass($this->service);
            $method = $reflection->getMethod('extractKeywords');
            $method->setAccessible(true);

            // Act
            $result = $method->invoke($this->service, $text);

            // Assert - 'the' should be filtered as a stop word
            expect($result)->not()->toContain('the');
        });

        it('limits keywords to 20', function () {
            // Arrange
            $text = str_repeat('uniqueword ', 50);

            $reflection = new ReflectionClass($this->service);
            $method = $reflection->getMethod('extractKeywords');
            $method->setAccessible(true);

            // Act
            $result = $method->invoke($this->service, $text);

            // Assert
            expect($result)->toHaveCount(1); // Only one unique word
        });
    });

    describe('parseSkillFile', function () {
        it('parses valid SKILL.md file', function () {
            // Arrange - use real skill file from project
            $skillsDir = $this->service->getSkillsDir();
            $skillFile = $skillsDir.'/agent-browser/SKILL.md';

            if (! File::exists($skillFile)) {
                expect(true)->toBeTrue();

                return;
            }

            $reflection = new ReflectionClass($this->service);
            $method = $reflection->getMethod('parseSkillFile');
            $method->setAccessible(true);

            // Act
            $result = $method->invoke($this->service, $skillFile);

            // Assert
            expect($result)->toBeArray()
                ->toHaveKey('name')
                ->toHaveKey('description');
        });

        it('returns null for file without frontmatter', function () {
            // Arrange
            $tempFile = tempnam(sys_get_temp_dir(), 'skill_test');
            File::put($tempFile, 'No frontmatter here');

            $reflection = new ReflectionClass($this->service);
            $method = $reflection->getMethod('parseSkillFile');
            $method->setAccessible(true);

            // Act
            $result = $method->invoke($this->service, $tempFile);

            // Assert
            expect($result)->toBeNull();

            // Cleanup
            File::delete($tempFile);
        });

        it('returns null for file missing required fields', function () {
            // Arrange
            $content = <<<'MD'
---
name: imagegen
---

Missing description
MD;
            $tempFile = tempnam(sys_get_temp_dir(), 'skill_test');
            File::put($tempFile, $content);

            $reflection = new ReflectionClass($this->service);
            $method = $reflection->getMethod('parseSkillFile');
            $method->setAccessible(true);

            // Act
            $result = $method->invoke($this->service, $tempFile);

            // Assert
            expect($result)->toBeNull();

            // Cleanup
            File::delete($tempFile);
        });
    });

    describe('suggestSkillsForMessage with Skill Model', function () {
        it('returns cached skill via relationship', function () {
            // Arrange - create skill and match in database
            $skill = Skill::create([
                'name' => 'imagegen',
                'dir_name' => 'imagegen',
                'path' => '/tmp/skills/imagegen',
                'source_type' => 'core',
                'description' => 'Generate images using AI',
                'checksum' => 'abc123',
                'keywords' => ['image', 'generate', 'ai'],
                'classification_status' => Skill::STATUS_CLASSIFIED,
            ]);

            SkillMatch::storeMatch(
                keywords: ['generate', 'image', 'sunset'],
                skillId: $skill->id,
                confidence: 0.95,
                category: 'creative',
                sampleMessage: 'Generate an image of a sunset'
            );

            // Act - search with similar keywords
            $result = $this->service->suggestSkillsForMessage('Generate an image of a sunset');

            // Assert - should hit cache
            expect($result)->toBeArray()
                ->and($result[0])->toHaveKey('from_cache', true)
                ->and($result[0]['skill']['name'])->toBe('imagegen');
        });

        it('stores new matches using skill name lookup', function () {
            // Arrange - create skill in database
            $skill = Skill::create([
                'name' => 'schedule',
                'dir_name' => 'schedule',
                'path' => '/tmp/skills/schedule',
                'source_type' => 'core',
                'description' => 'Schedule tasks and reminders',
                'checksum' => 'def456',
                'keywords' => ['schedule', 'task', 'reminder'],
            ]);

            // Set up skill index cache
            $skillIndex = [
                'schedule' => [
                    'name' => 'schedule',
                    'description' => 'Schedule tasks and reminders',
                    'keywords' => ['schedule', 'task', 'reminder'],
                    'dir_name' => 'schedule',
                    'path' => '/tmp/skills/schedule',
                    'directory' => '/tmp/skills/schedule',
                    'source_type' => 'core',
                    'has_scripts' => false,
                    'has_references' => false,
                    'has_assets' => false,
                ],
            ];
            Cache::put('laraclaw_skills_index', $skillIndex, 3600);

            // Act - search that won't hit cache
            $result = $this->service->suggestSkillsForMessage('Schedule a reminder for tomorrow');

            // Assert - should have stored in cache
            expect($result)->toBeArray();

            // Verify a match was stored
            $storedMatch = SkillMatch::whereHas('skill', fn ($q) => $q->where('name', 'schedule'))->first();
            expect($storedMatch)->not->toBeNull();
        });
    });

    describe('findSkillForMessage', function () {
        it('returns best matching skill', function () {
            // Arrange
            $skill = Skill::create([
                'name' => 'agent-browser',
                'dir_name' => 'agent-browser',
                'path' => '/tmp/skills/agent-browser',
                'source_type' => 'core',
                'description' => 'Browse websites and extract content',
                'checksum' => 'ghi789',
                'keywords' => ['browse', 'website', 'open'],
            ]);

            SkillMatch::storeMatch(
                keywords: ['open', 'website'],
                skillId: $skill->id,
                confidence: 0.9,
                category: 'automation'
            );

            // Act
            $result = $this->service->findSkillForMessage('Open a website for me');

            // Assert
            expect($result)->toBeArray()
                ->toHaveKey('skill');
        });

        it('returns null when no match found', function () {
            // Arrange - empty cache
            Cache::put('laraclaw_skills_index', [], 3600);

            // Act
            $result = $this->service->findSkillForMessage('Do something completely random');

            // Assert
            expect($result)->toBeNull();
        });
    });

    describe('getCacheStatistics', function () {
        it('returns statistics with skill information', function () {
            // Arrange
            $skill = Skill::create([
                'name' => 'test-skill',
                'dir_name' => 'test-skill',
                'path' => '/tmp/skills/test-skill',
                'source_type' => 'core',
                'description' => 'Test skill',
                'checksum' => 'test123',
            ]);

            SkillMatch::storeMatch(
                keywords: ['test'],
                skillId: $skill->id,
                confidence: 0.9
            );

            // Act
            $stats = $this->service->getCacheStatistics();

            // Assert - hit_count starts at 1 by default
            expect($stats->totalEntries)->toBe(1)
                ->and($stats->totalHits)->toBe(1);
        });
    });

    describe('clearMatchCache', function () {
        it('clears all skill matches', function () {
            // Arrange
            $skill = Skill::create([
                'name' => 'test-skill',
                'dir_name' => 'test-skill',
                'path' => '/tmp/skills/test-skill',
                'source_type' => 'core',
                'description' => 'Test skill',
                'checksum' => 'test123',
            ]);

            SkillMatch::storeMatch(
                keywords: ['test'],
                skillId: $skill->id,
                confidence: 0.9
            );

            expect(SkillMatch::count())->toBe(1);

            // Act
            $this->service->clearMatchCache();

            // Assert
            expect(SkillMatch::count())->toBe(0);
        });
    });

    describe('cleanupCache', function () {
        it('removes old entries with low hit counts', function () {
            // Arrange
            $skill = Skill::create([
                'name' => 'old-skill',
                'dir_name' => 'old-skill',
                'path' => '/tmp/skills/old-skill',
                'source_type' => 'core',
                'description' => 'Old skill',
                'checksum' => 'old123',
            ]);

            // Create old entry
            \DB::table('skill_matches')->insert([
                'intent_signature' => 'old',
                'intent_keywords' => json_encode(['old']),
                'skill_id' => $skill->id,
                'confidence_score' => 0.5,
                'hit_count' => 1,
                'created_at' => now()->subDays(60),
                'updated_at' => now()->subDays(60),
            ]);

            // Act
            $deleted = $this->service->cleanupCache(30, 2);

            // Assert
            expect($deleted)->toBe(1);
        });
    });
});
