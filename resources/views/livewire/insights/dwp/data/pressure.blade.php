<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Traits\HasDateRangeFilter;
use App\Models\InsDwpDevice;
use Livewire\WithPagination;
use Livewire\Attributes\Url;
use Livewire\Attributes\On;
use App\Models\InsDwpCount;
use Carbon\Carbon;

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
    public string $line = "G5";

    #[Url]
    public string $machine = "";

    public array $devices = [];
    public int $perPage = 20;
    public string $view = "pressure";
    
    // Deviation tracking properties
    public array $deviationSummary = [];
    public array $severityBreakdown = [];
    public int $progress = 0;
    
    // Standard ranges
    private array $stdRange = [30, 45];

    public function mount()
    {
        if (!$this->start_at || !$this->end_at) {
            $this->setThisWeek();
        }

        $this->devices = InsDwpDevice::orderBy("name")
            ->get()
            ->pluck("name", "id")
            ->toArray();

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

        if (!empty($this->machine)) {
            $query->where('ins_dwp_counts.mechine', $this->machine);
        }

        return $query->orderBy("ins_dwp_counts.created_at", "DESC");
    }

    private function getDataMachines($selectedLine = null)
    {
        if (!$selectedLine) {
            return [];
        }

        $device = InsDwpDevice::whereJsonContains('config', [['line' => strtoupper($selectedLine)]])
            ->select('config')
            ->first();

        if ($device) {
            foreach ($device->config as $lineConfig) {
                if (strtoupper($lineConfig['line']) === strtoupper($selectedLine)) {
                    return $lineConfig['list_mechine'] ?? [];
                }
            }
        }
        return [];
    }

    private function getMedian(array $array)
    {
        if (empty($array)) return 0;
        $numericArray = array_filter($array, 'is_numeric');
        if (empty($numericArray)) return 0;
        
        sort($numericArray);
        $count = count($numericArray);
        $middle = floor(($count - 1) / 2);
        $median = ($count % 2) ? $numericArray[$middle] : ($numericArray[$middle] + $numericArray[$middle + 1]) / 2;
        
        return round($median);
    }

    private function getBoxplotSummary(array $data): ?array
    {
        if (empty($data)) {
            return null;
        }

        sort($data);
        $count = count($data);
        $min = $data[0];
        $max = $data[$count - 1];

        $mid_index = (int)floor($count / 2);
        $median = ($count % 2 === 0)
            ? ($data[$mid_index - 1] + $data[$mid_index]) / 2
            : $data[$mid_index];

        $lower_half = array_slice($data, 0, $mid_index);
        $q1 = 0;
        if (!empty($lower_half)) {
            $q1_count = count($lower_half);
            $q1_mid_index = (int)floor($q1_count / 2);
            $q1 = ($q1_count % 2 === 0)
                ? ($lower_half[$q1_mid_index - 1] + $lower_half[$q1_mid_index]) / 2
                : $lower_half[$q1_mid_index];
        } else {
            $q1 = $min;
        }
        
        $upper_half = array_slice($data, ($count % 2 === 0) ? $mid_index : $mid_index + 1);
        $q3 = 0;
        if (!empty($upper_half)) {
            $q3_count = count($upper_half);
            $q3_mid_index = (int)floor($q3_count / 2);
            $q3 = ($q3_count % 2 === 0)
                ? ($upper_half[$q3_mid_index - 1] + $upper_half[$q3_mid_index]) / 2
                : $upper_half[$q3_mid_index];
        } else {
             $q3 = $max;
        }
        
        return array_map(fn($v) => round($v, 2), [$min, $q1, $median, $q3, $max]);
    }

    /**
     * Calculate OUTPUT QUALITY statistics
     * 
     * Logic: 
     * - Each database record is ONE independent measurement/output
     * - Standard: ALL sensor readings in PV within [30, 45]
     * - Out of Standard: ANY sensor reading outside [30, 45]
     */
    private function calculateDeviations()
    {
        $dataRaw = InsDwpCount::select("ins_dwp_counts.*")
            ->whereBetween("ins_dwp_counts.created_at", [
                Carbon::parse($this->start_at),
                Carbon::parse($this->end_at)->endOfDay(),
            ]);

        if ($this->line) {
            $dataRaw->where("ins_dwp_counts.line", "like", "%" . strtoupper(trim($this->line)) . "%");
        }

        if (!empty($this->machine)) {
            $dataRaw->where('ins_dwp_counts.mechine', $this->machine);
        }

        $pressureData = $dataRaw->whereNotNull('pv')->get();
        
        $totalOutputStandard = 0;
        $totalOutputOutOfStandard = 0;
        $severityCount = ["minor" => 0, "major" => 0, "critical" => 0];

        $minStd = $this->stdRange[0]; // 30
        $maxStd = $this->stdRange[1]; // 45

        // Process each record independently
        foreach ($pressureData as $record) {
            $pv = json_decode($record->pv, true);
            
            if (!is_array($pv) || count($pv) < 2) {
                continue;
            }
            
            $toeHeelArray = $pv[0] ?? [];
            $sideArray = $pv[1] ?? [];
            
            // Collect all sensor readings (filter valid numeric values > 0)
            $allSensorReadings = [];
            
            $validToeHeel = array_filter($toeHeelArray, fn($v) => is_numeric($v) && $v > 0);
            $validSide = array_filter($sideArray, fn($v) => is_numeric($v) && $v > 0);
            
            $allSensorReadings = array_merge($validToeHeel, $validSide);
            
            // Skip if no valid readings
            if (empty($allSensorReadings)) {
                continue;
            }
            
            // Check if ANY reading is out of standard range
            $hasOutOfStandard = false;
            $worstDeviation = 0;
            
            foreach ($allSensorReadings as $value) {
                if ($value < $minStd || $value > $maxStd) {
                    $hasOutOfStandard = true;
                    
                    // Calculate deviation to classify severity
                    $deviation = 0;
                    if ($value < $minStd) {
                        $deviation = $minStd - $value;
                    } elseif ($value > $maxStd) {
                        $deviation = $value - $maxStd;
                    }
                    
                    if ($deviation > $worstDeviation) {
                        $worstDeviation = $deviation;
                    }
                }
            }
            
            // Classify this output
            if ($hasOutOfStandard) {
                $totalOutputOutOfStandard++;
                
                // Classify by worst deviation found
                if ($worstDeviation > 10) {
                    $severityCount["critical"]++;
                } elseif ($worstDeviation > 5) {
                    $severityCount["major"]++;
                } else {
                    $severityCount["minor"]++;
                }
            } else {
                $totalOutputStandard++;
            }
        }

        $totalOutput = $totalOutputStandard + $totalOutputOutOfStandard;
        $outOfStandardRate = $totalOutput > 0 
            ? round(($totalOutputOutOfStandard / $totalOutput) * 100, 2) 
            : 0;

        $this->deviationSummary = [
            "total_measurements" => $totalOutput,  // Total Output
            "total_sections" => $totalOutputStandard,  // Output Standard
            "total_deviations" => $totalOutputOutOfStandard,  // Output Out of Standard
            "deviation_sections" => $totalOutputOutOfStandard, // Same as above for compatibility
            "major_plus_deviations" => $severityCount["major"] + $severityCount["critical"],
            "major_plus_sections" => $severityCount["major"] + $severityCount["critical"],
            "critical_deviations" => $severityCount["critical"],
            "critical_sections" => $severityCount["critical"],
            "critical_rate" => $outOfStandardRate,  // % Out of Standard
        ];

        $this->severityBreakdown = $severityCount;
    }

    #[On("updated")]
    public function with(): array
    {
        $counts = $this->getCountsQuery()->paginate($this->perPage);
        $this->generateCharts();
        $this->calculateDeviations();
        $this->renderCharts();
        return [
            "counts" => $counts,
        ];
    }

    #[On("updated")]
    public function update()
    {
        $this->generateCharts();
        $this->calculateDeviations();
        $this->renderCharts();
    }

    private function generateCharts()
    {
        $dataRaw = InsDwpCount::select(
            "ins_dwp_counts.*",
            "ins_dwp_counts.created_at as count_created_at"
        )
            ->whereBetween("ins_dwp_counts.created_at", [
                Carbon::parse($this->start_at),
                Carbon::parse($this->end_at)->endOfDay(),
            ]);

        if ($this->line) {
            $dataRaw->where("ins_dwp_counts.line", "like", "%" . strtoupper(trim($this->line)) . "%");
        }

        if (!empty($this->machine)) {
            $dataRaw->where('ins_dwp_counts.mechine', $this->machine);
        }

        $pressureData = $dataRaw->whereNotNull('pv')->get()->toArray();
        $counts = collect($pressureData);
        
        $toeheel_left_data = [];
        $toeheel_right_data = [];
        $side_left_data = [];
        $side_right_data = [];

        foreach ($counts as $count) {
            $arrayPv = json_decode($count['pv'], true);
            if (isset($arrayPv[0]) && isset($arrayPv[1])) {
                $toeHeelArray = $arrayPv[0];
                $sideArray = $arrayPv[1];
                
                $toeHeelMedian = $this->getMedian($toeHeelArray);
                $sideMedian = $this->getMedian($sideArray);
                
                if ($count['position'] === 'L') {
                    $toeheel_left_data[] = $toeHeelMedian;
                    $side_left_data[] = $sideMedian;
                } elseif ($count['position'] === 'R') {
                    $toeheel_right_data[] = $toeHeelMedian;
                    $side_right_data[] = $sideMedian;
                }
            }
        }

        $datasets = [
            [
                'x' => 'Toe-Heel Left',
                'y' => $this->getBoxplotSummary($toeheel_left_data)
            ],
            [
                'x' => 'Toe-Heel Right',
                'y' => $this->getBoxplotSummary($toeheel_right_data)
            ],
            [
                'x' => 'Side Left',
                'y' => $this->getBoxplotSummary($side_left_data)
            ],
            [
                'x' => 'Side Right',
                'y' => $this->getBoxplotSummary($side_right_data)
            ],
        ];

        $filteredDatasets = array_filter($datasets, fn($d) => $d['y'] !== null);

        $performanceData = [
            'labels' => ['Toe-Heel Left', 'Toe-Heel Right', 'Side Left', 'Side Right'],
            'datasets' => array_values($filteredDatasets),
        ];

        $this->dispatch('refresh-performance-chart', [
            'performanceData' => $performanceData,
        ]);
    }

    private function renderCharts()
    {
        // Output quality pie chart - classification by worst deviation found
        $severityChartData = [
            "labels" => [__("Minor (<5 KG)"), __("Major (>5 KG)"), __("Critical (>10 KG)")],
            "datasets" => [
                [
                    "data" => array_values($this->severityBreakdown),
                    "backgroundColor" => ["rgba(255, 205, 86, 0.8)", "rgba(255, 159, 64, 0.8)", "rgba(255, 99, 132, 0.8)"],
                ],
            ],
        ];

        $this->js(
            "
            (function() {
                  var severityCtx = document.getElementById('severity-chart');
                  if (window.severityChart) window.severityChart.destroy();
                  window.severityChart = new Chart(severityCtx, {
                     type: 'doughnut',
                     data: " .
                json_encode($severityChartData) .
                ",
                     options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            title: {
                                display: true,
                                text: '" .
                __("Klasifikasi Output Tidak Standard") .
                "',
                                font: {
                                    size: 16,
                                    weight: 'bold'
                                }
                            },
                            legend: {
                                position: 'left'
                            }
                        }
                     }
                  });
            })()
         "
        );
    }
}; ?>

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
                <x-select wire:model.live="line" wire:change="dispatch('updated')" class="w-full lg:w-32">
                    <option value="g5">G5</option>
                </x-select>
            </div>
            <div>
                <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Machine") }}</label>
                <x-select wire:model.live="machine" wire:change="dispatch('updated')" class="w-full lg:w-32">
                    <option value="">All</option>
                    <option value="1">1</option>
                    <option value="2">2</option>
                    <option value="3">3</option>
                    <option value="4">4</option>
                </x-select>
            </div>
        </div>
    </div>
  </div>

  <div class="overflow-hidden mb-6">
    <div class="grid grid-cols-1 gap-6">
        <!-- Boxplot Chart -->
        <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6">
            <h3 class="text-lg font-semibold mb-4 text-neutral-900 dark:text-neutral-100">
                DWP {{ $machine ? 'Machine ' . $machine : 'xxx' }} Performance Boxplot
            </h3>
            <div
                x-data="{
                    performanceChart: null,

                    initOrUpdateChart(performanceData) {
                        const chartEl = this.$refs.chartContainer;
                        if (!chartEl) {
                            console.error('[ApexChart] Chart container not found.');
                            return;
                        }

                        const datasets = performanceData.datasets || [];

                        const transformedData = datasets
                            .filter(dataset => {
                                return dataset &&
                                    dataset.hasOwnProperty('x') &&
                                    dataset.hasOwnProperty('y') &&
                                    Array.isArray(dataset.y) &&
                                    dataset.y.length === 5 &&
                                    dataset.y.every(val => typeof val === 'number' && !isNaN(val));
                            })
                            .map(dataset => {
                                return {
                                    x: dataset.x,
                                    y: dataset.y
                                };
                            });

                        const hasValidData = transformedData.length > 0;

                        if (this.performanceChart) {
                            this.performanceChart.destroy();
                        }

                        const options = {
                            series: [{
                                name: 'Performance',
                                data: transformedData
                            }],
                            chart: {
                                type: 'boxPlot',
                                height: 400,
                                toolbar: { show: true },
                                animations: {
                                    enabled: true,
                                    speed: 350
                                }
                            },
                            colors: ['#3b82f6'],
                            plotOptions: {
                                boxPlot: {
                                    colors: {
                                        upper: '#22c55e',
                                        lower: '#3b82f6'
                                    }
                                }
                            },
                            xaxis: {
                                type: 'category',
                            },
                            yaxis: {
                                title: { text: 'Pressure (kg)' },
                                labels: {
                                    formatter: (val) => { return val.toFixed(2) }
                                }
                            },
                            tooltip: {
                                y: {
                                    formatter: (val) => { return val.toFixed(2) + ' kg' }
                                }
                            },
                            noData: hasValidData ? undefined : {
                                text: 'No data available',
                                align: 'center',
                                verticalAlign: 'middle',
                            }
                        };

                        this.performanceChart = new ApexCharts(chartEl, options);
                        this.performanceChart.render();
                    }
                }"
                x-init="
                    $wire.on('refresh-performance-chart', function(payload) {
                        let data = payload;
                        if (payload && payload.detail) data = payload.detail;
                        if (Array.isArray(payload) && payload.length) data = payload[0];
                        if (payload && payload[0] && payload[0].performanceData) data = payload[0];

                        const performanceData = data?.performanceData;
                        if (!performanceData) {
                            console.warn('[DWP Dashboard] refresh-performance-chart payload missing');
                            return;
                        }

                        try {
                            initOrUpdateChart(performanceData);
                        } catch (e) {
                            console.error('[DWP Dashboard] error:', e);
                        }
                    });

                    $wire.$dispatch('updated');
                " >
                <div id="performanceChart" x-ref="chartContainer" wire:ignore></div>
            </div>
        </div>
    </div>
  </div>

  <!-- Main Content Grid: Chart + KPI Cards -->
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <!-- Pie Chart -->
    <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6">
        <div class="h-full">
            <canvas id="severity-chart"></canvas>
        </div>
    </div>

    <!-- KPI Cards Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6">
            <div class="text-neutral-500 dark:text-neutral-400 text-xs uppercase mb-2">{{ __("Total Output") }}</div>
            <div class="text-2xl font-bold">{{ number_format($deviationSummary["total_measurements"] ?? 0) }}</div>
            <div class="text-xs text-neutral-500 mt-1">{{ number_format($deviationSummary["total_sections"] ?? 0) . " " . __("standard") }}</div>
        </div>
        <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6">
            <div class="text-neutral-500 dark:text-neutral-400 text-xs uppercase mb-2">{{ __("Total Quantity Deviasi") }}</div>
            <div class="text-2xl font-bold text-red-500">{{ number_format($deviationSummary["total_deviations"] ?? 0) }}</div>
            <div class="text-xs text-neutral-500 mt-1">{{ __("out of standard") }}</div>
        </div>
        <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6">
            <div class="text-neutral-500 dark:text-neutral-400 text-xs uppercase mb-2">{{ __("Deviasi Major+") }}</div>
            <div class="text-2xl font-bold text-orange-600">{{ number_format($deviationSummary["major_plus_deviations"] ?? 0) }}</div>
            <div class="text-xs text-neutral-500 mt-1">{{ __("deviation >5 KG") }}</div>
        </div>
        <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6">
            <div class="text-neutral-500 dark:text-neutral-400 text-xs uppercase mb-2">{{ __("Tingkat Output Tidak Standard") }}</div>
            <div
                class="text-2xl font-bold {{ ($deviationSummary["critical_rate"] ?? 0) > 10 ? "text-red-500" : (($deviationSummary["critical_rate"] ?? 0) > 5 ? "text-yellow-500" : "text-green-500") }}"
            >
                {{ number_format($deviationSummary["critical_rate"] ?? 0, 2) }}%
            </div>
            <div class="text-xs text-neutral-500 mt-1">{{ __("Target: <10%") }}</div>
        </div>
    </div>
  </div>
</div>

@script
    <script>
        $wire.$dispatch('updated');
    </script>
@endscript