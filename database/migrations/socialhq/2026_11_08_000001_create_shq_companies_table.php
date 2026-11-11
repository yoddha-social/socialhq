<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A company is a TryPost account a platform admin let in: its owner
        // creates workspaces (brands) and sets up the company's AI.
        Schema::create('shq_companies', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('account_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('status', 20)->index();
            // Whether the company may use the platform's own AI keys (ours does;
            // others bring their own unless a platform admin allows it).
            $table->boolean('uses_platform_ai')->default(false);
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamps();
        });

        // Accounts that already own a workspace are companies already (ours).
        $now = now();

        DB::table('accounts')
            ->whereExists(fn ($query) => $query->select(DB::raw(1))->from('workspaces')->whereColumn('workspaces.account_id', 'accounts.id'))
            ->orderBy('accounts.created_at')
            ->get(['id', 'name', 'owner_id'])
            ->each(fn (object $account) => DB::table('shq_companies')->insert([
                'account_id' => $account->id,
                'name' => mb_substr((string) $account->name, 0, 120),
                'status' => 'approved',
                'uses_platform_ai' => true,
                'requested_by' => $account->owner_id,
                'decided_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('shq_companies');
    }
};
