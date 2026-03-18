<?php

declare(strict_types=1);

namespace App\TypedCollections;

use App\DTOs\IntentMappingDTO;
use Gamez\Illuminate\Support\TypedCollection;

/**
 * @extends TypedCollection<array-key, IntentMappingDTO>
 */
class IntentMappingDTOCollection extends TypedCollection
{
    protected static array $allowedTypes = [IntentMappingDTO::class];
}
