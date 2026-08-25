<?php

namespace Tests\Unit\Support;

use App\Support\StateAbbreviations;
use Tests\TestCase;

class StateAbbreviationsTest extends TestCase
{
    public function test_resolves_full_state_names_regardless_of_case(): void
    {
        $this->assertSame('TX', StateAbbreviations::toAbbreviation('Texas'));
        $this->assertSame('TX', StateAbbreviations::toAbbreviation('TEXAS'));
        $this->assertSame('TX', StateAbbreviations::toAbbreviation('texas'));
        $this->assertSame('NY', StateAbbreviations::toAbbreviation('New York'));
        $this->assertSame('DC', StateAbbreviations::toAbbreviation('District of Columbia'));
    }

    public function test_passes_through_already_valid_abbreviations(): void
    {
        $this->assertSame('TX', StateAbbreviations::toAbbreviation('TX'));
        $this->assertSame('TX', StateAbbreviations::toAbbreviation('tx'));
        $this->assertSame('NY', StateAbbreviations::toAbbreviation('Ny'));
    }

    public function test_collapses_stray_whitespace(): void
    {
        $this->assertSame('TX', StateAbbreviations::toAbbreviation('  Texas  '));
        $this->assertSame('NY', StateAbbreviations::toAbbreviation('New   York'));
    }

    public function test_returns_null_for_unrecognized_or_empty_input(): void
    {
        $this->assertNull(StateAbbreviations::toAbbreviation('Nowhere'));
        $this->assertNull(StateAbbreviations::toAbbreviation('Ontario'));
        $this->assertNull(StateAbbreviations::toAbbreviation(''));
        $this->assertNull(StateAbbreviations::toAbbreviation('   '));
        $this->assertNull(StateAbbreviations::toAbbreviation(null));
    }
}
