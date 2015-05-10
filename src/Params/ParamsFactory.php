<?php namespace DBDiff\Params;

use DBDiff\Exceptions\CLIException;


class ParamsFactory {
    
    public function get() {
        
        $params = new DefaultParams;

        $cli = new CLIGetter;
        $paramsCLI = $cli->getParams();
        $params = $this->merge($params, $paramsCLI);

        $fs = new FSGetter($params);
        $paramsFS = $fs->getParams();
        $params = $this->merge($params, $paramsFS);

        if (empty($params->server1)) {
            throw new CLIException("A server is required");
        }
        return $params;

    }

    protected function merge($obj1, $obj2) {
        return (object) array_merge((array) $obj1, (array) $obj2);
    }
}
