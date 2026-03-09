<?php

use App\Services\Skills\SkillChecksumCalculator;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->calculator = new SkillChecksumCalculator;
});

describe('SkillChecksumCalculator', function () {
    describe('calculate', function () {
        it('returns empty string for non-existent directory', function () {
            $result = $this->calculator->calculate('/tmp/nonexistent_dir_'.uniqid());
            expect($result)->toBe('');
        });

        it('calculates checksum for a directory with files', function () {
            $dir = sys_get_temp_dir().'/checksum_test_'.uniqid();
            mkdir($dir);
            file_put_contents($dir.'/file1.txt', 'content1');
            file_put_contents($dir.'/file2.txt', 'content2');

            $result = $this->calculator->calculate($dir);

            expect($result)->toBeString()
                ->not->toBe('');

            // Cleanup
            File::deleteDirectory($dir);
        });

        it('returns consistent checksum for same directory', function () {
            $dir = sys_get_temp_dir().'/checksum_consistent_'.uniqid();
            mkdir($dir);
            file_put_contents($dir.'/SKILL.md', 'test content');

            $result1 = $this->calculator->calculate($dir);
            $result2 = $this->calculator->calculate($dir);

            expect($result1)->toBe($result2);

            File::deleteDirectory($dir);
        });
    });

    describe('calculateThorough', function () {
        it('returns empty string for non-existent directory', function () {
            $result = $this->calculator->calculateThorough('/tmp/nonexistent_dir_'.uniqid());
            expect($result)->toBe('');
        });

        it('calculates thorough checksum based on file contents', function () {
            $dir = sys_get_temp_dir().'/checksum_thorough_'.uniqid();
            mkdir($dir);
            file_put_contents($dir.'/file.txt', 'original content');

            $result = $this->calculator->calculateThorough($dir);

            expect($result)->toBeString()
                ->not->toBe('');

            File::deleteDirectory($dir);
        });
    });
});
