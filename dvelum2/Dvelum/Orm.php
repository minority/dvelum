<?php
/**
 *  DVelum project https://github.com/dvelum/dvelum
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
 */
declare(strict_types=1);

namespace Dvelum;
use Dvelum\Cache\CacheInterface;
use Dvelum\Config\ConfigInterface;
use Dvelum\Orm\{
    Record, Model, Exception
};

use Dvelum\Db;
use Dvelum\Security\CryptServiceInterface;
use Dvelum\Utils;
use Dvelum\Config;

class Orm
{
    protected $configObjects = [];
    protected $configFiles = [];
    protected $models = [];
    /**
     * @var ConfigInterface
     */
    protected $configSettings;
    /**
     * @var ConfigInterface
     */
    protected $modelSettings;
    /**
     * @var CryptServiceInterface;
     */
    private $cryptService;
    /**
     * @var Record\Store $storage
     */
    protected $storage = null;
    /**
     * @var Record\Store $distributedStorage
     */
    protected $distributedStorage = null;
    /**
     * @var \Eventmanager $eventManager
     */
    protected $eventManager = null;
    /**
     * @var ConfigInterface $config
     */
    protected $config;
    /**
     * @var \Log $log
     */
    protected $log = false;

    protected $translator = false;
    protected $language;

    protected $storeLoader;
    protected $distributedStoreLoader;

    public function init(ConfigInterface $config, Db\ManagerInterface $dbManager, string $language, CacheInterface $cache = null)
    {
        $this->config = $config;
        $this->language = $language;
        $this->eventManager = new \Eventmanager();

        if ($cache) {
            $this->eventManager->setCache($cache);
        }

        $orm = $this;

        $this->modelSettings = Config\Factory::create([
            'hardCacheTime' => $config->get('hard_cache'),
            'dataCache' => $cache,
            'defaultDbManager' => $dbManager,
            'logLoader'=> function() use ($orm){
               return $orm->getLog();
            }
        ]);

        /*
         * Prepare Db_Object
         */
        Orm\Record\Builder::useForeignKeys($config->get('foreign_keys'));

        $this->configSettings = Config\Factory::create([
            'configPath' => $config->get('object_configs'),
            'translatorLoader' => function() use ($orm){
                return $orm->getTranslator();
            },
            'useForeignKeys' => $config->get('foreign_keys'),
            'ivField'=> $config->get('iv_field'),
        ]);

        $this->storeLoader = function()use($orm){return $orm->storage();};
        $this->distributedStoreLoader = function()use($orm){return $orm->distributedStorage();};
    }

    /**
     * Get ORM configuration options
     * @return ConfigInterface
     */
    public function getConfig() : ConfigInterface
    {
        return $this->config;
    }

    public function getTranslator()
    {
        if(empty($this->translator)){
            $this->translator = new Orm\Record\Config\Translator($this->language . '/objects.php');
        }
        return  $this->translator;
    }
    public function getCryptService() : \Dvelum\Security\CryptService
    {
        if(empty($this->cryptService)){
            $this->cryptService =  new \Dvelum\Security\CryptService( Config::storage()->get('crypt.php'));
        }
        return $this->cryptService;
    }

    public function getLog():?\Log
    {
        if($this->log!==false){
            return $this->log;
        }

        if ($this->config->get('db_object_error_log')) {
            $this->log = new Log\File($this->config->get('db_object_error_log_path'));
            /*
             * Switch to Db_Object error log
             */
            if (!empty($this->config->get('error_log_object'))) {
                $errorModel = $this->model($this->config->get('error_log_object'));
                $errorModel->setLog($this->log);
                $errorTable = $errorModel->table();
                $errorDb = $errorModel->getDbConnection();

                $logDb = new Log\Db('error_log', $errorDb, $errorTable);
                $this->log = new Log\Mixed($this->log, $logDb);
            }
        }
        return $this->log;
    }

    public function storage() : Record\Store
    {
        if(empty($this->storage)){
            $storageOptions = [
                'linksObject' => $this->config->get('links_object'),
                'historyObject' => $this->config->get('history_object'),
                'versionObject' => $this->config->get('version_object'),
            ];
            $storeClass = $this->config->get('storage');
            $this->storage = new $storeClass($storageOptions);
            $this->storage->setEventManager($this->eventManager);
            if(!empty($this->log)){
                $this->storage->setLog($this->log);
            }
        }
        return $this->storage;
    }

    public function distributedStorage() : Record\Store
    {
        if(empty($this->distributedStorage)){
            $storageOptions = [
                'linksObject' => $this->config->get('links_object'),
                'historyObject' => $this->config->get('history_object'),
                'versionObject' => $this->config->get('version_object'),
            ];
            $distributedStoreClass = $this->config->get('distributed_storage');
            $this->distributedStorage = new $distributedStoreClass($storageOptions);
            $this->distributedStorage->setEventManager($this->eventManager);
            if(!empty($this->log)){
                $this->distributedStorage->setLog($this->log);
            }
        }
        return $this->distributedStorage;
    }

