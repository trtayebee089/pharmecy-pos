@php($brand = app(\App\Services\Integrations::class)->brand())
Hello {{ $name }},

{{ $isNew ? "An account was created for you on the {$brand} POS." : "Your {$brand} POS password was reset by an administrator." }}

Username: {{ $username }}
Temporary password: {{ $password }}

Sign in and choose a new password; the temporary one only lets you change it.
If you did not expect this message, tell your manager.

{{ $brand }}
