<?php

use App\DTOs\IntentClassificationDTO;
use App\DTOs\SkillDiscoveryResultDTO;
use App\Services\SettingsService;
use App\Services\SkillAutoDiscoveryService;
use App\Services\SkillClassificationService;
use App\Services\SkillSearchService;
use Illuminate\Support\Facades\Cache;
use Mockery;

beforeEach(function () {
    $this->settings = Mockery::mock(SettingsService::class);
    $this->skillService = Mockery::mock(SkillSearchService::class);
    $this->classificationService = Mockery::mock(SkillClassificationService::class);

    $this->service = new SkillAutoDiscoveryService(
        $this->settings,
        $this->skillService,
        $this->classificationService
    );
});

afterEach(function () {
    Mockery::close();
    Cache::flush();
});

// ============================================
// Service Instantiation Tests
// ============================================

it('can be instantiated', function () {
    expect($this->service)->toBeInstanceOf(SkillAutoDiscoveryService::class);
});

// ============================================
// detectAndHandle Tests - Null Cases
// ============================================

it('returns null when no skill gap detected due to high confidence', function () {
    $classification = new IntentClassificationDTO(
        intent: 'chat',
        confidence: 0.9,
        matchedSkill: 'existing-skill',
        skillConfidence: 0.9,
        entities: [],
        keywords: []
    );

    $result = $this->service->detectAndHandle('Hello there', $classification);

    expect($result)->toBeNull();
});

it('returns null when no skill gap detected due to non-action intent', function () {
    $classification = new IntentClassificationDTO(
        intent: 'question',
        confidence: 0.2,
        matchedSkill: null,
        skillConfidence: 0.2,
        entities: [],
        keywords: []
    );

    $result = $this->service->detectAndHandle('What is the weather?', $classification);

    expect($result)->toBeNull();
});

// ============================================
// Pending Discovery Tests
// ============================================

it('stores and retrieves pending discoveries', function () {
    $result = new SkillDiscoveryResultDTO(
        searchTerm: 'imagegen',
        matches: [
            ['name' => 'imagegen', 'description' => 'Generate images', 'owner' => 'laraclaw', 'repo' => 'skills'],
        ],
        autoInstallEnabled: false,
        autoInstallMode: 'prompt'
    );

    $this->service->storePendingDiscovery('user123', $result, 'Generate an image', 'agent1');

    $pending = $this->service->getPendingDiscovery('user123');

    expect($pending)->not->toBeNull();
    expect($pending['original_message'])->toBe('Generate an image');
    expect($pending['agent_id'])->toBe('agent1');
    expect($pending['result'])->toBeInstanceOf(SkillDiscoveryResultDTO::class);
});

it('clears pending discoveries', function () {
    $result = new SkillDiscoveryResultDTO(
        searchTerm: 'test',
        matches: [],
        autoInstallEnabled: false
    );

    $this->service->storePendingDiscovery('user123', $result, 'Test message', 'agent1');
    $this->service->clearPendingDiscovery('user123');

    $pending = $this->service->getPendingDiscovery('user123');

    expect($pending)->toBeNull();
});

it('returns null for non-existent pending discovery', function () {
    $pending = $this->service->getPendingDiscovery('nonexistent');

    expect($pending)->toBeNull();
});

// ============================================
// parseDiscoveryResponse Tests
// ============================================

it('parses numeric selection responses', function () {
    $result = new SkillDiscoveryResultDTO(
        searchTerm: 'test',
        matches: [
            ['name' => 'skill1', 'description' => 'First skill', 'owner' => 'owner', 'repo' => 'repo'],
            ['name' => 'skill2', 'description' => 'Second skill', 'owner' => 'owner', 'repo' => 'repo'],
        ],
        autoInstallEnabled: false
    );

    $this->service->storePendingDiscovery('user123', $result, 'Test', 'agent1');

    $response = $this->service->parseDiscoveryResponse('1', 'user123');

    expect($response['is_selection'])->toBeTrue();
    expect($response['index'])->toBe(0);
    expect($response['skip'])->toBeFalse();
});

it('parses second skill selection', function () {
    $result = new SkillDiscoveryResultDTO(
        searchTerm: 'test',
        matches: [
            ['name' => 'skill1', 'description' => 'First skill', 'owner' => 'owner', 'repo' => 'repo'],
            ['name' => 'skill2', 'description' => 'Second skill', 'owner' => 'owner', 'repo' => 'repo'],
        ],
        autoInstallEnabled: false
    );

    $this->service->storePendingDiscovery('user123', $result, 'Test', 'agent1');

    $response = $this->service->parseDiscoveryResponse('2', 'user123');

    expect($response['is_selection'])->toBeTrue();
    expect($response['index'])->toBe(1);
    expect($response['skip'])->toBeFalse();
});

