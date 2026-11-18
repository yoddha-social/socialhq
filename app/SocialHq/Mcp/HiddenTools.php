<?php

declare(strict_types=1);

namespace App\SocialHq\Mcp;

use App\Mcp\Tools\ApiKey\CreateApiKeyTool;
use App\Mcp\Tools\ApiKey\DeleteApiKeyTool;
use App\Mcp\Tools\ApiKey\ListApiKeysTool;
use App\Mcp\Tools\Webhook\CreateWebhookTool;
use App\Mcp\Tools\Webhook\DeleteWebhookTool;
use App\Mcp\Tools\Webhook\GetWebhookTool;
use App\Mcp\Tools\Webhook\ListWebhookLogsTool;
use App\Mcp\Tools\Webhook\ListWebhooksTool;
use App\Mcp\Tools\Webhook\ReplayWebhookLogTool;
use App\Mcp\Tools\Webhook\RotateWebhookSecretTool;
use App\Mcp\Tools\Webhook\SendWebhookTestTool;
use App\Mcp\Tools\Webhook\UpdateWebhookTool;
use App\SocialHq\Agents\AdminSettings;
use Laravel\Mcp\Server\Tool;

/**
 * TryPost tools the MCP server leaves out. Every MCP caller is an agent, so a
 * tool its policy always refuses to agents is only something for a misled
 * model to try (TryPostServer::boot()).
 */
final class HiddenTools
{
    /**
     * @var list<class-string<Tool>>
     */
    public const ADMIN_SETTINGS = [
        ListWebhooksTool::class,
        GetWebhookTool::class,
        CreateWebhookTool::class,
        UpdateWebhookTool::class,
        DeleteWebhookTool::class,
        SendWebhookTestTool::class,
        RotateWebhookSecretTool::class,
        ListWebhookLogsTool::class,
        ReplayWebhookLogTool::class,
        ListApiKeysTool::class,
        CreateApiKeyTool::class,
        DeleteApiKeyTool::class,
    ];

    /**
     * @param  array<int, class-string<Tool>|Tool>  $tools
     * @return list<class-string<Tool>|Tool>
     */
    public static function without(array $tools): array
    {
        if (AdminSettings::agentsMayManage()) {
            return array_values($tools);
        }

        return array_values(array_filter(
            $tools,
            fn (string|Tool $tool): bool => ! in_array(is_string($tool) ? $tool : $tool::class, self::ADMIN_SETTINGS, true),
        ));
    }
}
