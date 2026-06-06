<?php

namespace App\Mail;

use App\Models\ContactRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ContactRequestReceived extends Mailable
{
    use Queueable, SerializesModels;

    public $contactRequest;

    public function __construct(ContactRequest $contactRequest)
    {
        $this->contactRequest = $contactRequest;
    }

    public function build()
    {
        return $this->subject('Նոր կոնտակտային հարցում')
            ->view('emails.contact-request')
            ->with([
                'name' => $this->contactRequest->name,
                'email' => $this->contactRequest->email,
                'phone' => $this->contactRequest->phone,
                'message' => $this->contactRequest->message,
            ]);
    }
}
