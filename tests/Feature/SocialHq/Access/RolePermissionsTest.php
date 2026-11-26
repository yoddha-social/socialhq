<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Access\AiAccess;
use App\SocialHq\Access\BuiltInRole;
use App\SocialHq\Access\Permission;
use App\SocialHq\Access\Role;
use App\SocialHq\Access\Roles;
use App\SocialHq\Access\WorkspaceAccess;
use App\SocialHq\AiGraphics\AiGraphics;
use App\SocialHq\Knowledge\BrandKnowledge;
use App\SocialHq\Plan\PlanAccess;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\AccessToken as PassportToken;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();
    $this->roles = Roles::ensureBuiltIns($this->workspace->account_id);
});

function rolePermissionsMember(Workspace $workspace, Role $role): User
{
    $member = workspaceMember($workspace, 'approval');
    Roles::assign($workspace, $member->id, $role);

    return $member->fresh();
}

test('each built-in role grants what its switches say, through every check', function (string $key, array $expected) {
    $member = rolePermissionsMember($this->workspace, $this->roles[$key]);
    $workspace = $this->workspace;

    expect([
        'view' => $member->can('view', $workspace),
        'createPost' => $member->can('createPost', $workspace),
        'publishDirectly' => $member->can('publishDirectly', $workspace),
        'approvePosts' => $member->can('approvePosts', $workspace),
        'manageRepurposes' => $member->can('manageRepurposes', $workspace),
        'planWork' => PlanAccess::canWork($member, $workspace),
        'planApprove' => PlanAccess::canApprove($member, $workspace),
        'brandEdit' => BrandKnowledge::canEdit($member, $workspace),
        'brandView' => BrandKnowledge::canView($member, $workspace),
        'ai' => AiAccess::allows($member, $workspace),
        'manageAccounts' => $member->can('manageAccounts', $workspace),
        'manageTeam' => $member->can('manageTeam', $workspace),
        'update' => $member->can('update', $workspace),
        'manageApiKeys' => $member->can('manageApiKeys', $workspace),
        'manageWebhooks' => $member->can('manageWebhooks', $workspace),
        'requiresApproval' => $member->requiresApprovalIn($workspace),
    ])->toBe($expected);
})->with([
    'admin' => ['admin', ['view' => true, 'createPost' => true, 'publishDirectly' => true, 'approvePosts' => true, 'manageRepurposes' => true, 'planWork' => true, 'planApprove' => true, 'brandEdit' => true, 'brandView' => true, 'ai' => true, 'manageAccounts' => true, 'manageTeam' => true, 'update' => true, 'manageApiKeys' => true, 'manageWebhooks' => true, 'requiresApproval' => false]],
    'manager' => ['manager', ['view' => true, 'createPost' => true, 'publishDirectly' => true, 'approvePosts' => true, 'manageRepurposes' => true, 'planWork' => true, 'planApprove' => true, 'brandEdit' => true, 'brandView' => true, 'ai' => true, 'manageAccounts' => false, 'manageTeam' => false, 'update' => false, 'manageApiKeys' => false, 'manageWebhooks' => false, 'requiresApproval' => false]],
    'editor' => ['editor', ['view' => true, 'createPost' => true, 'publishDirectly' => false, 'approvePosts' => false, 'manageRepurposes' => false, 'planWork' => true, 'planApprove' => false, 'brandEdit' => false, 'brandView' => true, 'ai' => true, 'manageAccounts' => false, 'manageTeam' => false, 'update' => false, 'manageApiKeys' => false, 'manageWebhooks' => false, 'requiresApproval' => true]],
    'designer' => ['designer', ['view' => true, 'createPost' => false, 'publishDirectly' => false, 'approvePosts' => false, 'manageRepurposes' => false, 'planWork' => true, 'planApprove' => false, 'brandEdit' => false, 'brandView' => true, 'ai' => true, 'manageAccounts' => false, 'manageTeam' => false, 'update' => false, 'manageApiKeys' => false, 'manageWebhooks' => false, 'requiresApproval' => true]],
    'viewer' => ['viewer', ['view' => true, 'createPost' => false, 'publishDirectly' => false, 'approvePosts' => false, 'manageRepurposes' => false, 'planWork' => false, 'planApprove' => false, 'brandEdit' => false, 'brandView' => true, 'ai' => false, 'manageAccounts' => false, 'manageTeam' => false, 'update' => false, 'manageApiKeys' => false, 'manageWebhooks' => false, 'requiresApproval' => true]],
]);

