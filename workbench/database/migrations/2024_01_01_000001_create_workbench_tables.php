<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The testbench skeleton ships its own users table; replace it.
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->boolean('is_admin')->default(false);
            // Panel ids this user may not enter, so canAccessPanel() has
            // something real to refuse.
            $table->json('blocked_panels')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Non-text columns on a resource that declares no globally
            // searchable attributes, so the search fallback has something it
            // must refuse to LIKE against.
            $table->json('meta')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->timestamps();
        });

        Schema::create('guarded_notes', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('secret')->nullable();
            $table->string('explodes')->nullable();
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content')->nullable();
            $table->string('status')->default('draft');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_featured')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->string('internal_notes')->nullable();
            $table->string('sealed_note')->nullable();
            // Gated on a role by its form field, so the read path has a
            // field that must not be serialised for everyone.
            $table->string('admin_note')->nullable();
            $table->text('body')->nullable();
            $table->json('body_json')->nullable();
            $table->text('summary')->nullable();
            $table->string('thumbnail')->nullable();
            $table->json('gallery')->nullable();
            $table->string('attachment')->nullable();
            $table->json('links')->nullable();
            $table->json('blocks')->nullable();
            $table->json('meta')->nullable();
            $table->json('intro')->nullable();
            $table->json('body_i18n')->nullable();
            $table->json('annotations')->nullable();
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('post_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('users');
    }
};