it('parses skip responses', function () {
    $result = new SkillDiscoveryResultDTO(
        searchTerm: 'test',
        matches: [
            ['name' => 'skill1', 'description' => 'First skill', 'owner' => 'owner', 'repo' => 'repo'],
        ],
        autoInstallEnabled: false
    );

    $this->service->storePendingDiscovery('user123', $result, 'Test', 'agent1');

    $response = $this->service->parseDiscoveryResponse('skip', 'user123');

    expect($response['is_selection'])->toBeTrue();
    expect($response['skip'])->toBeTrue();
});

it('parses cancel responses', function () {
    $result = new SkillDiscoveryResultDTO(
        searchTerm: 'test',
        matches: [
            ['name' => 'skill1', 'description' => 'First skill', 'owner' => 'owner', 'repo' => 'repo'],
        ],
        autoInstallEnabled: false
    );

    $this->service->storePendingDiscovery('user123', $result, 'Test', 'agent1');

    $response = $this->service->parseDiscoveryResponse('cancel', 'user123');

    expect($response['is_selection'])->toBeTrue();
    expect($response['skip'])->toBeTrue();
});

it('parses no responses', function () {
    $result = new SkillDiscoveryResultDTO(
        searchTerm: 'test',
        matches: [
            ['name' => 'skill1', 'description' => 'First skill', 'owner' => 'owner', 'repo' => 'repo'],
        ],
        autoInstallEnabled: false
    );

    $this->service->storePendingDiscovery('user123', $result, 'Test', 'agent1');

    $response = $this->service->parseDiscoveryResponse('no', 'user123');

    expect($response['is_selection'])->toBeTrue();
    expect($response['skip'])->toBeTrue();
});

it('returns not a selection for invalid responses', function () {
    $result = new SkillDiscoveryResultDTO(
        searchTerm: 'test',
        matches: [
            ['name' => 'skill1', 'description' => 'First skill', 'owner' => 'owner', 'repo' => 'repo'],
        ],
        autoInstallEnabled: false
    );

    $this->service->storePendingDiscovery('user123', $result, 'Test', 'agent1');

    $response = $this->service->parseDiscoveryResponse('hello world', 'user123');

    expect($response['is_selection'])->toBeFalse();
});

it('returns not a selection for out of range numbers', function () {
    $result = new SkillDiscoveryResultDTO(
        searchTerm: 'test',
        matches: [
            ['name' => 'skill1', 'description' => 'First skill', 'owner' => 'owner', 'repo' => 'repo'],
        ],
        autoInstallEnabled: false
    );

    $this->service->storePendingDiscovery('user123', $result, 'Test', 'agent1');

    $response = $this->service->parseDiscoveryResponse('5', 'user123');

    expect($response['is_selection'])->toBeFalse();
});

it('returns not a selection for zero', function () {
    $result = new SkillDiscoveryResultDTO(
        searchTerm: 'test',
        matches: [
            ['name' => 'skill1', 'description' => 'First skill', 'owner' => 'owner', 'repo' => 'repo'],
        ],
        autoInstallEnabled: false
    );

    $this->service->storePendingDiscovery('user123', $result, 'Test', 'agent1');

    $response = $this->service->parseDiscoveryResponse('0', 'user123');

    expect($response['is_selection'])->toBeFalse();
});

it('returns not a selection when no pending discovery exists', function () {
    $response = $this->service->parseDiscoveryResponse('1', 'nonexistent');

    expect($response['is_selection'])->toBeFalse();
});

// ============================================
// SkillDiscoveryResultDTO Tests
// ============================================

it('formats prompt message correctly', function () {
    $result = new SkillDiscoveryResultDTO(
        searchTerm: 'imagegen',
        matches: [
            ['name' => 'imagegen', 'description' => 'Generate images', 'owner' => 'laraclaw', 'repo' => 'skills', 'installs' => 100],
            ['name' => 'imagegen-pro', 'description' => 'Pro image generation', 'owner' => 'other', 'repo' => 'skills', 'installs' => 50],
        ],
        autoInstallEnabled: false
    );

    $message = $result->formatPromptMessage();

    expect($message)->toContain('I found 2 skill(s)');
    expect($message)->toContain('imagegen');
    expect($message)->toContain('100 installs');
    expect($message)->toContain('Reply with a number');
});

it('formats prompt message with single skill', function () {
    $result = new SkillDiscoveryResultDTO(
        searchTerm: 'test',
        matches: [
            ['name' => 'skill', 'description' => 'Test skill', 'owner' => 'owner', 'repo' => 'repo'],
        ],
        autoInstallEnabled: false
    );

    $message = $result->formatPromptMessage();

    expect($message)->toContain('I found 1 skill(s)');
});

