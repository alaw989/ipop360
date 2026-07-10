<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateEngagement extends Command
{
    protected $signature = 'restaurants:update-engagement';

    protected $description = 'Aggregate engagement clicks into restaurant counters';

    public function handle(): int
    {
        $updated = DB::transaction(function () {
            return DB::table('restaurant_engagement')
                ->select('restaurant_id')
                ->selectRaw("SUM(CASE WHEN action_type = 'website_click' THEN 1 ELSE 0 END) AS website_clicks")
                ->selectRaw("SUM(CASE WHEN action_type = 'directions_click' THEN 1 ELSE 0 END) AS directions_clicks")
                ->selectRaw("SUM(CASE WHEN action_type = 'call_click' THEN 1 ELSE 0 END) AS call_clicks")
                ->selectRaw('COUNT(*) AS total')
                ->groupBy('restaurant_id')
                ->chunk(200, function ($aggregates) {
                    foreach ($aggregates as $row) {
                        DB::table('restaurants')
                            ->where('id', $row->restaurant_id)
                            ->update([
                                'website_clicks_count' => $row->website_clicks,
                                'directions_clicks_count' => $row->directions_clicks,
                                'call_clicks_count' => $row->call_clicks,
                                'total_engagement' => $row->total,
                            ]);
                    }
                });

            return true;
        });

        $this->info('Engagement counters updated successfully.');

        return self::SUCCESS;
    }
}
