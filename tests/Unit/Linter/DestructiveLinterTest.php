<?php

namespace DBDiff\Tests\Unit\Linter;

use PHPUnit\Framework\TestCase;
use DBDiff\Linter\DestructiveLinter;
use DBDiff\Linter\LintResult;
use DBDiff\Linter\LintViolation;
use DBDiff\Diff\DropTable;
use DBDiff\Diff\AlterTableDropColumn;
use DBDiff\Diff\AlterTableAddColumn;
use DBDiff\Diff\DropEnum;
use DBDiff\Diff\DropRoutine;
use DBDiff\Diff\DropTrigger;
use DBDiff\Diff\DropView;
use DBDiff\Diff\DropMatView;
use DBDiff\Diff\DropSequence;
use DBDiff\Diff\DropCompositeType;
use DBDiff\Diff\DropDomain;
use DBDiff\Diff\DropPolicy;
use DBDiff\Diff\AddTable;

class DestructiveLinterTest extends TestCase
{
    private DestructiveLinter $linter;

    protected function setUp(): void {
        $this->linter = new DestructiveLinter();
    }

    // ── DropTable ───────────────────────────────────────────────────────────

    public function testDropTableProducesError(): void {
        $diff = ['schema' => [new DropTable('orders', null, 'source')]];
        $result = $this->linter->lint($diff);

        $this->assertTrue($result->hasErrors());
        $errors = $result->getErrors();
        $this->assertCount(1, $errors);
        $this->assertSame('error', $errors[0]->level);
        $this->assertSame('drop-table', $errors[0]->type);
        $this->assertStringContainsString('orders', $errors[0]->object);
        $this->assertStringContainsString('allow-destructive', $errors[0]->suggestion);
    }

    public function testMultipleDropTablesAllReportedAsErrors(): void {
        $diff = [
            'schema' => [
                new DropTable('orders', null, 'source'),
                new DropTable('payments', null, 'source'),
            ]
        ];
        $result = $this->linter->lint($diff);

        $this->assertCount(2, $result->getErrors());
    }

    // ── AlterTableDropColumn ────────────────────────────────────────────────

    public function testDropColumnProducesError(): void {
        $colDiff = (object)['Type' => 'varchar(255)'];
        $diff = ['schema' => [new AlterTableDropColumn('users', 'email', $colDiff)]];
        $result = $this->linter->lint($diff);

        $this->assertTrue($result->hasErrors());
        $errors = $result->getErrors();
        $this->assertCount(1, $errors);
        $this->assertSame('drop-column', $errors[0]->type);
        $this->assertStringContainsString('users', $errors[0]->object);
        $this->assertStringContainsString('email', $errors[0]->object);
    }

    // ── Rename heuristic ────────────────────────────────────────────────────

    public function testDropPlusAddSameTypeSameTableIsWarnedAsRename(): void {
        $colDiff = (object)['Type' => 'varchar(255)'];
        $diff = [
            'schema' => [
                new AlterTableDropColumn('users', 'email', $colDiff),
                new AlterTableAddColumn('users', 'email_address', $colDiff),
            ]
        ];
        $result = $this->linter->lint($diff);

        // Should be warning (possible-rename), not error (drop-column)
        $this->assertFalse($result->hasErrors());
        $this->assertTrue($result->hasWarnings());
        $warnings = $result->getWarnings();
        $this->assertCount(1, $warnings);
        $this->assertSame('possible-rename', $warnings[0]->type);
    }

    public function testDropPlusAddDifferentTypeIsStillError(): void {
        $intDiff    = (object)['Type' => 'int'];
        $varcharDiff = (object)['Type' => 'varchar(100)'];
        $diff = [
            'schema' => [
                new AlterTableDropColumn('users', 'age', $intDiff),
                new AlterTableAddColumn('users', 'nickname', $varcharDiff),
            ]
        ];
        $result = $this->linter->lint($diff);

        $this->assertTrue($result->hasErrors());
        $errors = $result->getErrors();
        $this->assertSame('drop-column', $errors[0]->type);
    }

