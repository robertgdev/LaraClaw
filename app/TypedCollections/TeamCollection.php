<?php

declare(strict_types=1);

namespace App\TypedCollections;

use App\Models\Team;
use Gamez\Illuminate\Support\TypedCollection;

/**
 * @extends TypedCollection<array-key, Team>
 */
class TeamCollection extends TypedCollection
{
    protected static array $allowedTypes = [Team::class];
}
