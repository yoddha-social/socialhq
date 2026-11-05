<?php

declare(strict_types=1);

namespace App\SocialHq\Signup\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * To the person who signed up: an admin let them in.
 */
class AccessApproved extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $userId) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.socialhq_access_approved.subject', ['app' => config('app.name')]));
    }

    public function content(): Content
    {
        $user = User::query()->find($this->userId);
        $workspaceNames = $user?->workspaces()->orderBy('name')->pluck('name')->join(', ') ?? '';

        return new Content(view: 'mail.socialhq-access-approved', with: [
            'title' => __('mail.socialhq_access_approved.title'),
            'previewText' => __('mail.socialhq_access_approved.preview', ['app' => config('app.name')]),
            'workspaceNames' => $workspaceNames,
            'url' => route('app.home'),
        ]);
    }
}
