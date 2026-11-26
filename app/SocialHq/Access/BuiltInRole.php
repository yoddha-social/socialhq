<?php

declare(strict_types=1);

namespace App\SocialHq\Access;

/**
 * The roles every company starts with. The owner may change the permissions
 * of every one but Admin, which always holds them all so a workspace always
 * has someone who can fix access. Built-in roles keep their name and can't be
 * deleted; "Reset to default" puts back defaultPermissions().
 */
enum BuiltInRole: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Editor = 'editor';
    case Designer = 'designer';
    case Viewer = 'viewer';

    /**
     * The role an invite or a new member gets when nobody picks one.
     */
    public const DEFAULT = self::Editor;

    /**
     * @return list<string>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::Admin => Permission::all(),
            self::Manager => [
                Permission::PostsCreate,
                Permission::PostsPublish,
                Permission::PostsApprove,
                Permission::RepurposeManage,
                Permission::PlanEdit,
                Permission::PlanApprove,
                Permission::BrandEdit,
                Permission::AiUse,
            ],
            self::Editor => [Permission::PostsCreate, Permission::PlanEdit, Permission::AiUse],
            self::Designer => [Permission::PlanEdit, Permission::AiUse],
            self::Viewer => [],
        };
    }

    /**
     * Admin's permissions never change.
     */
    public function isFixed(): bool
    {
        return $this === self::Admin;
    }

    /**
     * The English name stored on the role row (the unique name check needs it there).
     */
    public function defaultName(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Manager => 'Manager',
            self::Editor => 'Editor',
            self::Designer => 'Designer',
            self::Viewer => 'Viewer',
        };
    }

    public function position(): int
    {
        return match ($this) {
            self::Admin => 1,
            self::Manager => 2,
            self::Editor => 3,
            self::Designer => 4,
            self::Viewer => 5,
        };
    }
}
