<?php namespace DBDiff\Params;

use DBDiff\Exceptions\FSException;
use Symfony\Component\Yaml\Yaml;


class FSGetter implements ParamsGetter {

    function __construct($params) {
        $this->params = $params;
    }

    public function getParams() {
        $params = new \StdClass;
        $configFile = $this->getFile();

        if ($configFile) {
            try {
                $config = Yaml::parse(file_get_contents($configFile));
                foreach ($config as $key => $value) {
                    $this->setIn($params, $key, $value);
                }
            } catch (Exceptions $e) {
                throw new FSException("Error parsing config file");
            }
        }
        
        return $params;
    }

    protected function getFile() {
        $configFile = false;

        if (isset($this->params->config)) {
            $configFile = $this->params->config;
            if (!file_exists($configFile)) {
                throw new FSException("Config file not found");
            }
        } else {
            if (file_exists('.dbdiff')) {
                $configFile = '.dbdiff';
            }
        }

        return $configFile;
    }

    protected function setIn($obj, $key, $value) {
        if (strpos($key, '-') !== false) {
            $parts = explode('-', $key);
            $array = &$obj->$parts[0];
            $array[$parts[1]] = $value;
        } else {
            $obj->$key = $value;
        }
    }

}
