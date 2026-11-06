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
 * To the company's owner: a platform admin approved it.
 */
class CompanyApproved extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public int $companyId) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.socialhq_company_approved.subject', ['company' => $this->company()->name, 'app' => config('app.name')]));
    }

    public function content(): Content
    {
        $company = $this->company();

        return new Content(view: 'mail.socialhq-company-approved', with: [
            'title' => __('mail.socialhq_company_approved.title'),
            'previewText' => __('mail.socialhq_company_approved.preview', ['company' => $company->name]),
            'companyName' => $company->name,
            'url' => route('app.workspaces.create'),
        ]);
    }

    private function company(): Company
    {
        return Company::query()->findOrFail($this->companyId);
    }
}
