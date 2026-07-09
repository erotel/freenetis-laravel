<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('email_queue_attachments', function (Blueprint $table) {
            // 1 = příloha se vloží jako inline (cid) obrázek do těla e-mailu
            // (např. QR platba), ne jako klasická příloha ke stažení.
            $table->boolean('inline')->default(0)->after('mime');
        });
    }

    public function down(): void
    {
        Schema::table('email_queue_attachments', function (Blueprint $table) {
            $table->dropColumn('inline');
        });
    }
};
