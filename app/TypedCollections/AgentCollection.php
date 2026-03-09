<?php

declare(strict_types=1);

namespace App\TypedCollections;

use App\Models\Agent;
use Gamez\Illuminate\Support\TypedCollection;

/**
 * @extends TypedCollection<array-key, Agent>
 */
class AgentCollection extends TypedCollection
{
    protected static array $allowedTypes = [Agent::class];
}
