<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('versions_count')->default(0);
            $table->foreignId('duplicated_from_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id');
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('descriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('version_id');
            $table->string('body');
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id');
            $table->string('locale')->default('en');
            $table->timestamps();
        });

        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('product_tag', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id');
            $table->foreignId('tag_id');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('notes', function (Blueprint $table): void {
            $table->id();
            $table->morphs('notable');
            $table->string('body');
            $table->timestamps();
        });

        Schema::create('labels', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('labelables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('label_id');
            $table->morphs('labelable');
            $table->string('note')->nullable();
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('product_id');
            $table->string('title');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'documents', 'categories', 'labelables', 'labels', 'notes',
            'product_tag', 'tags', 'suppliers', 'settings', 'descriptions',
            'versions', 'products',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
