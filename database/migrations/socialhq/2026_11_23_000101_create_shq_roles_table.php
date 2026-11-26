<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Roles: a company's sets of permissions (App\SocialHq\Access). Every company
 * gets the five built-in roles; its owner adds custom ones. A membership and
 * an invite point at one role.
 *
 * The backfill gives each existing membership and invite the role matching
 * its flags (an admin is Admin, a member who publishes directly Manager, a
 * member who needs approval Editor). It writes plain rows and never calls the
 * app's code (.ai/rules/migrations.md), so the built-in roles' defaults are
 * written out here as they were on 2026-11-23. It also rewrites the flags to
 * mirror the role (a member who needed approval only through
 * members_always_reviewed now has requires_approval set); down() leaves them.
 */
return new class extends Migration
{
    /**
     * @var array<string, array{name: string, position: int, permissions: list<string>}>
     */
    private const BUILT_IN = [
        'admin' => ['name' => 'Admin', 'position' => 1, 'permissions' => [
            'posts.create', 'posts.publish', 'posts.approve', 'repurpose.manage', 'plan.edit', 'plan.approve',
            'brand.edit', 'ai.use', 'channels.manage', 'team.manage', 'workspace.settings', 'api_keys.manage', 'webhooks.manage',
        ]],
        'manager' => ['name' => 'Manager', 'position' => 2, 'permissions' => [
            'posts.create', 'posts.publish', 'posts.approve', 'repurpose.manage', 'plan.edit', 'plan.approve', 'brand.edit', 'ai.use',
        ]],
        'editor' => ['name' => 'Editor', 'position' => 3, 'permissions' => ['posts.create', 'plan.edit', 'ai.use']],
        'designer' => ['name' => 'Designer', 'position' => 4, 'permissions' => ['plan.edit', 'ai.use']],
        'viewer' => ['name' => 'Viewer', 'position' => 5, 'permissions' => []],
    ];

    public function up(): void
    {
        Schema::create('shq_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            // A built-in role's key (App\SocialHq\Access\BuiltInRole); null for a custom role.
            $table->string('key', 20)->nullable();
            $table->string('name', 40);
            // The name trimmed and lowercased: names are unique in a company whatever their case.
            $table->string('normalized_name', 40);
            $table->string('description', 160)->nullable();
            $table->json('permissions');
            $table->unsignedSmallInteger('position')->default(100);
            $table->timestamps();

            $table->unique(['account_id', 'key']);
            $table->unique(['account_id', 'normalized_name']);
        });

        // No "on delete": a role still in use can't be deleted (RoleChanges::delete
        // moves its people and invites first). Nulling it would hand the
        // membership back to its flags, which may say admin.
        Schema::table('user_workspace', function (Blueprint $table) {
            $table->foreignUuid('shq_role_id')->nullable()->after('workspace_id')->constrained('shq_roles');
            $table->index('shq_role_id');
        });

        Schema::table('invites', function (Blueprint $table) {
            $table->foreignUuid('shq_role_id')->nullable()->after('email')->constrained('shq_roles');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('invites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shq_role_id');
        });

        Schema::table('user_workspace', function (Blueprint $table) {
            $table->dropIndex(['shq_role_id']);
            $table->dropConstrainedForeignId('shq_role_id');
        });

        Schema::dropIfExists('shq_roles');
    }

    private function backfill(): void
    {
        $now = now();
        $membersAlwaysReviewed = (bool) config('socialhq.approvals.members_always_reviewed', true);

        /** @var array<string, array<string, string>> $roleIds account id => key => role id */
        $roleIds = [];

        DB::table('accounts')->orderBy('id')->select('id')->chunk(200, function ($accounts) use ($now, &$roleIds): void {
            $rows = [];

            foreach ($accounts as $account) {
                foreach (self::BUILT_IN as $key => $role) {
                    $id = (string) Str::uuid7();
                    $roleIds[$account->id][$key] = $id;
                    $rows[] = [
                        'id' => $id,
                        'account_id' => $account->id,
                        'key' => $key,
                        'name' => $role['name'],
                        'normalized_name' => Str::lower($role['name']),
                        'description' => null,
                        'permissions' => json_encode($role['permissions']),
                        'position' => $role['position'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            DB::table('shq_roles')->insert($rows);
        });

        $keyFor = fn (bool $isAdmin, bool $requiresApproval): string => match (true) {
            $isAdmin => 'admin',
            $requiresApproval || $membersAlwaysReviewed => 'editor',
            default => 'manager',
        };

        DB::table('user_workspace')
            ->join('workspaces', 'workspaces.id', '=', 'user_workspace.workspace_id')
            ->select(['user_workspace.user_id', 'user_workspace.workspace_id', 'user_workspace.is_admin', 'user_workspace.requires_approval', 'workspaces.account_id'])
            ->orderBy('user_workspace.user_id')
            ->orderBy('user_workspace.workspace_id')
            ->chunk(500, function ($memberships) use ($roleIds, $keyFor): void {
                foreach ($memberships as $membership) {
                    $key = $keyFor((bool) $membership->is_admin, (bool) $membership->requires_approval);
                    $roleId = $roleIds[$membership->account_id][$key] ?? null;

                    if ($roleId === null) {
                        continue;
                    }

                    DB::table('user_workspace')
                        ->where('user_id', $membership->user_id)
                        ->where('workspace_id', $membership->workspace_id)
                        ->update([
                            'shq_role_id' => $roleId,
                            'is_admin' => $key === 'admin',
                            'requires_approval' => $key === 'editor',
                        ]);
                }
            });

        DB::table('invites')
            ->select(['id', 'account_id', 'is_admin', 'requires_approval'])
            ->orderBy('id')
            ->chunk(500, function ($invites) use ($roleIds, $keyFor): void {
                foreach ($invites as $invite) {
                    $key = $keyFor((bool) $invite->is_admin, (bool) $invite->requires_approval);
                    $roleId = $roleIds[$invite->account_id][$key] ?? null;

                    if ($roleId === null) {
                        continue;
                    }

                    DB::table('invites')->where('id', $invite->id)->update([
                        'shq_role_id' => $roleId,
                        'is_admin' => $key === 'admin',
                        'requires_approval' => $key === 'editor',
                    ]);
                }
            });
    }
};
