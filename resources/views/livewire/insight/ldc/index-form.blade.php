<?php

use Livewire\Volt\Component;
use Livewire\Attributes\On;
use Illuminate\Validation\Rule;

use App\Models\InsLdcGroup;
use App\Models\InsLdcHide;
use Carbon\Carbon;

new class extends Component {

    public $line;
    public $workdate;
    public $style;
    public $material;

    public $area_vn;
    public $area_ab;
    public $area_qt;

    public $grade;
    public $code;
    public $shift;

    // public float $diff;
    // public float $defect;

    public function rules()
    {
        return [
            'line'      => ['required', 'string', 'min:2', 'max:3', 'regex:/^[a-zA-Z]+[0-9]+$/'],
            'workdate'  => ['required', 'date'],
            'style'     => ['required', 'string', 'min:9', 'max:11'],
            'material'  => ['nullable', 'string', 'max:140'],
            'area_vn'   => ['required', 'numeric', 'gte:0', 'lt:90'],
            'area_ab'   => ['required', 'numeric', 'gte:0', 'lt:90'],
            'area_qt'   => ['required', 'numeric', 'gte:0', 'lt:90'],
            'grade'     => ['nullable', 'integer', 'min:1', 'max:3'],
            'code'      => ['required', 'string', 'min:7', 'max:10'],
            'shift'     => ['required', 'integer', 'min:1', 'max:3']
        ];
    }

    public function clean($string): string
    {
        return trim(strtoupper($string));
    }

    #[On('group-selected')]
    public function setGroup($data)
    {
        $this->line     = $data['line'];
        $this->workdate = $data['workdate'];
        $this->style    = $data['style'];
        $this->material = $data['material'];
    }


    public function save()
    {
        $this->line       = $this->clean($this->line);
        $this->style      = $this->clean($this->style);
        $this->material   = $this->clean($this->material);
        $this->code       = $this->clean($this->code);

        if (!$this->line || !$this->workdate || !$this->style) {
            $this->js('notyfError("' . __('Info grup tidak sah') . '")');
        }

        $validated = $this->validate();

        $group = InsLdcGroup::firstOrCreate([
            'line'      => $this->line,
            'workdate'  => $this->workdate,
            'style'     => $this->style,
            'material'  => $this->material,
        ]);

        $group->update([
            'updated_at' => Carbon::now()
        ]);

        $hide = InsLdcHide::updateOrCreate(
            [ 
                'code' => $this->code 
            ], 
            [
                'ins_ldc_group_id' => $group->id,
                'area_vn'       => $this->area_vn,
                'area_ab'       => $this->area_ab,
                'area_qt'       => $this->area_qt,
                'grade'         => $this->grade,
                'shift'         => $this->shift,
                'user_id'       => Auth::user()->id
            ]);

        $this->js('$dispatch("close")');
        $this->js('notyfSuccess("' . __('Kulit disimpan') . '")');
        $this->dispatch('hide-created');

        $this->customReset();
    }

    public function customReset()
    {
        $this->reset(['area_vn', 'area_ab', 'area_qt', 'grade', 'code']);
    }

};

?>

<div class="bg-white dark:bg-neutral-800 shadow rounded-lg p-6 flex gap-x-6">
    <div class="w-60 grid grid-cols-1 grid-rows-2 gap-6 text-center border border-neutral-200 dark:border-neutral-700 rounded-lg p-6">
        <div>
            <div class="text-sm uppercase">{{ __('Selisih') }}</div>
            <div class="text-2xl font-bold">{{ __('Di atas 6%') }}</div>
        </div>
        <div>
            <div class="text-sm uppercase">Defect</div>
            <div class="text-2xl font-bold text-red-500">{{ __('Abnormal') }}</div>
        </div>
    </div>
    <form wire:submit="save">
        <div class="grid grid-cols-3 gap-3">
            <div>
                <div>
                    <label for="hide-area_vn"
                        class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __('VN') }}</label>
                    <x-text-input-suffix suffix="SF" id="hide-area_vn" wire:model="area_vn" type="number" step=".1" />
                    @error('area_vn')
                        <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
                    @enderror
                </div>
                <div class="mt-3">
                    <label for="hide-grade"
                        class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __('Grade') }}</label>
                    <x-text-input id="hide-grade" wire:model="grade" type="number" step="1" />
                    @error('grade')
                        <x-input-error messages="{{ $message }}" class="px-3" />
                    @enderror
                </div>
            </div>
            <div class="col-span-2">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="hide-area_ab"
                            class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __('AB') }}</label>
                        <x-text-input-suffix suffix="SF" id="hide-area_ab" wire:model="area_ab" type="number" step=".1" />
                        @error('area_ab')
                            <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
                        @enderror
                    </div>
                    <div>
                        <label for="hide-area_qt"
                            class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __('QT') }}</label>
                        <x-text-input-suffix suffix="SF" id="hide-area_qt" wire:model="area_qt" type="number" step=".1" />
                        @error('area_qt')
                            <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
                        @enderror
                    </div>
                    <div>
                        <label for="hide-code"
                            class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __('Barcode') }}</label>
                        <x-text-input id="hide-code" wire:model="code" type="text" step="1" />
                        <div class="flex w-full justify-between items-center text-neutral-500 px-3 mt-2">
                            <x-text-button type="button">XA</x-text-button>
                            <x-text-button type="button">XB</x-text-button>
                            <x-text-button type="button">XC</x-text-button>
                            <x-text-button type="button">XD</x-text-button>
                        </div>
                        @error('code')
                            <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
                        @enderror
                    </div>
                    <div>
                        <label for="hide-shift"
                        class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __('Shift') }}</label>
                        <x-select id="hide-shift" wire:model="shift">
                            <option value=""></option>
                            <option value="1">1</option>
                            <option value="2">2</option>
                            <option value="3">3</option>
                        </x-select>
                        @error('shift')
                            <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
                        @enderror
                    </div>
                </div>
            </div>
        </div>
        <div class="flex w-full mt-6">
            <x-primary-button type="submit" class="w-full justify-center">{{ __('Simpan') }}</x-primary-button>
        </div>
    </form>
</div>