<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Facades\Auth;

trait AuthorizesSpatiePermissions
{
    /**
     * Grant access if the user has any of the given Spatie permissions.
     * (No Admin-role bypass — Admin users follow the Admin role’s assigned permissions.)
     *
     * @param  array<int, string>  $anyOf
     */
    protected function authorizePermissions(array $anyOf): void
    {
        $user = Auth::user();
        if (! $user) {
            abort(403, 'Unauthorized action.');
        }
        foreach ($anyOf as $permission) {
            if ($user->can($permission)) {
                return;
            }
        }
        abort(403, 'You do not have permission to perform this action.');
    }
}
