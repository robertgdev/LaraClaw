<?php

namespace App\Logging;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Stringable;
use Throwable;

use function Safe\json_encode;

class MultiLogger
{
    /**
     * @param  array<mixed>  $context
     */
    public static function log(string $level, string|Stringable $message, array $context = []): void
    {
        // Convert Stringable to string for storage
        $messageString = (string) $message;

        // Always log to Laravel's default logger
        Log::log($level, $messageString, $context);

        // Try database logging (never break the request if this fails)
        try {
            DB::table('logs')->insert(
                [
                    'level' => strtoupper($level),
                    'message' => $messageString,
                    'context' => empty($context) ? null : json_encode($context),
                    'created_at' => now(),
                ]
            );
        } catch (Throwable $e) {
            // Fail silently or optionally log the DB failure to file
            Log::error('Database logging failed', ['original_message' => $messageString, 'db_error' => $e->getMessage()]);
        }
    }

    /**
     * @param  array<mixed>  $context
     */
    public static function emergency(string|Stringable $message, array $context = []): void
    {
        self::log('emergency', $message, $context);
    }

    /**
     * @param  array<mixed>  $context
     */
    public static function alert(string|Stringable $message, array $context = []): void
    {
        self::log('alert', $message, $context);
    }

    /**
     * @param  array<mixed>  $context
     */
    public static function critical(string|Stringable $message, array $context = []): void
    {
        self::log('critical', $message, $context);
    }

    /**
     * @param  array<mixed>  $context
     */
    public static function debug(string|Stringable $message, array $context = []): void
    {
        self::log('debug', $message, $context);
    }

    /**
     * @param  array<mixed>  $context
     */
    public static function error(string|Stringable $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    /**
     * @param  array<mixed>  $context
     */
    public static function info(string|Stringable $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    /**
     * @param  array<mixed>  $context
     */
    public static function notice(string|Stringable $message, array $context = []): void
    {
        self::log('notice', $message, $context);
    }

    /**
     * @param  array<mixed>  $context
     */
    public static function warning(string|Stringable $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }
}
