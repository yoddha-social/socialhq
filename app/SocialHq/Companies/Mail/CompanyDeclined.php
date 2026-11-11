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
 * To the company's owner: a platform admin declined it, with their note.
 */
class CompanyDeclined extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public int $companyId) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.socialhq_company_declined.subject', ['company' => $this->company()->name, 'app' => config('app.name')]));
    }

    public function content(): Content
    {
        $company = $this->company();

        return new Content(view: 'mail.socialhq-company-declined', with: [
            'title' => __('mail.socialhq_company_declined.title'),
            'previewText' => __('mail.socialhq_company_declined.preview', ['company' => $company->name]),
            'companyName' => $company->name,
            'note' => (string) $company->decision_note,
            'hours' => (int) config('socialhq.companies.ask_again_after_hours', 24),
            'url' => route('socialhq.company.waiting'),
        ]);
    }

    private function company(): Company
    {
        return Company::query()->findOrFail($this->companyId);
    }
}
