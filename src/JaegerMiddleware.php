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
        // Pre-request tracing. Never let a tracing failure block the app —
        // if setup throws, run the handler without a span.
        $operation = null;
        try {
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
        } catch (Throwable $e) {
            return $next($request);
        }

        // Run the app. Business exceptions must propagate; only tracing
        // side-effects around them are best-effort.
        try {
            /** @var Response $response */
            $response = $next($request);
        } catch (Throwable $e) {
            try {
                $meta = $this->routeMeta($request);
                if (!empty($meta)) {
                    $this->jaeger->addTags($meta);
                }
                if ($operation !== null) {
                    $this->jaeger->stop($operation, [
                        'error'         => true,
                        'error.message' => $e->getMessage(),
                        'error.class'   => get_class($e),
                    ]);
                }
                $this->jaeger->finish();
            } catch (Throwable $trErr) {
                // ignore tracing error while re-throwing the business one
            }
            throw $e;
        }

        // Post-request tracing. Same rule: swallow tracing errors.
        try {
            // Route info is only bound to the request AFTER the router has
            // dispatched inside $next(). Under Lumen a global middleware
            // runs before dispatch, so we defer routeMeta() until here.
            $meta = $this->routeMeta($request);
            if (!empty($meta)) {
                $this->jaeger->addTags($meta);
            }

            $status = method_exists($response, 'getStatusCode') ? (string) $response->getStatusCode() : '0';
            if ($operation !== null) {
                // Passed as string to work around a Zipkin-compact-UDP
                // serialization mismatch where integer tags come out as
                // base64-encoded ASCII ('MjAw' for 200) and Jaeger fails
                // to parse them.
                $this->jaeger->stop($operation, ['http.status_code' => $status]);
            }
            // Lumen has no Application::terminating(); flush here so spans
            // don't rely on Jaeger::__destruct() firing at an unpredictable
            // time.
            $this->jaeger->finish();
        } catch (Throwable $e) {
            // swallow
        }

        return $response;
    }

    /**
     * @param Request $request
     * @return bool
     */
    private function shouldSkip(Request $request)
    {
        try {
            $patterns = (array) config('jaeger.exclude_paths', []);
            if (empty($patterns)) {
                return false;
            }

            // Request::is() supports '*' wildcards and matches without the
            // leading slash, so both 'health' and 'api/v1/metrics/*' work.
            return call_user_func_array([$request, 'is'], $patterns);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Pull route metadata (controller class, action method, route name) into
     * span tags so the Jaeger UI shows which handler ran, not just the URI.
     *
     * @param Request $request
     * @return array
     */
    private function routeMeta(Request $request)
    {
        try {
            $route = $request->route();
        } catch (Throwable $e) {
            return [];
        }

        $meta = [];
        $action = null;

        if (is_object($route)) {
            // Laravel: Route object.
            if (method_exists($route, 'getActionName')) {
                $action = (string) $route->getActionName();
            }
            if (method_exists($route, 'getName')) {
                $name = $route->getName();
                if (!empty($name)) {
                    $meta['route.name'] = (string) $name;
                }
            }
        } elseif (is_array($route) && isset($route[1]) && is_array($route[1])) {
            // Lumen: [status, ['uses' => 'Foo@bar', 'as' => 'name'], params]
            if (isset($route[1]['uses'])) {
                $action = (string) $route[1]['uses'];
            }
            if (isset($route[1]['as'])) {
                $meta['route.name'] = (string) $route[1]['as'];
            }
        }

        if ($action !== null && $action !== '') {
            $meta['route.action'] = $action;
            if (strpos($action, '@') !== false) {
                list($cls, $mtd) = explode('@', $action, 2);
                $meta['route.controller'] = $cls;
                $meta['route.method']     = $mtd;
            }
        }

        return $meta;
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
