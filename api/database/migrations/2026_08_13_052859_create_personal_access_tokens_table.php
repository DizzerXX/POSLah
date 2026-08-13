<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sanctum's table, with two departures from the published stub.
 *
 * uuidMorphs rather than morphs: the tokenable here is a Device, and devices carry
 * client-generated UUIDv7 keys like everything else that syncs. The stub's bigint
 * tokenable_id cannot hold one.
 *
 * timestampsTz throughout, because a naive timestamp in a system whose accounts each carry
 * their own timezone is a wrong answer waiting to be reported.
 *
 * The bigint `id` is left as Sanctum ships it: this is framework infrastructure, not part
 * of the sync surface, and nothing client-side ever mints one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
