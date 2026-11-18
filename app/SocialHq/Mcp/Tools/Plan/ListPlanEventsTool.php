<?php

declare(strict_types=1);

namespace App\SocialHq\Mcp\Tools\Plan;

use App\Models\Workspace;
use App\SocialHq\Events\CalendarEvent;
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
#[Description('List the dated events (festivals, public holidays, international days and the brand\'s own dates) that overlap one month, as the content plan sees them, in date order. confirmed is true when a person checked the date; plan content only around confirmed events, and never calculate or guess a festival\'s date yourself: Nepali festival dates follow the Bikram Sambat calendar and come from the official holiday list. country_code is null for international days.')]
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
                ->map(fn (CalendarEvent $event): array => [
                    'id' => $event->id,
                    'name' => $event->name,
                    'category' => $event->category->value,
                    'starts_on' => $event->starts_on->toDateString(),
                    'ends_on' => $event->ends_on->toDateString(),
                    'country_code' => $event->country_code,
                    'confirmed' => $event->isVerified(),
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
