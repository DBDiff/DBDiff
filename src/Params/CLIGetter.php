<?php namespace DBDiff\Params;

use DBDiff\Exceptions\CLIException;
use Aura\Cli\CliFactory;
use Aura\Cli\Status;


class CLIGetter implements ParamsGetter {
    
    public function getParams() {
        $params = new \StdClass;

        $cliFactory = new CliFactory;
        $context = $cliFactory->newContext($GLOBALS);
        $stdio = $cliFactory->newStdio();

        $getopt = $context->getopt([
            'server1::', 'server2::', 'format::',
            'template::', 'type::', 'include::',
            'nocomments::', 'config::', 'output::', 'debug::'
        ]);
    
        $input = $getopt->get(1);
        if ($input) {
            $params->input = $this->parseInput($input);
        } else throw new CLIException("Missing input");

        if ($getopt->get('--server1'))
            $params->server1 = $this->parseServer($getopt->get('--server1'));
        if ($getopt->get('--server2'))
            $params->server2 = $this->parseServer($getopt->get('--server2'));
        if ($getopt->get('--format'))
            $params->format = $getopt->get('--format');
        if ($getopt->get('--template'))
            $params->template = $getopt->get('--template');
        if ($getopt->get('--type'))
            $params->type = $getopt->get('--type');
        if ($getopt->get('--include'))
            $params->include = $getopt->get('--include');
        if ($getopt->get('--nocomments'))
            $params->nocomments = $getopt->get('--nocomments');
        if ($getopt->get('--config'))
            $params->config = $getopt->get('--config');
        if ($getopt->get('--output'))
            $params->output = $getopt->get('--output');
        if ($getopt->get('--debug'))
            $params->debug = $getopt->get('--debug');

        return $params;
    }

    protected function parseServer($server) {
        $parts = explode('@', $server);
        $creds = explode(':', $parts[0]);
        $dns   = explode(':', $parts[1]);
        return [
            'user'     => $creds[0],
            'password' => $creds[1],
            'host'     => $dns[0],
            'port'     => $dns[1]
        ];
    }

    protected function parseInput($input) {
        $parts  = explode(':', $input);
        if (sizeof($parts) !== 2) {
            throw new CLIException("You need two resources to compare");
        }
        $first  = explode('.', $parts[0]);
        $second = explode('.', $parts[1]);
        if (sizeof($first) !== sizeof($second)) {
            throw new CLIException("The two resources must be of the same kind");
        }

        if (sizeof($first) === 2) {
            return [
                'kind' => 'db',
                'source' => ['server' => $first[0], 'db' => $first[1]],
                'target' => ['server' => $second[0], 'db' => $second[1]],
            ];
        } else if (sizeof($first) === 3) {
            return [
                'kind' => 'table',
                'source' => ['server' => $first[0],  'db' => $first[1],  'table' => $first[2]],
                'target' => ['server' => $second[0], 'db' => $second[1], 'table' => $second[2]],
            ];
        } else throw new CLIException("Unkown kind of resources");
    }
}
