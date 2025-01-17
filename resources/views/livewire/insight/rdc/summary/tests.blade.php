<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;

use Carbon\Carbon;
use Livewire\Attributes\Reactive;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use App\Models\InsRdcTest;
use Symfony\Component\HttpFoundation\StreamedResponse;


new #[Layout('layouts.app')] 
class extends Component {
    use WithPagination;

    #[Reactive]
    public $start_at;

    #[Reactive]
    public $end_at;

    #[Reactive]
    public $fquery;

    #[Reactive]
    public $ftype;

    public $perPage = 20;

    // public $sort = 'updated';

    private function getTestsQuery()
    {
        $start = Carbon::parse($this->start_at);
        $end = Carbon::parse($this->end_at)->endOfDay();

        $query = InsRdcTest::join('ins_rubber_batches', 'ins_rdc_tests.ins_rubber_batch_id', '=', 'ins_rubber_batches.id')
            ->join('ins_rdc_machines', 'ins_rdc_tests.ins_rdc_machine_id', '=', 'ins_rdc_machines.id')
            ->join('users', 'ins_rdc_tests.user_id', '=', 'users.id')
            ->select(
                'ins_rdc_tests.*',
                'ins_rdc_tests.queued_at as test_queued_at',
                'ins_rdc_tests.updated_at as test_updated_at',
                'ins_rubber_batches.code as batch_code',
                'ins_rubber_batches.code_alt as batch_code_alt',
                'ins_rubber_batches.model as batch_model',
                'ins_rubber_batches.color as batch_color',
                'ins_rubber_batches.mcs as batch_mcs',
                'ins_rdc_machines.number as machine_number',
                'users.emp_id as user_emp_id',
                'users.name as user_name'
            )
            ->whereBetween('ins_rdc_tests.updated_at', [$start, $end]);

        switch ($this->ftype) {
            case 'code':
                $query->where('ins_rubber_batches.code', 'LIKE', '%' . $this->fquery . '%');
                break;
            case 'model':
                $query->where('ins_rubber_batches.model', 'LIKE', '%' . $this->fquery . '%');
                break;
            case 'color':
                $query->where('ins_rubber_batches.color', 'LIKE', '%' . $this->fquery . '%');
                break;
            case 'mcs':
                $query->where('ins_rubber_batches.mcs', 'LIKE', '%' . $this->fquery . '%');
                break;
            case 'eval':
                $query->where('ins_rdc_tests.eval', 'LIKE', '%' . $this->fquery . '%');
                break;
            case 'emp_id':
                $query->where('users.emp_id', 'LIKE', '%' . $this->fquery . '%');
                break;
            default:
                $query->where(function (Builder $query) {
                    $query->orWhere('ins_rubber_batches.code', 'LIKE', '%' . $this->fquery . '%')
                        ->orWhere('ins_rubber_batches.model', 'LIKE', '%' . $this->fquery . '%')
                        ->orWhere('ins_rubber_batches.color', 'LIKE', '%' . $this->fquery . '%')
                        ->orWhere('ins_rubber_batches.mcs', 'LIKE', '%' . $this->fquery . '%')
                        ->orWhere('users.emp_id', 'LIKE', '%' . $this->fquery . '%');
                });
                break;
        }

        return $query->orderBy('ins_rdc_tests.updated_at', 'DESC');
    }

    public function with(): array
    {
        $tests = $this->getTestsQuery()->paginate($this->perPage);

        return [
            'tests' => $tests,
        ];
    }

    public function loadMore()
    {
        $this->perPage += 10;
    }

    public function download()
    {
        $filename = 'rdc_tests_export_' . now()->format('Y-m-d_His') . '.csv';

        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=$filename",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $columns = [
            __('Diperbarui'), __('Kode'), __('Kode alternatif'), __('Model'), __('Warna'), __('MCS'), __('Hasil'), __('Mesin'), __('Tag'), __('Nama'), __('Waktu antri')
        ];

        $callback = function () use ($columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);

            $this->getTestsQuery()->chunk(1000, function ($tests) use ($file) {
                foreach ($tests as $test) {
                    fputcsv($file, [
                        $test->test_updated_at,
                        $test->batch_code,
                        $test->batch_code_alt,
                        $test->batch_model ?? '-',
                        $test->batch_color ?? '-',
                        $test->batch_mcs ?? '-',
                        $test->evalHuman(),
                        $test->machine_number,
                        $test->tag,
                        $test->user_name,
                        $test->test_queued_at,
                    ]);
                }
            });

            fclose($file);
        };

        return new StreamedResponse($callback, 200, $headers);
    }
};

?>

