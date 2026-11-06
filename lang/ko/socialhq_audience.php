<?php

declare(strict_types=1);

// Social HQ copy, English only: bin/socialhq-sync-lang copies it to the other locales.

return [
    'title' => 'Where your audience is',
    'description' => 'The plan suggests posting times in your largest audience\'s time zone, and can keep to their countries\' events.',
    'edit' => 'Edit',
    'edit_label' => 'Edit where your audience is',
    'empty' => 'No places yet. Posting times follow your own time zone (:zone) until you add some.',
    'empty_member' => 'No places yet. Posting times follow your own time zone (:zone) until a workspace admin adds some.',
    'main' => 'Main time zone',
    'main_help' => 'Posting times are written in :zone, the time zone of your largest audience.',
    'share' => ':share%',
    'total' => ':total% of your audience',
    'dialog' => [
        'title' => 'Where your audience is',
        'description' => 'Add the countries and cities your audience lives in, with roughly what share of it is there.',
        'country' => 'Country',
        'country_placeholder' => 'Pick a country',
        'city' => 'City',
        'city_placeholder' => 'Any city',
        'timezone' => 'Time zone',
        'share' => 'Share (%)',
        'remove' => 'Remove place',
        'add' => 'Add place',
        'total' => 'Total: :total% of 100%',
        'cancel' => 'Cancel',
        'save' => 'Save places',
        'place' => 'Place :number',
    ],
    'validation' => [
        'country' => 'Pick a country from the list.',
        'city' => 'Keep the city under :max characters.',
        'timezone' => 'Pick a time zone from the list.',
        'share' => 'Enter a share from 1 to 100%.',
        'total' => 'The shares add up to more than 100%. Lower some of them.',
        'duplicate' => 'This place is already on the list.',
        'max' => 'Add up to :max places.',
    ],
];
