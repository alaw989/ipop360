<?php

namespace Tests\Unit;

use App\Support\AddressParts;
use PHPUnit\Framework\TestCase;

/**
 * AddressParts reads the city and state a one-line address names.
 */
class AddressPartsTest extends TestCase
{
    public function test_us_style_addresses_name_city_and_state(): void
    {
        $this->assertSame('Phoenix', AddressParts::city('622 E Adams St, Phoenix, AZ 85004'));
        $this->assertSame('AZ', AddressParts::state('622 E Adams St, Phoenix, AZ 85004'));
        $this->assertSame('Novi', AddressParts::city('39777 Grand River Ave, Novi, MI 48375-3021'));
        $this->assertSame('Tacoma', AddressParts::city('410 Harbor Way, Tacoma, WA 98402, USA'));
        $this->assertSame('WA', AddressParts::state('410 Harbor Way, Tacoma, WA 98402, USA'));
        $this->assertSame('Novi', AddressParts::city('39777 Grand River Ave, Novi, MI'));
        $this->assertSame('MI', AddressParts::state('39777 Grand River Ave, Novi, MI'));
    }

    public function test_osm_style_addresses_name_the_city_but_no_state(): void
    {
        $this->assertSame('Mesa', AddressParts::city('West Southern Avenue, 706, Mesa, 85210'));
        $this->assertNull(AddressParts::state('West Southern Avenue, 706, Mesa, 85210'));
        $this->assertSame('Phoenix', AddressParts::city('North 28th Drive, 12418, Phoenix'));
    }

    public function test_addresses_without_a_city_name_none(): void
    {
        $this->assertNull(AddressParts::city('Rainbow Boulevard, 4316, 66103'));
        $this->assertNull(AddressParts::city('1251 River Road'));
        $this->assertNull(AddressParts::city('Research Boulevard, 13376'));
        $this->assertNull(AddressParts::city(null));
        $this->assertNull(AddressParts::city('Main St, IN'), 'a two-part address');
        // A two-letter token that is no state doesn't pass as one.
        $this->assertNull(AddressParts::state('1 Main St, Springfield, ZZ'));
        // Puerto Rico: the city reads, the state isn't a US state abbreviation here.
        $this->assertSame('San Juan', AddressParts::city('1 Calle Loiza, San Juan, PR 00911'));
        $this->assertNull(AddressParts::state('1 Calle Loiza, San Juan, PR 00911'));
    }
}
