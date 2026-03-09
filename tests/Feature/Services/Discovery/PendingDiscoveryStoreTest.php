<?php

use App\DTOs\SkillDiscoveryResultDTO;
use App\Services\Discovery\PendingDiscoveryStore;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    $this->store = new PendingDiscoveryStore;
});

describe('PendingDiscoveryStore', function () {
    describe('store and get', function () {
        it('stores and retrieves discoveries', function () {
            $result = new SkillDiscoveryResultDTO(
                searchTerm: 'imagegen',
                matches: [
                    ['name' => 'imagegen', 'description' => 'Generate images', 'owner' => 'laraclaw', 'repo' => 'skills'],
                ],
                autoInstallEnabled: false
            );

            $this->store->store('user123', $result, 'Generate an image', 'agent1');

            $pending = $this->store->get('user123');

            expect($pending)->not->toBeNull()
                ->and($pending['original_message'])->toBe('Generate an image')
                ->and($pending['agent_id'])->toBe('agent1')
                ->and($pending['result'])->toBeInstanceOf(SkillDiscoveryResultDTO::class);
        });

        it('returns null for nonexistent discoveries', function () {
            expect($this->store->get('nonexistent'))->toBeNull();
        });
    });

    describe('clear', function () {
        it('clears stored discoveries', function () {
            $result = new SkillDiscoveryResultDTO(
                searchTerm: 'test',
                matches: [],
                autoInstallEnabled: false
            );

            $this->store->store('user123', $result, 'Test', 'agent1');
            $this->store->clear('user123');

            expect($this->store->get('user123'))->toBeNull();
        });
    });

    describe('parseResponse', function () {
        beforeEach(function () {
            $result = new SkillDiscoveryResultDTO(
                searchTerm: 'test',
                matches: [
                    ['name' => 'skill1', 'description' => 'First', 'owner' => 'o', 'repo' => 'r'],
                    ['name' => 'skill2', 'description' => 'Second', 'owner' => 'o', 'repo' => 'r'],
                ],
                autoInstallEnabled: false
            );
            $this->store->store('user123', $result, 'Test', 'agent1');
        });

        it('parses numeric selection', function () {
            $response = $this->store->parseResponse('1', 'user123');

            expect($response['is_selection'])->toBeTrue()
                ->and($response['index'])->toBe(0)
                ->and($response['skip'])->toBeFalse();
        });

        it('parses skip responses', function () {
            foreach (['skip', 'cancel', 'no', 'none'] as $word) {
                $response = $this->store->parseResponse($word, 'user123');

                expect($response['is_selection'])->toBeTrue()
                    ->and($response['skip'])->toBeTrue();
            }
        });

        it('rejects out-of-range numbers', function () {
            $response = $this->store->parseResponse('5', 'user123');

            expect($response['is_selection'])->toBeFalse();
        });

        it('rejects non-selection text', function () {
            $response = $this->store->parseResponse('hello world', 'user123');

            expect($response['is_selection'])->toBeFalse();
        });

        it('returns not a selection when no pending discovery', function () {
            $response = $this->store->parseResponse('1', 'nonexistent');

            expect($response['is_selection'])->toBeFalse();
        });
    });
});
