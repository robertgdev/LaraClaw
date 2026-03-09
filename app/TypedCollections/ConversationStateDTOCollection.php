<?php

declare(strict_types=1);

namespace App\TypedCollections;

use App\DTOs\ConversationStateDTO;
use Gamez\Illuminate\Support\TypedCollection;

/**
 * @extends TypedCollection<array-key, ConversationStateDTO>
 */
class ConversationStateDTOCollection extends TypedCollection
{
    protected static array $allowedTypes = [ConversationStateDTO::class];
}