it('generates correct install command', function () {
    $result = new SkillDiscoveryResultDTO(
        searchTerm: 'test',
        matches: [
            ['name' => 'myskill', 'description' => 'Test', 'owner' => 'myorg', 'repo' => 'myrepo'],
        ],
        autoInstallEnabled: false
    );

    expect($result->getInstallCommand(0))->toBe('myorg/myrepo@myskill');
});

it('returns null for invalid install command index', function () {
    $result = new SkillDiscoveryResultDTO(
        searchTerm: 'test',
        matches: [
            ['name' => 'skill', 'description' => 'Test', 'owner' => 'o', 'repo' => 'r'],
        ],
        autoInstallEnabled: false
    );

    expect($result->getInstallCommand(5))->toBeNull();
});

it('returns null for negative install command index', function () {
    $result = new SkillDiscoveryResultDTO(
        searchTerm: 'test',
        matches: [
            ['name' => 'skill', 'description' => 'Test', 'owner' => 'o', 'repo' => 'r'],
        ],
        autoInstallEnabled: false
    );

    expect($result->getInstallCommand(-1))->toBeNull();
});

it('detects when auto-install should happen for single match', function () {
    $single = new SkillDiscoveryResultDTO(
        searchTerm: 'test',
        matches: [
            ['name' => 'skill', 'description' => 'Test', 'owner' => 'o', 'repo' => 'r'],
        ],
        autoInstallEnabled: true
    );

    expect($single->shouldAutoInstallSingle())->toBeTrue();
});

it('detects when auto-install should happen for first mode', function () {
    $multiple = new SkillDiscoveryResultDTO(
        searchTerm: 'test',
        matches: [
            ['name' => 'skill1', 'description' => 'Test', 'owner' => 'o', 'repo' => 'r'],
            ['name' => 'skill2', 'description' => 'Test', 'owner' => 'o', 'repo' => 'r'],
        ],
        autoInstallEnabled: true,
        autoInstallMode: 'first'
    );

    expect($multiple->shouldAutoInstallFirst())->toBeTrue();
    expect($multiple->shouldAutoInstallSingle())->toBeFalse();
});

it('does not auto-install when disabled', function () {
    $result = new SkillDiscoveryResultDTO(
        searchTerm: 'test',
        matches: [
            ['name' => 'skill', 'description' => 'Test', 'owner' => 'o', 'repo' => 'r'],
        ],
        autoInstallEnabled: false
    );

    expect($result->shouldAutoInstallSingle())->toBeFalse();
    expect($result->shouldAutoInstallFirst())->toBeFalse();
});

it('does not auto-install first mode when prompt mode is set', function () {
    $result = new SkillDiscoveryResultDTO(
        searchTerm: 'test',
        matches: [
            ['name' => 'skill1', 'description' => 'Test', 'owner' => 'o', 'repo' => 'r'],
            ['name' => 'skill2', 'description' => 'Test', 'owner' => 'o', 'repo' => 'r'],
        ],
        autoInstallEnabled: true,
        autoInstallMode: 'prompt'
    );

    expect($result->shouldAutoInstallFirst())->toBeFalse();
});

it('serializes to array and back', function () {
    $original = new SkillDiscoveryResultDTO(
        searchTerm: 'test',
        matches: [
            ['name' => 'skill', 'description' => 'Test', 'owner' => 'o', 'repo' => 'r'],
        ],
        autoInstallEnabled: true,
        autoInstallMode: 'first'
    );
    $original->markAsAutoInstalled('skill');

    $array = $original->toArray();
    $restored = SkillDiscoveryResultDTO::fromArray($array);

    expect($restored->searchTerm)->toBe($original->searchTerm);
    expect($restored->matches)->toBe($original->matches);
    expect($restored->wasAutoInstalled)->toBeTrue();
    expect($restored->installedSkillName)->toBe('skill');
});

it('serializes without auto-install', function () {
    $original = new SkillDiscoveryResultDTO(
        searchTerm: 'test',
        matches: [
            ['name' => 'skill', 'description' => 'Test', 'owner' => 'o', 'repo' => 'r'],
        ],
        autoInstallEnabled: false
    );

    $array = $original->toArray();
    $restored = SkillDiscoveryResultDTO::fromArray($array);

    expect($restored->wasAutoInstalled)->toBeFalse();
    expect($restored->installedSkillName)->toBeNull();
});

