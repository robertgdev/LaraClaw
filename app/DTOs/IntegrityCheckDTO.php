<?php

namespace App\DTOs;

/**
 * Data Transfer Object for an integrity check result.
 */
readonly class IntegrityCheckDTO
{
    public function __construct(
        public string $name,
        public string $status,
        public string $message,
        public ?array $details = null,
    ) {}

    /**
     * Create a passing check.
     */
    public static function pass(string $name, string $message): self
    {
        return new self(name: $name, status: 'pass', message: $message);
    }

    /**
     * Create a failing check.
     */
    public static function fail(string $name, string $message, ?array $details = null): self
    {
        return new self(name: $name, status: 'fail', message: $message, details: $details);
    }

    /**
     * Create a warning check.
     */
    public static function warn(string $name, string $message, ?array $details = null): self
    {
        return new self(name: $name, status: 'warn', message: $message, details: $details);
    }

    /**
     * Check if this check passed.
     */
    public function isPass(): bool
    {
        return $this->status === 'pass';
    }

    /**
     * Check if this check failed.
     */
    public function isFail(): bool
    {
        return $this->status === 'fail';
    }

    /**
     * Check if this check has a warning.
     */
    public function isWarn(): bool
    {
        return $this->status === 'warn';
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status,
            'message' => $this->message,
            'details' => $this->details,
        ];
    }
}
