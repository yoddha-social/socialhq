<?php

declare(strict_types=1);

namespace App\SocialHq\Notices;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tells a person's open tabs that the bell has a new notice. It carries only
 * the notice's id: the tab asks the server for the text, in its own language.
 */
class NoticeCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public readonly int $noticeId;

    public readonly string $userId;

    public readonly string $workspaceId;

    public function __construct(Notice $notice)
    {
        $this->noticeId = $notice->id;
        $this->userId = $notice->user_id;
        $this->workspaceId = $notice->workspace_id;
    }

    public static function channelFor(string $userId): string
    {
        return "shq.user.{$userId}";
    }

    public function broadcastAs(): string
    {
        return 'notice.created';
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::channelFor($this->userId))];
    }

    /**
     * @return array{id: int, workspace_id: string}
     */
    public function broadcastWith(): array
    {
        return ['id' => $this->noticeId, 'workspace_id' => $this->workspaceId];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
