<x-emails.client-layout :subject="$isPasswordReset ? 'Tu contraseña ha sido restablecida' : 'Bienvenido — credenciales de acceso'">
    <h1 style="margin:0 0 16px 0;font-size:22px;line-height:1.3;color:#0b1220;font-weight:700;">
        @if (isset($isPasswordReset) && $isPasswordReset)
            Tu contraseña ha sido restablecida
        @else
            Bienvenido{{ $name ? ', '.$name : '' }}
        @endif
    </h1>

    <p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;color:#374151;">
        @if (isset($isPasswordReset) && $isPasswordReset)
            Un administrador ha restablecido tu contraseña en nuestro servidor.
            Usa las credenciales de abajo para acceder a tu panel.
        @else
            Se le ha creado una cuenta en nuestro servidor para la gestión de su proyecto.
            Estas son tus credenciales de acceso:
        @endif
    </p>

    <!-- Credentials block -->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:16px 0 24px 0;background-color:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;">
        <tr>
            <td style="padding:20px 24px;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif;font-size:14px;line-height:1.7;color:#1f2937;">
                <div style="margin-bottom:10px;">
                    <span style="display:inline-block;min-width:90px;color:#6b7280;font-weight:600;">URL</span>
                    <a href="{{ $loginUrl }}" target="_blank" rel="noopener" style="color:#0b1220;text-decoration:underline;font-family:SFMono-Regular,Consolas,Liberation Mono,Menlo,monospace;font-size:13px;">{{ $loginUrl }}</a>
                </div>
                <div style="margin-bottom:10px;">
                    <span style="display:inline-block;min-width:90px;color:#6b7280;font-weight:600;">Email</span>
                    <span style="font-family:SFMono-Regular,Consolas,Liberation Mono,Menlo,monospace;font-size:13px;color:#1f2937;">{{ $email }}</span>
                </div>
                <div>
                    <span style="display:inline-block;min-width:90px;color:#6b7280;font-weight:600;">Contraseña</span>
                    <span style="font-family:SFMono-Regular,Consolas,Liberation Mono,Menlo,monospace;font-size:13px;color:#1f2937;background-color:#ffffff;border:1px solid #e5e7eb;border-radius:4px;padding:2px 8px;">{{ $password }}</span>
                </div>
            </td>
        </tr>
    </table>

    <!-- CTA button -->
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px 0;">
        <tr>
            <td align="center" style="background-color:#0b1220;border-radius:6px;">
                <a href="{{ $loginUrl }}" target="_blank" rel="noopener" style="display:inline-block;padding:12px 28px;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;">
                    Acceder al panel
                </a>
            </td>
        </tr>
    </table>

    <p style="margin:0 0 12px 0;font-size:14px;line-height:1.6;color:#6b7280;">
        Si pierdes la contraseña no dudes en contactar con nosotros y te la volveremos a generar.
        Cualquier duda que tengas estaremos encantados de resolverla.
    </p>

    <p style="margin:16px 0 0 0;font-size:14px;line-height:1.6;color:#374151;">
        Un cordial saludo,<br>
        <strong>Los Creativos de Hawkins</strong>
    </p>
</x-emails.client-layout>
