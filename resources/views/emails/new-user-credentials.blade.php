<x-emails.layout>
Hola {{ $name }},

@if (isset($isPasswordReset) && $isPasswordReset)
Tu contraseña en **{{ $instanceName }}** ha sido restablecida por un administrador.
@else
Se ha creado una cuenta para ti en **{{ $instanceName }}**.
@endif

Estas son tus credenciales de acceso:

- **URL:** {{ $loginUrl }}
- **Email:** {{ $email }}
- **Contraseña:** {{ $password }}

Por seguridad, te recomendamos cambiar la contraseña una vez hayas iniciado sesión.

Si no esperabas este correo, ignóralo o contacta con el administrador.
</x-emails.layout>
{{-- resync-marker 2026-04-08 --}}