it('checks hasMatches correctly', function () {
    $withMatches = new SkillDiscoveryResultDTO(
        searchTerm: 'test',
        matches: [
            ['name' => 'skill', 'description' => 'Test', 'owner' => 'o', 'repo' => 'r'],
        ],
        autoInstallEnabled: false
    );

    $withoutMatches = new SkillDiscoveryResultDTO(
        searchTerm: 'test',
        matches: [],
        autoInstallEnabled: false
    );

    expect($withMatches->hasMatches())->toBeTrue();
    expect($withoutMatches->hasMatches())->toBeFalse();
});

it('checks needsUserSelection correctly', function () {
    $needsSelection = new SkillDiscoveryResultDTO(
        searchTerm: 'test',
        matches: [
            ['name' => 'skill', 'description' => 'Test', 'owner' => 'o', 'repo' => 'r'],
        ],
        autoInstallEnabled: false
    );

    $autoInstalled = new SkillDiscoveryResultDTO(
        searchTerm: 'test',
        matches: [
            ['name' => 'skill', 'description' => 'Test', 'owner' => 'o', 'repo' => 'r'],
        ],
        autoInstallEnabled: true
    );
    $autoInstalled->markAsAutoInstalled('skill');

    expect($needsSelection->needsUserSelection())->toBeTrue();
    expect($autoInstalled->needsUserSelection())->toBeFalse();
});

it('does not need user selection when no matches', function () {
    $result = new SkillDiscoveryResultDTO(
        searchTerm: 'test',
        matches: [],
        autoInstallEnabled: false
    );

    expect($result->needsUserSelection())->toBeFalse();
});

it('formats no match message', function () {
    $message = SkillDiscoveryResultDTO::formatNoMatchMessage('imagegen');

    expect($message)->toContain('imagegen');
    expect($message)->toContain("didn't find any matching skills");
});

it('formats auto installed message', function () {
    $result = new SkillDiscoveryResultDTO(
        searchTerm: 'test',
        matches: [
            ['name' => 'imagegen', 'description' => 'Generate images', 'owner' => 'laraclaw', 'repo' => 'skills'],
        ],
        autoInstallEnabled: true
    );
    $result->markAsAutoInstalled('imagegen');

    $message = $result->formatAutoInstalledMessage();

    expect($message)->toContain('Auto-installed');
    expect($message)->toContain('imagegen');
});

it('gets top match correctly', function () {
    $result = new SkillDiscoveryResultDTO(
        searchTerm: 'test',
        matches: [
            ['name' => 'skill1', 'description' => 'First', 'owner' => 'o', 'repo' => 'r'],
            ['name' => 'skill2', 'description' => 'Second', 'owner' => 'o', 'repo' => 'r'],
        ],
        autoInstallEnabled: false
    );

    $topMatch = $result->getTopMatch();

    expect($topMatch['name'])->toBe('skill1');
});

it('returns null for top match when no matches', function () {
    $result = new SkillDiscoveryResultDTO(
        searchTerm: 'test',
        matches: [],
        autoInstallEnabled: false
    );

    expect($result->getTopMatch())->toBeNull();
});

// ============================================
// markAsAutoInstalled Tests
// ============================================

it('marks result as auto installed', function () {
    $result = new SkillDiscoveryResultDTO(
        searchTerm: 'test',
        matches: [
            ['name' => 'skill', 'description' => 'Test', 'owner' => 'o', 'repo' => 'r'],
        ],
        autoInstallEnabled: true
    );

    $returned = $result->markAsAutoInstalled('skill');

    expect($returned)->toBe($result); // Fluent interface
    expect($result->wasAutoInstalled)->toBeTrue();
    expect($result->installedSkillName)->toBe('skill');
});

// ============================================
// Edge Cases
// ============================================

it('handles empty search term', function () {
    $result = new SkillDiscoveryResultDTO(
        searchTerm: '',
        matches: [],
        autoInstallEnabled: false
    );

    expect($result->searchTerm)->toBe('');
    expect($result->hasMatches())->toBeFalse();
});

it('handles skill without installs count', function () {
    $result = new SkillDiscoveryResultDTO(
        searchTerm: 'test',
        matches: [
            ['name' => 'skill', 'description' => 'Test', 'owner' => 'o', 'repo' => 'r'],
        ],
        autoInstallEnabled: false
    );

    $message = $result->formatPromptMessage();

    expect($message)->toContain('skill');
    // The message should still be formatted even without installs count
    expect($message)->toContain('I found 1 skill(s)');
});

it('handles skill without description', function () {
    $result = new SkillDiscoveryResultDTO(
        searchTerm: 'test',
        matches: [
            ['name' => 'skill', 'description' => '', 'owner' => 'o', 'repo' => 'r'],
        ],
        autoInstallEnabled: false
    );

    $message = $result->formatPromptMessage();

    expect($message)->toContain('skill');
});
