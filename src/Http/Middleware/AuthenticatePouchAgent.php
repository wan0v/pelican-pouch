<?php

namespace Wan0v\Pouch\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Wan0v\Pouch\Models\PouchNodeSetting;

/**
 * Authenticates the Pouch agent against the sync endpoint.
 *
 * The credential lives in `pouch_node_settings` and is issued from the node's
 * Pouch tab.
 */
class AuthenticatePouchAgent
{
    public function handle(Request $request, Closure $next): mixed
    {
        throw_if(is_null($bearer = $request->bearerToken()), new HttpException(401, 'Access to this endpoint must include an Authorization header.', null, ['WWW-Authenticate' => 'Bearer']));

        $parts = explode('.', $bearer);
        throw_if(count($parts) !== 2 || empty($parts[0]) || empty($parts[1]), new BadRequestHttpException('The Authorization header provided was not in a valid format.'));

        [$tokenId, $token] = $parts;

        $setting = PouchNodeSetting::query()
            ->where('agent_token_id', $tokenId)
            ->whereNotNull('agent_token')
            ->first();

        // One response for every failure — unlike the core daemon middleware
        // this does not distinguish "unknown id" from "wrong secret", and an
        // agent still presenting the Wings token lands here too.
        if ($setting === null || !hash_equals((string) $setting->agent_token, $token)) {
            throw new AccessDeniedHttpException('You are not authorized to access this resource.');
        }

        $request->attributes->set('node', $setting->node);

        return $next($request);
    }
}
