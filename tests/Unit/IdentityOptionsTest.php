<?php

namespace DBDiff\Tests\Unit;

use DBDiff\DB\Support\IdentityOptions;
use PHPUnit\Framework\TestCase;

/**
 * An identity's sequence options, changed in place (issue #236).
 */
class IdentityOptionsTest extends TestCase
{
    public function testParsesEachOption(): void
    {
        $this->assertSame(
            ['INCREMENT BY' => '10', 'MINVALUE' => '5', 'MAXVALUE' => '500', 'START WITH' => '5', 'CYCLE' => ''],
            IdentityOptions::parse('INCREMENT BY 10 MINVALUE 5 MAXVALUE 500 START WITH 5 CYCLE')
        );
        $this->assertSame([], IdentityOptions::parse(null));
        $this->assertSame([], IdentityOptions::parse('NO CYCLE'));
    }

    public function testDirection(): void
    {
        $this->assertTrue(IdentityOptions::ascending(null));
        $this->assertTrue(IdentityOptions::ascending('INCREMENT BY 10'));
        $this->assertFalse(IdentityOptions::ascending('INCREMENT BY -1'));
    }

    public function testAnAddedOrChangedOptionIsSet(): void
    {
        $this->assertSame(
            ['SET INCREMENT BY 10', 'SET MAXVALUE 100000', 'SET CYCLE'],
            IdentityOptions::changes('INCREMENT BY 5', 'INCREMENT BY 10 MAXVALUE 100000 CYCLE')
        );
    }

    public function testARemovedOptionGoesBackToItsDefault(): void
    {
        $this->assertSame(
            ['SET INCREMENT BY 1', 'SET NO MINVALUE', 'SET NO MAXVALUE', 'SET START WITH 1', 'SET NO CYCLE'],
            IdentityOptions::changes('INCREMENT BY 10 MINVALUE 5 MAXVALUE 500 START WITH 5 CYCLE', null)
        );
    }

    public function testARemovedStartIsLeftAloneWhereItsDefaultIsNotOne(): void
    {
        // Descending, or with a minimum: the default start is not 1.
        $this->assertSame(
            ['SET INCREMENT BY -1', 'SET NO MAXVALUE'],
            IdentityOptions::changes('START WITH 5 MAXVALUE 9', 'INCREMENT BY -1')
        );
        $this->assertNotContains('SET START WITH 1', IdentityOptions::changes('START WITH 5 MINVALUE 3', 'MINVALUE 3'));
    }

    public function testNothingChangesNothing(): void
    {
        $this->assertSame([], IdentityOptions::changes('INCREMENT BY 2', 'INCREMENT BY 2'));
    }
}