<div wire:poll.30s class="overflow-auto w-full">
    <div>
        <div class="flex justify-between items-center mb-6 px-5 py-1">
            <h1 class="text-2xl text-neutral-900 dark:text-neutral-100">
                {{ __('Hasil Uji') }}</h1>
            <div class="flex gap-x-2 items-center">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <x-text-button><i class="fa fa-fw fa-ellipsis-v"></i></x-text-button>
                    </x-slot>
                    <x-slot name="content">
                        {{-- <x-dropdown-link href="#" x-on:click.prevent="$dispatch('open-modal', 'raw-stats-info')">
                            {{ __('Statistik ')}}
                        </x-dropdown-link> --}}
                        {{-- <hr
                            class="border-neutral-300 dark:border-neutral-600" /> --}}
                        <x-dropdown-link href="#" wire:click.prevent="download">
                            <i class="fa fa-download me-2"></i>{{ __('Unduh') }}
                        </x-dropdown-link>
                    </x-slot>
                </x-dropdown>
            </div>
        </div>
        <div wire:key="modals"> 
            <x-modal name="raw-stats-info">
                <div class="p-6">
                    <h2 class="text-lg font-medium text-neutral-900 dark:text-neutral-100">
                        {{ __('Statistik hasil uji') }}
                    </h2>
                    <p class="mt-3 text-sm text-neutral-600 dark:text-neutral-400">
                        {{ __('Belum ada informasi statistik yang tersedia.') }}
                    </p>
                    <div class="mt-6 flex justify-end">
                        <x-primary-button type="button" x-on:click="$dispatch('close')">
                            {{ __('Paham') }}
                        </x-primary-button>
                    </div>
                </div>
            </x-modal>  
            <x-modal name="test-show">
                <livewire:insight.rdc.summary.test-show />
            </x-modal>
        </div>
        @if (!$tests->count())
            @if (!$start_at || !$end_at)
                <div wire:key="no-range" class="py-20">
                    <div class="text-center text-neutral-300 dark:text-neutral-700 text-5xl mb-3">
                        <i class="fa fa-calendar relative"><i
                                class="fa fa-question-circle absolute bottom-0 -right-1 text-lg text-neutral-500 dark:text-neutral-400"></i></i>
                    </div>
                    <div class="text-center text-neutral-400 dark:text-neutral-600">{{ __('Pilih rentang tanggal') }}
                    </div>
                </div>
            @else
                <div wire:key="no-match" class="py-20">
                    <div class="text-center text-neutral-300 dark:text-neutral-700 text-5xl mb-3">
                        <i class="fa fa-ghost"></i>
                    </div>
                    <div class="text-center text-neutral-500 dark:text-neutral-600">{{ __('Tidak ada yang cocok') }}
                    </div>
                </div>
            @endif
        @else
            <div wire:key="raw-tests" class="p-0 sm:p-1 overflow-auto">
                <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg w-full table">
                    <table class="table table-sm table-truncate text-sm text-neutral-600 dark:text-neutral-400">
                        <tr class="uppercase text-xs">
                            <th>{{ __('Diperbarui') }}</th>
                            <th>{{ __('Kode') }}</th>
                            <th>{{ __('Alt') }}</th>
                            <th>{{ __('Model') }}</th>
                            <th>{{ __('Warna') }}</th>
                            <th>{{ __('MCS') }}</th>
                            <th>{{ __('Hasil') }}</th>
                            <th>{{ __('M') }}</th>
                            <th>{{ __('Tag') }}</th>
                            <th>{{ __('Nama') }}</th>
                            <th>{{ __('Waktu antri') }}</th>
                        </tr>
                        @foreach ($tests as $test)
                        <tr wire:key="test-tr-{{ $test->id . $loop->index }}" tabindex="0"
                            x-on:click="$dispatch('open-modal', 'test-show'); $dispatch('test-show', { id: '{{ $test->id }}'})">
                            <td>{{ $test->test_updated_at }}</td>
                            <td>{{ $test->batch_code }}</td>
                            <td>{{ $test->batch_code_alt }}</td>
                            <td>{{ $test->batch_model ? $test->batch_model : '-' }}</td>
                            <td>{{ $test->batch_color ? $test->batch_color : '-'  }}</td>
                            <td>{{ $test->batch_mcs ? $test->batch_mcs : '-' }}</td>
                            <td><x-pill class="uppercase" color="{{ 
                                $test->eval === 'queue' ? 'yellow' : 
                                ($test->eval === 'pass' ? 'green' : 
                                ($test->eval === 'fail' ? 'red' : ''))
                                }}">{{ $test->evalHuman() }}</x-pill></td>
                            <td>{{ $test->machine_number }}</td>
                            <td>{{ $test->tag }}</td>
                            <td>{{ $test->user_name }}</td>
                            <td>{{ $test->test_queued_at }}</td>
                        </tr>
                    @endforeach
                    </table>
                </div>
            </div>
            <div class="flex items-center relative h-16">
                @if (!$tests->isEmpty())
                    @if ($tests->hasMorePages())
                        <div wire:key="more" x-data="{
                            observe() {
                                const observer = new IntersectionObserver((tests) => {
                                    tests.forEach(test => {
                                        if (test.isIntersecting) {
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
</div>
