<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Keywords;

/**
 * Towns and neighbourhoods the time zone database doesn't name, so the
 * keyword check can tell that "coffee delivery lalitpur" names a place. Each
 * one says the city it is part of, if any: an audience in Kathmandu covers
 * Thamel, not Lalitpur.
 *
 * Only places people search for something in: cities, towns and city
 * neighbourhoods. Districts known for a product (Ilam tea, Gulmi coffee,
 * Mustang apples) are left out, since a keyword names them for the product,
 * not for where the audience is. Names are lowercase words, as
 * KeywordMatcher reads them.
 */
final class LocalPlaces
{
    /**
     * Country code => place => the city it is part of (null: a city or town of its own).
     *
     * @var array<string, array<string, string|null>>
     */
    private const array PLACES = [
        'NP' => [
            // Kathmandu Valley
            'kathmandu' => null,
            'lalitpur' => null,
            'bhaktapur' => null,
            'kirtipur' => null,
            'patan' => 'lalitpur',
            'thimi' => 'bhaktapur',
            'nagarkot' => 'bhaktapur',
            'suryabinayak' => 'bhaktapur',
            'thamel' => 'kathmandu',
            'durbar marg' => 'kathmandu',
            'lazimpat' => 'kathmandu',
            'baluwatar' => 'kathmandu',
            'naxal' => 'kathmandu',
            'baneshwor' => 'kathmandu',
            'new baneshwor' => 'kathmandu',
            'koteshwor' => 'kathmandu',
            'tinkune' => 'kathmandu',
            'sinamangal' => 'kathmandu',
            'boudha' => 'kathmandu',
            'boudhanath' => 'kathmandu',
            'chabahil' => 'kathmandu',
            'kapan' => 'kathmandu',
            'maharajgunj' => 'kathmandu',
            'budhanilkantha' => 'kathmandu',
            'tokha' => 'kathmandu',
            'gongabu' => 'kathmandu',
            'balaju' => 'kathmandu',
            'kalanki' => 'kathmandu',
            'swayambhu' => 'kathmandu',
            'kalimati' => 'kathmandu',
            'tripureshwor' => 'kathmandu',
            'thapathali' => 'kathmandu',
            'babarmahal' => 'kathmandu',
            'putalisadak' => 'kathmandu',
            'dillibazar' => 'kathmandu',
            'chandragiri' => 'kathmandu',
            'jhamsikhel' => 'lalitpur',
            'pulchowk' => 'lalitpur',
            'kupondole' => 'lalitpur',
            'sanepa' => 'lalitpur',
            'jawalakhel' => 'lalitpur',
            'lagankhel' => 'lalitpur',
            'satdobato' => 'lalitpur',
            'imadol' => 'lalitpur',
            'godawari' => 'lalitpur',
            'bhaisepati' => 'lalitpur',
            // Other cities and towns
            'pokhara' => null,
            'biratnagar' => null,
            'birgunj' => null,
            'bharatpur' => null,
            'chitwan' => null,
            'sauraha' => 'chitwan',
            'butwal' => null,
            'bhairahawa' => null,
            'siddharthanagar' => null,
            'lumbini' => null,
            'dharan' => null,
            'itahari' => null,
            'hetauda' => null,
            'janakpur' => null,
            'nepalgunj' => null,
            'dhangadhi' => null,
            'mahendranagar' => null,
            'birendranagar' => null,
            'surkhet' => null,
            'tulsipur' => null,
            'ghorahi' => null,
            'damak' => null,
            'birtamod' => null,
            'dhulikhel' => null,
            'banepa' => null,
            'panauti' => null,
            'bandipur' => null,
            'tansen' => null,
            'lukla' => null,
        ],
    ];

    /**
     * Every place of every country.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_values(array_unique(array_merge(...array_map(array_keys(...), array_values(self::PLACES)))));
    }

    /**
     * The places of one country (an ISO 3166-1 alpha-2 code).
     *
     * @return list<string>
     */
    public static function inCountry(string $code): array
    {
        return array_keys(self::PLACES[strtoupper($code)] ?? []);
    }

    /**
     * The city a place is part of ("thamel" is part of "kathmandu"), or null.
     */
    public static function partOf(string $place): ?string
    {
        foreach (self::PLACES as $places) {
            if (array_key_exists($place, $places)) {
                return $places[$place];
            }
        }

        return null;
    }
}
