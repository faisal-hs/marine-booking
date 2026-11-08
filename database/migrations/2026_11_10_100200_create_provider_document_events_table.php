<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The review history of a provider's documents: every upload, approval, rejection and request for an update, who did it and why. Rows are never
 * edited. An update an admin asked for is OPEN until the provider's next upload of that type.
 *
 * Documents that already exist get their history written once: an upload, and an approval for the ones that were approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('provider_document_events')) {
            return;
        }

        Schema::create('provider_document_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 40);
            $table->foreignId('provider_document_id')->nullable()->constrained('provider_documents')->nullOnDelete();
            // submitted, approved, rejected, update_requested
            $table->string('action', 20);
            $table->text('note')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['provider_id', 'document_type']);
        });

        foreach (DB::table('provider_documents')->orderBy('id')->get() as $d) {
            DB::table('provider_document_events')->insert(['provider_id' => $d->provider_id, 'document_type' => $d->type, 'provider_document_id' => $d->id, 'action' => 'submitted', 'created_at' => $d->created_at]);

            if ($d->status === 'approved') {
                DB::table('provider_document_events')->insert(['provider_id' => $d->provider_id, 'document_type' => $d->type, 'provider_document_id' => $d->id, 'action' => 'approved', 'created_at' => $d->reviewed_at ?? $d->created_at]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_document_events');
    }
};
