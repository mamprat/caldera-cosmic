<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Livewire\Attributes\Url;
use Livewire\Attributes\On;
use App\Models\InsDwpCount;
use App\Models\InsDwpDevice;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Traits\HasDateRangeFilter;

new #[Layout("layouts.app")] class extends Component {
    use WithPagination;
    use HasDateRangeFilter;

    #[Url]
    public string $start_at = "";

    #[Url]
    public string $end_at = "";

    #[Url]
    public $device_id;

    #[Url]
    public string $line = "";

    #[Url]
    public string $mechine = "";


    public array $devices = [];
    public int $perPage = 20;
    public string $view = "raw";
    public array $position    = ['Left', 'Right'];
    public array $stdTh       = [30, 45];
    public array $stdSide     = [30, 45];
    public array $compareData = [
            'actual'       => "",
            'closest_standard' => "",
            'difference'   => "",
            'direction'    => "",
    ];

    public function mount()
    {
        if (! $this->start_at || ! $this->end_at) {
            $this->setThisWeek();
        }

        $this->devices = InsDwpDevice::orderBy("name")
            ->get()
            ->pluck("name", "id")
            ->toArray();

        // update menu
        $this->dispatch("update-menu", $this->view);
    }

    private function getCountsQuery()
    {
        $start = Carbon::parse($this->start_at);
        $end = Carbon::parse($this->end_at)->endOfDay();

        $query = InsDwpCount::select(
                "ins_dwp_counts.*",
                "ins_dwp_counts.created_at as count_created_at"
            )
            ->whereBetween("ins_dwp_counts.created_at", [$start, $end]);

        if ($this->device_id) {
            $device = InsDwpDevice::find($this->device_id);
            if ($device) {
                $deviceLines = $device->getLines();
                $query->whereIn("ins_dwp_counts.line", $deviceLines);
            }
        }

        if ($this->line) {
            $query->where("ins_dwp_counts.line", "like", "%" . strtoupper(trim($this->line)) . "%");
        }

        if ($this->mechine) {
            $query->where("ins_dwp_counts.mechine", "like", "%" . strtoupper(trim($this->mechine)) . "%");
        }

        return $query->orderBy("ins_dwp_counts.created_at", "DESC");
    }

    private function getDeviceForLine($line)
    {
        return InsDwpDevice::get()->first(function ($device) use ($line) {
            return $device->managesLine($line);
        });
    }

    // NEW: Helper function to calculate max
    private function getMax(array $array)
    {
        if (empty($array)) {
            return 0;
        }

        // Filter out non-numeric values
        $numericArray = array_filter($array, 'is_numeric');

        if (empty($numericArray)) {
            return 0;
        }

        // Get max value from the numeric array
        return max($numericArray);
    }

    #[On("updated")]
    public function with(): array
    {
        $counts = $this->getCountsQuery()->paginate($this->perPage);
        return [
            "counts" => $counts,
        ];
    }

    public function loadMore()
    {
        $this->perPage += 10;
    }

    public function download($type)
    {
        switch ($type) {
            case "counts":
                $this->js('toast("' . __("Unduhan dimulai...") . '", { type: "success" })');
                $filename = "dwp_counts_export_" . now()->format("Y-m-d_His") . ".csv";

                $headers = [
                    "Content-type" => "text/csv",
                    "Content-Disposition" => "attachment; filename=$filename",
                    "Pragma" => "no-cache",
                    "Cache-Control" => "must-revalidate, post-check=0, pre-check=0",
                    "Expires" => "0",
                ];

                $columns = [
                    __("Line"),
                    __("Device"),
                    __("Cumulative"),
                    __("Incremental"),
                    __("Created At"),
                ];

                $callback = function () use ($columns) {
                    $file = fopen("php://output", "w");
                    fputcsv($file, $columns);

                    $this->getCountsQuery()->chunk(1000, function ($counts) use ($file) {
                        foreach ($counts as $count) {
                            $device = $this->getDeviceForLine($count->line);
                            fputcsv($file, [
                                $count->line,
                                $device ? $device->name : "N/A",
                                $count->cumulative,
                                $count->incremental,
                                $count->created_at,
                            ]);
                        }
                    });

                    fclose($file);
                };

                return new StreamedResponse($callback, 200, $headers);
        }
    }

    public function compareWithStandards($actual, array $standards) {
        if (empty($standards)) {
            throw new InvalidArgumentException("Standards array cannot be empty.");
        }

        $closest = null;
        $minDistance = PHP_INT_MAX;

        foreach ($standards as $standard) {
            $distance = abs($actual - $standard);
            if ($distance < $minDistance) {
                $minDistance = $distance;
                $closest = $standard;
            }
        }

        $difference = $actual - $closest;
        $direction = $difference > 0 ? 'Up' : ($difference < 0 ? 'Down' : 'Equal');

        // Check if actual is between any two consecutive standards
        sort($standards);
        $isStandard = in_array($actual, $standards, true);

        if (!$isStandard && count($standards) > 1) {
            for ($i = 0; $i < count($standards) - 1; $i++) {
                if ($actual > $standards[$i] && $actual < $standards[$i + 1]) {
                    $isStandard = true;
                    break;
                }
            }
        }

        return [
            'actual'           => $actual,
            'closest_standard' => $closest,
            'difference'       => $difference,
            'direction'        => $direction,
            'is_standard'      => $isStandard,
        ];
    }
};

