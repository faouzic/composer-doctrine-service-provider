<?php

namespace ETNA\Silex\Provider\Config;

use Dflydev\Provider\DoctrineOrm\DoctrineOrmServiceProvider;
use Silex\Application;
use Pimple\ServiceProviderInterface;
use Pimple\Container;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 *
 */
class DoctrineOrm implements ServiceProviderInterface
{

    private $orms_options;

    /**
     * @param array|null $orms_options
     */
    public function __construct(array $orms_options = null)
    {
        $this->orms_options = $orms_options ?: null;
    }

    /**
     *
     * @{inherit doc}
     */
    public function register(Container $app)
    {
        if (true !== isset($app['dbs.options'])) {
            throw new \Exception('$app["dbs.options"] is not set');
        }

        if (!isset($app["application_path"])) {
            throw new \Exception('$app["application_path"] is not set');
        }

        if (!isset($app["application_namespace"])) {
            throw new \Exception('$app["application_namespace"] is not set');
        }

        $app["orm.proxies_dir"]           = realpath("{$app["application_path"]}/tmp/proxies");
        $app["orm.auto_generate_proxies"] = true;
        $app["orm.default_cache"]         = "array";


        if (true === empty($this->orms_options)) {
            $this->orms_options = [];
            $first              = true;
            foreach ($app['dbs.options'] as $db_name => $db_options) {
                $entities_path  = "Entities";
                $entities_path .= (true === $first) ? "" : ucfirst($db_name);

                $this->orms_options[(true === $first) ? "default" : "$db_name"] = [
                    "connection" => $db_name,
                    "mappings"   => [
                        [
                            "type"      => "annotation",
                            "namespace" => "{$app['application_namespace']}\\{$entities_path}",
                            "path"      => "{$app['application_path']}/app/{$entities_path}/",
                        ]
                    ]
                ];

                $first = false;
            }
        }

        $app["orm.ems.options"] = $this->orms_options;
        $app->register(new DoctrineOrmServiceProvider());

        $this->addCustomDqlTypes($app);
        $this->connectRepositories($app);
        $this->connectfindOr404($app);
    }

    /**
     * Rajoute les types de Date et string non présent nativement dans Doctrine,
     * vu que l'on fait que du sql on peut se le permettre :p
     * Rajoute aussi le type enum qui sera transformer en string
     *
     * @param Application $app Silex/Application
     */
    private function addCustomDqlTypes(Application $app)
    {
        $platform = $app["orm.em"]->getConnection()->getDatabasePlatform();
        $platform->registerDoctrineTypeMapping('enum', 'string');

        $configuration = $app["orm.em"]->getConfiguration();

        $configuration->addCustomStringFunction('MD5',  'DoctrineExtensions\Query\Mysql\Md5');
        $configuration->addCustomStringFunction('SHA1', 'DoctrineExtensions\Query\Mysql\Sha1');
        $configuration->addCustomStringFunction('SHA2', 'DoctrineExtensions\Query\Mysql\Sha2');

        $configuration->addCustomNumericFunction('RAND',  'DoctrineExtensions\Query\Mysql\Rand');
        $configuration->addCustomNumericFunction('ROUND', 'DoctrineExtensions\Query\Mysql\Round');

        $configuration->addCustomDatetimeFunction('DATE',       'DoctrineExtensions\Query\Mysql\Date');
        $configuration->addCustomDatetimeFunction('DATEDIFF',   'DoctrineExtensions\Query\Mysql\DateDiff');
        $configuration->addCustomDatetimeFunction('DATEADD',    'DoctrineExtensions\Query\Mysql\DateAdd');
        $configuration->addCustomDatetimeFunction('DATEFORMAT', 'DoctrineExtensions\Query\Mysql\DateFormat');
        $configuration->addCustomDatetimeFunction('HOUR',       'DoctrineExtensions\Query\Mysql\Hour');
        $configuration->addCustomDatetimeFunction('DAY',        'DoctrineExtensions\Query\Mysql\Day');
        $configuration->addCustomDatetimeFunction('WEEK',       'DoctrineExtensions\Query\Mysql\Week');
        $configuration->addCustomDatetimeFunction('MONTH',      'DoctrineExtensions\Query\Mysql\Month');
        $configuration->addCustomDatetimeFunction('YEAR',       'DoctrineExtensions\Query\Mysql\Year');
    }

    /**
     * Connect les repositories doctrine à l'application pour pouvoir faire
     * ex : $conversation = $app['repositories']('Conversation')->findOneById(1);
     *
     * Si il y a plusieurs db de loader les autres devront être appelées de la sorte
     * ex : $app['repositories_stats']('Stats')->findOneById(1);
     *
     * @param  Application   $app Silex Application
     *
     * @return null|Repositories
     */
    private function connectRepositories(Application $app)
    {
        foreach ($app["orm.ems.options"] as $db_name => $db_config) {
            $repositories_access = $db_name === 'default' ? 'repositories' : "repositories_{$db_name}";

            // Connect Repositories to the app
            $app[$repositories_access] = $app->protect(
                function ($repository_name) use ($app, $db_config) {
                    $class_name = "\\{$db_config['mappings'][0]['namespace']}\\". $repository_name;
                    if (class_exists($class_name)) {
                        return $app['orm.em']->getRepository($class_name);
                    }
                    return null;
                }
            );
        }
    }

    /**
     * retoune 404 si l'entité demandée n'est pas trouvée,
     * cette fonction se met sur une route silex
     * ex : ->convert("conversation", $app["findOneOr404Factory"]('Conversation', 'id'));
     *
     * Si il y a plusieurs Dbs les entités de la `default` db sont accessible via $app["findOneOr404Factory"]
     * pour les autre bases ex : $app["findOneOr404Factory_stats"]
     *
     * @param  Application $app Silex Application
     *
     * @return Entities|null
     */
    private function connectfindOr404(Application $app)
    {
        foreach ($app["orm.ems.options"] as $db_name => $db_config) {
            $fct_name = $db_name === 'default' ? 'findOneOr404Factory' : "findOneOr404Factory_{$db_name}";
            $app[$fct_name] = $app->protect(
                function ($entity_name, $field_name = "") use ($app, $db_config) {
                    return function ($field_value) use ($app, $db_config, $entity_name, $field_name) {
                        $class_name = "\\{$db_config['mappings'][0]['namespace']}\\". $entity_name;

                        if (!class_exists($class_name)) {
                            throw new NotFoundHttpException(sprintf('%s does not exist', $class_name));
                        }

                        $object = $app['orm.em']
                            ->getRepository($class_name)
                            ->{"findOneBy{$field_name}"}($field_value);

                        if (null === $object) {
                            throw new NotFoundHttpException(sprintf("{$entity_name} %s does not exist", $field_value));
                        }

                        return $object;
                    };
                }
            );
        }
    }
}
