import type { WorkspacePermission } from '@/types';

/** One permission of the catalog and the ones it needs (App\SocialHq\Access\Permission::catalog()). */
export interface CatalogPermission {
    key: WorkspacePermission;
    needs: WorkspacePermission[];
}

export interface CatalogGroup {
    key: string;
    permissions: CatalogPermission[];
}

export interface RolePerson {
    id: string;
    name: string;
    photo_url: string | null;
    workspace_id: string;
    workspace: string;
}

/** A company role (App\SocialHq\Access\RoleResource::make()). */
export interface CompanyRole {
    id: string;
    key: string | null;
    name: string;
    description: string | null;
    built_in: boolean;
    fixed: boolean;
    changed: boolean;
    permissions: WorkspacePermission[];
    defaults: WorkspacePermission[] | null;
    /** Roles pages: who has it (the whole company for the owner, else this workspace). */
    people?: RolePerson[];
    pending_invites?: number;
    /** Members page and invite dialog: whether the viewer may give it. */
    assignable?: boolean;
    /** Access requests: the workspaces where the viewer may give it. */
    assignable_in?: string[];
}

/** Shared props of the roles pages (App\SocialHq\Access\RolePage::props()). */
export interface RolesPageProps {
    canManage: boolean;
    scope: 'company' | 'workspace';
    company: string | null;
    workspace: { id: string; name: string };
    owner: { id: string; name: string; photo_url: string | null } | null;
    catalog: CatalogGroup[];
    limits: { custom: number; name: number; description: number };
    roles: CompanyRole[];
}

export const permissionLabelKey = (key: WorkspacePermission): string =>
    `socialhq_roles.permissions.${key}.label`;

export const permissionHintKey = (key: WorkspacePermission): string =>
    `socialhq_roles.permissions.${key}.hint`;

export const catalogKeys = (catalog: CatalogGroup[]): WorkspacePermission[] =>
    catalog.flatMap((group) => group.permissions.map((item) => item.key));

/** Keys in catalog order, so lists and comparisons are stable. */
export const inCatalogOrder = (
    catalog: CatalogGroup[],
    keys: Iterable<WorkspacePermission>,
): WorkspacePermission[] => {
    const wanted = new Set(keys);

    return catalogKeys(catalog).filter((key) => wanted.has(key));
};

export const sameSet = (
    a: WorkspacePermission[],
    b: WorkspacePermission[],
): boolean => a.length === b.length && a.every((key) => b.includes(key));

export interface ToggleResult {
    permissions: WorkspacePermission[];
    /** What else changed with it: the needs switched on, or the dependants switched off. */
    also: WorkspacePermission[];
    on: boolean;
}

/**
 * Switches one permission the way the server's rule wants it
 * (PermissionNeedsMet): turning one on turns on everything it needs,
 * turning one off turns off everything that needs it.
 */
export const togglePermission = (
    catalog: CatalogGroup[],
    current: WorkspacePermission[],
    key: WorkspacePermission,
): ToggleResult => {
    const needs = new Map(
        catalog.flatMap((group) =>
            group.permissions.map((item) => [item.key, item.needs] as const),
        ),
    );
    const held = new Set(current);

    if (held.has(key)) {
        const off = new Set<WorkspacePermission>([key]);
        let grew = true;

        while (grew) {
            grew = false;

            for (const [candidate, required] of needs) {
                if (
                    held.has(candidate) &&
                    !off.has(candidate) &&
                    required.some((need) => off.has(need))
                ) {
                    off.add(candidate);
                    grew = true;
                }
            }
        }

        off.delete(key);

        return {
            permissions: inCatalogOrder(
                catalog,
                current.filter((item) => item !== key && !off.has(item)),
            ),
            also: inCatalogOrder(catalog, off),
            on: false,
        };
    }

    const added = new Set<WorkspacePermission>();
    const walk = (item: WorkspacePermission): void => {
        for (const need of needs.get(item) ?? []) {
            if (!held.has(need) && !added.has(need)) {
                added.add(need);
                walk(need);
            }
        }
    };

    walk(key);

    return {
        permissions: inCatalogOrder(catalog, [...current, key, ...added]),
        also: inCatalogOrder(catalog, added),
        on: true,
    };
};

/** What someone gains and loses moving from one set of permissions to another. */
export const permissionDiff = (
    catalog: CatalogGroup[],
    from: WorkspacePermission[],
    to: WorkspacePermission[],
): { gains: WorkspacePermission[]; losses: WorkspacePermission[] } => ({
    gains: inCatalogOrder(
        catalog,
        to.filter((key) => !from.includes(key)),
    ),
    losses: inCatalogOrder(
        catalog,
        from.filter((key) => !to.includes(key)),
    ),
});

/**
 * The role in plain words ("In short"): lang keys of what it can and can't do.
 */
export const roleSummary = (
    permissions: WorkspacePermission[],
): { can: string[]; cannot: string[] } => {
    const has = (key: WorkspacePermission): boolean =>
        permissions.includes(key);
    const prefix = 'socialhq_roles.editor.summary.';
    const can: string[] = [`${prefix}see`];
    const cannot: string[] = [];
    const add = (allowed: boolean, key: string): void => {
        (allowed ? can : cannot).push(`${prefix}${key}`);
    };

    if (has('posts.publish')) {
        add(true, 'publish');
    } else {
        add(
            has('posts.create'),
            has('posts.create') ? 'write_reviewed' : 'write',
        );
    }

    add(has('posts.approve'), 'approve_posts');
    add(has('plan.edit'), has('plan.approve') ? 'plans_approve' : 'plans');
    add(has('ai.use'), 'ai');
    add(has('channels.manage'), 'channels');
    add(has('team.manage'), 'members');

    return { can, cannot };
};

/** How many people have a role: a person with it in several workspaces counts once. */
export const peopleCount = (people: RolePerson[] = []): number =>
    new Set(people.map((person) => person.id)).size;
