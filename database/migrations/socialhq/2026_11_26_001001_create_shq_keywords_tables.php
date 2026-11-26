<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SEO keywords move from the brand profile's JSON list ([{text, main}]) to
 * their own table, each with tags (a pillar, a product or service, an
 * occasion). Every saved keyword is copied as it was: its text, main or
 * not, and its place in the list. They were already cleaned and without
 * duplicates when saved, so nothing is dropped.
 *
 * Plain queries only (no app code), so the upgrade keeps working whatever
 * the app's classes become.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shq_keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('text', 60);
            // Its words, case and accents ignored: two keywords with the same match_key are the same keyword.
            $table->string('match_key', 120);
            $table->boolean('main')->default(false);
            // The admin's order.
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['workspace_id', 'match_key']);
            $table->index(['workspace_id', 'position']);
        });

        // What a keyword is for: a pillar, a product or service, an event or a range of months. None: general.
        Schema::create('shq_keyword_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('keyword_id')->constrained('shq_keywords')->cascadeOnDelete();
            $table->string('kind', 16);
            // pillar: the pillar's name; event: the occasion's key, the same every year (Keywords\Occasion).
            $table->string('value', 160)->nullable();
            // event: its name when it was tagged, shown when no date of it is listed any more.
            $table->string('label', 160)->nullable();
            // product: the knowledge entry; the tag goes with it.
            $table->foreignId('entry_id')->nullable()->constrained('shq_knowledge_entries')->cascadeOnDelete();
            // months: the first and last month (1 to 12); from after to runs over the new year.
            $table->unsignedTinyInteger('month_from')->nullable();
            $table->unsignedTinyInteger('month_to')->nullable();
            $table->timestamps();

            $table->index('keyword_id');
        });

        // Plan items: the keywords a person chose for this post (null: the app picks).
        Schema::table('shq_plan_items', function (Blueprint $table) {
            $table->json('seo_keywords')->nullable();
        });

        $now = now();

        DB::table('shq_brand_profiles')->whereNotNull('keywords')->orderBy('id')->each(function (object $profile) use ($now): void {
            $rows = [];
            $seen = [];

            foreach ((array) json_decode((string) $profile->keywords, true) as $index => $keyword) {
                $text = trim((string) data_get($keyword, 'text'));
                $key = self::matchKey($text);

                if ($text === '' || $key === '' || isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $rows[] = [
                    'workspace_id' => $profile->workspace_id,
                    'text' => mb_substr($text, 0, 60),
                    'match_key' => mb_substr($key, 0, 120),
                    'main' => (bool) data_get($keyword, 'main', false),
                    'position' => $index,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                DB::table('shq_keywords')->insert($rows);
            }
        });

        Schema::table('shq_brand_profiles', function (Blueprint $table) {
            $table->dropColumn('keywords');
        });
    }

    public function down(): void
    {
        Schema::table('shq_brand_profiles', function (Blueprint $table) {
            $table->json('keywords')->nullable();
        });

        // Back to the JSON list: text, main and order. Tags have nowhere to go.
        DB::table('shq_keywords')->orderBy('workspace_id')->orderBy('position')->orderBy('id')->get()
            ->groupBy('workspace_id')
            ->each(function ($keywords, string $workspaceId): void {
                $list = $keywords->map(fn (object $keyword): array => ['text' => $keyword->text, 'main' => (bool) $keyword->main])->values()->all();

                if (DB::table('shq_brand_profiles')->where('workspace_id', $workspaceId)->exists()) {
                    DB::table('shq_brand_profiles')->where('workspace_id', $workspaceId)->update(['keywords' => json_encode($list)]);
                } else {
                    DB::table('shq_brand_profiles')->insert(['workspace_id' => $workspaceId, 'keywords' => json_encode($list), 'created_at' => now(), 'updated_at' => now()]);
                }
            });

        Schema::table('shq_plan_items', function (Blueprint $table) {
            $table->dropColumn('seo_keywords');
        });

        Schema::dropIfExists('shq_keyword_tags');
        Schema::dropIfExists('shq_keywords');
    }

    /**
     * A keyword's words, lowercased, accents on Latin letters dropped, as
     * Keywords\BrandKeywords::sameKey() reads them today.
     */
    private static function matchKey(string $text): string
    {
        $text = mb_strtolower($text);
        $decomposed = Normalizer::normalize($text, Normalizer::FORM_D);

        if (is_string($decomposed)) {
            $text = (string) Normalizer::normalize((string) preg_replace('/[\x{0300}-\x{036F}]/u', '', $decomposed), Normalizer::FORM_C);
        }

        preg_match_all('/[\p{L}\p{M}\p{N}]+/u', $text, $matches);

        return implode(' ', $matches[0]);
    }
};
