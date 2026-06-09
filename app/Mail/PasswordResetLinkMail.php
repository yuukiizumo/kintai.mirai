<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordResetLinkMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public string $resetUrl)
    {
    }

    public function build(): self
    {
        return $this
            ->subject('Password reset link')
            ->view('emails.password-reset-link');
    }
}
