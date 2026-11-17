<?php

declare(strict_types=1);

namespace App\SocialHq\Companies\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * To someone who already has an account when the register form is sent again
 * with their email: nothing new was created; log in, or reset the password
 * with the link. The form itself never says which happened (CompanySignup).
 */
class AccountExists extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $email,
        public string $resetUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.socialhq_account_exists.subject', ['app' => config('app.name')]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.socialhq-account-exists', with: [
            'title' => __('mail.socialhq_account_exists.title', ['app' => config('app.name')]),
            'previewText' => __('mail.socialhq_account_exists.preview', ['app' => config('app.name')]),
            'name' => $this->name,
            'email' => $this->email,
            'url' => $this->resetUrl,
            'loginUrl' => route('login'),
        ]);
    }
}
