<?php

namespace Wan0v\Pouch\Observers;

use App\Models\Allocation;
use Wan0v\Pouch\Models\PouchRoute;

class AllocationObserver
{
    /**
     * Drop the route as soon as the allocation stops belonging to the server it
     * was published for.
     *
     * Deleting an allocation is already covered by the foreign key cascade, but
     * detaching one only nulls `server_id` (see Allocation::RELEASE_ATTRIBUTES),
     * which would otherwise leave a route pointing at a free allocation. The
     * same applies when an allocation is moved to a different node.
     */
    public function updated(Allocation $allocation): void
    {
        $serverChanged = $allocation->wasChanged('server_id');
        $nodeChanged = $allocation->wasChanged('node_id');

        if (!$serverChanged && !$nodeChanged) {
            return;
        }

        PouchRoute::query()
            ->where('allocation_id', $allocation->id)
            ->when(
                $allocation->server_id !== null,
                // Still attached, but to a different server or node.
                fn ($query) => $query->where(
                    fn ($inner) => $inner
                        ->where('server_id', '!=', $allocation->server_id)
                        ->orWhere('node_id', '!=', $allocation->node_id)
                ),
            )
            ->get()
            ->each
            ->delete();
    }
}
