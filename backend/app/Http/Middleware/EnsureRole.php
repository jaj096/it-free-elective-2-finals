<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side role check, e.g. ->middleware('role:approver,custodian').
 * The Flutter client hides menus by role, but this is the check that counts.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        $allowed = array_map(fn (string $r) => Role::from($r), $roles);

        abort_unless($user && $user->hasRole(...$allowed), 403, "You don't have access to this action.");

        return $next($request);
    }
}
