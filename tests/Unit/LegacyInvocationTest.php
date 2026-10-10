<?php

use DBDiff\Params\LegacyInvocation;
use PHPUnit\Framework\TestCase;

/**
 * The 2.x command line still works: `dbdiff server1.db1:server2.db2`.
 *
 * `diff` was made the default command for it, but Symfony takes the first
 * argument for a command name, so the input was rejected as an unknown
 * namespace ("There are no commands defined in the "server1.db1" namespace").
 */
class LegacyInvocationTest extends TestCase
{
    private function invoke(array $args): array
    {
        $commands = ['diff', 'migration:new', 'migration:up', 'url:encode'];
        return LegacyInvocation::withDiff(array_merge(['dbdiff'], $args), fn($n) => in_array($n, $commands, true));
    }

    public function testItPutsDiffInFrontOfALegacyInput(): void
    {
        $this->assertSame(['dbdiff', 'diff', 'server1.db1:server2.db2'], $this->invoke(['server1.db1:server2.db2']));
        $this->assertSame(['dbdiff', 'diff', '--type=data', 'server1.dev.t1:server2.prod.t1', '--nocomments'],
            $this->invoke(['--type=data', 'server1.dev.t1:server2.prod.t1', '--nocomments']));
        $this->assertSame(['dbdiff', 'diff', '--driver=sqlite', 'server1./var/db/v1.db:server1./var/db/v2.db'],
            $this->invoke(['--driver=sqlite', 'server1./var/db/v1.db:server1./var/db/v2.db']));
    }

    public function testItLeavesCommandsAndEverythingElseAlone(): void
    {
        foreach ([['diff', 'server1.a:server2.b'], ['migration:new', 'x'], ['url:encode', 'p@ss'], ['--version'], []] as $args) {
            $this->assertSame(array_merge(['dbdiff'], $args), $this->invoke($args), implode(' ', $args));
        }
    }
}
