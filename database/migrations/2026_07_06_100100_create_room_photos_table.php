<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Room cover/gallery photos. One row per uploaded photo; the row stores
     * the private-disk paths of the re-encoded variants (thumb/display/full).
     * Rows are immutable per upload — replace = new row — so authenticated
     * delivery can cache safely by checksum.
     */
    public function up(): void
    {
        Schema::create('room_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->string('storage_disk', 32);
            $table->string('thumb_path');
            $table->string('display_path');
            $table->string('full_path')->nullable();
            $table->string('original_name');
            $table->string('mime', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->char('checksum_sha256', 64);
            $table->boolean('is_cover')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['room_id', 'sort_order'], 'room_photos_room_sort_idx');
            $table->index(['room_id', 'is_cover'], 'room_photos_room_cover_idx');
            $table->index('checksum_sha256', 'room_photos_checksum_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_photos');
    }
};
