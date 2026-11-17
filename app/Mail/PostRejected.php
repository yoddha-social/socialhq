<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use App\Support\Mail\ApprovalEmailPost;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PostRejected extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * The approver's reason, if any. A plain property with a default, so a
     * payload queued before it existed still unserializes.
     */
    public ?string $reason = null;

    public function __construct(
        public string $postId,
        public User $approver,
        ?string $reason = null,
    ) {
        $this->reason = $reason;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.post_rejected.subject', ['name' => $this->approver->name]),
        );
    }

    public function content(): Content
    {
        $post = ApprovalEmailPost::find($this->postId);
        $workspaceName = (string) $post?->workspace?->name;

        return new Content(
            view: 'mail.post-rejected',
            with: [
                'title' => __('mail.post_rejected.title'),
                'previewText' => __('mail.post_rejected.preview', ['name' => $this->approver->name]),
                'approverName' => $this->approver->name,
                'workspaceName' => $workspaceName,
                'channel' => ApprovalEmailPost::channel($post),
                'reason' => $this->reason,
                'url' => route('app.posts.index', ['tab' => 'drafts']),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