    public function testDropPlusAddOnDifferentTablesIsError(): void {
        $colDiff = (object)['Type' => 'int'];
        $diff = [
            'schema' => [
                new AlterTableDropColumn('users', 'score', $colDiff),
                new AlterTableAddColumn('posts', 'score', $colDiff),
            ]
        ];
        $result = $this->linter->lint($diff);

        // Tables differ — no rename heuristic should fire
        $this->assertTrue($result->hasErrors());
        $this->assertSame('drop-column', $result->getErrors()[0]->type);
    }

    public function testRenameHeuristicWorkWithArrayDiffFormat(): void {
        $colDiff = ['Type' => 'text'];
        $diff = [
            'schema' => [
                new AlterTableDropColumn('articles', 'body', $colDiff),
                new AlterTableAddColumn('articles', 'content', $colDiff),
            ]
        ];
        $result = $this->linter->lint($diff);

        $this->assertFalse($result->hasErrors());
        $this->assertSame('possible-rename', $result->getWarnings()[0]->type);
    }

    // ── DropEnum ────────────────────────────────────────────────────────────

    public function testDropEnumProducesWarning(): void {
        $diff = ['schema' => [new DropEnum('user_status', 'CREATE TYPE "user_status" AS ENUM (\'active\')')]];
        $result = $this->linter->lint($diff);

        $this->assertFalse($result->hasErrors());
        $this->assertTrue($result->hasWarnings());
        $this->assertSame('drop-enum', $result->getWarnings()[0]->type);
        $this->assertStringContainsString('user_status', $result->getWarnings()[0]->object);
    }

    // ── DropRoutine ─────────────────────────────────────────────────────────

    public function testDropRoutineProducesWarning(): void {
        $diff = ['schema' => [new DropRoutine('calculate_tax', 'CREATE FUNCTION calculate_tax()')]];
        $result = $this->linter->lint($diff);

        $this->assertFalse($result->hasErrors());
        $warnings = $result->getWarnings();
        $this->assertCount(1, $warnings);
        $this->assertSame('drop-routine', $warnings[0]->type);
        $this->assertStringContainsString('calculate_tax', $warnings[0]->object);
    }

    // ── DropTrigger ─────────────────────────────────────────────────────────

    public function testDropTriggerProducesWarning(): void {
        $diff = ['schema' => [new DropTrigger('audit_log', 'users', 'CREATE TRIGGER audit_log ...')]];
        $result = $this->linter->lint($diff);

        $warnings = $result->getWarnings();
        $this->assertCount(1, $warnings);
        $this->assertSame('drop-trigger', $warnings[0]->type);
        $this->assertStringContainsString('audit_log', $warnings[0]->object);
        $this->assertStringContainsString('users', $warnings[0]->object);
    }

    // ── DropView ─────────────────────────────────────────────────────────────

    public function testDropViewProducesWarning(): void {
        $diff = ['schema' => [new DropView('active_users', 'CREATE VIEW active_users AS ...')]];
        $result = $this->linter->lint($diff);

        $warnings = $result->getWarnings();
        $this->assertCount(1, $warnings);
        $this->assertSame('drop-view', $warnings[0]->type);
    }

    // ── Newly modelled object kinds ─────────────────────────────────────────

    /**
     * These became possible to emit only once DBDiff modelled the object kinds,
     * so the linter had no rule for them. An unguarded DROP MATERIALIZED VIEW
     * or DROP SEQUENCE discards data or a counter.
     */
    public function testDropMatViewProducesWarning(): void {
        $diff = ['schema' => [new DropMatView('daily_totals', 'CREATE MATERIALIZED VIEW ...')]];
        $warnings = $this->linter->lint($diff)->getWarnings();

        $this->assertCount(1, $warnings);
        $this->assertSame('drop-matview', $warnings[0]->type);
        $this->assertStringContainsString('daily_totals', $warnings[0]->object);
    }

