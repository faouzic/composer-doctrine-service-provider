<?php

namespace ETNA\Silex\Provider\Config;

use Silex\Application;
use Pimple\ServiceProviderInterface;
use Pimple\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use Doctrine\DBAL\Logging\DebugStack;

class DoctrineDbProfiler implements ServiceProviderInterface
{
    /**
     *
     * @{inherit doc}
     */
    public function register(Container $app)
    {
        if (!isset($app["orm.em"])) {
            throw new \Exception('$app["orm.em"] is not set');
        }

        if ($app["debug"]) {
            $app["orm.profiler"] = new DebugStack();
            $app["orm.em"]->getConnection()->getConfiguration()->setSQLLogger($app["orm.profiler"]);

            $app->after(
                function (Request $request, Response $response) use ($app) {
                    $response->headers->set("X-ORM-Profiler-Route", $request->getPathInfo());
                    $response->headers->set("X-ORM-Profiler-Count", count($app["orm.profiler"]->queries));
                    $response->headers->set("X-ORM-Profiler-Queries", json_encode($app["orm.profiler"]->queries));
                }
            );
        }
    }
}
