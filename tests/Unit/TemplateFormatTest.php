<?php

use DBDiff\Migration\Format\TemplateFormat;
use PHPUnit\Framework\TestCase;

/**
 * A template is filled in, never run: `{!! $up !!}` and `{{ $up }}` become the
 * SQL as it is, and anything else in the file stays text.
 */
class TemplateFormatTest extends TestCase
{
    private function render(string $template): string
    {
        $file = tempnam(sys_get_temp_dir(), 'tmpl_');
        file_put_contents($file, $template);
        try {
            return (new TemplateFormat($file, true))->render("INSERT INTO t VALUES ('it''s', \"x\" < 1);\n", "DELETE FROM t;\n");
        } finally {
            unlink($file);
        }
    }

    public function testBothPlaceholderFormsGiveTheSqlUnescaped(): void
    {
        $this->assertSame(
            "UP: INSERT INTO t VALUES ('it''s', \"x\" < 1);\nDOWN: DELETE FROM t;",
            $this->render("UP: {!! \$up !!}\nDOWN: {{ \$down }}")
        );
    }

    public function testNothingInATemplateIsExecuted(): void
    {
        $out = $this->render("<?php echo 'ran'; ?>\n@php echo 'ran'; @endphp\n{!! \$up !!}");
        $this->assertStringContainsString("<?php echo 'ran'; ?>", $out);
        $this->assertStringContainsString("@php echo 'ran'; @endphp", $out);
        $this->assertStringNotContainsString("\nran", $out);
    }

    public function testAMissingTemplateIsAnError(): void
    {
        $this->expectExceptionMessage('Template file not found');
        new TemplateFormat('/nonexistent/template.tmpl');
    }
}
