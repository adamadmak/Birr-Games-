<?php

namespace App\Mail;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerifyEmailAddress extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public string $url)
    {
    }

    public function envelope(): Envelope
    {
        $site = (string) PlatformSetting::valueFor('site_title', config('app.name', 'Birr Games'));

        return new Envelope(subject: 'Confirm your email for '.$site);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.verify-email', with: [
            'site' => (string) PlatformSetting::valueFor('site_title', config('app.name', 'Birr Games')),
            'hours' => \App\Services\EmailVerification::LINK_LIFETIME_HOURS,
        ]);
    }
}