    public function testDropSequenceProducesWarning(): void {
        $diff = ['schema' => [new DropSequence('invoice_no', 'CREATE SEQUENCE ...')]];
        $warnings = $this->linter->lint($diff)->getWarnings();

        $this->assertCount(1, $warnings);
        $this->assertSame('drop-sequence', $warnings[0]->type);
    }

    public function testDropCompositeTypeProducesWarning(): void {
        $diff = ['schema' => [new DropCompositeType('address', 'CREATE TYPE ...')]];
        $warnings = $this->linter->lint($diff)->getWarnings();

        $this->assertCount(1, $warnings);
        $this->assertSame('drop-composite-type', $warnings[0]->type);
    }

    public function testDropDomainProducesWarning(): void {
        $diff = ['schema' => [new DropDomain('positive_int', 'CREATE DOMAIN ...')]];
        $warnings = $this->linter->lint($diff)->getWarnings();

        $this->assertCount(1, $warnings);
        $this->assertSame('drop-domain', $warnings[0]->type);
    }

    /**
     * Dropping a policy widens access rather than removing data, which is why
     * it is reported at all.
     */
    public function testDropPolicyProducesWarningNamingItsTable(): void {
        $diff = ['schema' => [new DropPolicy('tenant_isolation', 'documents', 'CREATE POLICY ...')]];
        $warnings = $this->linter->lint($diff)->getWarnings();

        $this->assertCount(1, $warnings);
        $this->assertSame('drop-policy', $warnings[0]->type);
        $this->assertStringContainsString('tenant_isolation', $warnings[0]->object);
        $this->assertStringContainsString('documents', $warnings[0]->object);
    }

    // ── Clean diffs ──────────────────────────────────────────────────────────

    public function testAddTableProducesNoViolations(): void {
        $diff = ['schema' => [new AddTable('new_feature', null, 'source')]];
        $result = $this->linter->lint($diff);

        $this->assertTrue($result->isEmpty());
        $this->assertFalse($result->hasErrors());
        $this->assertFalse($result->hasWarnings());
    }

    public function testEmptyDiffProducesNoViolations(): void {
        $result = $this->linter->lint([]);

        $this->assertTrue($result->isEmpty());
    }

    public function testDataOnlyDiffProducesNoViolations(): void {
        $result = $this->linter->lint(['data' => ['some data diff item']]);

        $this->assertTrue($result->isEmpty());
    }

    // ── Mixed diff ───────────────────────────────────────────────────────────

    public function testMixedDestructiveAndSafeChangesReportsAll(): void {
        $colDiff = (object)['Type' => 'int'];
        $diff = [
            'schema' => [
                new AddTable('feature_flags', null, 'source'),
                new DropTable('legacy_data', null, 'source'),
                new AlterTableDropColumn('users', 'phone', $colDiff),
                new DropView('old_view', 'CREATE VIEW old_view ...'),
            ]
        ];
        $result = $this->linter->lint($diff);

        $this->assertCount(2, $result->getErrors());
        $this->assertCount(1, $result->getWarnings());
        $this->assertCount(3, $result->getViolations());
    }

    // ── LintResult API ───────────────────────────────────────────────────────

    public function testLintResultGetErrorsFiltersOnlyErrors(): void {
        $colDiff = (object)['Type' => 'int'];
        $diff = [
            'schema' => [
                new DropTable('tbl', null, 'source'),
                new DropView('v', 'CREATE VIEW v ...'),
            ]
        ];
        $result = $this->linter->lint($diff);

        $errors   = $result->getErrors();
        $warnings = $result->getWarnings();
        $this->assertCount(1, $errors);
        $this->assertSame('error', $errors[0]->level);
        $this->assertCount(1, $warnings);
        $this->assertSame('warning', $warnings[0]->level);
    }
}
