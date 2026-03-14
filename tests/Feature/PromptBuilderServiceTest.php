<?php

use App\DTOs\EpisodicEventDTO;
use App\Enums\ChannelEnum;
use App\Enums\EpisodicEventTypeEnum;
use App\Services\PromptBuilderService;
use App\Services\SkillSearchService;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->service = app(PromptBuilderService::class);
    $this->tempDir = sys_get_temp_dir().'/laraclaw_test_'.uniqid();

    // Create temp directory structure
    File::makeDirectory($this->tempDir.'/.laraclaw', 0755, true);
});

afterEach(function () {
    // Clean up temp directory
    if (File::isDirectory($this->tempDir)) {
        File::deleteDirectory($this->tempDir);
    }
});

describe('PromptBuilderService', function () {
    describe('loadFile', function () {
        it('returns null for nonexistent file', function () {
            $result = $this->service->loadFile('/nonexistent/path/file.md');

            expect($result)->toBeNull();
        });

        it('loads existing file', function () {
            $filePath = $this->tempDir.'/test.md';
            File::put($filePath, 'Hello World');

            $result = $this->service->loadFile($filePath);

            expect($result)->toBe('Hello World');
        });

        it('returns null for empty file', function () {
            $filePath = $this->tempDir.'/empty.md';
            File::put($filePath, '');

            $result = $this->service->loadFile($filePath);

            expect($result)->toBeNull();
        });
    });

    describe('buildSystemPrompt', function () {
        it('returns empty string when no files exist', function () {
            $result = $this->service->buildSystemPrompt($this->tempDir);

            expect($result)->toBe('');
        });

        it('includes AGENTS.md in prompt', function () {
            File::put($this->tempDir.'/AGENTS.md', '# Agent Instructions');

            $result = $this->service->buildSystemPrompt($this->tempDir);

            expect($result)->toContain('# Agent Instructions');
        });

        it('includes SOUL.md in prompt', function () {
            File::put($this->tempDir.'/.laraclaw/SOUL.md', '# My Identity');

            $result = $this->service->buildSystemPrompt($this->tempDir);

            expect($result)->toContain('# My Identity')
                ->and($result)->toContain('# Your Identity');
        });

        it('includes custom prompt', function () {
            $result = $this->service->buildSystemPrompt($this->tempDir, [
                'custom_prompt' => 'Be helpful and concise.',
            ]);

            expect($result)->toContain('Be helpful and concise.')
                ->and($result)->toContain('# Additional Instructions');
        });

        it('combines all sections with separators', function () {
            File::put($this->tempDir.'/AGENTS.md', '# Instructions');
            File::put($this->tempDir.'/.laraclaw/SOUL.md', '# Identity');

            $result = $this->service->buildSystemPrompt($this->tempDir, [
                'custom_prompt' => 'Custom text',
            ]);

            expect($result)->toContain('# Instructions')
                ->and($result)->toContain('# Identity')
                ->and($result)->toContain('Custom text')
                ->and($result)->toContain(str_repeat('─', 40));
        });
    });

    describe('teammate injection', function () {
        it('injects teammate info into markers', function () {
            $agentsContent = "# Agents\n\n<!-- TEAMMATES_START -->\n<!-- TEAMMATES_END -->\n\nOther content";
            File::put($this->tempDir.'/AGENTS.md', $agentsContent);

            $result = $this->service->buildSystemPrompt($this->tempDir, [
                'agent_id' => 'coder',
                'agent_name' => 'Code Assistant',
                'agent_model' => 'claude-3-sonnet',
                'teammates' => [
                    ['id' => 'reviewer', 'name' => 'Code Reviewer', 'model' => 'claude-3-opus'],
                ],
            ]);

            expect($result)->toContain('### You')
                ->and($result)->toContain('@coder')
                ->and($result)->toContain('Code Assistant')
                ->and($result)->toContain('### Your Teammates')
                ->and($result)->toContain('@reviewer')
                ->and($result)->toContain('Code Reviewer');
        });

        it('handles no teammates gracefully', function () {
            $agentsContent = "# Agents\n\n<!-- TEAMMATES_START -->\n<!-- TEAMMATES_END -->\n\nOther content";
            File::put($this->tempDir.'/AGENTS.md', $agentsContent);

            $result = $this->service->buildSystemPrompt($this->tempDir, [
                'agent_id' => 'coder',
                'agent_name' => 'Code Assistant',
                'agent_model' => 'claude-3-sonnet',
            ]);

            expect($result)->toContain('### You')
                ->and($result)->toContain('@coder')
                ->and($result)->not->toContain('### Your Teammates');
        });

        it('handles missing markers gracefully', function () {
            $agentsContent = "# Agents\n\nNo markers here\n\nOther content";
            File::put($this->tempDir.'/AGENTS.md', $agentsContent);

            // Should not throw and should return content as-is
            $result = $this->service->buildSystemPrompt($this->tempDir, [
                'agent_id' => 'coder',
                'teammates' => [
                    ['id' => 'reviewer', 'name' => 'Reviewer', 'model' => 'opus'],
                ],
            ]);

            expect($result)->toContain('No markers here')
                // Teammate info should NOT be injected since markers are missing
                ->and($result)->not->toContain('@reviewer');
        });
    });

    describe('caching', function () {
        it('caches built prompts', function () {
            File::put($this->tempDir.'/AGENTS.md', 'Original content');

            // First call - should cache
            $result1 = $this->service->buildSystemPromptCached($this->tempDir);

            // Modify file
            File::put($this->tempDir.'/AGENTS.md', 'Modified content');

            // Second call - should return cached version
            $result2 = $this->service->buildSystemPromptCached($this->tempDir);

            expect($result1)->toBe($result2)
                ->and($result2)->toContain('Original content');
        });

        it('clears cache', function () {
            File::put($this->tempDir.'/AGENTS.md', 'Original content');

            // First call - should cache
            $result1 = $this->service->buildSystemPromptCached($this->tempDir);

            // Clear cache
            $this->service->clearCache($this->tempDir);

            // Modify file
            File::put($this->tempDir.'/AGENTS.md', 'Modified content');

            // Second call - should rebuild
            $result2 = $this->service->buildSystemPromptCached($this->tempDir);

            expect($result1)->not->toBe($result2)
                ->and($result2)->toContain('Modified content');
        });
    });

    describe('skill service', function () {
        it('can set skill service', function () {
            $skillService = app(SkillSearchService::class);

            $this->service->setSkillService($skillService);

            // Verify no exception is thrown
            expect(true)->toBeTrue();
        });

        it('includes skills section when skill service is set', function () {
            $skillService = app(SkillSearchService::class);
            $this->service->setSkillService($skillService);

            File::put($this->tempDir.'/AGENTS.md', '# Instructions');

            $result = $this->service->buildSystemPrompt($this->tempDir);

            // Should contain skills section header
            expect($result)->toContain('# Available Skills')
                ->and($result)->toContain('```execute:');
        });

        it('does not include skills section when skill service is not set', function () {
            File::put($this->tempDir.'/AGENTS.md', '# Instructions');

            $result = $this->service->buildSystemPrompt($this->tempDir);

            // Should NOT contain skills section
            expect($result)->not->toContain('# Available Skills');
        });
    });

    describe('template loading', function () {
        beforeEach(function () {
            // Set up skill service for template tests
            $skillService = app(SkillSearchService::class);
            $this->service->setSkillService($skillService);

            // Create temp storage and resources directories for template testing
            $this->storageClawDir = sys_get_temp_dir().'/laraclaw_storage_'.uniqid().'/claw';
            $this->resourcesClawDir = sys_get_temp_dir().'/laraclaw_resources_'.uniqid().'/claw';

            File::makeDirectory($this->storageClawDir, 0755, true);
            File::makeDirectory($this->resourcesClawDir, 0755, true);

            // Set custom template paths for testing
            $this->service->setTemplatePaths($this->storageClawDir, $this->resourcesClawDir);
        });

        afterEach(function () {
            // Clean up template directories
            if (File::isDirectory($this->storageClawDir)) {
                File::deleteDirectory(dirname($this->storageClawDir));
            }
            if (File::isDirectory($this->resourcesClawDir)) {
                File::deleteDirectory(dirname($this->resourcesClawDir));
            }
        });

        it('loads template from user customizations (storage) when available', function () {
            // Create user-customized template
            $userTemplate = "# Custom Skills\n\nUser customized template content\n\n{{skills_list}}";
            File::put($this->storageClawDir.'/skill-instructions.md', $userTemplate);

            // Create factory default template (should be ignored when user template exists)
            $factoryTemplate = "# Factory Skills\n\nFactory default content\n\n{{skills_list}}";
            File::put($this->resourcesClawDir.'/skill-instructions.md', $factoryTemplate);

            File::put($this->tempDir.'/AGENTS.md', '# Instructions');

            $result = $this->service->buildSystemPrompt($this->tempDir);

            // Should use user template, not factory
            expect($result)->toContain('User customized template content')
                ->and($result)->not->toContain('Factory default content');
        });

        it('falls back to factory defaults (resources) when user template missing', function () {
            // Only create factory default template (no user template)
            $factoryTemplate = "# Factory Skills\n\nFactory default content\n\n{{skills_list}}";
            File::put($this->resourcesClawDir.'/skill-instructions.md', $factoryTemplate);

            File::put($this->tempDir.'/AGENTS.md', '# Instructions');

            $result = $this->service->buildSystemPrompt($this->tempDir);

            // Should use factory template
            expect($result)->toContain('Factory default content');
        });

        it('uses hardcoded fallback when no templates exist', function () {
            // No templates in either location
            File::put($this->tempDir.'/AGENTS.md', '# Instructions');

            $result = $this->service->buildSystemPrompt($this->tempDir);

            // Should still have skills section from hardcoded fallback
            expect($result)->toContain('# Available Skills')
                ->and($result)->toContain('Few-Shot Examples');
        });

        it('replaces skills_list placeholder with actual skills', function () {
            // Create template with placeholder
            $template = "# Skills\n\n{{skills_list}}\n\nEnd of skills";
            File::put($this->storageClawDir.'/skill-instructions.md', $template);

            File::put($this->tempDir.'/AGENTS.md', '# Instructions');

            $result = $this->service->buildSystemPrompt($this->tempDir);

            // Placeholder should be replaced
            expect($result)->not->toContain('{{skills_list}}')
                ->and($result)->toContain('End of skills');
        });
    });

    describe('memory service integration', function () {
        it('can set memory service', function () {
            $memoryService = app(\App\Services\MemoryEngineService::class);

            $this->service->setMemoryService($memoryService);

            // Verify no exception is thrown
            expect(true)->toBeTrue();
        });

        it('includes memory context when memory service is set with sender_id and channel', function () {
            $memoryService = app(\App\Services\MemoryEngineService::class);
            $this->service->setMemoryService($memoryService);

            File::put($this->tempDir.'/AGENTS.md', '# Instructions');

            // Create a memory entry for the test user with high importance
            $senderId = 'test-sender-123';
            $channel = ChannelEnum::TELEGRAM;
            $memoryService->recordEvent($senderId, $channel, new EpisodicEventDTO(
                type: EpisodicEventTypeEnum::CORRECTION,
                content: 'User prefers dark mode',
                importance: 0.9,
            ));

            $result = $this->service->buildSystemPrompt($this->tempDir, [
                'sender_id' => $senderId,
                'channel' => $channel,
                'message' => 'What are my preferences?',
            ]);

            // Should contain memory section (either Relevant Memories or Important Context)
            $containsMemory = str_contains($result, '## Relevant Memories') || str_contains($result, '## Important Context');
            expect($containsMemory)->toBeTrue();

            // Cleanup
            \App\Models\Memory::forSender($senderId, $channel)->delete();
        });

        it('does not include memory section when memory service is not set', function () {
            File::put($this->tempDir.'/AGENTS.md', '# Instructions');

            $result = $this->service->buildSystemPrompt($this->tempDir, [
                'sender_id' => 'test-sender',
                'channel' => \App\Enums\ChannelEnum::TELEGRAM,
            ]);

            // Should NOT contain memory section
            expect($result)->not->toContain('## Relevant Memories')
                ->and($result)->not->toContain('## Important Context');
        });

        it('does not include memory section when sender_id is missing', function () {
            $memoryService = app(\App\Services\MemoryEngineService::class);
            $this->service->setMemoryService($memoryService);

            File::put($this->tempDir.'/AGENTS.md', '# Instructions');

            $result = $this->service->buildSystemPrompt($this->tempDir, [
                'channel' => \App\Enums\ChannelEnum::TELEGRAM,
            ]);

            // Should NOT contain memory section
            expect($result)->not->toContain('## Relevant Memories');
        });

        it('does not include memory section when channel is missing', function () {
            $memoryService = app(\App\Services\MemoryEngineService::class);
            $this->service->setMemoryService($memoryService);

            File::put($this->tempDir.'/AGENTS.md', '# Instructions');

            $result = $this->service->buildSystemPrompt($this->tempDir, [
                'sender_id' => 'test-sender',
            ]);

            // Should NOT contain memory section
            expect($result)->not->toContain('## Relevant Memories');
        });

        it('does not include memory section when no memories exist', function () {
            $memoryService = app(\App\Services\MemoryEngineService::class);
            $this->service->setMemoryService($memoryService);

            File::put($this->tempDir.'/AGENTS.md', '# Instructions');

            $result = $this->service->buildSystemPrompt($this->tempDir, [
                'sender_id' => 'nonexistent-user',
                'channel' => \App\Enums\ChannelEnum::TELEGRAM,
                'message' => 'Hello',
            ]);

            // Should NOT contain memory section when no memories
            expect($result)->not->toContain('## Relevant Memories')
                ->and($result)->not->toContain('## Important Context');
        });
    });
});
