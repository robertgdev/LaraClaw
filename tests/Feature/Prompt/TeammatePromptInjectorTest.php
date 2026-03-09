<?php

use App\Services\Prompt\TeammatePromptInjector;

beforeEach(function () {
    $this->injector = new TeammatePromptInjector;
});

describe('TeammatePromptInjector', function () {
    describe('inject', function () {
        it('injects agent self info between markers', function () {
            $content = "Before\n<!-- TEAMMATES_START -->\n<!-- TEAMMATES_END -->\nAfter";

            $result = $this->injector->inject($content, 'agent1', 'Agent One', 'claude-3', []);

            expect($result)->toContain('@agent1')
                ->toContain('Agent One')
                ->toContain('claude-3')
                ->toContain('Before')
                ->toContain('After');
        });

        it('injects teammate info between markers', function () {
            $content = "Before\n<!-- TEAMMATES_START -->\n<!-- TEAMMATES_END -->\nAfter";
            $teammates = [
                ['id' => 'agent2', 'name' => 'Agent Two', 'model' => 'gpt-4'],
            ];

            $result = $this->injector->inject($content, 'agent1', 'Agent One', 'claude-3', $teammates);

            expect($result)->toContain('@agent2')
                ->toContain('Agent Two')
                ->toContain('gpt-4');
        });

        it('returns content unchanged when markers are missing', function () {
            $content = 'No markers here';

            $result = $this->injector->inject($content, 'agent1', 'Agent One', 'claude-3', []);

            expect($result)->toBe($content);
        });
    });
});
