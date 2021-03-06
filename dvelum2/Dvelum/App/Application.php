<?php
/**
 *  DVelum project http://code.google.com/p/dvelum/ , https://github.com/k-samuel/dvelum , http://dvelum.net
 *  Copyright (C) 2011-2017  Kirill Yegorov
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

declare(strict_types=1);

namespace Dvelum\App;

use Dvelum\{
    Request,
    Response,
    Resource,
    View,
    Autoload,
    Config,
    Config\ConfigInterface,
    Db,
    Orm,
    Lang,
    Utils,
    Service,
    Cache\CacheInterface
};
use \Exception;


/**
 * Application - is the main class that initializes system configuration
 * settings. The system starts working with running an object of this class.
 * @author Kirill A Egorov
 */
class Application
{
    const MODE_PRODUCTION = 0;
    const MODE_DEVELOPMENT = 1;
    const MODE_TEST = 2;
    const MODE_INSTALL = 3;

    /**
     * Application config
     * @var Config\Adapter
     */
    protected $config;

    /**
     * @var \Cache_Interface
     */
    protected $cache;

    /**
     * @var boolean
     */
    protected $initialized = false;

    /**
     * @var Autoload
     */
    protected $autoloader;

    /**
     * The constructor accepts the main configuration object as an argument
     * @param ConfigInterface $config
     */
    public function __construct(ConfigInterface $config)
    {
        $this->config = $config;
    }

    /**
     * Inject Auto-loader
     * @param Autoload $autoloader
     */
    public function setAutoloader(Autoload $autoloader)
    {
        $this->autoloader = $autoloader;
    }

    /**
     * Initialize the application, configure the settings, inject dependencies
     * Adjust the settings necessary for running the system
     */
    public function init()
    {
        if ($this->initialized) {
            return;
        }

        date_default_timezone_set($this->config->get('timezone'));

        /*
         * Init cache connection
         */
        $cache = $this->initCache();
        $this->cache = $cache;

        /*
         * Init database connection
         */
        $dbManager = $this->initDb();

        /*
         * Init templates storage
         */
        $templateStorage = View::storage();
        $templateStorage->setConfig(Config\Factory::storage()->get('template_storage.php')->__toArray());

        $request = Request::factory();
        $request->setConfig(Config\Factory::create([
            'delimiter' => $this->config->get('urlDelimiter'),
            'extension' => $this->config->get('urlExtension'),
            'wwwRoot' => $this->config->get('wwwRoot')
        ]));

        $resource = Resource::factory();
        $resource->setConfig(Config\Factory::create([
            'jsCacheUrl' => $this->config->get('jsCacheUrl'),
            'jsCachePath' => $this->config->get('jsCachePath'),
            'cssCacheUrl' => $this->config->get('cssCacheUrl'),
            'cssCachePath' => $this->config->get('cssCachePath'),
            'wwwRoot' => $this->config->get('wwwRoot'),
            'wwwPath' => $this->config->get('wwwPath'),
            'cache' => $cache
        ]));

        Utils::setSalt($this->config->get('salt'));

        /*
         * Init lang dictionary (Lazy Load)
         */
        $lang = $this->config->get('language');

        /*
         * Register Services
         */
        Service::register(
            Config::storage()->get('services.php'),
            Config\Factory::create([
                'appConfig' => $this->config,
                'dbManager' => $dbManager,
                'cache' => $cache
            ])
        );

        // init external modules
        $externalsCfg = $this->config->get('externals');
        if ($externalsCfg['enabled']) {
            $this->initExternals();
        }

        $request = Request::factory();
        $response = Response::factory();

        if ($request->isAjax()) {
            $response->setFormat(Response::FORMAT_JSON);
        } else {
            $response->setFormat(Response::FORMAT_HTML);
        }

        $this->initialized = true;
    }

    /**
     * Init additional external modules
     * defined in external_modules option
     * of main configuration file
     */
    protected function initExternals()
    {
        $externals = Config\Factory::storage()->get('external_modules.php');

        \Externals_Manager::setConfig([
            'appConfig' => $this->config,
            'autoloader' => $this->autoloader
        ]);

        if ($externals->getCount()) {
            \Externals_Manager::factory()->loadModules();
        }
    }

    /**
     * Initialize Cache connections
     * @return CacheInterface | null
     */
    protected function initCache(): ? CacheInterface
    {
        if (!$this->config->get('use_cache')) {
            return null;
        }

        $cacheConfig = Config::storage()->get('cache.php')->__toArray();
        $cacheManager = new Cache\Manager();

        foreach ($cacheConfig as $name => $cfg) {
            if ($cfg['enabled']) {
                $cacheManager->connect($name, $cfg);
            }
        }

        if ($this->config->get('development')) {
            \Debug::setCacheCores($cacheManager->getRegistered());
        }

        return $cacheManager->get('data');
    }

    /**
     * Initialize Database connection
     * @return Db\ManagerInterface
     */
    protected function initDb()
    {
        $templatesPath = $this->config->get('templates');
        $dev = $this->config->get('development');
        $dbErrorHandler = function ( Db\Adapter\Event $e) use($templatesPath , $dev){
            $response = Response::factory();
            $request = Request::factory();
            if($request->isAjax()){
                $response->error(Lang::lang()->get('CANT_CONNECT'));
                exit();
            }else{
                $tpl = View::factory();
                $tpl->set('error_msg', ' ' . $e->getData()['message']);
                $tpl->set('development', $dev);
                echo $tpl->render('public/error.php');
                exit();
            }
        };

        $conManager = new Db\Manager($this->config);
        $conManager->setConnectionErrorHandler($dbErrorHandler);
        return $conManager;
    }

    /**
     * Start application
     */
    public function run()
    {
        $this->init();
        $page = Request::factory()->getPart(0);

        if ($page === $this->config->get('adminPath')) {
            $this->routeBackOffice();
        } else {
            $this->routeFrontend();
        }
    }

    /**
     * Start console application
     */
    public function runConsole()
    {
        $request = Request::factory();
        $response = Response::factory();
        $config = Config::storage()->get('console.php');
        $routerClass = $config->get('router');
        $router = new $routerClass();
        $router->route($request, $response);
        if (!$response->isSent()) {
            $response->send();
        }
    }

    /**
     * Run backend application
     */
    protected function routeBackOffice()
    {
        $request = Request::factory();
        $response = Response::factory();
        /*
         * Start routing
         */
        $router = new Router\Backend();
        $router->route($request, $response);

        if (!$response->isSent()) {
            $response->send();
        }
    }

    /**
     * Run frontend application
     */
    protected function routeFrontend()
    {
        $request = Request::factory();
        $response = Response::factory();

        if ($this->config->get('maintenance')) {
            $tpl = View::factory();
            $tpl->set('msg', Lang::lang()->get('MAINTENANCE'));
            $response->put($tpl->render('public/maintenance.php'));
            $response->send();
            return;
        }

        $auth = new Auth($request, $this->config);
        $auth->auth();

        /*
         * Start routing
        */
        $frontConfig = Config::storage()->get('frontend.php');
        $routerClass = '\\Dvelum\\App\\Router\\' . $frontConfig->get('router');

        if (!class_exists($routerClass)) {
            $routerClass = $frontConfig->get('router');
        }

        /**
         * @var \Dvelum\App\Router $router
         */
        $router = new $routerClass();
        $router->route($request, $response);

        if (!$response->isSent()) {
            $response->send();
        }
    }
}