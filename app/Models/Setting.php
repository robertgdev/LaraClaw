<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use function Safe\json_decode;
use function Safe\json_encode;

class Setting extends Model
{
    /**
     * The primary key for the model.
     */
    protected $primaryKey = 'key';

    /**
     * The "type" of the primary key.
     */
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = true;

    /**
     * Get all settings as a keyed array.
     */
    public static function getAllKeyed(): array
    {
        return static::all()
            ->mapWithKeys(function ($setting) {
                return [$setting->key => $setting->getParsedValue()];
            })
            ->toArray();
    }

    /**
     * Get a specific setting by key.
     */
    public static function findByKey(string $key, mixed $default = null): mixed
    {
        $settings = static::getAllKeyed();

        return $settings[$key] ?? $default;
    }

    /**
     * Set a setting value.
     */
    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => is_array($value) ? json_encode($value) : (string) $value]
        );
    }

    /**
     * Set multiple settings at once.
     */
    public static function setMany(array $settings): void
    {
        foreach ($settings as $key => $value) {
            static::updateOrCreate(
                ['key' => $key],
                ['value' => is_array($value) ? json_encode($value) : (string) $value]
            );
        }
    }

    /**
     * Delete a setting by key.
     */
    public static function remove(string $key): bool
    {
        $deleted = static::where('key', $key)->delete();

        return $deleted > 0;
    }

    /**
     * Parse the value - detect JSON arrays/objects.
     */
    public function getParsedValue(): mixed
    {
        $value = $this->value;

        // Try to decode as JSON
        if (is_string($value) && in_array($value[0] ?? '', ['[', '{'])) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        // Try to detect boolean
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }

        // Try to detect integer
        if (is_numeric($value) && strpos($value, '.') === false) {
            return (int) $value;
        }

        // Try to detect float
        if (is_numeric($value)) {
            return (float) $value;
        }

        return $value;
    }

    /**
     * Get default settings structure.
     */
    public static function getDefaults(): array
    {
        return [
            'workspace.path' => config('laraclaw.workspace.path'),
            'workspace.name' => config('laraclaw.workspace.name'),
            'channels.enabled' => ['telegram'],
            'models.provider' => 'anthropic',
            'models.anthropic.model' => 'claude-sonnet-4-5',
            'monitoring.heartbeat_interval' => 300,
        ];
    }

    /**
     * Initialize default settings if they don't exist.
     */
    public static function initializeDefaults(): int
    {
        $count = 0;
        foreach (static::getDefaults() as $key => $value) {
            if (! static::where('key', $key)->exists()) {
                static::set($key, $value);
                $count++;
            }
        }

        return $count;
    }
}
