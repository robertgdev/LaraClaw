<?php

declare(strict_types=1);

namespace App\Services\Setup;

use Illuminate\Support\Facades\File;
use function Safe\preg_match;
use function Safe\preg_replace;

/**
 * Handles reading and writing .env file configuration changes.
 *
 * Extracted from LaraClawSetupCommand to be reusable across
 * setup, migration, and configuration commands.
 */
class SetupEnvWriter
{
    /**
     * Write key-value pairs to the .env file.
     *
     * Updates existing variables and appends new ones at the end.
     *
     * @param  array<string, string>  $changes  Key-value pairs to write
     * @param  string|null  $envPath  Path to .env file (defaults to base_path('.env'))
     */
    public function write(array $changes, ?string $envPath = null): void
    {
        $envPath = $envPath ?? base_path('.env');
        $envContent = File::exists($envPath) ? File::get($envPath) : '';

        foreach ($changes as $key => $value) {
            $pattern = '/^'.preg_quote($key, '/').'=.*/m';
            $replacement = $key.'='.$value;

            if (preg_match($pattern, $envContent)) {
                $envContent = preg_replace($pattern, $replacement, $envContent);
            } else {
                $envContent .= "\n".$replacement;
            }
        }

        File::put($envPath, $envContent);
    }

    /**
     * Determine if a key name should have its value masked for display.
     */
    public function shouldMaskValue(string $key): bool
    {
        return str_contains($key, 'TOKEN')
            || str_contains($key, 'API_KEY')
            || str_contains($key, 'SECRET');
    }

    /**
     * Generate a secure random API token.
     *
     * @return string A 48-character hex string
     */
    public function generateApiToken(): string
    {
        return bin2hex(random_bytes(24));
    }
}
