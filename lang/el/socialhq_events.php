<?php

declare(strict_types=1);

// Social HQ copy, English only: bin/socialhq-sync-lang copies it to the other locales.

return [
    'title' => 'Events',
    'description' => 'Festivals, holidays and other days your brands can post about. The AI plans only around dates someone on your team has confirmed.',
    'read_only' => 'Workspace admins add, change and confirm dates. If a date looks wrong, tell one of them.',
    'shared_read_only' => 'You can add and change this brand\'s own dates. Only the account owner can change dates shared by every brand.',

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
        'country' => 'Country',
        'all_countries' => 'All countries',
        'country_hint' => 'Dates for :country, plus international days and your brands\' own dates.',
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
        'country' => 'Another country',
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
        'add_country' => 'Add a country',
    ],

    'dialog' => [
        'add_title' => 'Add a date',
        'add_description' => 'A date your brand plans around, like a launch or an anniversary. The AI uses it once you confirm it.',
        'edit_title' => 'Edit date',
        'edit_description' => 'If you change the name or dates, use Confirm date again after saving.',
        'name' => 'Name',
        'name_placeholder' => 'Store anniversary',
        'name_ne' => 'Nepali name',
        'name_local' => 'Local name',
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
        'brand_only_locked' => 'Only the account owner can add dates for every brand.',
        'cancel' => 'Cancel',
        'save' => 'Save date',
    ],

    'delete' => [
        'title' => 'Delete :name?',
        'description' => 'It\'s removed from the events list and publish calendar of every brand that sees it.',
        'confirm' => 'Delete',
    ],

    'empty' => [
        'title' => 'You haven\'t added any dates yet',
        'description' => 'To plan around festivals and holidays, use Import official dates. You confirm each date before the AI uses it.',
        'description_member' => 'To plan around festivals and holidays, a workspace admin needs to add the dates.',
        'description_brand' => 'To plan around festivals and holidays, the account owner imports the official dates. You can add this brand\'s own dates with Add a date.',
        'none' => 'No dates in this period',
        'none_hint' => 'Try another month, or the next 90 days.',
        'none_hint_upcoming' => 'Pick Month to see other months.',
        'no_match' => 'No dates match this filter',
        'no_match_hint' => 'Try another filter, or show all dates.',
        'show_all' => 'Show all',
    ],

    'errors' => [
        'ends_before_start' => 'Pick an end date on or after the start date.',
        'too_long' => 'Pick an end date within 31 days of the start date.',
        'duplicate' => 'There\'s already an event with this name on that day. Use another name or day.',
        'brand_kind_shared' => 'A brand date belongs to one brand. Switch on Only this brand, or pick another kind.',
    ],

    'import' => [
        'done' => 'Official dates imported: :created new, :updated updated, :skipped unchanged.',
        'missing' => '{1} One date is no longer in the official list; it stays until you delete it.|[0,*] :count dates are no longer in the official list; they stay until you delete them.',
        'failed' => 'Couldn\'t import the official dates. Try again; if it keeps failing, tell whoever runs Social HQ.',
    ],

    'countries' => [
        'title' => 'Add a country\'s public holidays',
        'description' => 'Import a country\'s public holidays from Nager.Date. Each date starts as Needs check, and the AI uses it once someone confirms it.',
        'countries' => 'Countries',
        'search' => 'Search countries',
        'selected' => ':count of :max picked',
        'nepal_note' => 'Nepal\'s dates come from Import official dates.',
        'years' => 'Years',
        'loading' => 'Loading countries…',
        'load_failed' => 'Couldn\'t load the countries from Nager.Date. Try again in a few minutes.',
        'retry' => 'Try again',
        'no_match' => 'No country matches your search.',
        'cancel' => 'Cancel',
        'import' => 'Import holidays',
        'done' => 'Public holidays imported for :countries: :created new, :updated updated, :skipped unchanged. Check each date before planning around it.',
        'missing' => '{1} One holiday is no longer listed by Nager.Date; it stays until you delete it.|[0,*] :count holidays are no longer listed by Nager.Date; they stay until you delete them.',
        'unavailable' => 'Couldn\'t reach Nager.Date for :countries, so nothing was imported. Try again in a few minutes.',
        'failed' => 'Couldn\'t import these holidays. Try again; if it keeps failing, tell whoever runs Social HQ.',
        'errors' => [
            'pick_country' => 'Pick at least one country.',
            'too_many' => 'Pick up to :max countries at a time.',
            'nepal' => 'Nepal\'s dates come from the official list: use Import official dates.',
            'unknown' => 'Pick a country from the list.',
            'pick_year' => 'Pick this year, next year or both.',
        ],
    ],

    'markers' => [
        'label' => 'Events on this day: :names',
        'needs_check' => ':name (needs check)',
    ],
];
