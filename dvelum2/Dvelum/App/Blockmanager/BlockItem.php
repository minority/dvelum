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

namespace Dvelum\App\Blockmanager;

class BlockItem
{
    protected $defaultClass = '\\Dvelum\\App\\Block\\Simple';
    /**
     * @var array $config
     */
    protected $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Get item code
     * @return null|string
     */
    public function getCode() : ?string
    {
        if(isset($this->config['code']) && !empty($this->config['code'])){
            return (string) $this->config['code'];
        }
        return null;
    }

    /**
     * Check if block allows caching
     * @return bool
     */
    public function isCacheble() : bool
    {
        return $this->getClass()::cacheable;
    }

    /**
     * Get Block adapter class name
     * @return string
     */
    public function getClass() : string
    {
        $class = $this->defaultClass;
        $config = $this->config;

        if($config['is_system'] && strlen($config['sys_name']) && class_exists($config['sys_name']))
            $class = $config['sys_name'];

        return $class;
    }

    public function __toString()
    {
        $class = new $this->getClass();
        $blockObject = new $class($this->config);

        if(!($blockObject instanceof \Block) && !($blockObject instanceof \Dvelum\App\Block\AbstractAdapter))
            trigger_error('Invalid block class');

        return $blockObject->render();
    }
}