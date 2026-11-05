<?php

declare(strict_types=1);

// Social HQ module copy. English only: every other locale ships this same
// file (bin/socialhq-sync-lang copies it), so the parity test passes and
// non-English users see English for Social HQ screens.

return [
    'workspace_switcher' => [
        'label' => 'Workspace',
        'all' => 'All workspaces',
        'settings' => 'Workspace settings',
        'switch_to' => 'Switch workspace',
    ],
    'approvals' => [
        'members_note' => 'Needs approval. In Social HQ every member who is not an admin sends posts for approval.',
    ],
    'signup' => [
        'not_allowed' => "This email can't sign up here. Ask a workspace admin to invite you.",
        'pending' => [
            'page_title' => 'Waiting for access',
            'title' => 'Waiting for an admin',
            'description' => "You're signed up as :email. An admin needs to add you to a workspace before you can start.",
            'verify_first' => "First confirm your email: we sent you a link. Admins hear about your request once it's confirmed.",
            'notified' => "Your admins have your request. We'll email you as soon as they let you in.",
            'resend' => 'Resend confirmation email',
            'link_sent' => 'A new confirmation link is on its way.',
            'log_out' => 'Log out',
        ],
        'requests' => [
            'page_title' => 'Access requests',
            'title' => 'Access requests',
            'description' => 'People who signed up with your company email and are waiting to be let in.',
            'menu_item' => 'Access requests',
            'empty' => 'No one is waiting for access.',
            'requested' => 'Signed up :time',
            'approve' => 'Let in',
            'decline' => 'Decline',
            'approve_title' => 'Let :name in',
            'approve_description' => 'Pick the workspaces :name can work in.',
            'workspaces_label' => 'Workspaces',
            'admin_label' => 'Make them a workspace admin',
            'admin_hint' => 'Admins manage members, channels and settings, and publish without approval.',
            'confirm_approve' => 'Let them in',
            'decline_title' => 'Decline :name?',
            'decline_description' => 'Their account is deleted and they can sign up again later.',
            'confirm_decline' => 'Decline',
            'cancel' => 'Cancel',
            'approved' => ':name can now work in the workspaces you picked.',
            'declined' => 'You declined :name.',
            'pick_workspace' => 'Pick at least one workspace.',
        ],
        'mail' => [
            'requested' => [
                'subject' => ':name wants to join Social HQ',
                'title' => 'New access request',
                'preview' => ':name signed up and is waiting for access.',
                'heading' => 'Someone is waiting for access',
                'body' => ':name (:email) signed up with a company email. Let them into a workspace, or decline.',
                'button' => 'Review the request',
                'note' => 'Only admins of your workspaces get this email.',
            ],
            'approved' => [
                'subject' => "You're in: :app is ready",
                'title' => 'Access approved',
                'preview' => 'An admin let you into :app.',
                'heading' => "You're in",
                'body' => 'An admin added you to: :workspaces.',
                'button' => 'Open Social HQ',
            ],
        ],
    ],
];
