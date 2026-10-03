<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [HandleInertiaRequests::class]);
        $middleware->redirectUsersTo(fn (Request $request) => route('dashboard'));
        // Preserve the submitted patient wording, whitespace and empty fields for the research record.
        $preservesPatientText = fn (Request $request) => $request->isMethod('post') && $request->is('admin/screenings', 'patient/messages');
        $middleware->trimStrings(except: [$preservesPatientText]);
        $middleware->convertEmptyStringsToNull(except: [$preservesPatientText]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->expectsJson());
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            if (! $request->expectsJson() && in_array($response->getStatusCode(), [403, 404, 419, 429, 500, 503])) {
                return Inertia::render('Error', ['status' => $response->getStatusCode()])
                    ->toResponse($request)->setStatusCode($response->getStatusCode());
            }

            return $response;
        });
    })->create();