test('a viewer sees the calendar but can’t write posts, open the plan or use AI', function () {
    $viewer = rolePermissionsMember($this->workspace, $this->roles['viewer']);

    $this->actingAs($viewer)->get(route('app.calendar'))->assertOk();
    $this->actingAs($viewer)->get(route('app.posts.create'))->assertForbidden();
    $this->actingAs($viewer)->get(route('socialhq.plan.show'))->assertForbidden();
    $this->actingAs($viewer)->get(route('socialhq.brand.index'))->assertOk();

    $this->actingAs($viewer)
        ->get(route('app.calendar'))
        ->assertInertia(fn ($page) => $page
            ->where('aiTasks.any', false)
            ->where('auth.currentWorkspace.permissions', []));
});

test('a designer works on the plan but writes no posts', function () {
    $designer = rolePermissionsMember($this->workspace, $this->roles['designer']);

    $this->actingAs($designer)->get(route('socialhq.plan.show'))->assertOk();
    $this->actingAs($designer)->get(route('app.posts.create'))->assertForbidden();
});

test('a role without Use AI is refused by the AI endpoints with its reason', function () {
    $custom = Role::factory()->withPermissions([Permission::PostsCreate])->create(['account_id' => $this->workspace->account_id]);
    $member = rolePermissionsMember($this->workspace, $custom);

    $this->actingAs($member)
        ->postJson(route('app.posts.ai.assist'), ['mode' => 'generate', 'prompt' => 'A post about our new winter menu and prices'])
        ->assertForbidden()
        ->assertJson(['message' => __('socialhq_roles.denied.ai')]);
});

test('a custom role with Manage channels manages channels and nothing else', function () {
    $custom = Role::factory()->withPermissions([Permission::ChannelsManage])->create(['account_id' => $this->workspace->account_id]);
    $member = rolePermissionsMember($this->workspace, $custom);

    $this->actingAs($member)->get(route('app.workspace.channels'))->assertOk();
    $this->actingAs($member)->get(route('app.members'))->assertForbidden();
    $this->actingAs($member)->get(route('app.workspace.settings'))->assertForbidden();
});

test('approvers are the owner and the members whose role approves posts', function () {
    $manager = rolePermissionsMember($this->workspace, $this->roles['manager']);
    $editor = rolePermissionsMember($this->workspace, $this->roles['editor']);
    $publisher = rolePermissionsMember(
        $this->workspace,
        Role::factory()->withPermissions([Permission::PostsCreate, Permission::PostsPublish])->create(['account_id' => $this->workspace->account_id]),
    );

    $ids = $this->workspace->approvers()->pluck('id')->all();

    expect($ids)->toContain($this->owner->id, $manager->id)
        ->not->toContain($editor->id)
        ->not->toContain($publisher->id);
});

test('an agent never publishes directly or approves, whatever the role', function () {
    config(['socialhq.approvals.agent_posts_reviewed' => true]);
    $manager = rolePermissionsMember($this->workspace, $this->roles['manager']);
    $agent = $manager->withAccessToken(new PassportToken(['oauth_access_token_id' => 'agent', 'oauth_scopes' => ['mcp:use']]));
    $this->actingAs($agent);

    expect($agent->can('publishDirectly', $this->workspace))->toBeFalse()
        ->and($agent->can('approvePosts', $this->workspace))->toBeFalse()
        ->and(PlanAccess::canApprove($agent, $this->workspace))->toBeFalse()
        ->and($agent->requiresApprovalIn($this->workspace))->toBeTrue()
        // The person behind the key still sees repurposes read-only.
        ->and($agent->can('viewRepurposes', $this->workspace))->toBeTrue();
});

test('a REST API key works only while its person’s role may manage API keys', function () {
    subscribeAccount($this->owner->account);
    $custom = Role::factory()->withPermissions([Permission::PostsCreate, Permission::ApiKeysManage])->create(['account_id' => $this->workspace->account_id]);
    $member = rolePermissionsMember($this->workspace, $custom);
    $token = passportToken($member, $this->workspace);

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->getJson(route('api.workspace.show'))
        ->assertOk();

    // A role edited behind its back (not through RoleChanges): the key is refused at once.
    $custom->forceFill(['permissions' => [Permission::PostsCreate]])->save();
    app('auth')->forgetGuards();

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->getJson(route('api.workspace.show'))
        ->assertStatus(Response::HTTP_FORBIDDEN)
        ->assertJson(['message' => 'Insufficient workspace permissions.']);
});

