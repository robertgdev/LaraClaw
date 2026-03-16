<?php

use App\DTOs\ParsedSkillDTO;
use App\Models\Skill;
use App\Models\SkillMatch;
use App\Services\SettingsService;
use App\Services\SkillClassificationService;
use App\Services\SkillSearchService;
use App\TypedCollections\ParsedSkillDTOCollection;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->settings = app(SettingsService::class);
    $this->skillService = app(SkillSearchService::class);
    $this->service = new SkillClassificationService($this->settings, $this->skillService);

    // Clear cache before each test
    Cache::flush();

    // Clear tables
    SkillMatch::query()->delete();
    Skill::query()->delete();
});

afterEach(function () {
    // Clean up
    SkillMatch::query()->delete();
    Skill::query()->delete();
    Cache::flush();
});

describe('SkillClassificationService', function () {
    describe('buildSingleSkillPrompt (via ClassificationPromptBuilder)', function () {
        it('builds prompt for a single skill', function () {
            // Arrange
            $promptBuilder = $this->service->getPromptBuilder();
            $skillName = 'imagegen';
            $skill = [
                'name' => 'imagegen',
                'description' => 'Generate images using AI',
                'keywords' => ['image', 'generate', 'ai'],
            ];

            // Act
            $result = $promptBuilder->buildSingleSkillPrompt($skillName, $skill);

            // Assert
            expect($result)->toBeString()
                ->toContain('imagegen')
                ->toContain('Generate images using AI')
                ->toContain('JSON')
                ->toContain('sample_intent');
        });

        it('includes intents per skill count', function () {
            // Arrange
            $promptBuilder = $this->service->getPromptBuilder();
            $skillName = 'test-skill';
            $skill = [
                'name' => 'test-skill',
                'description' => 'A test skill',
                'keywords' => ['test'],
            ];

            // Act
            $result = $promptBuilder->buildSingleSkillPrompt($skillName, $skill);

            // Assert - default is 5 intents per skill
            expect($result)->toContain('5');
        });

        it('truncates long descriptions', function () {
            // Arrange
            $promptBuilder = $this->service->getPromptBuilder();
            $skillName = 'test-skill';
            $longDescription = str_repeat('This is a very long description. ', 50);
            $skill = [
                'name' => 'test-skill',
                'description' => $longDescription,
                'keywords' => ['test'],
            ];

            // Act
            $result = $promptBuilder->buildSingleSkillPrompt($skillName, $skill);

            // Assert - should not contain the full long description
            expect($result)->toBeString()
                ->and(strlen($result))->toBeLessThan(strlen($longDescription) + 500);
        });

        it('handles skill without keywords', function () {
            // Arrange
            $promptBuilder = $this->service->getPromptBuilder();
            $skillName = 'minimal';
            $skill = [
                'name' => 'minimal',
                'description' => 'Minimal skill',
            ];

            // Act
            $result = $promptBuilder->buildSingleSkillPrompt($skillName, $skill);

            // Assert
            expect($result)->toBeString()
                ->toContain('minimal')
                ->toContain('Minimal skill');
        });
    });

    describe('parseClassificationResponse', function () {
        it('parses valid JSON array response', function () {
            // Arrange
            $skill = Skill::create([
                'name' => 'imagegen',
                'dir_name' => 'imagegen',
                'path' => '/tmp/skills/imagegen',
                'source_type' => 'core',
                'description' => 'Generate images',
                'checksum' => 'abc123',
            ]);

            $response = <<<'JSON'
Here are the skill mappings:
[
  {
    "sample_intent": "Generate an image of a sunset",
    "keywords": ["generate", "image", "sunset"],
    "confidence": 0.95,
    "category": "creative"
  },
  {
    "sample_intent": "Schedule a meeting for tomorrow",
    "keywords": ["schedule", "meeting", "tomorrow"],
    "confidence": 0.90,
    "category": "scheduling"
  }
]
JSON;

            // Act
            $result = $this->service->parseClassificationResponse($response, $skill->id);

            // Assert
            expect($result)->toBeInstanceOf(\App\TypedCollections\IntentMappingDTOCollection::class)
                ->toHaveCount(2)
                ->and($result[0]->sampleIntent)->toBe('Generate an image of a sunset')
                ->and($result[0]->skillId)->toBe($skill->id)
                ->and($result[1]->skillId)->toBe($skill->id);
        });

        it('returns empty collection for invalid JSON', function () {
            // Arrange
            $response = 'This is not valid JSON at all';

            // Act
            $result = $this->service->parseClassificationResponse($response);

            // Assert
            expect($result)->toBeInstanceOf(\App\TypedCollections\IntentMappingDTOCollection::class)
                ->toBeEmpty();
        });

        it('returns empty collection for JSON without array', function () {
            // Arrange
            $response = '{"not": "an array"}';

            // Act
            $result = $this->service->parseClassificationResponse($response);

            // Assert
            expect($result)->toBeInstanceOf(\App\TypedCollections\IntentMappingDTOCollection::class)
                ->toBeEmpty();
        });

        it('filters entries missing required fields', function () {
            // Arrange
            $response = <<<'JSON'
[
  {
    "sample_intent": "Valid entry",
    "keywords": ["valid"],
    "confidence": 0.9,
    "category": "creative"
  },
  {
    "sample_intent": "Also valid - keywords are optional",
    "confidence": 0.8
  },
  {
    "keywords": ["no intent"],
    "confidence": 0.7
  }
]
JSON;

            // Act
            $result = $this->service->parseClassificationResponse($response);

            // Assert - only the third entry (missing sample_intent) should be filtered
            expect($result)->toHaveCount(2)
                ->and($result[0]->sampleIntent)->toBe('Valid entry')
                ->and($result[1]->sampleIntent)->toBe('Also valid - keywords are optional')
                ->and($result[1]->keywords)->toBe([]);
        });

        it('applies default values for missing optional fields', function () {
            // Arrange
            $response = <<<'JSON'
[
  {
    "sample_intent": "Minimal entry"
  }
]
JSON;

            // Act
            $result = $this->service->parseClassificationResponse($response);

            // Assert
            expect($result)->toHaveCount(1)
                ->and($result[0]->keywords)->toBe([])
                ->and($result[0]->confidence)->toBe(0.8)
                ->and($result[0]->category)->toBe('unknown');
        });
    });

    describe('storeMappings', function () {
        it('stores mappings in database', function () {
            // Arrange
            $skill1 = Skill::create([
                'name' => 'imagegen',
                'dir_name' => 'imagegen',
                'path' => '/tmp/skills/imagegen',
                'source_type' => 'core',
                'description' => 'Generate images',
                'checksum' => 'abc123',
            ]);

            $skill2 = Skill::create([
                'name' => 'schedule',
                'dir_name' => 'schedule',
                'path' => '/tmp/skills/schedule',
                'source_type' => 'core',
                'description' => 'Schedule tasks',
                'checksum' => 'def456',
            ]);

            $mappings = new \App\TypedCollections\IntentMappingDTOCollection([
                new \App\DTOs\IntentMappingDTO(
                    sampleIntent: 'Generate an image',
                    keywords: ['generate', 'image'],
                    skillId: $skill1->id,
                    confidence: 0.95,
                    category: 'creative',
                ),
                new \App\DTOs\IntentMappingDTO(
                    sampleIntent: 'Schedule a reminder',
                    keywords: ['schedule', 'reminder'],
                    skillId: $skill2->id,
                    confidence: 0.90,
                    category: 'scheduling',
                ),
            ]);

            // Act
            $stored = $this->service->storeMappings($mappings);

            // Assert
            expect($stored)->toBe(2)
                ->and(SkillMatch::count())->toBe(2);
        });

        it('stores mapping with correct data', function () {
            // Arrange
            $skill = Skill::create([
                'name' => 'agent-browser',
                'dir_name' => 'agent-browser',
                'path' => '/tmp/skills/agent-browser',
                'source_type' => 'core',
                'description' => 'Browse websites',
                'checksum' => 'abc123',
            ]);

            $mappings = new \App\TypedCollections\IntentMappingDTOCollection([
                new \App\DTOs\IntentMappingDTO(
                    sampleIntent: 'Open a website',
                    keywords: ['open', 'website', 'browser'],
                    skillId: $skill->id,
                    confidence: 0.92,
                    category: 'automation',
                ),
            ]);

            // Act
            $this->service->storeMappings($mappings);

            // Assert
            $match = SkillMatch::first();
            expect($match)->not->toBeNull()
                ->and($match->skill_id)->toBe($skill->id)
                ->and($match->skill->name)->toBe('agent-browser')
                ->and($match->confidence_score)->toBe(0.92)
                ->and($match->intent_category)->toBe('automation')
                ->and($match->intent_keywords)->toBe(['open', 'website', 'browser']);
        });

        it('handles empty mappings collection', function () {
            // Arrange
            $mappings = new \App\TypedCollections\IntentMappingDTOCollection([]);

            // Act
            $stored = $this->service->storeMappings($mappings);

            // Assert
            expect($stored)->toBe(0)
                ->and(SkillMatch::count())->toBe(0);
        });

        it('skips mappings without skill_id', function () {
            // Arrange
            $mappings = new \App\TypedCollections\IntentMappingDTOCollection([
                new \App\DTOs\IntentMappingDTO(
                    sampleIntent: 'No skill ID',
                    keywords: ['test'],
                    skillId: null,
                    confidence: 0.9,
                    category: 'test',
                ),
            ]);

            // Act
            $stored = $this->service->storeMappings($mappings);

            // Assert
            expect($stored)->toBe(0)
                ->and(SkillMatch::count())->toBe(0);
        });
    });

    describe('getClassificationModel', function () {
        it('returns fast model for known providers', function () {
            // Act & Assert
            expect($this->service->getClassificationModel('groq'))->toBe('llama-3.3-70b-versatile')
                ->and($this->service->getClassificationModel('openai'))->toBe('gpt-4o-mini')
                ->and($this->service->getClassificationModel('anthropic'))->toBe('claude-3-5-haiku-20241022')
                ->and($this->service->getClassificationModel('gemini'))->toBe('gemini-2.0-flash');
        });

        it('returns default model for unknown provider', function () {
            // Arrange - mock settings to return a default
            $defaultModel = $this->settings->getDefaultModel('unknown-provider');

            // Act
            $result = $this->service->getClassificationModel('unknown-provider');

            // Assert - should fall back to settings default
            expect($result)->toBeString();
        });
    });

    describe('setIntentsPerSkill', function () {
        it('sets the intents per skill count', function () {
            // Act
            $result = $this->service->setIntentsPerSkill(10);

            // Assert - should return self for chaining
            expect($result)->toBeInstanceOf(SkillClassificationService::class);
        });
    });

    describe('getCacheStatistics', function () {
        it('returns cache statistics', function () {
            // Arrange - add some test data
            $skill = Skill::create([
                'name' => 'test-skill',
                'dir_name' => 'test-skill',
                'path' => '/tmp/skills/test-skill',
                'source_type' => 'core',
                'description' => 'Test skill',
                'checksum' => 'abc123',
                'classification_status' => Skill::STATUS_CLASSIFIED,
            ]);

            $mapping = new \App\DTOs\IntentMappingDTO(
                sampleIntent: 'Test message',
                keywords: ['test', 'keywords'],
                skillId: $skill->id,
                confidence: 0.9,
                category: 'test',
            );
            SkillMatch::storeMatch($mapping);

            // Act
            $result = $this->service->getCacheStatistics();

            // Assert
            expect($result)->toBeInstanceOf(\App\DTOs\CacheStatsDTO::class)
                ->toHaveKeys(['totalEntries', 'totalHits', 'skillsCovered', 'skillsPending', 'skillsClassified', 'skillsFailed'])
                ->and($result->totalEntries)->toBeGreaterThanOrEqual(1);
        });

        it('returns zeros for empty cache', function () {
            // Arrange - ensure cache is empty
            SkillMatch::query()->delete();
            Skill::query()->delete();

            // Act
            $result = $this->service->getCacheStatistics();

            // Assert
            expect($result)->toBeInstanceOf(\App\DTOs\CacheStatsDTO::class)
                ->and($result->totalEntries)->toBe(0)
                ->and($result->totalHits)->toBe(0);
        });
    });

    describe('classifyAllSkills', function () {
        it('returns error result when no skills found', function () {
            // Arrange - mock empty skill index
            $mockSkillService = Mockery::mock(SkillSearchService::class);
            $mockSkillService->shouldReceive('indexSkills')
                ->once()
                ->andReturn(new ParsedSkillDTOCollection([]));

            $service = new SkillClassificationService($this->settings, $mockSkillService);

            // Act
            $result = $service->classifyAllSkills();

            // Assert
            expect($result)->toBeInstanceOf(\App\DTOs\SkillClassificationResultDTO::class)
                ->toHaveKeys(['skillsProcessed', 'skillsSkipped', 'mappingsGenerated', 'mappingsStored', 'errors'])
                ->and($result->skillsProcessed)->toBe(0)
                ->and($result->mappingsGenerated)->toBe(0)
                ->and($result->errors)->toContain('No skills found');
        });

        it('clears existing mappings when clearExisting is true', function () {
            // Arrange - add existing skill and mapping
            $skill = Skill::create([
                'name' => 'existing-skill',
                'dir_name' => 'existing-skill',
                'path' => '/tmp/skills/existing-skill',
                'source_type' => 'core',
                'description' => 'Existing skill',
                'checksum' => 'abc123',
                'classification_status' => Skill::STATUS_CLASSIFIED,
            ]);

            $mapping = new \App\DTOs\IntentMappingDTO(
                sampleIntent: 'Existing mapping',
                keywords: ['existing'],
                skillId: $skill->id,
                confidence: 0.8,
                category: 'test',
            );
            SkillMatch::storeMatch($mapping);

            expect(SkillMatch::count())->toBe(1);

            // Mock skill service to return empty to avoid LLM call
            $mockSkillService = Mockery::mock(SkillSearchService::class);
            $mockSkillService->shouldReceive('indexSkills')
                ->once()
                ->andReturn(new ParsedSkillDTOCollection([]));

            $service = new SkillClassificationService($this->settings, $mockSkillService);

            // Act
            $service->classifyAllSkills(clearExisting: true);

            // Assert - cache should be cleared
            expect(SkillMatch::count())->toBe(0);
        });

        it('resets classification status when clearExisting is true', function () {
            // Arrange
            $skill = Skill::create([
                'name' => 'classified-skill',
                'dir_name' => 'classified-skill',
                'path' => '/tmp/skills/classified-skill',
                'source_type' => 'core',
                'description' => 'Classified skill',
                'checksum' => 'abc123',
                'classification_status' => Skill::STATUS_CLASSIFIED,
            ]);

            // Mock skill service to return empty
            $mockSkillService = Mockery::mock(SkillSearchService::class);
            $mockSkillService->shouldReceive('indexSkills')
                ->once()
                ->andReturn(new ParsedSkillDTOCollection([]));

            $service = new SkillClassificationService($this->settings, $mockSkillService);

            // Act
            $service->classifyAllSkills(clearExisting: true);

            // Assert
            $skill->refresh();
            expect($skill->classification_status)->toBe(Skill::STATUS_PENDING);
        });

        it('skips already classified skills with same checksum', function () {
            // Arrange - create a temp skill directory so checksum can be calculated
            $tempDir = sys_get_temp_dir().'/skill_skip_test_'.uniqid();
            mkdir($tempDir);
            file_put_contents($tempDir.'/SKILL.md', '---\nname: classified-skill\n---\nTest skill content');

            // Calculate the actual checksum
            $actualChecksum = Skill::calculateChecksum($tempDir);

            // Create a classified skill with the same checksum
            Skill::create([
                'name' => 'classified-skill',
                'dir_name' => 'classified-skill',
                'path' => $tempDir,
                'source_type' => 'core',
                'description' => 'Classified skill',
                'checksum' => $actualChecksum,
                'classification_status' => Skill::STATUS_CLASSIFIED,
                'intents_count' => 5,
            ]);

            // Mock skill service to return the same skill
            $mockSkillService = Mockery::mock(SkillSearchService::class);
            $mockSkillService->shouldReceive('indexSkills')
                ->once()
                ->andReturn(new ParsedSkillDTOCollection([
                    new ParsedSkillDTO(
                        name: 'classified-skill',
                        dirName: 'classified-skill',
                        description: 'Classified skill',
                        path: $tempDir.'/SKILL.md',
                        directory: $tempDir,
                        keywords: [],
                        hasScripts: false,
                        hasReferences: false,
                        hasAssets: false,
                    ),
                ]));

            $service = new SkillClassificationService($this->settings, $mockSkillService);

            // Act
            $result = $service->classifyAllSkills();

            // Assert - skill should be skipped (no LLM call)
            expect($result->skillsProcessed)->toBe(0)
                ->and($result->skillsSkipped)->toBe(1);

            // Cleanup
            unlink($tempDir.'/SKILL.md');
            rmdir($tempDir);
        });
    });

    describe('buildSkillDetails (via ClassificationPromptBuilder)', function () {
        it('builds details from mappings', function () {
            // Arrange
            $promptBuilder = $this->service->getPromptBuilder();
            $mappings = new \App\TypedCollections\IntentMappingDTOCollection([
                new \App\DTOs\IntentMappingDTO(
                    sampleIntent: 'Generate an image',
                    keywords: ['generate', 'image'],
                    skillId: null,
                    confidence: 0.9,
                    category: 'creative',
                ),
                new \App\DTOs\IntentMappingDTO(
                    sampleIntent: 'Create a picture',
                    keywords: ['create', 'picture'],
                    skillId: null,
                    confidence: 0.9,
                    category: 'creative',
                ),
            ]);

            // Act
            $result = $promptBuilder->buildSkillDetails($mappings);

            // Assert
            expect($result)->toBeArray()
                ->toHaveKeys(['intents', 'keywords'])
                ->and($result['intents'])->toHaveCount(2)
                ->and($result['keywords'])->toHaveCount(4);
        });
    });
});
