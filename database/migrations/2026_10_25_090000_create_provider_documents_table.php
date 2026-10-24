<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('provider_id')->constrained('providers')->cascadeOnDelete();
            // One of config('providers.documents.types'); validated on upload.
            $table->string('type', 40);
            $table->string('title', 120)->nullable();
            // What the provider called the file, kept only for display. The file itself
            // is stored under a server-generated name (see ProviderDocumentService).
            $table->string('original_name', 255);
            $table->string('path', 255);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->timestamps();

            $table->index(['provider_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_documents');
    }
};
