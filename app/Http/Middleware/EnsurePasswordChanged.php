<?php

namespace App\Http\Middleware;

use App\Filament\Auth\Pages\ChangePassword;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePasswordChanged
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User || ! $user->mustChangePassword()) {
            return $next($request);
        }

        $routeName = $request->route()?->getName() ?? '';

        if (
            $request->routeIs(ChangePassword::getRouteName())
            || str_ends_with($routeName, '.auth.logout')
        ) {
            return $next($request);
        }

        return redirect()->guest(ChangePassword::getUrl());
    }
}
