<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name');
            $table->foreignId('category_id')->nullable()->constrained();
            $table->decimal('price', 10, 2)->nullable();
            $table->unsignedInteger('stock')->nullable();
            $table->date('launch_date')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('locked')->default(false);
            $table->timestamps();
        });
    }
};
