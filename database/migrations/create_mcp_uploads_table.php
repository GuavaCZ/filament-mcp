<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('filament-mcp.uploads.table', 'mcp_uploads'), function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Who may consume this upload. Scoped to the user rather than the
            // token so a rotated token does not strand an in-flight upload.
            $table->nullableMorphs('uploadable');
            $table->string('panel_id')->nullable();
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('original_name')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('filament-mcp.uploads.table', 'mcp_uploads'));
    }
};
