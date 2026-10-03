<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        if ($request->routeIs('patient.*')) {
            return [...parent::share($request), 'auth' => ['user' => null], 'integration' => null];
        }

        return [...parent::share($request),
            'auth' => ['user' => $request->user()?->only('name', 'email')],
            'integration' => [
                'configured' => (bool) config('triage.api_key') && (bool) config('triage.model'),
                'model' => config('triage.model'),
                'prompt_version' => config('triage.prompt_version'),
            ],
        ];
    }
}
