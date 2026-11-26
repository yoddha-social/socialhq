<?php

declare(strict_types=1);

// Social HQ copy, English only: bin/socialhq-sync-lang copies it to the other locales.

return [
    'groups' => [
        'posts' => 'Posts and calendar',
        'plan' => 'Planning',
        'content' => 'Brand and AI',
        'workspace' => 'Workspace',
        'integrations' => 'Integrations',
    ],
    // Keyed like the permissions (posts.create => permissions.posts.create).
    'permissions' => [
        'posts' => [
            'create' => [
                'label' => 'Write posts',
                'hint' => 'Create, edit and duplicate posts, add notes, and delete their own posts. Also ideas, templates, labels and signatures.',
            ],
            'publish' => [
                'label' => 'Publish without approval',
                'hint' => 'Schedule and publish directly, and delete anyone’s post. Without it, their posts wait for approval.',
            ],
            'approve' => [
                'label' => 'Approve posts',
                'hint' => 'Approve or reject posts others send for approval.',
            ],
        ],
        'repurpose' => [
            'manage' => [
                'label' => 'Manage repurposes',
                'hint' => 'Set up repurposes, which post again later without review.',
            ],
        ],
        'plan' => [
            'edit' => [
                'label' => 'Work on plans',
                'hint' => 'Monthly plans, copy, briefs and creatives, the production board and the events list.',
            ],
            'approve' => [
                'label' => 'Approve plans',
                'hint' => 'Approve a plan, copy, a final creative or an event date.',
            ],
        ],
        'brand' => [
            'edit' => [
                'label' => 'Edit brand knowledge',
                'hint' => 'The brand profile, knowledge entries, documents, website sources and planning settings the AI writes from.',
            ],
        ],
        'ai' => [
            'use' => [
                'label' => 'Use AI',
                'hint' => 'Write captions, copy and briefs with AI, and generate images. Uses the company’s AI budget.',
            ],
        ],
        'channels' => [
            'manage' => [
                'label' => 'Manage channels',
                'hint' => 'Connect, reconnect and disconnect channels, and change their posting times.',
            ],
        ],
        'team' => [
            'manage' => [
                'label' => 'Manage members',
                'hint' => 'Invite and remove members and change their role, up to their own access.',
            ],
        ],
        'workspace' => [
            'settings' => [
                'label' => 'Workspace settings',
                'hint' => 'Name, logo, time zone and the workspace’s defaults.',
            ],
        ],
        'api_keys' => [
            'manage' => [
                'label' => 'Manage API keys',
                'hint' => 'Create, use and revoke the workspace’s API keys.',
            ],
        ],
        'webhooks' => [
            'manage' => [
                'label' => 'Manage webhooks',
                'hint' => 'Send post events to other apps.',
            ],
        ],
    ],
    'built_in' => [
        'admin' => 'Runs the workspace: members, channels, settings and every post.',
        'manager' => 'Leads the content: publishes directly and approves posts and plans.',
        'editor' => 'Writes posts, copy and plans. Posts wait for approval.',
        'designer' => 'Works on briefs and creatives in the plan. No posts.',
        'viewer' => 'Sees the calendar and posts. Changes nothing.',
    ],
    'denied' => [
        'ai' => 'Your role in this workspace can’t use AI. Ask the owner or an admin.',
    ],
    'errors' => [
        'owner_only' => 'Only the account owner changes roles.',
        'role_missing' => 'Pick one of your company’s roles.',
        'above_your_access' => 'You can only give a role whose permissions you have yourself.',
        'member_above_your_access' => 'This person has access you don’t have. Ask the owner, or someone with at least their access, to change or remove them.',
        'name_taken' => 'Another role already has this name.',
        'name_reserved' => '“:name” is the name of a built-in role. Pick another name.',
        'needs' => '“:permission” needs “:needs”. Turn on both, or neither.',
        'unknown_permission' => 'One of the permissions doesn’t exist. Reload the page and try again.',
        'too_many' => 'A company can have up to :max custom roles. Delete one you don’t use first.',
        'built_in_name' => 'Built-in roles keep their name and description. Duplicate the role to rename it.',
        'fixed' => 'Admin always has every permission, so a workspace always has someone who can fix access.',
        'built_in_delete' => 'Built-in roles can’t be deleted. Change their switches instead.',
        'move_to_required' => 'People or pending invites have this role. Choose the role they get instead.',
        'move_to_invalid' => 'Choose another of your company’s roles.',
    ],
    'flash' => [
        'created' => '“:name” created. Nobody has it yet.',
        'duplicated' => 'Copied as “:name”. Rename it and change what you need.',
        'updated' => '{0} Role saved.|{1} Role saved. 1 person has the new access now.|[0,*] Role saved. :count people have the new access now.',
        'deleted' => '{0} Role deleted.|{1} Role deleted. 1 person moved to :role.|[0,*] Role deleted. :count people moved to :role.',
        'member_changed' => ':name now has the :role role.',
    ],
    // Added to a copied role's name: "Manager (copy)", "Manager (copy 2)".
    'copy_suffix' => ' (copy)',
    'copy_suffix_numbered' => ' (copy :number)',
    'nav' => 'Roles',
    'badges' => [
        'owner' => 'Owner',
        'fixed' => 'Fixed',
        'built_in' => 'Built-in',
        'custom' => 'Custom',
        'changed' => 'Changed',
    ],
    'members' => [
        'description' => 'People in :workspace and the role each one has here.',
        'count' => '{1} 1 member|[0,*] :count members',
        'breakdown' => ':role :count',
        'what_roles_do' => 'What can each role do?',
        'you' => '(you)',
        'no_role' => 'No role',
        'locked' => [
            'owner' => 'The account owner can do everything.',
            'self' => 'You can’t change your own role.',
            'above' => 'They have access you don’t, so you can’t change or remove them.',
        ],
        'remove' => 'Remove from workspace',
        'invite_pending' => 'Invite pending · joins as :role',
        'menu' => [
            'label' => 'Role of :name',
            'above' => 'Above your access: it has permissions you don’t.',
            'compare' => 'Compare roles',
        ],
    ],
    'change' => [
        'title' => 'Change :name’s role to :role?',
        'description' => ':from → :to, in :workspace only. Other workspaces keep their own role.',
        'can_now' => 'Can now',
        'can_no_longer' => 'Can no longer',
        'nothing' => 'Nothing',
        'approval_warning' => 'From now on their posts wait for approval. Posts already scheduled stay scheduled.',
        'api_keys' => 'Their API keys for this workspace stop working.',
        'submit' => 'Change role',
    ],
    'invite' => [
        'role' => 'Role',
        'compare' => 'Compare roles',
        'load_failed' => 'Couldn’t load the roles. Close this and try again.',
    ],
    'list' => [
        'title' => 'Roles',
        'description' => 'The roles of :company and what each can do, in every workspace.',
        'description_no_company' => 'What each role can do, in every workspace.',
        'compare' => 'Compare roles',
        'create' => 'Create role',
        'owner_note' => 'Only :owner, the account owner, changes roles. You can give members any role whose permissions you have.',
        'owner_description' => 'Everything, plus roles, company AI and workspaces. One per company.',
        'permission_count' => ':count of :total',
        'no_one' => 'No one',
        'edit' => 'Edit',
        'view' => 'View',
        'agents_note' => 'Agents (API keys and MCP) act with their person’s role, and their posts always wait for approval.',
        'workspace_scope' => 'People shown are in :workspace only.',
    ],
    'editor' => [
        'duplicate' => 'Duplicate',
        'delete' => 'Delete',
        'name' => 'Name',
        'description' => 'Description',
        'group_count' => ':count of :total',
        'needs' => 'Needs “:permissions”',
        'also_on' => 'Also turned on: :permissions. “:permission” needs it.|Also turned on: :permissions. “:permission” needs them.',
        'also_off' => 'Also turned off: :permissions. It needs “:permission”.|Also turned off: :permissions. They need “:permission”.',
        'owner_only' => [
            'title' => 'Owner only',
            'hint' => 'No role can have these',
            'roles' => ['label' => 'Roles', 'hint' => 'Create, change and delete roles.'],
            'company_ai' => ['label' => 'Company AI', 'hint' => 'AI providers, keys and the budget, and the usage log.'],
            'workspaces' => ['label' => 'Workspaces', 'hint' => 'Create and delete workspaces.'],
        ],
        'summary' => [
            'title' => 'In short',
            'see' => 'See the calendar, posts and brand knowledge',
            'publish' => 'Publish posts directly',
            'write_reviewed' => 'Write posts, which wait for approval',
            'write' => 'Write posts',
            'approve_posts' => 'Approve posts',
            'plans_approve' => 'Work on and approve plans',
            'plans' => 'Work on plans',
            'ai' => 'Use AI',
            'channels' => 'Connect channels',
            'members' => 'Manage members',
        ],
        'people' => [
            'title' => 'Who has it',
            'empty' => 'No one yet. Give it to someone on the Members page.',
        ],
        'reset' => [
            'title' => 'Built-in role',
            'changed' => 'You changed this role. Its name stays; its switches can go back to how Social HQ sets them.',
            'default' => 'As Social HQ sets it. Change any switch; you can always go back.',
            'action' => 'Reset to default',
        ],
        'unsaved' => '{0} Unsaved changes|{1} Unsaved changes · applies to 1 person at once|[0,*] Unsaved changes · applies to :count people at once',
        'discard' => 'Discard',
        'save' => 'Save role',
    ],
    'create' => [
        'title' => 'Create role',
        'description' => 'Start from a role close to what you need, then change its switches.',
        'name_placeholder' => 'e.g. Video producer',
        'name_hint' => 'Up to :max characters, and not the name of another role.',
        'start_from' => 'Start from',
        'nothing' => 'Nothing',
        'permission_count' => ':count of :total permissions',
        'submit' => 'Create and edit',
    ],
    'delete' => [
        'title' => 'Delete “:name”?',
        'people' => '{1} 1 person has this role. Choose the role they get instead. Pending invites with it move too.|[0,*] :count people have this role. Choose the role they get instead. Pending invites with it move too.',
        'invites' => '{1} 1 pending invite joins with this role. Choose the role it gets instead.|[0,*] :count pending invites join with this role. Choose the role they get instead.',
        'nobody' => 'No one has this role. It goes at once.',
        'move_to' => 'Move them to',
        'submit' => 'Delete role',
    ],
    'compare' => [
        'title' => 'Compare roles',
        'description' => 'Every permission, every role. Pick a role’s name to open it.',
        'permission' => 'Permission',
        'people' => '{0} No one|{1} 1 person|[0,*] :count people',
    ],
    'access_requests' => [
        'role' => 'Role',
        'role_hint' => 'What they can do in the workspaces you pick.',
        'not_everywhere' => 'You can’t give this role in every workspace you picked.',
    ],
];