?>

<div>
    <div class="p-0 sm:p-1 mb-6">
        <div class="flex flex-col lg:flex-row gap-3 w-full bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-4">
            <div>
                <div class="flex mb-2 text-xs text-neutral-500">
                    <div class="flex">
                        <x-dropdown align="left" width="48">
                            <x-slot name="trigger">
                                <x-text-button class="uppercase ml-3">
                                    {{ __("Rentang") }}
                                    <i class="icon-chevron-down ms-1"></i>
                                </x-text-button>
                            </x-slot>
                            <x-slot name="content">
                                <x-dropdown-link href="#" wire:click.prevent="setToday">
                                    {{ __("Hari ini") }}
                                </x-dropdown-link>
                                <x-dropdown-link href="#" wire:click.prevent="setYesterday">
                                    {{ __("Kemarin") }}
                                </x-dropdown-link>
                                <hr class="border-neutral-300 dark:border-neutral-600" />
                                <x-dropdown-link href="#" wire:click.prevent="setThisWeek">
                                    {{ __("Minggu ini") }}
                                </x-dropdown-link>
                                <x-dropdown-link href="#" wire:click.prevent="setLastWeek">
                                    {{ __("Minggu lalu") }}
                                </x-dropdown-link>
                                <hr class="border-neutral-300 dark:border-neutral-600" />
                                <x-dropdown-link href="#" wire:click.prevent="setThisMonth">
                                    {{ __("Bulan ini") }}
                                </x-dropdown-link>
                                <x-dropdown-link href="#" wire:click.prevent="setLastMonth">
                                    {{ __("Bulan lalu") }}
                                </x-dropdown-link>
                            </x-slot>
                        </x-dropdown>
                    </div>
                </div>
                <div class="flex gap-3">
                    <x-text-input wire:model.live="start_at" type="date" class="w-40" />
                    <x-text-input wire:model.live="end_at" type="date" class="w-40" />
                </div>
            </div>
            <div class="border-l border-neutral-300 dark:border-neutral-700 mx-2"></div>
            <div class="grid grid-cols-2 lg:flex gap-3">
                <div>
                    <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Line") }}</label>
                    <x-select wire:model.live="line" class="w-full lg:w-32">
                            <option value=""></option>
                            <option value="a1">A1</option>
                            <option value="g5">G5</option>
                    </x-select>
                </div>
                <div>
                    <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Machine") }}</label>
                    <x-select wire:model.live="mechine" class="w-full lg:w-32">
                            <option value=""></option>
                            <option value="1">1</option>
                            <option value="2">2</option>
                            <option value="3">3</option>
                            <option value="4">4</option>
                    </x-select>
                </div>
            </div>
            <div class="border-l border-neutral-300 dark:border-neutral-700 mx-2"></div>
            <div class="grow flex justify-between gap-x-2 items-center">
                <div>
                    <div class="px-3">
                        <div wire:loading.class="hidden">{{ $counts->total() . " " . __("entri") }}</div>
                        <div wire:loading.class.remove="hidden" class="hidden">{{ __("Memuat...") }}</div>
                    </div>
                </div>
                <div class="flex gap-x-2">
                    <x-dropdown align="right" width="48">
                        <x-slot name="trigger">
                            <x-text-button><i class="icon-ellipsis-vertical"></i></x-text-button>
                        </x-slot>
                        <x-slot name="content">
                            <x-dropdown-link href="#" wire:click.prevent="download('counts')">
                                <i class="icon-download me-2"></i>
                                {{ __("CSV Data") }}
                            </x-dropdown-link>
                        </x-slot>
                    </x-dropdown>
                </div>
            </div>
        </div>
    </div>

    <div wire:key="modals">
        <x-modal name="detail-pressure" maxWidth="3xl">
            <livewire:insights.dwp.data.detail.pressure />
        </x-modal>
    </div>

    @if (! $counts->count())
        @if (! $start_at || ! $end_at)
            <div wire:key="no-range" class="py-20">
                <div class="text-center text-neutral-300 dark:text-neutral-700 text-5xl mb-3">
                    <i class="icon-calendar relative"><i class="icon-circle-help absolute bottom-0 -right-1 text-lg text-neutral-500 dark:text-neutral-400"></i></i>
                </div>
                <div class="text-center text-neutral-400 dark:text-neutral-600">{{ __("Pilih rentang tanggal") }}</div>
            </div>
        @else
            <div wire:key="no-match" class="py-20">
                <div class="text-center text-neutral-300 dark:text-neutral-700 text-5xl mb-3">
                    <i class="icon-ghost"></i>
                </div>
                <div class="text-center text-neutral-500 dark:text-neutral-600">{{ __("Tidak ada yang cocok") }}</div>
            </div>
        @endif
    @else
        <div key="raw-counts" class="overflow-x-auto overflow-y-hidden rounded-lg border border-neutral-200 dark:border-neutral-700">
            <div class="min-w-full bg-white dark:bg-neutral-800 shadow-sm">
                <table class="min-w-full text-sm text-neutral-600 dark:text-neutral-400">
                    <thead class="sticky top-0 z-10 bg-white dark:bg-neutral-800 border-b border-neutral-200 dark:border-neutral-700">
                        <tr class="uppercase text-xs text-left">
                            <th class="py-3 px-4 font-medium">Line</th>
                            <th class="py-3 px-4 font-medium">Machine</th>
                            <th class="py-3 px-4 font-medium text-right">Count</th>
                            <th class="py-3 px-4 font-medium">Duration</th>
                            <th class="py-3 px-4 font-medium">Position</th>
                            <th class="py-3 px-4 font-medium">Range STD</th>
                            <th class="py-3 px-4 font-medium">Toe/Heel</th>
                            <th class="py-3 px-4 font-medium">Side</th>
                            <th class="py-3 px-4 font-medium">Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($counts as $count)
                            @php
                                $pv = json_decode($count->pv, true)['waveforms'];
                                $toeHeelArray = $pv[0] ?? null;
                                $sideArray = $pv[1] ?? null;

                                $toeHeelValue = $toeHeelArray ? $this->getMax($toeHeelArray) : null;
                                $sideValue = $sideArray ? $this->getMax($sideArray) : null;

                                $toeHeelComparison = $toeHeelValue ? $this->compareWithStandards($toeHeelValue, $this->stdTh) : null;
                                $sideComparison = $sideValue ? $this->compareWithStandards($sideValue, $this->stdSide) : null;
                            @endphp

                            <tr
                                wire:key="count-tr-{{ $count->id }}"
                                tabindex="0"
                                x-on:click="
                                    $dispatch('open-modal', 'detail-pressure');
                                    $dispatch('pressure-detail-load', { id: '{{ $count->id }}' });
                                "
                                class="hover:bg-neutral-50 dark:hover:bg-neutral-700 transition-colors cursor-pointer border-b border-neutral-100 dark:border-neutral-700/50"
                            >
                                <td class="py-3 px-4">{{ $count->line }}</td>
                                <td class="py-3 px-4">{{ $count->mechine }}</td>
                                <td class="py-3 px-4 font-mono text-right">{{ number_format($count->count) }}</td>
                                <td class="py-3 px-4">
                                    {{ Carbon::parse($count->duration)->format('i:s') }}
                                </td>
                                <td class="py-3 px-4">
                                    {{ ($count->position ?? '') === 'R' ? 'Right' : 'Left' }}
                                </td>
                                <td class="py-3 px-4">
                                    <span>{{$this->stdTh[0]}} - {{$this->stdTh[1]}}</span>
                                </td>
                                <td class="py-3 px-4">
                                    @if($toeHeelValue !== null && $toeHeelComparison !== null)
                                        <span class="{{ $toeHeelComparison['is_standard'] === false ? 'text-red-500 font-bold' : 'text-green-500' }}">
                                            {{ $toeHeelValue }}
                                        </span>
                                        @if($toeHeelComparison['is_standard'] === false)
                                            <span class="ml-1 inline-flex items-center">
                                                <i class="icon {{
                                                    $toeHeelComparison['direction'] === 'Down'
                                                        ? 'icon-chevron-down text-red-500'
                                                        : 'icon-chevron-up text-green-500'
                                                }}"></i>
                                                <span class="ml-1 text-xs {{
                                                    $toeHeelComparison['direction'] === 'Down' ? 'text-red-500' : 'text-green-500'
                                                }}">
                                                    {{ $toeHeelComparison['difference'] }}
                                                </span>
                                            </span>
                                        @endif
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="py-3 px-4">
                                    @if($sideValue !== null && $sideComparison !== null)
                                        <span class="{{ $sideComparison['is_standard'] === false ? 'text-red-500 font-bold' : 'text-green-500' }}">
                                            {{ $sideValue }}
                                        </span>
                                        @if($sideComparison['is_standard'] === false)
                                            <span class="ml-1 inline-flex items-center">
                                                <i class="icon {{
                                                    $sideComparison['direction'] === 'Down'
                                                        ? 'icon-chevron-down text-red-500'
                                                        : 'icon-chevron-up text-green-500'
                                                }}"></i>
                                                <span class="ml-1 text-xs {{
                                                    $sideComparison['direction'] === 'Down' ? 'text-red-500' : 'text-green-500'
                                                }}">
                                                    {{ $sideComparison['difference'] }}
                                                </span>
                                            </span>
                                        @endif
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="py-3 px-4 font-mono text-xs">
                                    {{ $count->created_at->format('M j, Y g:i A') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <div class="flex items-center relative h-16">
            @if (! $counts->isEmpty())
                @if ($counts->hasMorePages())
                    <div
                        wire:key="more"
                        x-data="{
                        observe() {
                            const observer = new IntersectionObserver((counts) => {
                                counts.forEach(count => {
                                    if (count.isIntersecting) {
                                        @this.loadMore()
                                    }
                                })
                            })
                            observer.observe(this.$el)
                        }
                    }"
                        x-init="observe"
                    ></div>
                    <x-spinner class="sm" />
                @else
                    <div class="mx-auto">{{ __("Tidak ada lagi") }}</div>
                @endif
            @endif
        </div>
    @endif
</div>

@script
    <script>
        $wire.$dispatch('updated');
    </script>
@endscript
