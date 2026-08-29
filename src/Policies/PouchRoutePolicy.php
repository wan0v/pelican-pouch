<?php

namespace Wan0v\Pouch\Policies;

use App\Models\User;
use App\Policies\DefaultAdminPolicies;
use Wan0v\Pouch\Models\PouchRoute;

class PouchRoutePolicy
{
    use DefaultAdminPolicies;

    protected string $modelName = PouchRoute::RESOURCE_NAME;

    /**
     * Routes are always scoped to a node, so respect the node access a role
     * grants just like the core allocation policy does.
     */
    public function before(User $user, string $ability, string|PouchRoute $route): ?bool
    {
        // For "viewAny" the $route param is the class name.
        if (is_string($route)) {
            return null;
        }

        if (!$user->canTarget($route->node)) {
            return false;
        }

        return null;
    }
}
