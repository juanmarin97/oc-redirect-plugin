<?php

declare(strict_types=1);

namespace Vdlp\Redirect\Classes;

use Closure;
use Illuminate\Http\Request;
use October\Rain\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Throwable;
use Vdlp\Redirect\Classes\Contracts\CacheManagerInterface;
use Vdlp\Redirect\Classes\Contracts\RedirectConditionInterface;
use Vdlp\Redirect\Classes\Contracts\RedirectManagerInterface;

final class RedirectMiddleware
{
    /**
     * @var RedirectManagerInterface
     */
    private $redirectManager;

    /**
     * @var CacheManagerInterface
     */
    private $cacheManager;

    /**
     * @var Dispatcher
     */
    private $dispatcher;

    /**
     * @var LoggerInterface
     */
    private $log;

    public function __construct(
        RedirectManagerInterface $redirectManager,
        CacheManagerInterface $cacheManager,
        Dispatcher $dispatcher,
        LoggerInterface $log
    ) {
        $this->redirectManager = $redirectManager;
        $this->cacheManager = $cacheManager;
        $this->dispatcher = $dispatcher;
        $this->log = $log;
    }

    /**
     * Run the request filter.
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Only handle specific request methods.
        if (!in_array($request->method(), ['GET', 'POST', 'HEAD'], true)) {
            return $next($request);
        }

        if ($request->header('X-Vdlp-Redirect') === 'Tester') {
            $this->redirectManager->setSettings(new RedirectManagerSettings(false, false));
        }

        $rule = false;

        $requestUri = str_replace($request->getBasePath(), '', $request->getRequestUri());

        try {
            if ($this->cacheManager->cachingEnabledAndSupported()) {
                $rule = $this->redirectManager->matchCached($requestUri, $request->getScheme());
            } else {
                $rule = $this->redirectManager->match($requestUri, $request->getScheme());
            }
        } catch (Throwable $e) {
            $this->log->error("Vdlp.Redirect: Could not perform redirect for $requestUri: " . $e->getMessage());
        }

        if (!$rule) {
            return $next($request);
        }

        /*
         * Extensibility:
         *
         * At this point a positive match was made based on the request URI.
         */
        $this->dispatcher->fire('vdlp.redirect.match', [$rule, $requestUri]);

        /*
         * Extensibility:
         *
         * Developers can add their own conditions. If a condition does not pass the redirect will be ignored.
         */
        foreach ($this->redirectManager->getConditions() as $condition) {
            /** @var RedirectConditionInterface $condition */
            $condition = resolve($condition);

            if (!$condition->passes($rule, $requestUri)) {
                return $next($request);
            }
        }

        $this->redirectManager->redirectWithRule($rule, $requestUri);

        return $next($request);
    }
}
