<?php

namespace App\Mail;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResetPasswordLink extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public string $url)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your '.$this->site().' password');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.reset-password', with: [
            'site' => $this->site(),
            'minutes' => (int) config('auth.passwords.users.expire', 60),
        ]);
    }

    private function site(): string
    {
        return (string) PlatformSetting::valueFor('site_title', config('app.name', 'Vamoseone'));
    }
}
