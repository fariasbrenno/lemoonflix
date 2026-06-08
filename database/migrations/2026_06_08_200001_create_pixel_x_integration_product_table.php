<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pixel_x_integration_product', function (Blueprint $table) {
            $table->unsignedBigInteger('pixel_x_integration_id');
            $table->string('product_id', 36);

            $table->foreign('pixel_x_integration_id')
                ->references('id')
                ->on('pixel_x_integrations')
                ->cascadeOnDelete();

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->cascadeOnDelete();

            $table->unique(['pixel_x_integration_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pixel_x_integration_product');
    }
};
