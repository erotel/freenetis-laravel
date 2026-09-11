<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schůze členů + prezence (usnášeníschopnost). Hlasování samotné běží na
 * externím elektronickém zařízení — tady jen prezence + kvórum + plná moc.
 *
 *  - meetings: jedna členská schůze (datum, typ, práh kvóra, stav).
 *  - meeting_attendances: docházka seniora (člena s hlasovacím právem) na dané
 *    schůzi. checked_in_at != NULL = fyzicky přítomen. proxy_holder_id = člen,
 *    který nese jeho hlas (plná moc za nepřítomného). Absence = obojí NULL.
 *  - member_voting_logs: audit udělení/odebrání hlasovacího práva (can_vote),
 *    vč. odkazu na schůzi, která degradaci spustila (pravidlo 3× absence).
 *
 * members.can_vote = ruční příznak „senior / hlasovací právo".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('members', 'can_vote')) {
            Schema::table('members', function (Blueprint $table) {
                $table->boolean('can_vote')->default(false)->after('type');
            });
        }

        if (!Schema::hasTable('meetings')) {
            Schema::create('meetings', function (Blueprint $table) {
                $table->increments('id');
                $table->string('title', 255);
                $table->date('held_on');
                $table->string('type', 40)->default('clenska_schuze');
                $table->unsignedTinyInteger('quorum_percent')->default(50);
                $table->string('status', 10)->default('planned'); // planned|open|closed
                $table->string('comment', 500)->nullable();
                $table->timestamps();
                $table->index(['type', 'held_on']);
            });
        }

        if (!Schema::hasTable('meeting_attendances')) {
            Schema::create('meeting_attendances', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('meeting_id');
                $table->unsignedInteger('member_id');
                $table->timestamp('checked_in_at')->nullable();   // NULL = nepřítomen
                $table->unsignedInteger('proxy_holder_id')->nullable(); // kdo nese jeho hlas
                $table->string('note', 255)->nullable();
                $table->timestamps();
                $table->unique(['meeting_id', 'member_id'], 'uq_attendance');
                $table->index('member_id');
                $table->index('proxy_holder_id');
            });
        }

        if (!Schema::hasTable('member_voting_logs')) {
            Schema::create('member_voting_logs', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('member_id');
                $table->string('action', 20);             // granted|degraded
                $table->unsignedInteger('meeting_id')->nullable();
                $table->string('reason', 255)->nullable();
                $table->unsignedInteger('user_id')->nullable(); // kdo provedl
                $table->timestamp('created_at')->nullable();
                $table->index('member_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_attendances');
        Schema::dropIfExists('member_voting_logs');
        Schema::dropIfExists('meetings');
        if (Schema::hasColumn('members', 'can_vote')) {
            Schema::table('members', function (Blueprint $table) {
                $table->dropColumn('can_vote');
            });
        }
    }
};
