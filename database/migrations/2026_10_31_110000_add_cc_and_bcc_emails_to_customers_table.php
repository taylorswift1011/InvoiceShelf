<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extra addresses a customer wants copied on every email sent to them:
     * the send dialogs prefill CC and BCC from these, and automatic sends
     * (recurring invoices, payment reminders) include them. JSON arrays of
     * addresses, null when there are none.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'cc_emails')) {
                $table->json('cc_emails')->nullable();
            }

            if (! Schema::hasColumn('customers', 'bcc_emails')) {
                $table->json('bcc_emails')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['cc_emails', 'bcc_emails']);
        });
    }
};
