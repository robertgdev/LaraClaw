<?php

use App\Services\Shell\ShellHistoryManager;
use Illuminate\Support\Facades\File;

afterEach(function () {
    // Clean up temp files
    if (isset($this->historyFile) && File::exists($this->historyFile)) {
        File::delete($this->historyFile);
    }
});

describe('ShellHistoryManager', function () {
    describe('add and getAll', function () {
        it('adds entries to history', function () {
            $this->historyFile = sys_get_temp_dir().'/test_history_'.uniqid();
            $manager = new ShellHistoryManager($this->historyFile);

            $manager->add('first command');
            $manager->add('second command');

            expect($manager->getAll())->toHaveCount(2)
                ->and($manager->getAll()[0])->toBe('first command')
                ->and($manager->getAll()[1])->toBe('second command');
        });

        it('does not add duplicates in sequence', function () {
            $this->historyFile = sys_get_temp_dir().'/test_history_'.uniqid();
            $manager = new ShellHistoryManager($this->historyFile);

            $manager->add('same command');
            $manager->add('same command');

            expect($manager->getAll())->toHaveCount(1);
        });
    });

    describe('save and load', function () {
        it('persists history to file', function () {
            $this->historyFile = sys_get_temp_dir().'/test_history_'.uniqid();
            $manager = new ShellHistoryManager($this->historyFile);

            $manager->add('command 1');
            $manager->add('command 2');
            $manager->save();

            // Create new instance and load
            $manager2 = new ShellHistoryManager($this->historyFile);
            $manager2->load();

            expect($manager2->getAll())->toHaveCount(2);
        });
    });

    describe('getRecent', function () {
        it('returns last N entries', function () {
            $this->historyFile = sys_get_temp_dir().'/test_history_'.uniqid();
            $manager = new ShellHistoryManager($this->historyFile);

            for ($i = 0; $i < 10; $i++) {
                $manager->add("command $i");
            }

            $recent = $manager->getRecent(3);
            expect($recent)->toHaveCount(3)
                ->and($recent[0])->toBe('command 7');
        });
    });

    describe('isEmpty', function () {
        it('returns true for empty history', function () {
            $this->historyFile = sys_get_temp_dir().'/test_history_'.uniqid();
            $manager = new ShellHistoryManager($this->historyFile);

            expect($manager->isEmpty())->toBeTrue();
        });

        it('returns false after adding entry', function () {
            $this->historyFile = sys_get_temp_dir().'/test_history_'.uniqid();
            $manager = new ShellHistoryManager($this->historyFile);
            $manager->add('test');

            expect($manager->isEmpty())->toBeFalse();
        });
    });
});
