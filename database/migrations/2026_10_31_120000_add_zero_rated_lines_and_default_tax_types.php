<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Zero-rated lines under document-level tax, and default sales taxes.
     *
     * - invoice_items / estimate_items.zero_rated: the line came from a
     *   zero-rated catalogue item. When a company taxes whole documents, the
     *   document's taxes are charged on the other lines only, and the PDF marks
     *   the line. (invoice_items also holds recurring-invoice lines.)
     * - tax_types.is_default: a sales tax every new invoice and estimate starts
     *   with when the company taxes whole documents.
     */
    public function up(): void
    {
        foreach (['invoice_items', 'estimate_items'] as $table) {
            if (! Schema::hasColumn($table, 'zero_rated')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->boolean('zero_rated')->default(false);
                });
            }
        }

        if (! Schema::hasColumn('tax_types', 'is_default')) {
            Schema::table('tax_types', function (Blueprint $table) {
                $table->boolean('is_default')->default(false);
            });
        }
    }

    public function down(): void
    {
        foreach (['invoice_items', 'estimate_items'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('zero_rated');
            });
        }

        Schema::table('tax_types', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
