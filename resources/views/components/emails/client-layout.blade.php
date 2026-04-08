@props([
    'subject' => 'Credenciales de acceso',
])
{{-- Corporate HTML email used for scoped client credentials. Everything is
     table-based with inline styles so it renders consistently across Gmail,
     Outlook, Apple Mail and the IONOS webmail client. The logo is embedded
     as a base64 data URI so there is no dependency on a publicly reachable
     asset path. This layout is only used by NewClientCredentials — other
     Coolify emails still use the default x-emails.layout. --}}
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{{ $subject ?? "Credenciales de acceso" }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif;color:#1f2937;">
    <!-- Outer wrapper -->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3f4f6;padding:32px 16px;">
        <tr>
            <td align="center">
                <!-- Main card -->
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(15,23,42,0.08);">

                    <!-- Header with logo on dark background -->
                    <tr>
                        <td align="center" style="background-color:#0b1220;padding:32px 24px;">
                            <img src="data:image/png;base64,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" alt="Hawkins" width="200" style="display:block;height:auto;max-width:200px;width:200px;border:0;outline:none;text-decoration:none;">
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding:40px 40px 16px 40px;font-size:15px;line-height:1.6;color:#1f2937;">
                            {{ $slot }}
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding:24px 40px 32px 40px;border-top:1px solid #e5e7eb;font-size:13px;line-height:1.6;color:#6b7280;">
                            <strong style="color:#1f2937;">Los Creativos de Hawkins</strong><br>
                            Hawkins TechMarketing<br>
                            <a href="https://hawkins.es" style="color:#0b1220;text-decoration:none;">hawkins.es</a>
                        </td>
                    </tr>
                </table>

                <!-- Legal / unsubscribe line -->
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;margin-top:16px;">
                    <tr>
                        <td align="center" style="padding:0 16px;font-size:12px;line-height:1.5;color:#9ca3af;">
                            Este mensaje contiene credenciales de acceso. Si no esperabas recibirlo, ignóralo o avísanos.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>