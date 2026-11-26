<?php

declare(strict_types=1);

namespace App\SocialHq\Access;

/**
 * The permissions a role can hold in a workspace. Each one is a single check
 * in the app (WorkspacePolicy, PlanAccess, BrandKnowledge, AiAccess), so a
 * switch never promises more than the app enforces. Seeing the workspace
 * (calendar, posts, insights, brand knowledge) comes with membership and is
 * not a permission.
 *
 * Owner-only things (roles, company AI, creating and deleting workspaces,
 * billing) are not permissions: no role can hold them.
 */
final class Permission
{
    public const PostsCreate = 'posts.create';

    public const PostsPublish = 'posts.publish';

    public const PostsApprove = 'posts.approve';

    public const RepurposeManage = 'repurpose.manage';

    public const PlanEdit = 'plan.edit';

    public const PlanApprove = 'plan.approve';

    public const BrandEdit = 'brand.edit';

    public const AiUse = 'ai.use';

    public const ChannelsManage = 'channels.manage';

    public const TeamManage = 'team.manage';

    public const WorkspaceSettings = 'workspace.settings';

    public const ApiKeysManage = 'api_keys.manage';

    public const WebhooksManage = 'webhooks.manage';

    /**
     * The catalog in display order, by group. A permission's `needs` must be
     * held with it: approving a post schedules it, so it needs publishing,
     * which needs writing.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const GROUPS = [
        'posts' => [
            self::PostsCreate => [],
            self::PostsPublish => [self::PostsCreate],
            self::PostsApprove => [self::PostsPublish],
            self::RepurposeManage => [self::PostsPublish],
        ],
        'plan' => [
            self::PlanEdit => [],
            self::PlanApprove => [self::PlanEdit],
        ],
        'content' => [
            self::BrandEdit => [],
            self::AiUse => [],
        ],
        'workspace' => [
            self::ChannelsManage => [],
            self::TeamManage => [],
            self::WorkspaceSettings => [],
        ],
        'integrations' => [
            self::ApiKeysManage => [],
            self::WebhooksManage => [],
        ],
    ];

    /**
     * Every permission key, in display order.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_merge(...array_map(array_keys(...), array_values(self::GROUPS)));
    }

    /**
     * @return array<string, list<string>> group key => its permission keys
     */
    public static function groups(): array
    {
        return array_map(array_keys(...), self::GROUPS);
    }

    public static function exists(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }

    /**
     * The permissions this one needs directly.
     *
     * @return list<string>
     */
    public static function needs(string $permission): array
    {
        foreach (self::GROUPS as $permissions) {
            if (array_key_exists($permission, $permissions)) {
                return $permissions[$permission];
            }
        }

        return [];
    }

    /**
     * Pairs (permission, what it needs) that this set breaks.
     *
     * @param  list<string>  $permissions
     * @return list<array{permission: string, needs: string}>
     */
    public static function unmetNeeds(array $permissions): array
    {
        $unmet = [];

        foreach ($permissions as $permission) {
            foreach (self::needs($permission) as $need) {
                if (! in_array($need, $permissions, true)) {
                    $unmet[] = ['permission' => $permission, 'needs' => $need];
                }
            }
        }

        return $unmet;
    }

    /**
     * Known keys only, without duplicates, in catalog order. Stored sets go
     * through this when read, so a key dropped from the catalog grants nothing.
     *
     * @param  iterable<mixed>  $permissions
     * @return list<string>
     */
    public static function normalize(iterable $permissions): array
    {
        $given = [];

        foreach ($permissions as $permission) {
            if (is_string($permission)) {
                $given[$permission] = true;
            }
        }

        return array_values(array_filter(self::all(), fn (string $permission): bool => isset($given[$permission])));
    }

    /**
     * The catalog for pages: each group with its permissions and what they need.
     *
     * @return list<array{key: string, permissions: list<array{key: string, needs: list<string>}>}>
     */
    public static function catalog(): array
    {
        $catalog = [];

        foreach (self::GROUPS as $group => $permissions) {
            $catalog[] = [
                'key' => $group,
                'permissions' => array_map(
                    fn (string $key, array $needs): array => ['key' => $key, 'needs' => $needs],
                    array_keys($permissions),
                    array_values($permissions),
                ),
            ];
        }

        return $catalog;
    }
}
