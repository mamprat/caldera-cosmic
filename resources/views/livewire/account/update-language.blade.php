<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use App\Models\Pref;

new class extends Component
{
    public string $current_lang = '';
    public string $lang = '';

    public function mount()
    {
        $accountPref = Pref::where('user_id', Auth::user()->id)->where('name', 'account')->first();
        $data = $accountPref ? json_decode($accountPref->data, true) : [];
        $this->lang = isset($data['lang']) ? $data['lang'] : 'id';
    }

    public function updateLang(): void
    {
        try {
            // $validated = $this->validate([
            //     'current_password' => ['required', 'string', 'current_password'],
            //     'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            // ]);
            $validated = $this->validate([
                'lang' => ['required', Rule::in(['id', 'en'])]
        ]);
        } catch (ValidationException $e) {
            $this->reset('current_lang', 'lang');
            throw $e;
        }

        $pref = Pref::firstOrCreate(
            ['user_id' => Auth::user()->id, 'name' => 'account'],
            ['data' => json_encode([])]
        );
        $existingData = json_decode($pref->data, true);
        $existingData['lang'] = $validated['lang'];

        App::setLocale($validated['lang']);
        session()->put('lang', $validated['lang']);
        $pref->update(['data' => json_encode($existingData)]);

        $this->js('window.dispatchEvent(escKey)');
        $this->js('notyf.success("' . __('Bahasa diperbarui') . '")');
        // $this->dispatch('password-updated');
    }
}; ?>

<section>
    <header>
        <h2 class="text-lg font-medium text-neutral-900 dark:text-neutral-100">
            {{ __('Bahasa') }}
        </h2>
    </header>

    <form wire:submit="updateLang" class="mt-6">
        <div class="mb-6">
            <x-radio wire:model="lang" id="lang-id" name="lang" value="id">Bahasa Indonesia</x-radio>
            <x-radio wire:model="lang" id="lang-en" name="lang" value="en">English (US)</x-radio>
        </div>
        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Simpan') }}</x-primary-button>
{{-- 
            <x-action-message class="me-3" on="password-updated">
                {{ __('Tersimpan.') }}
            </x-action-message> --}}
        </div>
    </form>
</section>
