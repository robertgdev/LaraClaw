<?php

use App\Services\Setup\SetupEnvWriter;

describe('SetupEnvWriter', function () {
    beforeEach(function () {
        $this->writer = new SetupEnvWriter;
        $this->tempEnvPath = sys_get_temp_dir().'/test-env-'.uniqid();
    });

    afterEach(function () {
        if (file_exists($this->tempEnvPath)) {
            unlink($this->tempEnvPath);
        }
    });

    it('writes new environment variables', function () {
        $this->writer->write(['TEST_KEY' => 'test_value'], $this->tempEnvPath);

        $content = file_get_contents($this->tempEnvPath);

        expect($content)->toContain('TEST_KEY=test_value');
    });

    it('updates existing environment variables', function () {
        file_put_contents($this->tempEnvPath, "EXISTING_KEY=old_value\nOTHER_KEY=other\n");

        $this->writer->write(['EXISTING_KEY' => 'new_value'], $this->tempEnvPath);

        $content = file_get_contents($this->tempEnvPath);

        expect($content)->toContain('EXISTING_KEY=new_value')
            ->and($content)->not->toContain('EXISTING_KEY=old_value')
            ->and($content)->toContain('OTHER_KEY=other');
    });

    it('appends new variables to existing file', function () {
        file_put_contents($this->tempEnvPath, "EXISTING_KEY=value\n");

        $this->writer->write(['NEW_KEY' => 'new_value'], $this->tempEnvPath);

        $content = file_get_contents($this->tempEnvPath);

        expect($content)->toContain('EXISTING_KEY=value')
            ->and($content)->toContain('NEW_KEY=new_value');
    });

    it('generates API tokens of 48 characters', function () {
        $token = $this->writer->generateApiToken();

        expect(strlen($token))->toBe(48)
            ->and(ctype_xdigit($token))->toBeTrue();
    });

    it('generates unique API tokens', function () {
        $token1 = $this->writer->generateApiToken();
        $token2 = $this->writer->generateApiToken();

        expect($token1)->not->toBe($token2);
    });

    it('masks sensitive keys', function () {
        expect($this->writer->shouldMaskValue('DISCORD_BOT_TOKEN'))->toBeTrue()
            ->and($this->writer->shouldMaskValue('OPENAI_API_KEY'))->toBeTrue()
            ->and($this->writer->shouldMaskValue('CLIENT_SECRET'))->toBeTrue()
            ->and($this->writer->shouldMaskValue('WORKSPACE_PATH'))->toBeFalse();
    });
});
