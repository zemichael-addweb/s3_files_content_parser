<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // rename the table
        Schema::rename('pdf_contents', 'file_contents');

        Schema::table('file_contents', function (Blueprint $table) {
            // change content from text to longText
            $table->longText('content')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // rename the table
        Schema::rename('file_contents', 'pdf_contents');

        Schema::table('pdf_contents', function (Blueprint $table) {
            $table->string('content')->change();
        });
    }
};
