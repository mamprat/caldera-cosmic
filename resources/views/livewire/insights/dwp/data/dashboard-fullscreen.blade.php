<?php

use Livewire\Volt\Component;
use Livewire\Attributes\On;
use App\Traits\HasDateRangeFilter;
use App\Models\InsDwpDevice;
use App\Models\InsDwpCount;
use App\Models\InsDwpTimeAlarmCount;
use Livewire\WithPagination;
use Livewire\Attributes\Url;
use Carbon\Carbon;
use Livewire\Attributes\Layout;

new #[Layout("layouts.app")] class extends Component {
    use WithPagination;
    use HasDateRangeFilter;
    public array $machineData = [];
    public array $stdRange = [30, 45];
    public $lastRecord = null;
    public $view = "dashboard";

    #[Url]
    public string $start_at = "";

    #[Url]
    public string $end_at = "";

    #[Url]
    public string $line = "g5";

    public string $lines = "";

    public int $totalStandart = 0;
    public int $totalOutStandart = 0;
    public int $onlineTime = 0;
    public string $fullTimeFormat = "";

    // Add new properties for the top summary boxes
    public int $timeConstraintAlarm = 0;
    public int $longestQueueTime = 0;
    public int $alarmsActive = 0;

    // === NEW: Add property for online monitoring ===
    public array $onlineMonitoringData = ['online' => 100, 'offline' => 0];

    public function mount()
    {
        // today for init start and end date
        $this->start_at = Carbon::today()->toDateString();
        $this->end_at = Carbon::today()->toDateString();

        $this->dispatch("update-menu", $this->view);
        $this->updateData();
        $this->generateChartsClient();
        $dataReads = $this->getPressureReadingStats();
        $this->totalStandart = $dataReads['standard_count'] ?? 0;
        $this->totalOutStandart = $dataReads['not_standard_count'] ?? 0;
    }

    private function getDataLine($line=null)
    {
        $lines = [];
        $dataRaws = InsDwpDevice::orderBy("name")
            ->select("name", "id", "config")
            ->get()->toArray();
        foreach($dataRaws as $dataRaw){
            if (!empty($line)){
                if ($dataRaw['config'][0]['line'] == strtoupper($line)){
                    $lines[] = $dataRaw['config'][0];
                    break;
                }
            }else {
                $lines[] = $dataRaw['config'][0];
            }
        }
        return $lines;
    }

    /**
     * GET DATA MACHINES
     * Description : This code for get data machines on database ins_dwp_device
     */ 
    private function getDataMachines($selectedLine = null)
    {
        if (!$selectedLine) {
            return [];
        }

        // Query for the specific device that handles this line to avoid loading all of them.
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

    public function updateData()
    {
        $machineConfigs = $this->getDataMachines($this->line);
        $machineNames = array_column($machineConfigs, 'name');

        if (empty($machineNames)) {
            $this->machineData = [];
            return;
        }

        // === NEW: Calculate Online Monitoring Stats ===
        $dataOnlineMonitoring = $this->getOnlineMonitoringStats($machineNames);
        $this->onlineMonitoringData = $dataOnlineMonitoring['percentages'];
        $this->onlineTime = $dataOnlineMonitoring['total_hours'] ?? 0;
        $this->fullTimeFormat = $dataOnlineMonitoring['full_time_format'] ?? "";

        // --- Step 1: Get latest sensor reading for each machine (Your query is already efficient) ---
        $latestCountsQuery = InsDwpCount::select('mechine', 'position', 'pv', 'created_at')
            ->whereIn('id', function ($query) use ($machineNames) {
                $query->selectRaw('MAX(id)')
                    ->from('ins_dwp_counts')
                    ->whereIn('mechine', $machineNames)
                    ->groupBy('mechine', 'position');
            });

        // --- Step 2 (OPTIMIZED): Get all Left/Right output counts in a SINGLE query to prevent N+1 problem ---
        $outputsQuery = InsDwpCount::whereIn('mechine', $machineNames)
            ->selectRaw('mechine, position, count(*) as total')
            ->groupBy('mechine', 'position');

        // --- Step 3 (FIXED): Get all recent records for a correct average calculation ---
        $recentRecordsQuery = InsDwpCount::whereIn('mechine', $machineNames)
            ->where('created_at', '>=', now()->subDay())
            ->select('mechine', 'pv');

        // Apply date range filter to relevant queries
        if ($this->start_at && $this->end_at) {
            $start = Carbon::parse($this->start_at);
            $end = Carbon::parse($this->end_at)->endOfDay();
            $latestCountsQuery->whereBetween('created_at', [$start, $end]);
            $outputsQuery->whereBetween('created_at', [$start, $end]);
        }
        
        // Execute the queries
        $latestCounts = $latestCountsQuery->get();
        $allOutputs = $outputsQuery->get();
        $recentRecords = $recentRecordsQuery->get()->groupBy('mechine');

        // --- OPTIMIZATION: Process outputs into an easy-to-use lookup array ---
        $outputCounts = [];
        foreach ($allOutputs as $output) {
            $outputCounts[$output->mechine][$output->position] = $output->total;
        }

        // --- Step 4: Process all fetched data with no more N+1 queries ---
        $newData = [];
        foreach ($machineConfigs as $machine) {
            $machineName = $machine['name'];

            $leftLast = $latestCounts->where('mechine', $machineName)->where('position', 'L')->first();
            $rightLast = $latestCounts->where('mechine', $machineName)->where('position', 'R')->first();

            $leftPv = $leftLast ? (json_decode($leftLast->pv, true) ?? [[0], [0]]) : [[0], [0]];
            $rightPv = $rightLast ? (json_decode($rightLast->pv, true) ?? [[0], [0]]) : [[0], [0]];

            // Get medians from the arrays and round them
            $leftToesHeels = $leftPv[0] ?? [0];
            $leftSides = $leftPv[1] ?? [0];
            $rightToesHeels = $rightPv[0] ?? [0];
            $rightSides = $rightPv[1] ?? [0];
            $leftData = [
                'side' => round($this->getMax($leftSides)), 
                'toeHeel' => round($this->getMax($leftToesHeels))
            ];
            $rightData = [
                'side' => round($this->getMax($rightSides)), 
                'toeHeel' => round($this->getMax($rightToesHeels))
            ];
            // --- FIXED: Correctly calculate average from all recent records ---
            $allPvs = [];
            if (isset($recentRecords[$machineName])) {
                foreach ($recentRecords[$machineName] as $record) {
                    $decodedPvs = json_decode($record->pv, true) ?? [];
                    if (is_array($decodedPvs) && count($decodedPvs) >= 2) {
                        // Add all values from both toe_heel and side arrays
                        $allPvs = array_merge($allPvs, $decodedPvs[0] ?? [], $decodedPvs[1] ?? []);
                    }
                }
            }
            $nonZeroValues = array_filter($allPvs, fn($v) => is_numeric($v) && $v > 0);
            $averagePressure = !empty($nonZeroValues) ? round(array_sum($nonZeroValues) / count($nonZeroValues)) : 0;

            $statuses = [
                'leftToeHeel'  => $this->getStatus($leftData['toeHeel']),
                'leftSide'     => $this->getStatus($leftData['side']),
                'rightToeHeel' => $this->getStatus($rightData['toeHeel']),
                'rightSide'    => $this->getStatus($rightData['side']),
            ];

            $newData[] = [
                'id' => 'machine-' . $machineName,
                'name' => $machineName,
                'sensors' => [
                    'left'  => ['toeHeel' => ['value' => $leftData['toeHeel'], 'status' => $statuses['leftToeHeel']], 'side' => ['value' => $leftData['side'], 'status' => $statuses['leftSide']]],
                    'right' => ['toeHeel' => ['value' => $rightData['toeHeel'], 'status' => $statuses['rightToeHeel']], 'side' => ['value' => $rightData['side'], 'status' => $statuses['rightSide']]],
                ],
                'lastDataSensors' => [
                    'left'  => $leftLast ? $leftLast->toArray() : null,
                    'right' => $rightLast ? $rightLast->toArray() : null,
                ],
                'overallStatus' => in_array('alert', $statuses) ? 'alert' : 'normal',
                'average' => $averagePressure,
                'output' => [
                    'left'  => $outputCounts[$machineName]['L'] ?? 0,
                    'right' => $outputCounts[$machineName]['R'] ?? 0,
                ],
            ];
        }

        $this->machineData = $newData;
        $performanceData = $this->getPerformanceData($newData);

        // update alarm and summary data
        $this->longestQueueTime = $this->getLongestDuration()['duration'] ?? 0;
        $this->alarmsActive = $this->getAlarmActiveCount();
        $this->dispatch('data-updated', performance: $performanceData);
    }

    // NEW: A function to calculate data for the new charts
    public function getPerformanceData(array $machineData)
    {
        $totalMachines = count($machineData);
        $outOfStandard = 0;

        // Data for the Horizontal Bar Chart (Performa AVG Pressure)
        $avgPressures = [
            'labels' => [],
            'data' => []
        ];

        foreach($machineData as $machine) {
            if ($machine['overallStatus'] === 'alert') {
                $outOfStandard++;
            }
            $avgPressures['labels'][] = $machine['name'];
            $avgPressures['data'][] = round($machine['average'], 1);
        }

        $dataReads = $this->getPressureReadingStats();
        $outOfStandard = $dataReads['not_standard_count'] ?? 0;
        $standardReads = $dataReads['standard_count'] ?? 0;
        $totalReads = $dataReads['total_count'] ?? 1;
        //prevent division by zero
        if ($totalReads == 0) {
            $totalReads = 1;
        }
        // Data for the Donut Chart (Daily Performance) - sourced from DB by date range
        $standard = ($standardReads / $totalReads) * 100;
        $outOfStandard = ($outOfStandard / $totalReads) * 100;
        $dailyPerformance = [
            'standard' => round($standard, 2),
            'outOfStandard' => round($outOfStandard, 2)
        ];

        return [
            'daily' => $dailyPerformance,
            'avgPressures' => $avgPressures
        ];
    }

    public function getPressureReadingStats()
    {
        [$minStd, $maxStd] = $this->stdRange;
        $standardReadings = 0;
        $notStandardReadings = 0;

        $machineConfigs = $this->getDataMachines($this->line);
        $machineNames = array_column($machineConfigs, 'name');

        if (empty($machineNames)) {
            return [
                'total_count' => 0,
                'standard_count' => 0,
                'not_standard_count' => 0,
            ];
        }

        // Fetch ALL records (L and R) without grouping
        $query = InsDwpCount::whereIn('mechine', $machineNames)
            ->whereIn('position', ['L', 'R'])
            ->select('mechine', 'pv', 'position', 'created_at');

        if ($this->start_at && $this->end_at) {
            $start = Carbon::parse($this->start_at);
            $end = Carbon::parse($this->end_at)->endOfDay();
            $query->whereBetween('created_at', [$start, $end]);
        }

        // Group records into cycles by rounding created_at to nearest 5 seconds
        $cycles = [];

        foreach ($query->cursor() as $record) {
            // Round timestamp to nearest 5 seconds to group near-simultaneous L/R
            $roundedTime = Carbon::parse($record->created_at)
                ->floorSeconds(5)
                ->format('Y-m-d H:i:s');

            $key = $record->mechine . '|' . $roundedTime;

            if (!isset($cycles[$key])) {
                $cycles[$key] = ['L' => null, 'R' => null];
            }

            if (in_array($record->position, ['L', 'R'])) {
                $cycles[$key][$record->position] = json_decode($record->pv, true);
            }
        }

        // Now process only complete cycles (both L and R present)
        foreach ($cycles as $cycle) {
            if (
                is_array($cycle['L']) && count($cycle['L']) >= 2 &&
                is_array($cycle['R']) && count($cycle['R']) >= 2
            ) {
                $allValues = array_merge(
                    $cycle['L'][0] ?? [], $cycle['L'][1] ?? [],
                    $cycle['R'][0] ?? [], $cycle['R'][1] ?? []
                );

                foreach ($allValues as $value) {
                    if (is_numeric($value)) {
                        if ($value >= $minStd && $value <= $maxStd) {
                            $standardReadings++;
                        } else {
                            $notStandardReadings++;
                        }
                    }
                }
            }
        }

        return [
            'total_count' => $standardReadings + $notStandardReadings,
            'standard_count' => $standardReadings,
            'not_standard_count' => $notStandardReadings,
        ];
    }

    public function checkLastData()
    {
        $this->lastRecord = InsDwpCount::latest()->first();
    }

    private function getStatus($value)
    {
        if ($value > $this->stdRange[1] || $value < $this->stdRange[0]) {
            return 'alert';
        }
        if ($value > ($this->stdRange[1] - 1) || $value < ($this->stdRange[0] + 1)) {
            return 'warning';
        }
        return 'normal';
    }

    private function getMedian(array $array)
    {
        if (empty($array)) return 0;
        // Filter out non-numeric values
        $numericArray = array_filter($array, 'is_numeric');
        if (empty($numericArray)) return 0;
        
        sort($numericArray);
        $count = count($numericArray);
        $middle = floor(($count - 1) / 2);
        $median = ($count % 2) ? $numericArray[$middle] : ($numericArray[$middle] + $numericArray[$middle + 1]) / 2;
        
        return round($median);
    }

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

    private function getLongestDuration(){
        // GET LONG DURATION DATA from database
        $longDurationData = InsDwpTimeAlarmCount::orderBy('duration', 'desc')
            ->whereBetween('created_at', [
                Carbon::parse($this->start_at)->startOfDay(),
                Carbon::parse($this->end_at)->endOfDay()
            ])
            ->first();
        if (empty($longDurationData)){
            return [];
        }else {
            return $longDurationData->toArray();
        }
    }

    function getAlarmActiveCount(){
        // GET ALARM ACTIVE COUNT from database
        $alarmActiveCount = InsDwpTimeAlarmCount::whereBetween('created_at', [
                Carbon::parse($this->start_at)->startOfDay(),
                Carbon::parse($this->end_at)->endOfDay()
            ])->orderBy('created_at', 'desc')->first()->cumulative ?? 0;
        return $alarmActiveCount;
    }

    public function with(): array
    {
        $longestDuration = $this->getLongestDuration();

        return [
            'machineData' => $this->machineData,
            'longestDurationValue' => $longestDuration['duration'] ?? 'N/A',
            'longestDurationCumulative' => $longestDuration['cumulative'] ?? 'N/A',
        ];
    }

    #[On("data-updated")]
    public function update()
    {
        // Use server-side JS injection to render charts (pattern similar to metric-detail)
        $this->generateChartsClient();
    }

    /**
     * NEW: Helper function to get colors for the chart lines
     */
    private function getLineColor($line)
    {
        switch (strtoupper($line)) {
            case 'G1': return '#ef4444'; // red
            case 'G2': return '#3b82f6'; // blue
            case 'G3': return '#22c55e'; // green
            case 'G4': return '#f97316'; // orange
            case 'G5': return '#a855f7'; // purple
            default: return '#6b7280'; // gray
        }
    }

    /**
     * NEW: Get data for the DWP Time Constraint line chart - HOURLY for one day
     */
    private function getDwpTimeConstraintData()
    {
        // 1. Set date to a single day (use start_at or default to today)
        $date = ($this->start_at) ? Carbon::parse($this->start_at)->startOfDay() : now()->startOfDay();
        // End is same day (we only care about one day)
        $startOfDay = $date->copy();
        $endOfDay = $date->copy()->endOfDay();

        // 2. Define lines (still supports multiple, but usually one)
        $lines = [$this->line ? strtoupper($this->line) : 'G5'];

        // 3. Query hourly data for the selected day
        // Use SUM(incremental) to get actual alarm count per hour (not cumulative)
        $results = InsDwpTimeAlarmCount::query()
            ->whereIn('line', $lines)
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->selectRaw('HOUR(created_at) as hour, line, SUM(incremental) as alarm_count')
            ->groupByRaw('HOUR(created_at), line')
            ->get()
            ->keyBy(function ($item) {
                return $item->hour . '_' . $item->line;
            });

        // 4. Define working hours: 7 AM to 4 PM (7:00 to 16:00 inclusive = 10 hours)
        $workingHours = range(7, 16);

        $labels = [];
        $datasets = [];

        // 5. Initialize datasets
        foreach ($lines as $line) {
            $datasets[$line] = [
                'label' => $line,
                'data' => [],
                'borderColor' => $this->getLineColor($line),
                'backgroundColor' => $this->getLineColor($line),
                'tension' => 0.3,
                'fill' => false,
            ];
        }

        // 6. Fill data for each working hour
        foreach ($workingHours as $hour) {
            // Format label as "07:00", "08:00", ..., "16:00"
            $labels[] = str_pad($hour, 2, '0', STR_PAD_LEFT) . ':00';

            foreach ($lines as $line) {
                $key = $hour . '_' . $line;
                $value = $results->get($key) ? (int) $results->get($key)->alarm_count : 0;
                $datasets[$line]['data'][] = $value;
            }
        }

        return [
            'labels' => $labels,
            'datasets' => array_values($datasets)
        ];
    }

    /**
     * Render charts by injecting client-side JS with embedded data (similar to metric-detail.generateChart)
     */
    private function generateChartsClient()
    {
        // Get data for all charts
        $perf = $this->getPerformanceData($this->machineData);
        $daily = $perf['daily'] ?? ['standard' => 100, 'outOfStandard' => 0];
        $online = $this->onlineMonitoringData ?? ['online' => 100, 'offline' => 0];
        
        // === NEW: Get DWP Time Constraint Chart Data ===
        $dwpData = $this->getDwpTimeConstraintData();

        // Encode all data for JavaScript
        $dailyJson = json_encode($daily);
        $onlineJson = json_encode($online);
        $dwpJson = json_encode($dwpData); // === NEW ===
        $this->js(
            "
            (function(){
                try {
                    // --- 1. Get Data from PHP ---
                    const dailyData = " . $dailyJson . ";
                    const onlineData = " . $onlineJson . ";
                    const dwpData = " . $dwpJson . "; // === NEW ===

                    // --- 2. Theme Helpers ---
                    function isDarkModeLocal(){
                        try{ return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches || document.documentElement.classList.contains('dark'); }catch(e){return false}
                    }
                    const theme = {
                        textColor: isDarkModeLocal() ? '#e6edf3' : '#0f172a',
                        gridColor: isDarkModeLocal() ? 'rgba(255,255,255,0.06)' : 'rgba(15,23,42,0.06)'
                    };

                    // --- 3. DAILY PERFORMANCE (doughnut) ---
                    const dailyCanvas = document.getElementById('dailyPerformanceChart');
                    if (dailyCanvas) {
                        try {
                            const ctx = dailyCanvas.getContext('2d');
                            if (window.__dailyPerformanceChart instanceof Chart) {
                                try { window.__dailyPerformanceChart.destroy(); } catch(e){}
                            }
                            window.__dailyPerformanceChart = new Chart(ctx, {
                                type: 'doughnut',
                                data: {
                                    labels: ['Standard', 'Out Of Standard'],
                                    datasets: [{
                                        data: [dailyData.standard || 0, dailyData.outOfStandard || 0],
                                        backgroundColor: ['#22c55e', '#ef4444'],
                                        hoverOffset: 30,
                                        borderWidth: 0
                                    }]
                                },
                                options: {
                                    cutout: '70%',
                                    plugins: {
                                        legend: { display: false },
                                        tooltip: { bodyColor: theme.textColor, titleColor: theme.textColor }
                                    },
                                    responsive: true,
                                    maintainAspectRatio: false
                                }
                            });
                        } catch (e) { console.error('[DWP Dashboard] injected daily chart error', e); }
                    } else {
                        console.warn('[DWP Dashboard] dailyPerformanceChart canvas not found');
                    }

                    // --- 4. ONLINE SYSTEM MONITORING (pie) ---
                    const onlineCanvas = document.getElementById('onlineSystemMonitoring');
                    if (onlineCanvas) {
                        try {
                            const ctx2 = onlineCanvas.getContext && onlineCanvas.getContext('2d');
                            if (window.__onlineSystemMonitoringChart instanceof Chart) {
                                try { window.__onlineSystemMonitoringChart.destroy(); } catch(e){}
                            }
                            window.__onlineSystemMonitoringChart = new Chart(ctx2, {
                                type: 'pie',
                                data: {
                                    labels: ['Online', 'Offline'],
                                    datasets: [{
                                        data: [onlineData.online || 0, onlineData.offline || 0],
                                        borderWidth: 1,
                                        backgroundColor: ['#22c55e', '#d1d5db'],
                                        borderRadius: 5
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    plugins: {
                                        legend: { display: false },
                                        tooltip: {
                                            callbacks: {
                                                label: function(context){
                                                    let label = context.label || '';
                                                    if (label) label += ': ';
                                                    if (context.parsed !== null) label += context.parsed.toFixed(2) + '%';
                                                    return label;
                                                }
                                            }
                                        }
                                    }
                                }
                            });
                        } catch (e) { console.error('[DWP Dashboard] injected online chart error', e); }
                    } else {
                        console.warn('[DWP Dashboard] onlineSystemMonitoring canvas not found');
                    }
                    
                    // --- 5. === NEW: DWP TIME CONSTRAINT CHART (line) === ---
                    const dwpCtx = document.getElementById('dwpTimeConstraintChart');
                    if (dwpCtx) {
                        try {
                            const ctx3 = dwpCtx.getContext('2d');
                            if (window.__dwpTimeConstraintChart instanceof Chart) {
                                try { window.__dwpTimeConstraintChart.destroy(); } catch(e){}
                            }
                            window.__dwpTimeConstraintChart = new Chart(ctx3, {
                            type: 'line',
                            data: dwpData,
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                scales: {
                                    x: {
                                        grid: { 
                                            display: true,
                                            color: '#e5e7eb',
                                            drawBorder: true,
                                            drawOnChartArea: true,
                                            drawTicks: true,
                                        },
                                        ticks: { 
                                            color: '#000000',
                                            font: { size: 11 }
                                        }
                                    },
                                    y: {
                                        beginAtZero: true,
                                        grid: { 
                                            display: true,
                                            color: '#e5e7eb',
                                            drawBorder: true,
                                            drawOnChartArea: true,
                                            drawTicks: true
                                        },
                                        ticks: { 
                                            color: '#000000',
                                            font: { size: 16 }
                                        }
                                    }
                                },
                                plugins: {
                                    legend: {
                                        position: 'bottom',
                                        labels: { color: '#000000' }
                                    },
                                    tooltip: {
                                        enabled: true
                                    }
                                }
                            },
                            plugins: [{
                                afterDatasetsDraw: function(chart) {
                                    const ctx = chart.ctx;
                                    chart.data.datasets.forEach((dataset, i) => {
                                        const meta = chart.getDatasetMeta(i);
                                        meta.data.forEach((element, index) => {
                                            const value = dataset.data[index];
                                            if (value > 0) {
                                                ctx.font = 'bold 15px sans-serif';
                                                ctx.fillStyle = dataset.borderColor;
                                                ctx.textAlign = 'center';
                                                ctx.fillText(value, element.x, element.y - (-15));
                                            }
                                        });
                                    });
                                }
                            }]
                        });
                        } catch (e) { console.error('[DWP Dashboard] injected dwp chart error', e); }
                    } else {
                        console.warn('[DWP Dashboard] dwpTimeConstraintChart canvas not found');
                    }

                } catch (e) {
                    console.error('[DWP Dashboard] generateChartsClient error', e);
                }
            })();
            "
        );
    }

    private function getOnlineMonitoringStats(array $machineNames): array
    {
        $period = $this->calculatePeriod();
        if ($period->totalDuration <= 0) {
            return [
                'percentages' => ['online' => 0, 'offline' => 100],
                'total_hours' => 0, // in hours
                'full_time_format' => "0 hours 0 minutes 0 seconds"
            ];
        }
        if (empty($machineNames)) {
            return [
                'percentages' => ['online' => 0, 'offline' => 100],
                'total_hours' => 0, // in hours
                'full_time_format' => "0 hours 0 minutes 0 seconds"
            ];
        }
        $activityTimestamps = $this->getActivityTimestamps($machineNames, Carbon::parse($period->start), Carbon::parse($period->end));
        $totalDowntime = $this->calculateTotalDowntime($activityTimestamps, Carbon::parse($period->start), Carbon::parse($period->end));
        
        $onlineDuration = $period->totalDuration - $totalDowntime;
        
        return [
            'percentages' => $this->calculatePercentages($period->totalDuration, $totalDowntime),
            'total_hours' => $onlineDuration / 3600, // in hours
            'full_time_format' => $this->formatDuration($onlineDuration)
        ];
    }

    private function calculatePeriod(): object
    {
        $start = InsDwpTimeAlarmCount::where('created_at', '>=', now()->startOfDay())
            ->where('created_at', '<=', now()->endOfDay())
            ->min('created_at');

        $end = now()->format('Y-m-d H:i:s');
        
        if (!$start) {
            return (object) [
                'start' => now()->format('Y-m-d H:i:s'),
                'end' => $end,
                'totalDuration' => 0
            ];
        }
        
        $totalDuration = Carbon::parse($start)->diffInSeconds(Carbon::parse($end));
        
        return (object) [
            'start' => $start,
            'end' => $end,
            'totalDuration' => $totalDuration
        ];
    }

    private function parseStartDateTime(): Carbon
    {
        $start = $this->start_at ? Carbon::parse($this->start_at) : now()->subDay();
        return $start->startOfDay();
    }

    private function parseEndDateTime(): Carbon
    {
        $end = $this->end_at ? Carbon::parse($this->end_at) : now();
        return $end->endOfDay();
    }

    private function getActivityTimestamps(array $machineNames, Carbon $start, Carbon $end): array
    {
        return InsDwpCount::whereIn('mechine', $machineNames)
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('created_at', 'asc')
            ->pluck('created_at')
            ->toArray();
    }

    private function calculateTotalDowntime(array $timestamps, Carbon $periodStart, Carbon $periodEnd): int
    {
        $downtimeThreshold = $this->getDowntimeThresholdInSeconds();

        // If no timestamps, entire period is downtime (if it exceeds threshold)
        if (empty($timestamps)) {
            $totalGap = $periodStart->diffInSeconds($periodEnd);
            return $totalGap > $downtimeThreshold ? $totalGap : 0;
        }

        $totalDowntime = 0;

        // 1. Initial gap: from periodStart to first timestamp
        $initialGap = Carbon::parse($timestamps[0])->diffInSeconds($periodStart);
        if ($initialGap > $downtimeThreshold) {
            $totalDowntime += $initialGap;
        }

        // 2. Middle gaps: between consecutive timestamps
        for ($i = 1; $i < count($timestamps); $i++) {
            $gap = Carbon::parse($timestamps[$i])->diffInSeconds(Carbon::parse($timestamps[$i - 1]));
            if ($gap > $downtimeThreshold) {
                $totalDowntime += $gap;
            }
        }

        // 3. Final gap: from last timestamp to periodEnd
        $finalGap = $periodEnd->diffInSeconds(Carbon::parse(end($timestamps)));
        if ($finalGap > $downtimeThreshold) {
            $totalDowntime += $finalGap;
        }

        return $totalDowntime;
    }

    private function formatDuration(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $remainingSeconds = $seconds % 60;
        
        $parts = [];
        
        if ($hours > 0) {
            $parts[] = $hours . ' hour' . ($hours !== 1 ? 's' : '');
        }
        
        if ($minutes > 0) {
            $parts[] = $minutes . ' minute' . ($minutes !== 1 ? 's' : '');
        }
        
        if ($remainingSeconds > 0 || empty($parts)) { // Always show seconds if no other units, or if there are remaining seconds
            $parts[] = $remainingSeconds . ' second' . ($remainingSeconds !== 1 ? 's' : '');
        }
        
        return implode(' ', $parts);
    }

    private function calculatePercentages(int $totalDuration, int $totalDowntime): array
    {
        if ($totalDuration <= 0) {
            return ['online' => 0, 'offline' => 100];
        }
        
        $onlineDuration = $totalDuration - $totalDowntime;
        $onlinePercentage = ($onlineDuration / $totalDuration) * 100;
        $offlinePercentage = ($totalDowntime / $totalDuration) * 100;
        
        return [
            'online' => round($onlinePercentage, 2),
            'offline' => round($offlinePercentage, 2)
        ];
    }

    private function getDowntimeThresholdInSeconds(): int
    {
        return 120; // 2 minutes
    }
}; ?>

<div class="p-4">
    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        <!-- Sidebar: Left Column (1/4 on lg) -->
        <div class="lg:col-span-1 space-y-6">
            <!-- Time Constraint Alarm -->
            <div class="bg-white dark:bg-neutral-800 p-6 rounded-lg shadow-md">
                <div class="font-semibold text-neutral-700 dark:text-neutral-200 text-xl mb-4">
                    Time Constraint Alarm
                </div>
                <div class="space-y-4">
                    <div>
                        <p class="text-md text-neutral-600 dark:text-neutral-400">Long Queue time</p>
                        <p class="text-3xl font-bold">{{ $this->longestQueueTime }} <span class="text-base">sec</span></p>
                    </div>
                    <div>
                        <p class="text-md text-neutral-600 dark:text-neutral-400">Alarm Active</p>
                        <p class="text-3xl font-bold">{{ $this->alarmsActive }}</p>
                    </div>
                </div>
            </div>

            <!-- Performance Machine -->
            <div class="bg-white dark:bg-neutral-800 p-6 rounded-lg shadow-md">
                <h2 class="text-lg font-semibold text-slate-800 dark:text-slate-200 mb-4">Performance Machine</h2>
                <div class="h-[150px]">
                    <canvas id="dailyPerformanceChart" wire:ignore></canvas>
                </div>
                <div class="flex flex-col gap-2 mt-4">
                    <div class="flex items-center gap-2">
                        <span class="w-4 h-4 rounded bg-green-500"></span>
                        <span>Standard: {{ $this->totalStandart }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="w-4 h-4 rounded bg-red-600"></span>
                        <span>Out Of Standard: {{ $this->totalOutStandart }}</span>
                    </div>
                </div>
            </div>

            <!-- Online System Monitoring -->
            <div class="bg-white dark:bg-neutral-800 p-6 rounded-lg shadow-md">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold text-slate-800 dark:text-slate-200">
                        Online System Monitoring
                    </h2>
                    <span class="text-sm text-neutral-600 dark:text-neutral-400">{{ $this->fullTimeFormat }}</span>
                </div>
                <div class="h-[150px]">
                    <canvas id="onlineSystemMonitoring" wire:ignore></canvas>
                </div>
                <div class="flex flex-col gap-2 mt-4">
                    <div class="flex items-center gap-2">
                        <span class="w-4 h-4 rounded bg-green-500"></span>
                        <span>Online</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="w-4 h-4 rounded bg-gray-300 dark:bg-gray-600"></span>
                        <span>Offline</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content: Right Column (3/4 on lg) -->
        <div class="lg:col-span-3 space-y-6">
            <!-- DWP Time Constraint Chart -->
            <div class="bg-white dark:bg-neutral-800 p-6 rounded-lg shadow-md h-[350px]">
                <h1 class="text-xl font-bold text-center mb-4">DWP Time Constraint</h1>
                <div class="h-[250px]">
                    <canvas id="dwpTimeConstraintChart" wire:ignore></canvas>
                </div>
            </div>

            <!-- Standard Machine Labels -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                @for($i = 1; $i <= 4; $i++)
                    <div class="bg-white dark:bg-neutral-800 p-3 rounded-lg shadow-md text-center">
                        <h2 class="text-sm text-slate-800 dark:text-slate-200">
                            Standard Machine #{{ $i }}: <span>{{ $this->stdRange[0] }} ~ {{ $this->stdRange[1] }} kg</span>
                        </h2>
                    </div>
                @endfor
            </div>

            <!-- Machine Data Cards -->
            <div wire:key="machine-data" wire:poll.20s="updateData">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                    @forelse ($machineData as $machine)
                        <div class="relative p-6 bg-white dark:bg-neutral-800 border-4 shadow-md rounded-xl
                            {{ $machine['overallStatus'] == 'alert' ? 'border-red-500' : 'border-transparent' }}">
                            <div class="absolute top-[20px] -left-5 px-2 py-2 bg-white dark:bg-neutral-800
                                border-4 rounded-lg text-slate-800 dark:text-slate-200 text-2xl font-bold
                                {{ $machine['overallStatus'] == 'alert' ? 'border-red-500' : 'bg-green-500' }}">
                                #{{ $machine['name'] }}
                            </div>
                            <div class="mt-8">
                                <div class="grid grid-cols-2 gap-2 text-center">
                                    <div>
                                        <h4 class="font-semibold text-neutral-700 dark:text-neutral-200">LEFT</h4>
                                        <p class="text-xs text-neutral-600 dark:text-neutral-400">Toe/Heel</p>
                                        <div class="p-2 rounded-md text-white font-bold text-lg mb-2
                                            {{ $machine['sensors']['left']['toeHeel']['status'] == 'alert' ? 'bg-red-500' : 'bg-green-500' }}">
                                            {{ $machine['sensors']['left']['toeHeel']['value'] }}
                                        </div>
                                        <p class="text-xs text-neutral-600 dark:text-neutral-400">Side</p>
                                        <div class="p-2 rounded-md text-white font-bold text-lg
                                            {{ $machine['sensors']['left']['side']['status'] == 'alert' ? 'bg-red-500' : 'bg-green-500' }}">
                                            {{ $machine['sensors']['left']['side']['value'] }}
                                        </div>
                                    </div>
                                    <div>
                                        <h4 class="font-semibold text-neutral-700 dark:text-neutral-200">RIGHT</h4>
                                        <p class="text-xs text-neutral-600 dark:text-neutral-400">Toe/Heel</p>
                                        <div class="p-2 rounded-md text-white font-bold text-lg mb-2
                                            {{ $machine['sensors']['right']['toeHeel']['status'] == 'alert' ? 'bg-red-500' : 'bg-green-500' }}">
                                            {{ $machine['sensors']['right']['toeHeel']['value'] }}
                                        </div>
                                        <p class="text-xs text-neutral-600 dark:text-neutral-400">Side</p>
                                        <div class="p-2 rounded-md text-white font-bold text-lg
                                            {{ $machine['sensors']['right']['side']['status'] == 'alert' ? 'bg-red-500' : 'bg-green-500' }}">
                                            {{ $machine['sensors']['right']['side']['value'] }}
                                        </div>
                                    </div>
                                </div>
                                <div class="text-center mt-4">
                                    <h3 class="text-sm text-neutral-600 dark:text-neutral-400">Output</h3>
                                    <div class="p-2 rounded-md dark:bg-neutral-900 font-bold text-lg">
                                        {{ $machine['output']['left'] ?? 0 }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="col-span-4 text-center text-neutral-500 py-8">
                            No machine data available for the selected line.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
