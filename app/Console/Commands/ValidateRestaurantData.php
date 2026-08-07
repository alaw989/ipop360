<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Services\RestaurantValidationService;
use Illuminate\Console\Command;

class ValidateRestaurantData extends Command
{
    protected $signature = 'restaurants:validate
        {--dry-run : Show what would change without persisting}
        {--limit=0 : Max restaurants to process (0 = all)}';

    protected $description = 'Normalize existing restaurant data through RestaurantValidationService';

    private int $updated = 0;

    private int $skipped = 0;

    /** @var array<int, array<string, mixed>> */
    private array $changes = [];

    public function handle(RestaurantValidationService $validator): int
    {
        $dryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $query = Restaurant::query()->orderBy('id');

        $total = $limit > 0 ? $limit : $query->count();

        if ($total === 0) {
            $this->warn('No restaurants to validate.');

            return self::SUCCESS;
        }

        $this->info("Validating {$total} restaurants...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $restaurants = $limit > 0 ? $query->take($limit)->get() : $query->get();

        foreach ($restaurants as $restaurant) {
            $original = $restaurant->toArray();

            $normalized = $validator->normalize($original);

            $dirty = [];
            foreach ($normalized as $key => $value) {
                if ($key === 'updated_at') {
                    continue;
                }
                $originalValue = $original[$key] ?? null;
                if ($value !== $originalValue) {
                    $dirty[$key] = ['from' => $originalValue, 'to' => $value];
                }
            }

            if (empty($dirty)) {
                $this->skipped++;
                $bar->advance();

                continue;
            }

            $this->updated++;
            $this->changes[$restaurant->id] = [
                'name' => $restaurant->name,
                'fields' => $dirty,
            ];

            if (! $dryRun) {
                $restaurant->update($normalized);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Done. {$this->updated} updated, {$this->skipped} already clean.");

        if ($this->updated > 0) {
            $this->newLine();
            $this->line('Changes:');
            foreach ($this->changes as $id => $change) {
                $this->line("  #{$id} {$change['name']}");
                foreach ($change['fields'] as $field => $vals) {
                    $this->line("    {$field}: \"{$vals['from']}\" → \"{$vals['to']}\"");
                }
            }
        }

        return self::SUCCESS;
    }
}