test('a role of another company grants nothing', function () {
    $member = workspaceMember($this->workspace, 'member');
    $foreign = Role::factory()->withPermissions(Permission::all())->create();
    DB::table('user_workspace')->where('user_id', $member->id)->update(['shq_role_id' => $foreign->id]);

    $access = WorkspaceAccess::of($member->fresh(), $this->workspace);

    expect($access->isMember)->toBeTrue()
        ->and($access->permissions)->toBe([])
        ->and($member->fresh()->can('createPost', $this->workspace))->toBeFalse();
});

test('a membership without a role keeps the access its flags gave before roles', function (string $flags, bool $alwaysReviewed, array $expected) {
    config(['socialhq.approvals.members_always_reviewed' => $alwaysReviewed]);
    $member = workspaceMember($this->workspace, $flags);

    expect(WorkspaceAccess::of($member, $this->workspace)->permissions)->toBe($expected);
})->with([
    'admin' => ['admin', true, Permission::all()],
    'member, members reviewed' => ['member', true, [Permission::PostsCreate, Permission::PlanEdit, Permission::AiUse]],
    'member who publishes' => ['member', false, [Permission::PostsCreate, Permission::PostsPublish, Permission::PostsApprove, Permission::RepurposeManage, Permission::PlanEdit, Permission::PlanApprove, Permission::AiUse]],
    'member flagged for approval' => ['approval', false, [Permission::PostsCreate, Permission::PlanEdit, Permission::AiUse]],
]);

test('someone outside the workspace holds nothing, the owner everything', function () {
    $outsider = workspaceOutsider($this->workspace);
    $stranger = User::factory()->create();

    expect(WorkspaceAccess::of($outsider, $this->workspace)->isMember)->toBeFalse()
        ->and(WorkspaceAccess::of($outsider, $this->workspace)->permissions)->toBe([])
        ->and(WorkspaceAccess::of($stranger, $this->workspace)->permissions)->toBe([])
        ->and(WorkspaceAccess::of($this->owner, $this->workspace)->permissions)->toBe(Permission::all());
});

test('the built-in defaults hold what each permission needs', function (BuiltInRole $role) {
    expect(Permission::unmetNeeds($role->defaultPermissions()))->toBe([]);
})->with(BuiltInRole::cases());

test('the migration writes the built-in roles as BuiltInRole defines them', function () {
    $migration = require collect(glob(database_path('migrations/socialhq/*_create_shq_roles_table.php')))->sole();
    $builtIn = (new ReflectionClassConstant($migration, 'BUILT_IN'))->getValue();

    foreach (BuiltInRole::cases() as $role) {
        expect($builtIn[$role->value]['name'])->toBe($role->defaultName())
            ->and($builtIn[$role->value]['position'])->toBe($role->position())
            ->and($builtIn[$role->value]['permissions'])->toBe($role->defaultPermissions());
    }
});

test('every permission has copy', function () {
    foreach (Permission::all() as $permission) {
        expect(__("socialhq_roles.permissions.{$permission}.label"))->not->toBe("socialhq_roles.permissions.{$permission}.label")
            ->and(__("socialhq_roles.permissions.{$permission}.hint"))->not->toBe("socialhq_roles.permissions.{$permission}.hint");
    }

    foreach (array_keys(Permission::groups()) as $group) {
        expect(__("socialhq_roles.groups.{$group}"))->not->toBe("socialhq_roles.groups.{$group}");
    }
});

test('reading the website needs Use AI as well as brand editing', function () {
    $custom = Role::factory()->withPermissions([Permission::BrandEdit])->create(['account_id' => $this->workspace->account_id]);
    $member = rolePermissionsMember($this->workspace, $custom);

    $this->actingAs($member)
        ->post(route('socialhq.brand.website.store'), ['url' => 'https://example.com'])
        ->assertForbidden();
});

test('AI graphics are offered only to roles that write posts and use AI', function () {
    config(['socialhq.ai_graphics.enabled' => true]);
    $designer = rolePermissionsMember($this->workspace, $this->roles['designer']);
    $editor = rolePermissionsMember($this->workspace, $this->roles['editor']);

    expect(AiGraphics::mayMakeGraphics($designer, $this->workspace))->toBeFalse()
        ->and(AiGraphics::mayMakeGraphics($editor, $this->workspace))->toBeTrue();
});
