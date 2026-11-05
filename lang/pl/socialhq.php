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
    'ai_graphics' => [
        'menu_item' => 'Generate with AI',
        'title' => 'Generate a graphic with AI',
        'description' => 'Describe the graphic. Add up to :count reference images to use or follow, such as a product photo or a style.',
        'prompt_label' => 'Prompt',
        'prompt_placeholder' => 'A festive Dashain post with our product on a marigold background, headline "Happy Dashain"',
        'references_label' => 'Reference images (optional)',
        'add_reference' => 'Add image',
        'remove_reference' => 'Remove :name',
        'format_label' => 'Format',
        'formats' => [
            'square' => 'Square',
            'portrait' => 'Portrait',
            'story' => 'Story',
            'landscape' => 'Landscape',
        ],
        'submit' => 'Generate',
        'checking' => 'Checking the request…',
        'generating' => 'Generating the graphic. This takes up to a minute.',
        'review_note' => 'Requests and results go through an automatic content check. Every generation is logged.',
        'label' => 'AI graphic',
        'errors' => [
            'blocked' => 'The content check blocked this graphic.',
            'check_unavailable' => 'The content check is unavailable, so nothing was generated. Try again in a few minutes.',
            'generation_failed' => 'The graphic could not be generated. Try again or change the prompt.',
            'no_access' => 'You can no longer create posts in this workspace.',
            'daily_limit' => 'This workspace has used today\'s AI graphics. Try again tomorrow.',
            'too_many_references' => 'Add at most :count reference images.',
            'reference_type' => 'Reference images must be JPEG, PNG or WebP.',
        ],
    ],
];
