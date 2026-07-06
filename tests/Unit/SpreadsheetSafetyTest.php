<?php

namespace Tests\Unit;

use App\Support\SpreadsheetSafety;
use PHPUnit\Framework\TestCase;

class SpreadsheetSafetyTest extends TestCase
{
    public function test_lone_dash_placeholder_is_not_escaped(): void
    {
        $this->assertSame('-', SpreadsheetSafety::escapeCell('-'));
    }

    public function test_formula_like_values_are_escaped(): void
    {
        $this->assertSame("'=HYPERLINK(\"http://evil\")", SpreadsheetSafety::escapeCell('=HYPERLINK("http://evil")'));
        $this->assertSame("'+cmd", SpreadsheetSafety::escapeCell('+cmd'));
        $this->assertSame("'@cmd", SpreadsheetSafety::escapeCell('@cmd'));
        $this->assertSame("'-2+3", SpreadsheetSafety::escapeCell('-2+3'));
        $this->assertSame("'--", SpreadsheetSafety::escapeCell('--'));
        $this->assertSame("'\tx", SpreadsheetSafety::escapeCell("\tx"));
        $this->assertSame("'\rx", SpreadsheetSafety::escapeCell("\rx"));
    }

    public function test_plain_values_and_non_strings_pass_through(): void
    {
        $this->assertSame('Budi Santoso', SpreadsheetSafety::escapeCell('Budi Santoso'));
        $this->assertSame('24/535278/SV/12345', SpreadsheetSafety::escapeCell('24/535278/SV/12345'));
        $this->assertSame('2004-05-15', SpreadsheetSafety::escapeCell('2004-05-15'));
        $this->assertSame('', SpreadsheetSafety::escapeCell(''));
        $this->assertSame(42, SpreadsheetSafety::escapeCell(42));
        $this->assertNull(SpreadsheetSafety::escapeCell(null));
    }

    public function test_escape_row_applies_to_every_cell(): void
    {
        $this->assertSame(
            ['-', "'=x", 'aman', 7],
            SpreadsheetSafety::escapeRow(['-', '=x', 'aman', 7])
        );
    }
}
