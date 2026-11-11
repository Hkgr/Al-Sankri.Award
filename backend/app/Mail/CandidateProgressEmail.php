<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class CandidateProgressEmail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(public array $details) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->details['subject'].' | '.($this->details['locale'] === 'en' ? 'Dr. Abdulkader Sankari Award' : 'جائزة الدكتور عبد القادر سنكري'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'mail.candidate-progress',
            text: 'mail.candidate-progress-text',
            with: $this->details + [
                'logoPath' => resource_path('mail/award-'.$this->details['locale'].'.png'),
                'when' => $this->details['occurredAt'] === null ? null : Carbon::parse($this->details['occurredAt'], 'UTC')->timezone('Asia/Damascus')->locale($this->details['locale'])->isoFormat($this->details['locale'] === 'en' ? 'D MMMM YYYY, HH:mm' : 'D MMMM YYYY، HH:mm'),
            ],
        );
    }

    public function headers(): Headers
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'award.invalid';

        return new Headers(messageId: $this->details['messageKey'].'@'.$host);
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
