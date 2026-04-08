<x-layout-simple>
    <section class="bg-gray-50 dark:bg-base">
        <div class="flex flex-col items-center justify-center px-6 py-8 mx-auto md:h-screen lg:py-0">
            <div class="w-full max-w-md space-y-8">
                <div class="text-center flex items-center justify-center">
                    <x-client-logo class="h-16 w-auto" />
                </div>

                <div class="space-y-6">
                    @if (session('status'))
                        <div class="mb-6 p-4 bg-success/10 border border-success rounded-lg">
                            <p class="text-sm text-success">{{ session('status') }}</p>
                        </div>
                    @endif

                    @if (session('error'))
                        <div class="mb-6 p-4 bg-error/10 border border-error rounded-lg">
                            <p class="text-sm text-error">{{ session('error') }}</p>
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="mb-6 p-4 bg-error/10 border border-error rounded-lg">
                            @foreach ($errors->all() as $error)
                                <p class="text-sm text-error">{{ $error }}</p>
                            @endforeach
                        </div>
                    @endif

                    {{-- Client login is email + password only. No HawCert certificate
                         upload, no access-key, no forgot-password, no registration
                         notice. The form posts to the standard /login endpoint so
                         Fortify's authenticateUsing() picks it up like any other
                         login — the only difference is the rendered form. --}}
                    <form action="/login" method="POST" class="flex flex-col gap-4">
                        @csrf
                        <x-forms.input type="email" name="email" autocomplete="email" required
                            label="{{ __('input.email') }}" />
                        <x-forms.input type="password" name="password" autocomplete="current-password" required
                            label="{{ __('input.password') }}" />

                        <x-forms.button class="w-full justify-center py-3" type="submit">
                            {{ __('auth.login') }}
                        </x-forms.button>
                    </form>
                </div>
            </div>
        </div>
    </section>
</x-layout-simple>
