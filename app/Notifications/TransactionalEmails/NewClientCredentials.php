<?php

namespace App\Notifications\TransactionalEmails;

use App\Models\User;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\CustomEmailNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sends login credentials to a newly created user (typically a scoped
 * client) or to an existing user whose password was just regenerated.
 *
 * This notification is routed through the team's EmailChannel, not the
 * instance-wide TransactionalEmailChannel, so it uses whatever SMTP
 * configuration is active on the team's Notifications → Email panel.
 * Because the target user is already attached to the team before the
 * notification is dispatched, EmailChannel's team-membership validation
 * passes without needing the isTestNotification bypass.
 */
class NewClientCredentials extends CustomEmailNotification
{
    public string $emails;

    public function __construct(
        public User $user,
        public string $plainPassword,
        public string $loginUrl,
        public string $instanceName,
        public bool $isPasswordReset = false,
    ) {
        // EmailChannel::send() reads $notification->emails (single string,
        // same contract as App\Notifications\Test) to override the recipient
        // list, so the notification is delivered to the target user instead
        // of the team's emailNotificationSettings recipients.
        $this->emails = $user->email;
        $this->onQueue('high');
    }

    public function via(object $notifiable): array
    {
        return [EmailChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Subject is hardcoded in Spanish because this notification only ever
        // goes to scoped clients managed by Hawkins; there is no
        // multi-tenancy requirement to branch on locale or instance name.
        $subject = $this->isPasswordReset
            ? 'Tu contraseña ha sido restablecida'
            : 'Bienvenido — credenciales de acceso';

        $mail = new MailMessage;
        $mail->subject($subject);
        $mail->view('emails.new-user-credentials', [
            'name' => $this->user->name,
            'email' => $this->user->email,
            'password' => $this->plainPassword,
            'loginUrl' => $this->loginUrl,
            'instanceName' => $this->instanceName,
            'isPasswordReset' => $this->isPasswordReset,
        ]);

        return $mail;
    }
}
