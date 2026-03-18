<?php

use App\Services\Skills\SkillFileParser;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->parser = new SkillFileParser;
});

describe('SkillFileParser', function () {
    describe('parse', function () {
        it('parses valid SKILL.md file with frontmatter', function () {
            // Arrange
            $content = <<<'MD'
---
name: test-skill
description: A test skill for unit testing
license: MIT
---

# Test Skill

This is the body of the skill file.
It contains useful keywords like generate and create.
MD;
            $tempDir = sys_get_temp_dir().'/skill_parser_test_'.uniqid();
            mkdir($tempDir, 0755, true);
            $tempFile = $tempDir.'/SKILL.md';
            File::put($tempFile, $content);

            // Act
            $result = $this->parser->parse($tempFile);

            // Assert
            expect($result)->toBeInstanceOf(\App\DTOs\ParsedSkillDTO::class)
                ->and($result->name)->toBe('test-skill')
                ->and($result->description)->toBe('A test skill for unit testing')
                ->and($result->license)->toBe('MIT')
                ->and($result->path)->toBe($tempFile)
                ->and($result->dirName)->toBe(basename($tempDir))
                ->and($result->keywords)->toBeArray()
                ->and($result->hasScripts)->toBeFalse()
                ->and($result->hasReferences)->toBeFalse()
                ->and($result->hasAssets)->toBeFalse();

            // Cleanup
            File::deleteDirectory($tempDir);
        });

        it('returns null for file without frontmatter', function () {
            $tempFile = tempnam(sys_get_temp_dir(), 'skill_test');
            File::put($tempFile, 'No frontmatter here');

            $result = $this->parser->parse($tempFile);

            expect($result)->toBeNull();

            File::delete($tempFile);
        });

        it('returns null for file missing required name field', function () {
            $content = <<<'MD'
---
description: Has description but no name
---

Body content
MD;
            $tempFile = tempnam(sys_get_temp_dir(), 'skill_test');
            File::put($tempFile, $content);

            $result = $this->parser->parse($tempFile);

            expect($result)->toBeNull();

            File::delete($tempFile);
        });

        it('returns null for file missing required description field', function () {
            $content = <<<'MD'
---
name: test-skill
---

Body with no description
MD;
            $tempFile = tempnam(sys_get_temp_dir(), 'skill_test');
            File::put($tempFile, $content);

            $result = $this->parser->parse($tempFile);

            expect($result)->toBeNull();

            File::delete($tempFile);
        });

        it('detects scripts subdirectory', function () {
            $tempDir = sys_get_temp_dir().'/skill_parser_scripts_'.uniqid();
            mkdir($tempDir.'/scripts', 0755, true);
            $content = <<<'MD'
---
name: scripted-skill
description: A skill with scripts
---

Body
MD;
            File::put($tempDir.'/SKILL.md', $content);

            $result = $this->parser->parse($tempDir.'/SKILL.md');

            expect($result->hasScripts)->toBeTrue();

            File::deleteDirectory($tempDir);
        });

        it('detects references subdirectory', function () {
            $tempDir = sys_get_temp_dir().'/skill_parser_refs_'.uniqid();
            mkdir($tempDir.'/references', 0755, true);
            $content = <<<'MD'
---
name: ref-skill
description: A skill with references
---

Body
MD;
            File::put($tempDir.'/SKILL.md', $content);

            $result = $this->parser->parse($tempDir.'/SKILL.md');

            expect($result->hasReferences)->toBeTrue();

            File::deleteDirectory($tempDir);
        });

        it('extracts keywords from description and body', function () {
            $content = <<<'MD'
---
name: keyword-skill
description: Generate beautiful images using advanced AI
---

This skill creates stunning artwork and photographs.
MD;
            $tempDir = sys_get_temp_dir().'/skill_parser_kw_'.uniqid();
            mkdir($tempDir, 0755, true);
            File::put($tempDir.'/SKILL.md', $content);

            $result = $this->parser->parse($tempDir.'/SKILL.md');

            expect($result->keywords)->toBeArray()
                ->toContain('generate')
                ->toContain('beautiful')
                ->toContain('images');

            File::deleteDirectory($tempDir);
        });

        it('strips quotes from frontmatter values', function () {
            $content = <<<'MD'
---
name: "quoted-skill"
description: 'A quoted description'
---

Body
MD;
            $tempDir = sys_get_temp_dir().'/skill_parser_quote_'.uniqid();
            mkdir($tempDir, 0755, true);
            File::put($tempDir.'/SKILL.md', $content);

            $result = $this->parser->parse($tempDir.'/SKILL.md');

            expect($result->name)->toBe('quoted-skill')
                ->and($result->description)->toBe('A quoted description');

            File::deleteDirectory($tempDir);
        });
    });
});
