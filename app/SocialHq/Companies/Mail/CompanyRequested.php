<?php

declare(strict_types=1);

namespace App\SocialHq\Companies\Mail;

use App\SocialHq\Companies\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * To the platform admins: someone confirmed their email and asks to start a company.
 */
class CompanyRequested extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public int $companyId) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.socialhq_company_requested.subject', ['company' => $this->company()->name, 'app' => config('app.name')]));
    }

    public function content(): Content
    {
        $company = $this->company();
        $owner = $company->account?->owner;

        return new Content(view: 'mail.socialhq-company-requested', with: [
            'title' => __('mail.socialhq_company_requested.title'),
            'previewText' => __('mail.socialhq_company_requested.preview', ['company' => $company->name]),
            'companyName' => $company->name,
            'requesterName' => (string) $owner?->name,
            'requesterEmail' => (string) $owner?->email,
            'url' => route('socialhq.platform.companies.index'),
        ]);
    }

    private function company(): Company
    {
        return Company::query()->findOrFail($this->companyId);
    }
}
