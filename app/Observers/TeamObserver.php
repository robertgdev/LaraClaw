<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Conversation;
use App\Models\Team;

final readonly class TeamObserver
{
    public function deleting(Team $team): void
    {
        if (! $team->isForceDeleting()) {
            $team->conversations()->delete();
        }
    }

    public function deleted(Team $team): void
    {
        $team->agents()->detach();
    }

    public function restored(Team $team): void
    {
        Conversation::withTrashed()->where('team_id', $team->team_id)->restore();
    }
}
