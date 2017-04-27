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

namespace Dvelum\Config\File;

use Dvelum\Config;

/**
 * Config reader for arrays in files
 * @author Kirill Egorov 2010
 * @package Config
 * @subpackage File
 */
class AsArray extends Config\File
{
    /**
     * Main config path to apply
     * @var mixed
     */
    protected $applyTo;
    /**
     * (non-PHPdoc)
     * @see library/Config/File#readFile($data)
     */
    protected function readFile(string $name) : array
    {
        return require $name;
    }
    /**
     * (non-PHPdoc)
     * @see Config_File::save()
     * @todo refactor
     */
    public function save() : bool
    {
        if(!empty($this->applyTo) && \file_exists($this->applyTo)) {
            $src = include $this->applyTo;
            $data = [];
            foreach($this->data as $k=>$v){
                if(!isset($src[$k]) || $src[$k]!=$v){
                    $data[$k] = $v;
                }
            }
        }else{
            $data = $this->data;
        }

        if(\file_exists($this->name)) {
            if(!\is_writable($this->name))
                return false;
        } else {
            $dir = dirname($this->name);

            if(!\file_exists($dir)) {
                if(!@mkdir($dir,0775,true))
                    return false;
            } elseif(!\is_writable($dir)) {
                return false;
            }
        }
        if(\Utils::exportArray($this->name, $data)!==false){
            Config\Factory::cache();
            return true;
        }
        return false;
    }

    /**
     * Create config
     * @param string $file - path to config
     * @throws \Exception
     * @return boolean - success flag
     */
    static public function create(string $file) : bool
    {
        $dir = dirname($file);
        if(!file_exists($dir) && !@mkdir($dir,0755, true))
            throw new \Exception('Cannot create '.$dir);

        if(\File::getExt($file)!=='.php')
            throw new \Exception('Invalid file name');

        if(\Utils::exportArray($file, array())!==false)
            return true;

        return false;
    }

    /**
     * Set main config file.
     * @param mixed $path
     */
    public function setApplyTo(string $path)
    {
        $this->applyTo = $path;
    }
}