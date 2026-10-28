<?php

namespace Tests\Unit;

use App\Reports\Formats;
use PHPUnit\Framework\TestCase;

class ReportCellTest extends TestCase
{
    public function test_a_cell_takes_the_direction_of_its_first_strong_letter_and_is_escaped(): void
    {
        $ltr = fn (string $value): bool => str_starts_with((string) Formats::cell($value), '<bdi dir="ltr">');

        $this->assertTrue($ltr('=HYPERLINK("http://example.org","اضغط")'));
        $this->assertTrue($ltr('1500.50 USD'));
        $this->assertTrue($ltr('+963944123456'));
        $this->assertFalse($ltr('=1+1 ملاحظات الحكم'));
        $this->assertFalse($ltr('28 تشرين الأول 2026'));
        $this->assertFalse($ltr('—'));
        $this->assertFalse($ltr(''));

        $this->assertSame('<bdi dir="ltr">&lt;script&gt;alert(1)&lt;/script&gt; عمل</bdi>', (string) Formats::cell('<script>alert(1)</script> عمل'));
        $this->assertStringContainsString("\u{200B}", (string) Formats::cell(str_repeat('a', 40)));
    }
}
