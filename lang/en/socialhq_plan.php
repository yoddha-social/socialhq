<?php

declare(strict_types=1);

// Social HQ copy, English only: bin/socialhq-sync-lang copies it to the other locales.

return [
    'nav' => 'Plan',
    'tabs' => [
        'label' => 'Plan sections',
        'plan' => 'Content plan',
        'production' => 'Production',
        'events' => 'Events',
    ],
    'validation' => [
        'date_in_month' => 'Pick a day in this plan\'s month.',
        'channel' => 'Pick one of this brand\'s channels.',
        'content_type' => 'Pick a format this channel offers.',
        'pillar' => 'Pick one of the brand\'s pillars.',
        'event' => 'Pick an event of this month.',
        'owner' => 'Pick someone in this workspace.',
        'pillars_max' => 'Add up to :max pillars.',
        'pillar_blank' => 'Give every pillar a name, or remove it.',
        'pillar_length' => 'Keep each pillar under :max characters.',
        'pillar_unique' => 'Give each pillar a different name.',
        'cadence' => 'Posts per week go from 0 to :max.',
    ],
    'errors' => [
        'empty_plan' => 'Add at least one item to the plan first.',
        'open_suggestions' => 'Accept or drop every AI suggestion before approving the plan.',
        'item_changed' => 'This item changed in the meantime. Reload the page and try again.',
    ],
    'generation' => [
        'errors' => [
            'ai_unavailable' => 'AI drafts aren\'t set up on this server yet. Ask whoever runs Social HQ to set up an AI provider.',
            'ai_failed' => 'The AI couldn\'t draft this month. Try again in a few minutes.',
            'invalid_output' => 'The AI\'s answer couldn\'t be read. Try again.',
            'no_access' => 'The person who started this draft can no longer work on this plan.',
            'stale' => 'The last AI draft stopped before it finished. Try again.',
            'already_running' => 'An AI draft of this month is already running.',
        ],
    ],
];
