<?php

namespace Tests\Unit;

use App\Support\OpeningHoursDisplay;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Stored hours, whatever their source, come out as a week in 12-hour time or
 * as cleaned text.
 */
class OpeningHoursDisplayTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private static function week(mixed $stored): array
    {
        $out = OpeningHoursDisplay::present($stored);
        self::assertIsArray($out);
        self::assertTrue($out['structured'], 'expected a week, got text');
        self::assertArrayHasKey('week', $out);

        return array_column($out['week'], 'hours', 'day');
    }

    public function test_openstreetmap_hours_become_the_week_in_12_hour_time(): void
    {
        $this->assertSame([
            'Monday' => '10:30 AM – 12 AM',
            'Tuesday' => '10:30 AM – 12 AM',
            'Wednesday' => '10:30 AM – 12 AM',
            'Thursday' => '10:30 AM – 12 AM',
            'Friday' => '10:30 AM – 12 AM',
            'Saturday' => '11 AM – 12 AM',
            'Sunday' => '11 AM – 12 AM',
        ], self::week('Mo-Fr 10:30-24:00, Sa,Su 11:00-24:00'));
    }

    public function test_days_the_text_leaves_out_are_closed(): void
    {
        $week = self::week('Tu-Fr 11:00-20:00; Sa 12:00-20:00; Su 12:00-18:00');

        $this->assertSame('Closed', $week['Monday']);
        $this->assertSame('12 PM – 6 PM', $week['Sunday']);
    }

    public function test_day_ranges_wrap_past_sunday_and_lists_may_have_spaces(): void
    {
        $week = self::week('Su-Th 11:00-21:00; Fr, Sa 11:00-22:00');

        $this->assertSame('11 AM – 9 PM', $week['Sunday']);
        $this->assertSame('11 AM – 9 PM', $week['Monday']);
        $this->assertSame('11 AM – 10 PM', $week['Friday']);
        $this->assertSame('11 AM – 10 PM', $week['Saturday']);
    }

    public function test_split_shifts_and_late_nights(): void
    {
        $week = self::week('Tu-Th 11:30-14:30, 17:00-22:30; Fr-Sa 18:00-02:00; Mo off');

        $this->assertSame('11:30 AM – 2:30 PM, 5 PM – 10:30 PM', $week['Tuesday']);
        $this->assertSame('6 PM – 2 AM', $week['Friday']);
        $this->assertSame('Closed', $week['Monday']);
    }

    public function test_a_later_rule_that_overlaps_replaces_and_one_that_does_not_adds(): void
    {
        $longerWeekends = self::week('Mo-Su 11:00-22:00; Fr-Sa 11:00-23:00');
        $this->assertSame('11 AM – 10 PM', $longerWeekends['Thursday']);
        $this->assertSame('11 AM – 11 PM', $longerWeekends['Friday']);

        $lunchThenDinner = self::week('Mo-Fr 11:00-15:00; Mo-Th 17:30-21:30');
        $this->assertSame('11 AM – 3 PM, 5:30 PM – 9:30 PM', $lunchThenDinner['Monday']);
        $this->assertSame('11 AM – 3 PM', $lunchThenDinner['Friday']);
    }

    public function test_around_the_clock(): void
    {
        $this->assertSame(array_fill_keys(
            ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
            'Open 24 hours',
        ), self::week('24/7'));
        $this->assertSame('Open 24 hours', self::week('Mo-Su 00:00-24:00')['Wednesday']);
    }

    public function test_times_without_days_mean_every_day_and_holiday_rules_are_passed_over(): void
    {
        $week = self::week('11:00-20:00; PH off');

        $this->assertSame('11 AM – 8 PM', $week['Monday']);
        $this->assertSame('11 AM – 8 PM', $week['Sunday']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function textThatIsNotAPlainWeek(): array
    {
        return [
            'months' => ['Sep-Jun Su-Th 10:30-21:00; Jul-Aug 10:30-22:00'],
            'open end' => ['We-Su 17:00+'],
            'a comment' => ['closed "temporarily"'],
            'days with no times' => ['Mo-Th'],
        ];
    }

    #[DataProvider('textThatIsNotAPlainWeek')]
    public function test_text_that_is_not_a_plain_week_is_shown_as_it_is(string $text): void
    {
        $this->assertSame(['structured' => false, 'raw_text' => $text], OpeningHoursDisplay::present($text));
    }

    public function test_googles_day_map_is_put_in_order(): void
    {
        $week = self::week([
            'sunday' => "3\u{202F}–9\u{202F}PM",
            'monday' => 'Closed',
            'friday' => "11\u{202F}AM–9\u{202F}PM",
        ]);

        $this->assertSame(['Monday', 'Friday', 'Sunday'], array_keys($week), 'days Google left out are unknown, not closed');
        $this->assertSame('Closed', $week['Monday']);
    }

    public function test_a_schema_org_list_joins_services_and_closes_missing_days(): void
    {
        $week = self::week(['structured' => true, 'hours' => [
            ['day' => 'Tuesday', 'open' => '5 PM', 'close' => '9 PM'],
            ['day' => 'Monday', 'open' => '11 AM', 'close' => '2 PM'],
            ['day' => 'Monday', 'open' => '5 PM', 'close' => '9 PM'],
        ]]);

        $this->assertSame('11 AM – 2 PM, 5 PM – 9 PM', $week['Monday']);
        $this->assertSame('5 PM – 9 PM', $week['Tuesday']);
        $this->assertSame('Closed', $week['Sunday']);
    }

    public function test_scraped_text_is_read_as_a_week_when_it_can_be_and_otherwise_loses_its_markup(): void
    {
        $this->assertSame('11 AM – 9 PM', self::week(['structured' => false, 'raw_text' => 'Mo-Th 11:00-21:00; Fr-Sa 11:00-22:00'])['Monday']);

        $this->assertSame(
            ['structured' => false, 'raw_text' => "Monday to Friday\n9:00 AM – 1:00 PM\n\nWeekends & Holidays"],
            OpeningHoursDisplay::present(['structured' => false, 'raw_text' => "Monday to Friday\r\n9:00 AM – 1:00 PM\r\n<br><br>\r\n<strong>Weekends &amp; Holidays</strong>  "]),
        );
    }

    public function test_nothing_to_show(): void
    {
        $this->assertNull(OpeningHoursDisplay::present(null));
        $this->assertNull(OpeningHoursDisplay::present(''));
        $this->assertNull(OpeningHoursDisplay::present([]));
        $this->assertNull(OpeningHoursDisplay::present(['structured' => false, 'raw_text' => ' <br> ']));
    }
}
