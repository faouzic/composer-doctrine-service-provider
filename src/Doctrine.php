<?php

namespace ETNA\Silex\Provider\Config;

use Silex\Provider\DoctrineServiceProvider;

use Silex\Application;
use Pimple\ServiceProviderInterface;
use Pimple\Container;

class Doctrine implements ServiceProviderInterface
{
    private $dbs_options;

    /**
     * @param null|string[] $dbs_options
     */
    public function __construct(array $dbs_options = null)
    {
        $dbs_options       = $dbs_options ?: [
            "default",
        ];
        $this->dbs_options = [];
        foreach ($dbs_options as $db_name) {
            $database_env = getenv(strtoupper("{$db_name}_DATABASE_URL"));
            if (false === $database_env) {
                throw new \Exception(strtoupper($db_name) . "_DATABASE_URL doesn't exist");

            }
            $this->dbs_options[$db_name] = [
                "url"     => $database_env,
                "charset" => "utf8",
            ];
        }
    }

    /**
     *
     * @{inherit doc}
     */
    public function register(Container $app)
    {
        $app["dbs.options"] = $this->dbs_options;
        $app->register(new DoctrineServiceProvider());
    }
}
