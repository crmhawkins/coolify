<?php

namespace App\Livewire\User;

use App\Models\Project;
use App\Models\User;
use App\Notifications\TransactionalEmails\NewClientCredentials;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Edit extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public int $userId;

    public User $targetUser;

    public string $name = '';

    public string $email = '';

    public bool $isClient = true;

    /** @var array<int, int> */
    public array $assignedProjectIds = [];

    public $availableProjects = [];

    public ?string $regeneratedPassword = null;

    public bool $emailSent = false;

    public ?string $emailError = null;

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email,'.$this->userId],
            'isClient' => ['boolean'],
            'assignedProjectIds' => ['array'],
            'assignedProjectIds.*' => ['integer', 'exists:projects,id'],
        ];
    }

    public function mount(int $user): void
    {
        // Users do not have a UUID column in Coolify; we look up by primary id.
        // withoutGlobalScopes() is a no-op here (User has no global scope) but
        // makes the intent explicit.
        $this->targetUser = User::query()->withoutGlobalScopes()->findOrFail($user);
        $this->userId = $this->targetUser->id;

        $this->authorize('update', $this->targetUser);

        $this->name = $this->targetUser->name;
        $this->email = $this->targetUser->email;
        $this->isClient = (bool) $this->targetUser->is_client;
        $this->assignedProjectIds = $this->targetUser->assignedProjects()->pluck('projects.id')->all();
        $this->availableProjects = Project::ownedByCurrentTeamCached();
    }

    public function submit(): void
    {
        $this->authorize('update', $this->targetUser);
        $this->validate();

        try {
            $teamId = currentTeam()->id;
            $assignedProjects = Project::query()
                ->whereIn('id', $this->assignedProjectIds)
                ->where('team_id', $teamId)
                ->get();

            if ($assignedProjects->count() !== count($this->assignedProjectIds)) {
                $this->addError('assignedProjectIds', 'One or more selected projects do not belong to the current team.');

                return;
            }

            $this->targetUser->name = $this->name;
            $this->targetUser->email = $this->email;
            $this->targetUser->is_client = $this->isClient;
            $this->targetUser->save();

            $this->targetUser->assignedProjects()->sync($assignedProjects->pluck('id')->all());

            $this->dispatch('success', 'Usuario actualizado.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function regeneratePassword(): void
    {
        $this->authorize('update', $this->targetUser);

        try {
            $plainPassword = Str::password(length: 20, symbols: true);
            $this->targetUser->password = Hash::make($plainPassword);
            $this->targetUser->save();

            // Invalidate existing sessions for the affected user only (not the current admin session).
            DB::table('sessions')
                ->where('user_id', $this->targetUser->id)
                ->where('id', '!=', session()->getId())
                ->delete();

            $this->regeneratedPassword = $plainPassword;

            $this->sendCredentialsEmail($this->targetUser, $plainPassword);

            $this->dispatch('success', 'Contraseña regenerada.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->targetUser);

        try {
            DB::transaction(function () {
                // Detach from all teams (the User::deleting hook also handles this,
                // but we explicitly clean up the project_user pivot first).
                $this->targetUser->assignedProjects()->detach();

                // Hard delete — the user is removed but the projects survive
                // because the project_user pivot uses ON DELETE CASCADE the
                // other way around (project deletion would cascade, not user).
                $this->targetUser->delete();
            });

            $this->dispatch('success', 'Usuario eliminado.');
            $this->redirect(route('users.index'), navigate: true);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * Dispatches the password-reset email via the current team's configured
     * email channel (Notifications → Email). Same contract as the
     * credentials email in User\Create.
     */
    private function sendCredentialsEmail(User $user, string $plainPassword): void
    {
        $this->emailSent = false;
        $this->emailError = null;

        try {
            $team = currentTeam();

            if (! $team->emailNotificationSettings) {
                $this->emailError = 'El team no tiene configuración de email. Entra en Notifications → Email y guarda la configuración SMTP.';

                return;
            }

            if (! $team->emailNotificationSettings->smtp_enabled && ! $team->emailNotificationSettings->resend_enabled) {
                $this->emailError = 'El SMTP (o Resend) del team no está activado. Actívalo en Notifications → Email marcando "Enabled".';

                return;
            }

            // Clients land on the white-labelled /clientes login; internal
            // users go to the standard /login with certificate support.
            $loginPath = $user->is_client ? '/clientes' : '/login';

            $team->notify(new NewClientCredentials(
                user: $user,
                plainPassword: $plainPassword,
                loginUrl: rtrim(base_url(), '/').$loginPath,
                instanceName: config('app.name'),
                isPasswordReset: true,
            ));

            $this->emailSent = true;
        } catch (\Throwable $e) {
            $this->emailError = 'No se pudo enviar el email: '.$e->getMessage();
        }
    }

    public function render()
    {
        return view('livewire.user.edit');
    }
}
// resync-marker 2026-04-08
