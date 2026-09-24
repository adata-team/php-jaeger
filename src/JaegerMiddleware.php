<?php

namespace Adata\LaravelJaeger;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class JaegerMiddleware
{
    /** @var Jaeger */
    private $jaeger;

    public function __construct(Jaeger $jaeger)
    {
        $this->jaeger = $jaeger;
    }

    /**
     * @param Request  $request
     * @param Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if ($this->shouldSkip($request)) {
            return $next($request);
        }

        $this->jaeger->initServerContext($request->server->all());

        $operation = $request->method() . ' ' . $this->route($request);

        $this->jaeger->start($operation, [
            'http.method' => $request->method(),
            'http.url'    => $request->fullUrl(),
            'http.path'   => $request->path(),
            'http.ip'     => (string) $request->ip(),
        ]);

        try {
            /** @var Response $response */
            $response = $next($request);
        } catch (Throwable $e) {
            $this->jaeger->stop($operation, [
                'error'         => true,
                'error.message' => $e->getMessage(),
                'error.class'   => get_class($e),
            ]);
            throw $e;
        }

        $status = method_exists($response, 'getStatusCode') ? (int) $response->getStatusCode() : 0;

        $this->jaeger->stop($operation, [
            'http.status_code' => $status,
        ]);

        // Lumen has no Application::terminating(); flush here so spans don't
        // rely on the tracer's __destruct() firing at an unpredictable time.
        $this->jaeger->finish();

        return $response;
    }

    /**
     * @param Request $request
     * @return bool
     */
    private function shouldSkip(Request $request)
    {
        $patterns = (array) config('jaeger.exclude_paths', []);
        if (empty($patterns)) {
            return false;
        }

        // Request::is() supports '*' wildcards and matches without the
        // leading slash, so both 'health' and 'api/v1/metrics/*' work.
        return call_user_func_array([$request, 'is'], $patterns);
    }

    /**
     * Best-effort route name/URI extraction that works across Laravel and
     * Lumen without triggering fatals if no route is bound.
     *
     * @param Request $request
     * @return string
     */
    private function route(Request $request)
    {
        try {
            $route = $request->route();
        } catch (Throwable $e) {
            return $request->path();
        }

        if (is_object($route) && method_exists($route, 'uri')) {
            return $route->uri();
        }
        if (is_array($route) && isset($route[1]['uses'])) {
            // Lumen route info tuple: [status, ['uses' => ..., 'as' => ...], params]
            return isset($route[1]['as']) ? (string) $route[1]['as'] : (string) $route[1]['uses'];
        }

        return $request->path();
    }
}
