<?php

namespace Database\Factories;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Setting>
 */
class SettingFactory extends Factory
{
    protected $model = Setting::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(3),
            'value' => fake()->word(),
        ];
    }

    /**
     * Create a workspace path setting.
     */
    public function workspacePath(string $path = '/tmp/tinyclaw'): static
    {
        return $this->state(fn (array $attributes) => [
            'key' => 'workspace.path',
            'value' => $path,
        ]);
    }

    /**
     * Create a workspace name setting.
     */
    public function workspaceName(string $name = 'Test Workspace'): static
    {
        return $this->state(fn (array $attributes) => [
            'key' => 'workspace.name',
            'value' => $name,
        ]);
    }

    /**
     * Create an enabled channels setting.
     */
    public function enabledChannels(array $channels = ['telegram']): static
    {
        return $this->state(fn (array $attributes) => [
            'key' => 'channels.enabled',
            'value' => json_encode($channels),
        ]);
    }

    /**
     * Create a default provider setting.
     */
    public function defaultProvider(string $provider = 'anthropic'): static
    {
        return $this->state(fn (array $attributes) => [
            'key' => 'models.provider',
            'value' => $provider,
        ]);
    }

    /**
     * Create a model setting for a provider.
     */
    public function modelForProvider(string $provider = 'anthropic', string $model = 'claude-sonnet-4-5'): static
    {
        return $this->state(fn (array $attributes) => [
            'key' => "models.{$provider}.model",
            'value' => $model,
        ]);
    }

    /**
     * Create a heartbeat interval setting.
     */
    public function heartbeatInterval(int $seconds = 300): static
    {
        return $this->state(fn (array $attributes) => [
            'key' => 'monitoring.heartbeat_interval',
            'value' => (string) $seconds,
        ]);
    }
}
