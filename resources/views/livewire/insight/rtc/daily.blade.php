<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;

use Carbon\Carbon;
use Livewire\Attributes\Reactive;
use Illuminate\Support\Facades\DB;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    #[Reactive]
    public $fline;
    public $perPage = 20;

    public function with(): array
    {
        $rows = DB::table('ins_rtc_metrics')
            ->join('ins_rtc_clumps', 'ins_rtc_clumps.id', '=', 'ins_rtc_metrics.ins_rtc_clump_id')
            ->join('ins_rtc_devices', 'ins_rtc_devices.id', '=', 'ins_rtc_clumps.ins_rtc_device_id')
            ->select('ins_rtc_devices.line')
            ->selectRaw('COUNT(DISTINCT ins_rtc_clumps.id) as clump_qty')
            ->selectRaw('MAX(ins_rtc_metrics.dt_client) as dt_client')
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, MIN(ins_rtc_metrics.dt_client), MAX(ins_rtc_metrics.dt_client))) as average_duration')
            ->where('ins_rtc_metrics.dt_client', '>=', Carbon::now()->subDays(90));

        if ($this->fline) {
            $rows->where('ins_rtc_devices.line', $this->fline);
        }

        $rows->groupBy('ins_rtc_devices.line');
        $rows = $rows->paginate($this->perPage);

        return [
            'rows' => $rows,
        ];

    }

    public function loadMore()
    {
        $this->perPage += 10;
    }
};

?>

<div wire:poll class="w-full">
    <h1 class="text-2xl mb-6 text-neutral-900 dark:text-neutral-100 px-5">
        {{ __('Ringkasan Harian') }}</h1>

    @if (!$rows->count())

        <div wire:key="no-match" class="py-20">
            <div class="text-center text-neutral-300 dark:text-neutral-700 text-5xl mb-3">
                <i class="fa fa-ghost"></i>
            </div>
            <div class="text-center text-neutral-500 dark:text-neutral-600">{{ __('Tidak ada yang cocok') }}
            </div>
        </div>
    @else
        <div wire:key="line-all-rows" class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg overflow-auto">
            <table class="table table-sm table-truncate text-neutral-600 dark:text-neutral-400">
                <tr class="uppercase text-xs">
                    <th>{{ __('Line') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th>{{ __('Qty gilingan') }}</th>
                    <th>{{ __('Rerata waktu gilingan') }}</th>
                    <th>{{ __('Data terakhir') }}</th>
                </tr>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row->line }}</td>
                        <td>
                            @if ((Carbon::now()->diffInMinutes($row->dt_client) > 30) && (Carbon::now()->diffInMinutes($row->dt_client) < 0 ))
                                <div class="flex text-xs gap-x-2 items-center text-red-500">
                                    
                                    <i class="fa fa-2xs fa-circle"></i>
                                    <span>{{ __('OFFLINE') }}</span>
                                </div>
                            @else
                                <div class="flex text-xs gap-x-2 items-center text-green-500">
                                    <i class="fa fa-2xs fa-circle"></i>
                                    <span>{{ __('ONLINE') }}</span>
                                </div>
                            @endif
                        </td>
                        <td>{{ $row->clump_qty }}</td>
                        <td>{{ $row->average_duration }}</td>
                        <td>{{ $row->dt_client }}</td>

                    </tr>
                @endforeach
            </table>
        </div>
        <div class="flex items-center relative h-16">
            @if (!$rows->isEmpty())
                @if ($rows->hasMorePages())
                    <div wire:key="more" x-data="{
                        observe() {
                            const observer = new IntersectionObserver((rows) => {
                                rows.forEach(row => {
                                    if (row.isIntersecting) {
                                        @this.loadMore()
                                    }
                                })
                            })
                            observer.observe(this.$el)
                        }
                    }" x-init="observe"></div>
                    <x-spinner class="sm" />
                @else
                    <div class="mx-auto">{{ __('Tidak ada lagi') }}</div>
                @endif
            @endif
        </div>
    @endif
</div>