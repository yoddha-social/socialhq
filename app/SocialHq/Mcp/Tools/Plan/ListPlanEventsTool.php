<?php

declare(strict_types=1);

namespace App\SocialHq\Mcp\Tools\Plan;

use App\Models\Workspace;
use App\SocialHq\Events\EventGroup;
use App\SocialHq\Mcp\Concerns\WorksOnPlans;
use App\SocialHq\Plan\PlanMonth;
use App\SocialHq\Plan\PlanOptions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
#[Description('List the dated events (festivals, public holidays, international days and the brand\'s own dates) that overlap one month, as the content plan sees them, in date order. The same holiday on the same day in several countries is one event: countries lists them (empty for an international day or the brand\'s own date), and ids holds each country\'s date, any of which a plan item\'s event may be. confirmed is true when a person checked the date for at least one of its countries (confirmed_countries); plan content only around confirmed events, once per event, and never calculate or guess a festival\'s date yourself: Nepali festival dates follow the Bikram Sambat calendar and come from the official holiday list.')]
class ListPlanEventsTool extends Tool
{
    use WorksOnPlans;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->planWorkspace($request);

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $month = $this->planMonth($request);

        return Response::structured([
            'month' => PlanMonth::key($month),
            'events' => PlanOptions::events($workspace, $month, PlanMonth::end($month))
                ->map(fn (EventGroup $event): array => [
                    'id' => $event->id,
                    'ids' => $event->ids(),
                    'name' => $event->name(),
                    'category' => $event->category()->value,
                    'starts_on' => $event->startsOn()->toDateString(),
                    'ends_on' => $event->endsOn()->toDateString(),
                    'countries' => $event->countries(),
                    'confirmed' => $event->isConfirmed(),
                    'confirmed_countries' => $event->confirmedCountries(),
                ])
                ->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'month' => $schema->string()->description('The month as YYYY-MM, e.g. 2026-12. Omitted, the current month in the user\'s time zone.'),
        ];
    }
}
