<?php

namespace App\Models;

use App\DTOs\SkillClassificationStatsDTO;
use App\DTOs\SkillFileDTO;
use App\DTOs\SkillSyncResultDTO;
use App\Services\Skills\SkillChecksumCalculator;
use App\Services\Skills\SkillSyncService;
use App\TypedCollections\SkillFileDTOCollection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\File;

/**
 * Skill Model
 *
 * Represents a skill that can be invoked by the agent.
 * Tracks classification state and checksum for change detection.
 *
 * ## Checksum-Based Change Detection
 *
 * The checksum is calculated from ALL files in the skill directory recursively.
 * This ensures that any change to SKILL.md, scripts, references, or assets
 * triggers a re-classification.
 *
 * ## Classification States
 *
 * - pending: Skill has not been classified yet
 * - classified: Skill has been successfully classified
 * - failed: Classification failed (see last_error)
 * - skipped: Classification was skipped (unchanged checksum)
 *
 * @property int $id
 * @property string $name Skill name (unique)
 * @property string $dir_name Directory name
 * @property string $path Full path to skill directory
 * @property string|null $source_type Where skill comes from
 * @property string $description Skill description from SKILL.md
 * @property string|null $license License if specified
 * @property array<string>|null $keywords Extracted keywords
 * @property string $checksum SHA-256 hash of all skill files
 * @property bool $has_scripts Has scripts directory
 * @property bool $has_references Has references directory
 * @property bool $has_assets Has assets directory
 * @property string $classification_status Classification state
 * @property \Illuminate\Support\Carbon|null $classified_at Last classification time
 * @property string|null $classification_provider LLM provider used
 * @property string|null $classification_model Model used
 * @property int $intents_count Number of intents generated
 * @property string|null $last_error Last classification error if any
 * @property bool $is_active Soft disable flag
 * @property array<string,string>|null $metadata Extensible metadata
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class Skill extends Model
{
    protected $fillable = [
        'name',
        'dir_name',
        'path',
        'source_type',
        'description',
        'license',
        'keywords',
        'checksum',
        'has_scripts',
        'has_references',
        'has_assets',
        'classification_status',
        'classified_at',
        'classification_provider',
        'classification_model',
        'intents_count',
        'last_error',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'keywords' => 'array',
        'has_scripts' => 'boolean',
        'has_references' => 'boolean',
        'has_assets' => 'boolean',
        'is_active' => 'boolean',
        'metadata' => 'array',
        'classified_at' => 'datetime',
        'intents_count' => 'integer',
    ];

    /**
     * The model's default values for attributes.
     */
    protected $attributes = [
        'classification_status' => self::STATUS_PENDING,
        'has_scripts' => false,
        'has_references' => false,
        'has_assets' => false,
        'is_active' => true,
        'intents_count' => 0,
    ];

    // ==========================================
    // Constants
    // ==========================================

    const STATUS_PENDING = 'pending';

    const STATUS_CLASSIFIED = 'classified';

    const STATUS_FAILED = 'failed';

    const STATUS_SKIPPED = 'skipped';

    // ==========================================
    // Relationships
    // ==========================================

    /**
     * Get all skill matches (intent mappings) for this skill.
     */
    public function matches(): HasMany
    {
        return $this->hasMany(SkillMatch::class);
    }

    /**
     * Get the agents that have this skill assigned.
     */
    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'agent_skill', 'skill_id', 'agent_id')
            ->withTimestamps();
    }

    // ==========================================
    // Query Scopes
    // ==========================================

    /**
     * Scope: Active skills only.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Inactive skills only.
     */
    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    /**
     * Scope: Skills with a specific classification status.
     */
    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('classification_status', $status);
    }

    /**
     * Scope: Pending classification.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('classification_status', self::STATUS_PENDING);
    }

    /**
     * Scope: Successfully classified.
     */
    public function scopeClassified(Builder $query): Builder
    {
        return $query->where('classification_status', self::STATUS_CLASSIFIED);
    }

    /**
     * Scope: Failed classification.
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('classification_status', self::STATUS_FAILED);
    }

    /**
     * Scope: Skills needing classification (pending or failed).
     */
    public function scopeNeedsClassification(Builder $query): Builder
    {
        return $query->whereIn('classification_status', [self::STATUS_PENDING, self::STATUS_FAILED]);
    }

    /**
     * Scope: Skills with a specific checksum.
     */
    public function scopeWithChecksum(Builder $query, string $checksum): Builder
    {
        return $query->where('checksum', $checksum);
    }

    // ==========================================
    // Static Helper Methods
    // ==========================================

    /**
     * Find a skill by name.
     */
    public static function findByName(string $name): ?self
    {
        return self::where('name', $name)->first();
    }

    /**
     * Find or create a skill from indexed data.
     *
     * @param  array<string, mixed>  $skillData
     */
    public static function findOrCreateFromIndex(array $skillData, string $checksum): self
    {
        return self::updateOrCreate(
            ['name' => $skillData['name']],
            [
                'dir_name' => $skillData['dir_name'],
                'path' => $skillData['path'],
                'source_type' => $skillData['source_type'],
                'description' => $skillData['description'],
                'license' => $skillData['license'] ?? null,
                'keywords' => $skillData['keywords'] ?? [],
                'checksum' => $checksum,
                'has_scripts' => $skillData['has_scripts'] ?? false,
                'has_references' => $skillData['has_references'] ?? false,
                'has_assets' => $skillData['has_assets'] ?? false,
            ]
        );
    }

    /**
     * Calculate checksum for a skill directory.
     * Includes ALL files recursively for comprehensive change detection.
     */
    public static function calculateChecksum(string $directory): string
    {
        /** @var SkillChecksumCalculator $calculator */
        $calculator = app(SkillChecksumCalculator::class);

        return $calculator->calculate($directory);
    }

    /**
     * Calculate checksum including file contents (more thorough but slower).
     * Use this for critical change detection.
     *
     * @see SkillChecksumCalculator::calculateThorough()
     */
    public static function calculateThoroughChecksum(string $directory): string
    {
        /** @var SkillChecksumCalculator $calculator */
        $calculator = app(SkillChecksumCalculator::class);

        return $calculator->calculateThorough($directory);
    }

    /**
     * Sync skills from indexed data.
     * Creates new skills, updates existing ones, and marks removed skills as inactive.
     *
     * @param  array<string, mixed>  $indexedSkills  Skills from SkillSearchService::indexSkills()
     */
    public static function syncFromIndex(array $indexedSkills): SkillSyncResultDTO
    {
        /** @var SkillSyncService $syncService */
        $syncService = app(SkillSyncService::class);

        return $syncService->syncFromIndex($indexedSkills);
    }

    /**
     * Get skills that need classification (checksum changed or never classified).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, self>
     */
    public static function getSkillsNeedingClassification(): \Illuminate\Database\Eloquent\Collection
    {
        return self::active()
            ->needsClassification()
            ->get();
    }

    /**
     * Get classification statistics.
     */
    public static function getClassificationStats(): SkillClassificationStatsDTO
    {
        return new SkillClassificationStatsDTO(
            total: self::active()->count(),
            pending: self::active()->pending()->count(),
            classified: self::active()->classified()->count(),
            failed: self::active()->failed()->count(),
            totalIntents: self::active()->sum('intents_count'),
        );
    }

    // ==========================================
    // Instance Methods
    // ==========================================

    /**
     * Check if this skill needs classification.
     */
    public function isPendingOrFailed(): bool
    {
        return in_array($this->classification_status, [self::STATUS_PENDING, self::STATUS_FAILED]);
    }

    /**
     * Check if the checksum has changed since last classification.
     */
    public function hasChecksumChanged(): bool
    {
        $currentChecksum = self::calculateChecksum($this->path);

        return $currentChecksum !== $this->checksum;
    }

    /**
     * Mark this skill as classified.
     */
    public function markClassified(int $intentsCount, string $provider, string $model): void
    {
        $this->update([
            'classification_status' => self::STATUS_CLASSIFIED,
            'classified_at' => now(),
            'classification_provider' => $provider,
            'classification_model' => $model,
            'intents_count' => $intentsCount,
            'last_error' => null,
        ]);
    }

    /**
     * Mark this skill as failed.
     */
    public function markFailed(string $error): void
    {
        $this->update([
            'classification_status' => self::STATUS_FAILED,
            'last_error' => $error,
        ]);
    }

    /**
     * Mark this skill as skipped (unchanged).
     */
    public function markSkipped(): void
    {
        $this->update([
            'classification_status' => self::STATUS_SKIPPED,
        ]);
    }

    /**
     * Update the checksum for this skill.
     */
    public function updateChecksum(): string
    {
        $checksum = self::calculateChecksum($this->path);
        $this->update(['checksum' => $checksum]);

        return $checksum;
    }

    /**
     * Get the SKILL.md content for this skill.
     */
    public function getSkillContent(): ?string
    {
        $skillFile = $this->path.'/SKILL.md';

        if (! File::exists($skillFile)) {
            return null;
        }

        return File::get($skillFile);
    }

    /**
     * Get reference files for this skill.
     */
    public function getReferences(): SkillFileDTOCollection
    {
        if (! $this->has_references) {
            return new SkillFileDTOCollection([]);
        }

        $refDir = $this->path.'/references';

        if (! File::isDirectory($refDir)) {
            return new SkillFileDTOCollection([]);
        }

        $files = File::files($refDir);

        $dtos = array_map(fn ($f) => new SkillFileDTO(
            name: $f->getFilename(),
            path: $f->getPathname(),
        ), $files);

        return new SkillFileDTOCollection($dtos);
    }

    /**
     * Get scripts for this skill.
     */
    public function getScripts(): SkillFileDTOCollection
    {
        if (! $this->has_scripts) {
            return new SkillFileDTOCollection([]);
        }

        $scriptsDir = $this->path.'/scripts';

        if (! File::isDirectory($scriptsDir)) {
            return new SkillFileDTOCollection([]);
        }

        $files = File::files($scriptsDir);

        $dtos = array_map(fn ($f) => new SkillFileDTO(
            name: $f->getFilename(),
            path: $f->getPathname(),
        ), $files);

        return new SkillFileDTOCollection($dtos);
    }

    /**
     * Delete all skill matches for this skill.
     */
    public function clearMatches(): int
    {
        return $this->matches()->delete();
    }
}
