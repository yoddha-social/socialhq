<?php

declare(strict_types=1);

// Social HQ copy, English only: bin/socialhq-sync-lang copies it to the other locales.

return [
    'title' => 'Events',
    'description' => 'Festivals, holidays and days your brands can post about. Dates come from official lists, and someone on your team confirms each one. The AI plans only around confirmed dates.',
    'read_only' => 'Workspace admins confirm, add and change dates. Ask one if a date looks wrong.',

    'view' => [
        'label' => 'Period',
        'month' => 'Month',
        'upcoming' => 'Next 90 days',
    ],
    'nav' => [
        'previous' => 'Previous month',
        'next' => 'Next month',
        'this_month' => 'This month',
    ],

    'filters' => [
        'label' => 'Show dates',
        'all' => 'All',
        'nepal' => 'Nepal',
        'international' => 'International',
        'brand' => 'This brand only',
        'unverified' => 'Needs check',
    ],

    'categories' => [
        'festival' => 'Festival',
        'public_holiday' => 'Public holiday',
        'national_day' => 'National day',
        'international_day' => 'International day',
        'commercial' => 'Shopping day',
        'observance' => 'Observance',
        'brand' => 'Brand date',
    ],
    'regions' => [
        'nepal' => 'Nepal',
        'international' => 'International',
        'brand' => 'This brand',
    ],

    'badges' => [
        'public_holiday' => 'Public holiday',
        'needs_check' => 'Needs check',
        'confirmed_by' => 'Confirmed by :name',
        'confirmed' => 'Confirmed',
        'brand_only' => 'This brand only',
    ],
    'until' => 'Until :date',
    'source' => 'Source',
    'today' => 'Today',

    'actions' => [
        'confirm' => 'Confirm date',
        'unconfirm' => 'Undo confirmation',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'more' => 'More actions for :name',
        'add' => 'Add a date',
        'import' => 'Import official dates',
    ],

    'dialog' => [
        'add_title' => 'Add a date',
        'add_description' => 'A date your brand plans around, like a launch or an anniversary. Someone on your team confirms it before the AI uses it.',
        'edit_title' => 'Edit date',
        'edit_description' => 'Changing the name or the dates takes back its confirmation.',
        'name' => 'Name',
        'name_placeholder' => 'Store anniversary',
        'name_ne' => 'Nepali name',
        'category' => 'Kind',
        'starts_on' => 'Starts',
        'ends_on' => 'Ends',
        'bs_date' => 'Nepali date (BS)',
        'bs_date_placeholder' => '2083 Kartik 17',
        'public_holiday' => 'Public holiday',
        'public_holiday_hint' => 'Offices and schools close that day.',
        'description' => 'Notes',
        'source_url' => 'Source link',
        'source_url_placeholder' => 'https://',
        'brand_only' => 'Only this brand',
        'brand_only_hint' => 'The other brands of your account won\'t see it.',
        'cancel' => 'Cancel',
        'save' => 'Save date',
        'add' => 'Add date',
    ],

    'delete' => [
        'title' => 'Delete :name?',
        'description' => 'It leaves the events list and the publish calendar of every brand that sees it.',
        'confirm' => 'Delete',
    ],

    'empty' => [
        'title' => 'You haven\'t added any dates yet',
        'description' => 'To plan around festivals and holidays, import the official dates. You confirm each one before the AI uses it.',
        'description_member' => 'To plan around festivals and holidays, a workspace admin needs to import the official dates.',
        'none' => 'No dates in this period',
        'none_hint' => 'Try another month, or the next 90 days.',
        'no_match' => 'No dates match this filter',
        'no_match_hint' => 'Try another filter, or show all dates.',
        'show_all' => 'Show all',
    ],

    'errors' => [
        'ends_before_start' => 'The end date can\'t be before the start date.',
        'too_long' => 'An event can last up to 31 days.',
        'duplicate' => 'An event with this name is already on that day.',
    ],

    'import' => [
        'done' => 'Official dates imported: :created new, :updated updated, :skipped unchanged.',
        'failed' => 'Couldn\'t import the official dates. Ask whoever runs Social HQ to check the server log.',
    ],

    'markers' => [
        'label' => 'Events on this day: :names',
        'needs_check' => ':name (needs check)',
        'open' => 'Open events',
    ],
];