    /**
     * @param string $name
     * @param bool $id
     * @deprecated
     * @throws \Exception
     * @return mixed
     */
    public function object(string $name, $id = false)
    {
        return $this->record($name, $id);
    }
    /**
     * Factory method of object creation is preferable to use, cf. method  __construct() description
     * @param string $name
     * @param int|int[]|bool $id , optional default false
     * @throws \Exception
     * @return Orm\Record|Orm\Record[]
     */
    public function record(string $name, $id = false)
    {
        if (!is_array($id)) {
            return new Record($name, $id);
        }

        $list = [];

        $model = $this->model($name);
        $config = $this->config($name);

        $data = $model->getItems($id);

        /*
         * Load links info
         */
        $links = $config->getLinks([Record\Config::LINK_OBJECT_LIST]);
        $linksData = [];

        if (!empty($links)) {
            foreach ($links as $object => $fields) {
                foreach ($fields as $field => $linkType) {
                    $fieldObject = $config->getField($field);
                    if ($fieldObject->isManyToManyLink()) {
                        $relationsObject = $config->getRelationsObject($field);
                        $relationsData = $this->model($relationsObject)->query()
                            ->params([
                                'sort' => 'order_no',
                                'dir' => 'ASC'
                            ])
                            ->filters(['source_id' => $id])
                            ->fields(['target_id', 'source_id'])
                            ->fetchAll();
                    } else {
                        $linkedObject = $fieldObject->getLinkedObject();
                        $linksObject = $this->model($linkedObject)->getStore()->getLinksObjectName();
                        $linksModel = $this->model($linksObject);
                        $relationsData = $linksModel->query()
                            ->params(['sort' => 'order', 'dir' => 'ASC'])
                            ->filters([
                                'src' => $name,
                                'src_id' => $id,
                                'src_field' => $field,
                                'target' => $linkedObject
                            ])
                            ->fields(['target_id', 'source_id' => 'src_id'])
                            ->fetchAll();
                    }
                    if (!empty($relationsData)) {
                        $linksData[$field] = Utils::groupByKey('source_id', $relationsData);
                    }
                }
            }
        }

        $primaryKey = $config->getPrimaryKey();
        foreach ($data as $item) {
            $o = new Record($name);
            $o->disableAcl(true);
            /*
             * Apply links info
             */
            if (!empty($linksData)) {
                foreach ($linksData as $field => $source) {
                    if (isset($source[$item[$primaryKey]])) {
                        $item[$field] = Utils::fetchCol('target_id', $source[$item[$primaryKey]]);
                    }
                }
            }

            $o->setId($item[$primaryKey]);
            $o->setRawData($item);
            $list[$item[$primaryKey]] = $o;
            $o->disableAcl(false);
        }
        return $list;
    }

    /**
     * Instantiate data structure for the objects named $name
     * @param string $name - object name
     * @param boolean $force - reload config
     * @return Orm\Record\Config
     * @throws Exception
     */
    public function config(string $name, bool $force = false): Orm\Record\Config
    {
        $name = strtolower($name);

        if ($force || !isset($this->configObjects[$name])) {
            $config = new Record\Config($name, $force, $this->configSettings);
            $orm = $this;
            $loader = function () use ($orm){
                return $orm->cryptService();
            };
            $config->setCryptServiceLoader($loader);
            $this->configObjects[$name] = $config;
        }
        return $this->configObjects[$name];
    }

    /**
     * Object config existence check
     * @param $name
     * @return bool
     */
    public function configExists($name): bool
    {
        $name = strtolower($name);

        if (isset($this->configObjects[$name]) || isset($this->configFiles[$name])) {
            return true;
        }

        $cfgPath = $this->configSettings->get('configPath');

        if (Config\Factory::storage()->exists($cfgPath . $name . '.php')) {
            $this->configFiles[$name] = $cfgPath . $name . '.php';
            return true;
        }

        return false;
    }

    /**
     * Get ORM Object Config settings
     * @return ConfigInterface
     */
    public function getConfigSettings(): ConfigInterface
    {
        return $this->configSettings;
    }

    /**
     * Get Orm Model Settings
     * @return ConfigInterface
     */
    public function getModelSettings() : ConfigInterface
    {
        return $this->modelSettings;
    }

    /**
     * Factory method of model instantiation
     * @param string $objectName — the name of the object in ORM
     * @return Model
     */
    public function model(string $objectName): Model
    {
        $listName = strtolower($objectName);

        if (isset($this->models[$listName])) {
            return $this->models[$listName];
        }

        $objectName = implode('_', array_map('ucfirst', explode('_', $listName)));

        $className = 'Model_' . $objectName;
        $nameSpacedClassName = 'App\\'.str_replace('_','\\', $className);

        $modelSettings = $this->modelSettings;
        $modelSettings['storeLoader'] = $this->storeLoader;

        /*
         * Instantiate real or virtual model
         */
        if (class_exists($className)) {
            $this->models[$listName] = new $className($objectName, $modelSettings, $this->config);
        } elseif (class_exists($nameSpacedClassName)) {
            $this->models[$listName] = new $nameSpacedClassName($objectName, $modelSettings, $this->config);
        } else {
            if($this->config($objectName)->isDistributed()){
                $modelSettings['storeLoader'] = $this->distributedStoreLoader;
                $this->models[$listName] = new Orm\Distributed\Model($objectName, $modelSettings, $this->config);
            }else{
                $this->models[$listName] = new Model($objectName, $modelSettings, $this->config);
            }
        }
        return $this->models[$listName];
    }
}