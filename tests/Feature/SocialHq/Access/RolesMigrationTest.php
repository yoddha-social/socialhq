<?php

declare(strict_types=1);

use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Access\Permission;
use App\SocialHq\Access\WorkspaceAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->migration = require collect(glob(database_path('migrations/socialhq/*_create_shq_roles_table.php')))->sole();
    $this->migration->down();
});

afterEach(function () {
    if (! Schema::hasTable('shq_roles')) {
        $this->migration->up();
    }
});

/**
 * Members and invites as they were before roles: flags only.
 *
 * @return array{workspace: Workspace, users: array<string, User>, invites: array<string, Invite>}
 */
function rolesMigrationSeed(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $owner->account_id, 'user_id' => $owner->id]);
    $users = ['owner' => $owner];
    $flags = [
        'owner' => [true, false],
        'admin' => [true, false],
        'publisher' => [false, false],
        'reviewed' => [false, true],
    ];

    foreach ($flags as $key => [$isAdmin, $requiresApproval]) {
        $users[$key] ??= User::factory()->create(['account_id' => $owner->account_id]);
        DB::table('user_workspace')->insert([
            'user_id' => $users[$key]->id,
            'workspace_id' => $workspace->id,
            'is_admin' => $isAdmin,
            'requires_approval' => $requiresApproval,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $invites = [];

    foreach (['admin' => [true, false], 'reviewed' => [false, true]] as $key => [$isAdmin, $requiresApproval]) {
        $invites[$key] = Invite::query()->forceCreate([
            'account_id' => $owner->account_id,
            'invited_by' => $owner->id,
            'email' => "{$key}-invite@example.com",
            'is_admin' => $isAdmin,
            'requires_approval' => $requiresApproval,
            'workspaces' => [$workspace->id],
        ]);
    }

    return ['workspace' => $workspace, 'users' => $users, 'invites' => $invites];
}

function rolesMigrationRoleKey(Workspace $workspace, User $user): ?string
{
    return DB::table('user_workspace')
        ->join('shq_roles', 'shq_roles.id', '=', 'user_workspace.shq_role_id')
        ->where('user_workspace.workspace_id', $workspace->id)
        ->where('user_workspace.user_id', $user->id)
        ->value('shq_roles.key');
}

test('existing members get the role matching their flags, with members reviewed', function () {
    config(['socialhq.approvals.members_always_reviewed' => true]);
    ['workspace' => $workspace, 'users' => $users, 'invites' => $invites] = rolesMigrationSeed();

    $this->migration->up();

    expect(DB::table('shq_roles')->where('account_id', $workspace->account_id)->orderBy('position')->pluck('key')->all())
        ->toBe(['admin', 'manager', 'editor', 'designer', 'viewer'])
        ->and(rolesMigrationRoleKey($workspace, $users['admin']))->toBe('admin')
        // Members always reviewed: nobody but admins published directly, so they become editors.
        ->and(rolesMigrationRoleKey($workspace, $users['publisher']))->toBe('editor')
        ->and(rolesMigrationRoleKey($workspace, $users['reviewed']))->toBe('editor')
        ->and(DB::table('user_workspace')->where('user_id', $users['publisher']->id)->value('requires_approval'))->toBeTrue()
        ->and(DB::table('shq_roles')->where('id', $invites['admin']->fresh()->shq_role_id)->value('key'))->toBe('admin')
        ->and(DB::table('shq_roles')->where('id', $invites['reviewed']->fresh()->shq_role_id)->value('key'))->toBe('editor');
});

test('a member who published directly becomes a Manager and keeps publishing', function () {
    config(['socialhq.approvals.members_always_reviewed' => false]);
    ['workspace' => $workspace, 'users' => $users] = rolesMigrationSeed();

    $this->migration->up();

    $publisher = $users['publisher']->fresh();

    expect(rolesMigrationRoleKey($workspace, $publisher))->toBe('manager')
        ->and(rolesMigrationRoleKey($workspace, $users['reviewed']))->toBe('editor')
        ->and($publisher->canPublishDirectlyIn($workspace))->toBeTrue()
        ->and(WorkspaceAccess::of($users['reviewed']->fresh(), $workspace)->permissions)->toBe([Permission::PostsCreate, Permission::PlanEdit, Permission::AiUse]);
});

test('the migration rolls back', function () {
    $this->migration->up();
    $this->migration->down();

    expect(Schema::hasTable('shq_roles'))->toBeFalse()
        ->and(Schema::hasColumn('user_workspace', 'shq_role_id'))->toBeFalse()
        ->and(Schema::hasColumn('invites', 'shq_role_id'))->toBeFalse();
});
