<x-emails.client-layout>
Buenas {{ $name }},

@if (isset($isPasswordReset) && $isPasswordReset)
Tu contraseña en nuestro servidor ha sido restablecida por un administrador.
@else
Se le ha creado una cuenta en nuestro servidor para la gestión de su proyecto.
@endif

Estas son tus credenciales de acceso:

- **URL:** {{ $loginUrl }}
- **Email:** {{ $email }}
- **Contraseña:** {{ $password }}

Si pierdes la contraseña no dudes en contactar con nosotros y te la volveremos a generar.

Cualquier duda que tengas estaremos encantado de resolverla.

Un Cordial saludo,

Los Creativos de Hawkins.
</x-emails.client-layout>
