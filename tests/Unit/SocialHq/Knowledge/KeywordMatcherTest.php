<?php

declare(strict_types=1);

use App\SocialHq\Knowledge\Keywords\KeywordMatcher;

test('a text says a keyword when it has its words in order, case and accents ignored', function (string $text, bool $says) {
    expect(KeywordMatcher::says($text, 'specialty coffee kathmandu'))->toBe($says);
})->with([
    'the same words' => ['Specialty coffee Kathmandu, roasted daily.', true],
    'other case' => ['SPECIALTY COFFEE KATHMANDU', true],
    'a linking word between' => ['The best specialty coffee in Kathmandu.', true],
    'two linking words between' => ['specialty coffee from the Kathmandu valley', true],
    'another word between' => ['specialty coffee shop Kathmandu', false],
    'another order' => ['Kathmandu specialty coffee', false],
    'a word missing' => ['specialty coffee only', false],
    'a hashtag of the words run together' => ['Come by! #SpecialtyCoffeeKathmandu', true],
    'part of a word' => ['specialty coffeekathmandu', false],
]);

test('accents and the keyword\'s own linking words do not matter', function () {
    expect(KeywordMatcher::says('A café in Thamel.', 'cafe in thamel'))->toBeTrue()
        ->and(KeywordMatcher::says('Our cafe, Thamel.', 'cafe in thamel'))->toBeTrue()
        ->and(KeywordMatcher::says('Our café thamel', 'café thamel'))->toBeTrue();
});

test('whole words only: a longer word does not say a shorter one', function () {
    expect(KeywordMatcher::says('Cold brewing at home', 'cold brew'))->toBeFalse()
        ->and(KeywordMatcher::says('Cold brew at home', 'cold brew'))->toBeTrue();
});

test('Nepali keywords keep their vowel signs', function () {
    expect(KeywordMatcher::says('नयाँ कफी काठमाडौं मा', 'कफी काठमाडौं'))->toBeTrue()
        ->and(KeywordMatcher::says('कफि काठमाडौं', 'कफी काठमाडौं'))->toBeFalse();
});

test('in() lists the keywords a text says, in the order given', function () {
    expect(KeywordMatcher::in('Cold brew season is here. #nepalicoffee', ['nepali coffee', 'cold brew', 'organic coffee']))
        ->toBe(['nepali coffee', 'cold brew']);
});
