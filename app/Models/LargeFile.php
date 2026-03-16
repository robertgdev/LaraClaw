<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LargeFile model - stores metadata for large file attachments.
 *
 * Large files are stored externally and referenced by ID in summaries.
 * This allows summaries to track file operations without embedding
 * large content directly.
 *
 * @property string $file_id Primary key
 * @property int $conversation_id FK to conversations.id
 * @property string|null $file_name Original file name
 * @property string|null $mime_type MIME type
 * @property int|null $byte_size File size in bytes
 * @property string $storage_uri URI where file is stored
 * @property string|null $exploration_summary LLM-generated summary of file contents
 */
class LargeFile extends Model
{
    protected $primaryKey = 'file_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'file_id',
        'conversation_id',
        'file_name',
        'mime_type',
        'byte_size',
        'storage_uri',
        'exploration_summary',
    ];

    protected $casts = [
        'byte_size' => 'integer',
    ];

    // ==========================================
    // Relationships
    // ==========================================

    /**
     * The conversation this large file belongs to.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    // ==========================================
    // Helper Methods
    // ==========================================

    /**
     * Generate a file ID.
     */
    public static function generateId(): string
    {
        return 'file_'.substr(hash('sha256', uniqid('', true)), 0, 16);
    }

    /**
     * Check if this is an image file.
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type ?? '', 'image/');
    }

    /**
     * Check if this is a text file.
     */
    public function isText(): bool
    {
        return str_starts_with($this->mime_type ?? '', 'text/');
    }

    /**
     * Get human-readable file size.
     */
    public function getHumanSize(): string
    {
        $bytes = $this->byte_size ?? 0;
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 2).' '.$units[$unitIndex];
    }
}
