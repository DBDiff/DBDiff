<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use DBDiff\DB\Support\PostgresComments;
use DBDiff\Diff\AlterComment;
use DBDiff\SQLGen\DiffToSQL\AlterCommentSQL;
use DBDiff\SQLGen\Dialect\PostgresDialect;

/** What COMMENT ON names, and which direction sets what; see CommentsRoundTripTest. */
class PostgresCommentsTest extends TestCase
{
    public function testEachKindIsNamedAsCommentOnTakesIt(): void
    {
        $t = fn(string $type, string $identity, array $names = [], array $args = []) =>
            PostgresComments::target($type, $identity, $names, $args);

        $this->assertSame('TABLE public.h', $t('table', 'public.h'));
        $this->assertSame('COLUMN "App Data"."T".n', $t('table column', '"App Data"."T".n'));
        $this->assertSame('COLUMN public.pr.a', $t('composite type column', 'public.pr.a'));
        $this->assertSame('FOREIGN TABLE public.f', $t('foreign table', 'public.f'));
        $this->assertSame('FUNCTION public.f(integer,pg_catalog.text)', $t('function', 'public.f(integer,pg_catalog.text)'));
        $this->assertSame(
            'CONSTRAINT "c on x" ON "App Data"."T t"',
            $t('table constraint', '"c on x" on "App Data"."T t"', ['App Data', 'T t', 'c on x'])
        );
        $this->assertSame('CONSTRAINT "d_pos" ON DOMAIN public.d', $t('domain constraint', 'd_pos on public.d', ['public.d'], ['d_pos']));
        $this->assertSame('TRIGGER "tr" ON "public"."t"', $t('trigger', 'tr on public.t', ['public', 't', 'tr']));
        $this->assertSame('POLICY "p" ON "public"."t"', $t('policy', 'p on public.t', ['public', 't', 'p']));
        $this->assertSame('SCHEMA app', $t('schema', 'app'));
        $this->assertNull($t('rule', 'r on public.t'));
    }

    public function testEachDirectionSetsItsOwnCommentOrNone(): void
    {
        $sql = new AlterCommentSQL(new AlterComment('TABLE public.h', "'new'", 'NULL'), new PostgresDialect());
        $this->assertSame("COMMENT ON TABLE public.h IS 'new';", $sql->getUp());
        $this->assertSame('COMMENT ON TABLE public.h IS NULL;', $sql->getDown());

        $created = new AlterCommentSQL(new AlterComment('VIEW public.v', "'v'", null), new PostgresDialect());
        $this->assertSame('', $created->getDown());
    }

    /** @dataProvider settings */
    public function testWhatADirectionSets(?array $want, ?array $have, bool $recreated, ?string $expected): void
    {
        $setting = new \ReflectionMethod(PostgresComments::class, 'setting');
        $this->assertSame($expected, $setting->invoke(null, $want, $have, $recreated));
    }

    public static function settings(): array
    {
        $with = fn(?string $c) => ['comment' => $c];
        return [
            'dropped: nothing to set'        => [null, $with("'a'"), false, null],
            'created with a comment'         => [$with("'a'"), null, false, "'a'"],
            'created without one'            => [$with(null), null, false, null],
            'the same'                       => [$with("'a'"), $with("'a'"), false, null],
            'changed'                        => [$with("'b'"), $with("'a'"), false, "'b'"],
            'removed'                        => [$with(null), $with("'a'"), false, 'NULL'],
            'recreated keeps its comment'    => [$with("'a'"), $with("'a'"), true, "'a'"],
            'recreated without one'          => [$with(null), $with(null), true, null],
        ];
    }
}
