<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let a catalogue item be marked zero-rated. Saving such an item gives it
     * the company's zero-rated tax type as its default tax.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('items', 'zero_rated')) {
            Schema::table('items', function (Blueprint $table) {
                $table->boolean('zero_rated')->default(false);
            });
        }
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('zero_rated');
        });
    }
};
