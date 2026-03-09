<?php

use App\Services\ProviderMapper;
use Prism\Prism\Enums\Provider;

describe('ProviderMapper', function () {
    it('resolves openai provider', function () {
        expect(ProviderMapper::resolve('openai'))->toBe(Provider::OpenAI);
        expect(ProviderMapper::resolve('open_ai'))->toBe(Provider::OpenAI);
    });

    it('resolves anthropic provider', function () {
        expect(ProviderMapper::resolve('anthropic'))->toBe(Provider::Anthropic);
        expect(ProviderMapper::resolve('claude'))->toBe(Provider::Anthropic);
    });

    it('resolves gemini provider', function () {
        expect(ProviderMapper::resolve('gemini'))->toBe(Provider::Gemini);
        expect(ProviderMapper::resolve('google'))->toBe(Provider::Gemini);
    });

    it('resolves groq provider', function () {
        expect(ProviderMapper::resolve('groq'))->toBe(Provider::Groq);
    });

    it('resolves mistral provider', function () {
        expect(ProviderMapper::resolve('mistral'))->toBe(Provider::Mistral);
    });

    it('resolves xai provider', function () {
        expect(ProviderMapper::resolve('xai'))->toBe(Provider::XAI);
        expect(ProviderMapper::resolve('x-ai'))->toBe(Provider::XAI);
    });

    it('resolves ollama provider', function () {
        expect(ProviderMapper::resolve('ollama'))->toBe(Provider::Ollama);
    });

    it('resolves deepseek provider', function () {
        expect(ProviderMapper::resolve('deepseek'))->toBe(Provider::DeepSeek);
    });

    it('defaults to anthropic for unknown providers', function () {
        expect(ProviderMapper::resolve('unknown_provider'))->toBe(Provider::Anthropic);
        expect(ProviderMapper::resolve(''))->toBe(Provider::Anthropic);
    });

    it('is case-insensitive', function () {
        expect(ProviderMapper::resolve('OpenAI'))->toBe(Provider::OpenAI);
        expect(ProviderMapper::resolve('ANTHROPIC'))->toBe(Provider::Anthropic);
        expect(ProviderMapper::resolve('Gemini'))->toBe(Provider::Gemini);
    });
});
