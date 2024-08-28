<?php

use Livewire\Volt\Component;
use App\Models\InsRdcMachine;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Gate;

new class extends Component {

    public int $number;
    public string $name = '';
    public array  $cells = [['field' => '', 'address' => '']];

    public function rules()
    {
        return [
            'number'                => ['required', 'integer', 'min:1', 'max:99', 'unique:ins_rdc_machines'],
            'name'                  => ['required', 'string', 'min:1', 'max:20'],
            'cells'                 => ['required', 'array', 'min:1', 'max:9'],
            'cells.*.field'         => ['required', 'string', 'max:20'],
            'cells.*.address'       => ['required', 'string', 'regex:/^[A-Z]+[1-9]\d*$/'],
        ];
    }

    public function save()
    {
        $machine = new InsRdcMachine;
        Gate::authorize('manage', $machine);

        $this->name = strtoupper(trim($this->name));
        $validated = $this->validate();

        // Ensure 'appropriate casing
        $cells = array_map(function($cell) {
            return [
                'field'     => strtolower(trim($cell['field'])),
                'address'   => strtoupper(trim($cell['address'])),
            ];
        }, $validated['cells']);

        $machine->fill([
            'number' => $validated['number'],
            'name' => $validated['name'],
            'cells' => json_encode($cells),
        ]);

        $machine->save();

        $this->js('$dispatch("close")');
        $this->js('notyfSuccess("' . __('Mesin dibuat') . '")');
        $this->dispatch('updated');

        $this->customReset();
    }

    public function customReset()
    {
        $this->reset(['number', 'name', 'cells']);
        $this->cells = [['field' => '', 'address' => '']];
    }

    public function addCell()
    {
        if (count($this->cells) < 9) {
            $this->cells[] = ['field' => '', 'address' => ''];
        }
    }

    public function removeCell($index)
    {
        if (count($this->cells) > 1) {
            unset($this->cells[$index]);
            $this->cells = array_values($this->cells);
        }
    }

    public function moveCell($fromIndex, $toIndex)
    {
        if ($fromIndex !== $toIndex && $fromIndex >= 0 && $toIndex >= 0 && $fromIndex < count($this->cells) && $toIndex < count($this->cells)) {
            $cell = $this->cells[$fromIndex];
            array_splice($this->cells, $fromIndex, 1);
            array_splice($this->cells, $toIndex, 0, [$cell]);
        }
    }
};
?>

<div x-data="{ 
    draggingIndex: null,
    dragoverIndex: null,
    isDragging: false,
    startDrag(index) {
        this.draggingIndex = index;
        this.isDragging = true;
    },
    endDrag() {
        this.draggingIndex = null;
        this.dragoverIndex = null;
        this.isDragging = false;
    },
    onDragOver(index) {
        if (this.draggingIndex !== null && this.draggingIndex !== index) {
            this.dragoverIndex = index;
        }
    },
    onDrop(index) {
        if (this.draggingIndex !== null) {
            $wire.moveCell(this.draggingIndex, index);
            this.endDrag();
        }
    }
}">
    <form wire:submit="save" class="p-6">
        <div class="flex justify-between items-start">
            <h2 class="text-lg font-medium text-neutral-900 dark:text-neutral-100">
                {{ __('Mesin baru') }}
            </h2>
            <x-text-button type="button" x-on:click="$dispatch('close')"><i class="fa fa-times"></i></x-text-button>
        </div>
        <div class="mt-6">
            <label for="machine-number" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __('Nomor') }}</label>
            <x-text-input id="machine-number" wire:model="number" type="number" />
            @error('number')
                <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
            @enderror
        </div>  
        <div class="mt-6">
            <label for="machine-name" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __('Nama') }}</label>
            <x-text-input id="machine-name" wire:model="name" type="text" />
            @error('name')
                <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
            @enderror
        </div>  
        <div class="my-10">
            <label class="block mb-4 uppercase text-xs text-center text-neutral-500">{{ __('Sel-sel') }}</label>
            @foreach($cells as $index => $cell)
                <div class="mt-2" 
                     x-on:dragstart="startDrag({{ $index }})"
                     x-on:dragend="endDrag"
                     x-on:dragover.prevent="onDragOver({{ $index }})"
                     x-on:drop.prevent="onDrop({{ $index }})"
                     :class="{ 'opacity-50': draggingIndex === {{ $index }}, 'opacity-30': dragoverIndex === {{ $index }} }">
                    <div class="grid grid-cols-2 gap-y-2 gap-x-2">
                        <div class="flex gap-x-3 items-center">
                            <i class="fa fa-grip-lines cursor-move" draggable="true"></i>
                            <x-text-input type="text" wire:model="cells.{{ $index }}.field" placeholder="{{ __('Kolom')}}" />
                        </div>    
                        <div class="flex gap-x-3">
                            <x-text-input type="text" wire:model="cells.{{ $index }}.address" placeholder="{{ __('Alamat') }}" />
                            <x-text-button type="button" wire:click="removeCell({{ $index }})"><i class="fa fa-times"></i></x-text-button>
                        </div>
                    </div>
                    <div class="px-3">
                        @error("cells.{$index}.field")
                            <x-input-error messages="{{ $message }}" class="mt-2" />
                        @enderror
                        @error("cells.{$index}.address")
                            <x-input-error messages="{{ $message }}" class="mt-2" />
                        @enderror
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-6 flex justify-between">
            <x-secondary-button :disabled="count($cells) >= 9" type="button" wire:click="addCell">{{ __('Tambah sel')}}</x-secondary-button>
            <x-primary-button type="submit">
                {{ __('Simpan') }}
            </x-primary-button>
        </div>
    </form>
    <x-spinner-bg wire:loading.class.remove="hidden"></x-spinner-bg>
    <x-spinner wire:loading.class.remove="hidden" class="hidden"></x-spinner>
</div>