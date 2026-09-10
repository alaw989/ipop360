<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reversible holding pen for restaurant values judged wrong by an integrity
 * check (a dictionary site stored as a website, a rating copied from another
 * city, a tracking-pixel "social link"...). Instead of nulling/deleting in
 * place, the old value is moved here with the reason and the detector that
 * flagged it, so any cleanup can be audited and undone
 * (FieldQuarantineService::restore).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_quarantine', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('field', 64);
            $table->longText('old_value')->nullable();
            $table->string('reason', 64);
            $table->string('detector', 64)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('quarantined_at')->useCurrent();
            $table->timestamp('restored_at')->nullable();

            $table->index(['restaurant_id', 'field']);
            $table->index('reason');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_quarantine');
    }
};
