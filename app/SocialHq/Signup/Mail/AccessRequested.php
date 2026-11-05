<?php

declare(strict_types=1);

namespace App\SocialHq\Signup\Mail;

use App\SocialHq\Signup\AccessRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * To the account's admins: someone signed up and confirmed their email.
 */
class AccessRequested extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public int $accessRequestId) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.socialhq_access_requested.subject', ['name' => $this->accessRequest()->name, 'app' => config('app.name')]));
    }

    public function content(): Content
    {
        $request = $this->accessRequest();

        return new Content(view: 'mail.socialhq-access-requested', with: [
            'title' => __('mail.socialhq_access_requested.title'),
            'previewText' => __('mail.socialhq_access_requested.preview', ['name' => $request->name]),
            'requesterName' => $request->name,
            'requesterEmail' => $request->email,
            'url' => route('socialhq.access-requests.index'),
        ]);
    }

    private function accessRequest(): AccessRequest
    {
        return AccessRequest::query()->findOrFail($this->accessRequestId);
    }
}
