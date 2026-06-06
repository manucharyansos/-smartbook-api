<?php

namespace App\Mail;

use App\Models\Business;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OwnerRegistered extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $owner,
        public Business $business,
        public int $trialDays,
    ) {}

    public function build()
    {
        return $this
            ->subject('Գրանցումը հաջողվեց — Vizit')
            ->view('emails.owner_registered');
    }
}
