{{-- Minimal layout used by emails sent to scoped client users. Unlike the
     default <x-emails.layout> used elsewhere in Coolify, this template does
     NOT inject the "Hello," header, the "Thank you, Coolify" footer or the
     "Contact Support" link. That keeps the white-labelled client emails
     fully under our control. Other Coolify emails (invitations, email
     verification, etc.) still use the standard layout. --}}
{{ Illuminate\Mail\Markdown::parse($slot) }}
