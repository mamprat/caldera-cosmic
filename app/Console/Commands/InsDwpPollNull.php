<?php

namespace App\Console\Commands;

use App\Models\InsDwpDevice;
use App\Models\InsDwpCount;
use Carbon\Carbon;
use Illuminate\Console\Command;
use ModbusTcpClient\Composer\Read\ReadRegistersBuilder;
use ModbusTcpClient\Network\NonBlockingClient;

class InsDwpPollNull extends Command
{
    // Configuration variables
    protected $pollIntervalSeconds = 0.01;
    protected $modbusTimeoutSeconds = 2;
    protected $modbusPort = 503;

    // Cycle detection configuration
    protected $cycleStartThreshold = 1; // Value to detect the start of a cycle (now used by 'capturing' state)
    protected $toeHeelEndThreshold = 0; // Value to detect end of toe/heel cycle
    // Note: sideEndThreshold is no longer needed in the new logic but left for context
    protected $sideEndThreshold = 0;
    protected $goodValueMin = 30;        // Min value for a good reading
    protected $goodValueMax = 45;        // Max value for a good reading
    protected $cycleTimeoutSeconds = 30; // Failsafe to reset a stuck cycle

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:ins-dwp-poll-null {--v : Verbose output} {--d : Debug output}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Poll DWP (Deep-Well Press) counter data from Modbus servers and track incremental counts';

    // In-memory buffer to track last cumulative values per line
    protected $lastCumulativeValues = [];

    // State machine for cycle detection
    // NEW: Tracks each position (L/R) independently.
    // Example: ['LINEA-mc1-L' => ['state' => 'idle']]
    // Example: ['LINEA-mc1-R' => ['state' => 'capturing', 'start_time' => 167...]]
    protected $cycleStates = [];

    // Memory optimization counters
    protected $pollCycleCount = 0;
    protected $memoryCleanupInterval = 1000; // Clean memory every 1000 cycles

    // Statistics tracking
    protected $deviceStats = [];
    protected $totalReadings = 0;
    protected $totalErrors = 0;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $devices = InsDwpDevice::active()->get();
        if ($devices->isEmpty()) {
            $this->error('✗ No active DWP devices found');
            return 1;
        }
        while (true) {
            $cycleStartTime = microtime(true);
            $cycleReadings = 0;
            $cycleErrors = 0;

            foreach ($devices as $device) {
                if ($this->option('v')) {
                    $this->comment("→ Polling {$device->name} ({$device->ip_address})");
                }
                try {
                    $readings = $this->pollDevice($device);
                    print_r($readings);
                } catch (\Throwable $th) {
                    $this->error("✗ Error polling {$device->name} ({$device->ip_address}): " . $th->getMessage());
                    $cycleErrors++;
                    $this->updateDeviceStats($device->name, false);
                }
            }
        }
    }

    /**
     * Poll a single device and process all its lines
     */
    private function pollDevice(InsDwpDevice $device)
    {
        $unit_id = 1;
        $savedReadingsCount = 0;

        foreach ($device->config as $lineConfig) {
            $line = strtoupper(trim($lineConfig['line']));
            $responses = [];
            foreach($lineConfig['list_mechine'] as $listMachine){
                try {
                    $machineName = $listMachine['name'];

                    // We can optimize by reading all 4 registers in one request
                    $request = ReadRegistersBuilder::newReadInputRegisters('tcp://' . $device->ip_address . ':' . $this->modbusPort, $unit_id)
                        ->int16($listMachine['addr_th_l'], 'toe_heel_left')
                        ->int16($listMachine['addr_th_r'], 'toe_heel_right')
                        ->int16($listMachine['addr_side_l'], 'side_left')
                        ->int16($listMachine['addr_side_r'], 'side_right')
                        ->build();

                    $response = (new NonBlockingClient(['readTimeoutSec' => $this->modbusTimeoutSeconds]))
                        ->sendRequests($request)->getData();

                    // Process Left and Right positions together as one machine cycle
                    // $savedReadingsCount += $this->processMachineCycle(
                    //     $line,
                    //     $machineName,
                    //     [
                    //         'L' => ['toe_heel' => $response['toe_heel_left'], 'side' => $response['side_left']],
                    //         'R' => ['toe_heel' => $response['toe_heel_right'], 'side' => $response['side_right']]
                    //     ]
                    // );

                    $responses[] = [
                        'Machine' => $machineName,
                        'L' => ['toe_heel' => $response['toe_heel_left'], 'side' => $response['side_left']],
                        'R' => ['toe_heel' => $response['toe_heel_right'], 'side' => $response['side_right']]
                    ];

                } catch (\Exception $e) {
                    $this->error("    ✗ Error reading machine {$machineName} on line {$line}: " . $e->getMessage());
                    continue;
                }
            }
        }

        return $responses;
    }

    /**
     * MODIFIED: This function now processes L and R positions independently
     * by delegating to the new processPositionCycle method.
     */
    private function processMachineCycle(string $line, string $machineName, array $positionsData)
    {
        $savedCount = 0;

        // Process Left position cycle independently
        $savedCount += $this->processPositionCycle(
            $line,
            $machineName,
            'L',
            $positionsData['L'] // ['toe_heel' => value, 'side' => value]
        );

        // Process Right position cycle independently
        $savedCount += $this->processPositionCycle(
            $line,
            $machineName,
            'R',
            $positionsData['R'] // ['toe_heel' => value, 'side' => value]
        );

        return $savedCount;
    }

    /**
     * Process a single position (L/R) with dual-buffer logic.
     * Starts when either sensor ≥ 10, ends when both ≤ 0.
     * Saves full waveform arrays if both peaks are in [30,45].
     */
    private function processPositionCycle(string $line, string $machineName, string $position, array $data)
    {
        $toeHeelValue = (int) $data['toe_heel'];
        $sideValue = (int) $data['side'];
        $cycleKey = "{$line}-{$machineName}-{$position}";
        $endThreshold = 2;
        $minSamples = 3;

        if (!isset($this->cycleStates[$cycleKey])) {
            $this->cycleStates[$cycleKey] = ['state' => 'idle'];
        }
        $state = &$this->cycleStates[$cycleKey];

        // Failsafe timeout
        if ($state['state'] !== 'idle' && (time() - ($state['start_time'] ?? 0)) > $this->cycleTimeoutSeconds) {
            if ($this->option('d')) $this->warn("Cycle {$cycleKey} timed out.");
            $state = ['state' => 'idle'];
        }

        if ($state['state'] === 'idle') {
            if ($toeHeelValue >= $this->cycleStartThreshold || $sideValue >= $this->cycleStartThreshold) {
                $now = (int) (microtime(true) * 1000);
                $state = [
                    'state' => 'active',
                    'start_time' => time(),
                    'th_buffer' => [['value' => $toeHeelValue, 'ts' => $now]],
                    'side_buffer' => [['value' => $sideValue, 'ts' => $now]],
                    'end_count' => 0,
                ];
                if ($this->option('d')) {
                    $this->line("Cycle started for {$cycleKey}: TH={$toeHeelValue}, Side={$sideValue} @ {$now}");
                }
            }
            return 0;
        }

        if ($state['state'] === 'active') {
            $shouldEnd = false;

            // Debounced end condition
            if ($toeHeelValue <= $endThreshold && $sideValue <= $endThreshold) {
                $state['end_count']++;
                $shouldEnd = $state['end_count'] >= 2;
            } else {
                $now = (int) (microtime(true) * 1000);
                $state['th_buffer'][] = ['value' => $toeHeelValue, 'ts' => $now];
                $state['side_buffer'][] = ['value' => $sideValue, 'ts' => $now];
                $state['end_count'] = 0;
                $shouldEnd = false;
            }

            if ($shouldEnd) {
                if (count($state['th_buffer']) < $minSamples) {
                    if ($this->option('d')) {
                        $this->line("Cycle {$cycleKey} too short (" . count($state['th_buffer']) . " samples). Discarded.");
                    }
                    $saved = $this->saveEnhancedCycle($line, $machineName, $position, $state, 'SHORT_CYCLE');
                    if ($this->option('d')) {
                        $this->line("⚠️ Short cycle saved for {$cycleKey} (" . count($state['th_buffer']) . " samples)");
                    }
                    $state = ['state' => 'idle'];
                    return $saved;
                }

                // Append final zero with timestamp
                $now = (int) (microtime(true) * 1000);
                $state['th_buffer'][] = ['value' => 0, 'ts' => $now];
                $state['side_buffer'][] = ['value' => 0, 'ts' => $now];

                $durationInSeconds = time() - $state['start_time'];
                $saved = $this->saveEnhancedCycle($line, $machineName, $position, $state, 'COMPLETE', $durationInSeconds);

                $state = ['state' => 'idle'];
                return $saved;
            }

            // Prevent buffer overflow
            if (count($state['th_buffer']) > 100) {
                $now = (int) (microtime(true) * 1000);
                $state['th_buffer'][] = ['value' => 0, 'ts' => $now];
                $state['side_buffer'][] = ['value' => 0, 'ts' => $now];
                $saved = $this->saveEnhancedCycle($line, $machineName, $position, $state, 'OVERFLOW');
                $state = ['state' => 'idle'];
                return $saved;
            }

            return 0;
        }

        return 0;
    }

    private function resampleWaveformTimeBased(array $waveform, int $totalDurationSec = 15, int $samplesPerSecond = 1): array
    {
        // Extract timestamps and values
        $ts = array_column($waveform, 'ts');
        $vals = array_column($waveform, 'value');

        if (count($waveform) < 2) {
            // Degenerate case: return constant or padded
            return array_fill(0, $totalDurationSec * $samplesPerSecond, $vals[0] ?? 0);
        }

        $startTs = $ts[0];
        $endTs = $ts[count($ts) - 1];
        $actualDurationSec = ($endTs - $startTs) / 1000.0;

        // Optional: warn if mismatch
        // error_log("Expected $totalDurationSec sec, got " . $actualDurationSec . " sec");

        $output = [];
        $numPoints = $totalDurationSec * $samplesPerSecond; // e.g., 15 for 1 Hz over 15 sec

        for ($i = 0; $i < $numPoints; $i++) {
            // Target time: evenly spaced from start to end (linear in time)
            // We map i ∈ [0, numPoints-1] → t ∈ [startTs, endTs]
            $t = $startTs + ($i / ($numPoints - 1)) * ($endTs - $startTs);

            // Find segment where t falls: ts[j] ≤ t < ts[j+1]
            $j = 0;
            while ($j < count($ts) - 2 && $t >= $ts[$j + 1]) {
                $j++;
            }

            // Clamp to last segment if t == endTs
            if ($t >= $ts[count($ts) - 1]) {
                $output[] = $vals[count($vals) - 1];
                continue;
            }

            $t0 = $ts[$j];
            $t1 = $ts[$j + 1];
            $v0 = $vals[$j];
            $v1 = $vals[$j + 1];

            // Linear interpolation
            if ($t1 == $t0) {
                $value = $v0;
            } else {
                $alpha = ($t - $t0) / ($t1 - $t0);
                $value = $v0 + $alpha * ($v1 - $v0);
            }

            $output[] = (int) round($value);
        }

        return $output;
    }

    private function saveEnhancedCycle(string $line, string $machineName, string $position, array $state, string $cycleType, int $duration = 0)
    {
        $lastCumulative = $this->lastCumulativeValues[$line] ?? 0;
        $newCumulative = $lastCumulative + 1;

        // Extract buffers (already in [{value, ts}, ...] format)
        $thBuffer = $state['th_buffer'] ?? [];
        $sideBuffer = $state['side_buffer'] ?? [];

        // Get peak values
        $maxTh = !empty($thBuffer) ? max(array_column($thBuffer, 'value')) : 0;
        $maxSide = !empty($sideBuffer) ? max(array_column($sideBuffer, 'value')) : 0;

        // Determine quality grade
        $qualityGrade = $this->determineQualityGrade($maxTh, $maxSide, $cycleType);

        // Boolean quality indicators
        $thQuality = ($maxTh >= $this->goodValueMin && $maxTh <= $this->goodValueMax) ? 1 : 0;
        $sideQuality = ($maxSide >= $this->goodValueMin && $maxSide <= $this->goodValueMax) ? 1 : 0;

        // ✅ Build PV with embedded timestamps
        $enhancedPvData = [
            'cycle_type' => $cycleType,
            'waveforms' => [
                'th'   => $this->resampleWaveformTimeBased($thBuffer, $duration, 1),   // [{value:34, ts:1731709388.123}, ...]
                'side' => $this->resampleWaveformTimeBased($sideBuffer, $duration, 1), // same
            ]
        ];

        // STD_ERROR field with boolean array format [[th_quality],[side_quality]]
        $stdErrorBooleanArray = [[$thQuality], [$sideQuality]];

        $count = new InsDwpCount([
            'mechine' => (int) trim($machineName, "mc"),
            'line' => $line,
            'count' => $newCumulative,
            'pv' => json_encode($enhancedPvData, JSON_UNESCAPED_SLASHES),
            'position' => $position,
            'duration' => $duration ?: 1,
            'incremental' => 1,
            'std_error' => json_encode($stdErrorBooleanArray),
        ]);

        $count->save();
        $this->lastCumulativeValues[$line] = $newCumulative;

        // Enhanced logging
        $statusIcon = ($thQuality && $sideQuality) ? '✅' : ($qualityGrade === 'DEFECTIVE' ? '❌' : '⚠️');
        if ($this->option('v')) {
            $this->line("{$statusIcon} Saved {$qualityGrade} cycle for {$line}-{$machineName}-{$position}. " .
                       "Peaks: TH={$maxTh}({$thQuality}), Side={$maxSide}({$sideQuality}). Total: {$newCumulative}");
        }

        return 1;
    }

    private function determineQualityGrade(int $maxTh, int $maxSide, string $cycleType): string
    {
        // Handle special cycle types first
        if ($cycleType === 'SHORT_CYCLE') return 'SHORT_CYCLE';
        if ($cycleType === 'OVERFLOW') return 'OVERFLOW';
        if ($cycleType === 'TIMEOUT') return 'TIMEOUT';

        // Perfect quality range
        if ($maxTh >= $this->goodValueMin && $maxTh <= $this->goodValueMax &&
            $maxSide >= $this->goodValueMin && $maxSide <= $this->goodValueMax) {
            return 'EXCELLENT';
        }

        // Good range (slightly extended)
        $goodMin = $this->goodValueMin - 5; // 25
        $goodMax = $this->goodValueMax + 10; // 55

        if ($maxTh >= $goodMin && $maxTh <= $goodMax &&
            $maxSide >= $goodMin && $maxSide <= $goodMax) {
            return 'GOOD';
        }

        // Marginal - one sensor good, one acceptable
        $marginalMin = 15;
        $marginalMax = 70;

        $thGood = ($maxTh >= $this->goodValueMin && $maxTh <= $this->goodValueMax);
        $sideGood = ($maxSide >= $this->goodValueMin && $maxSide <= $this->goodValueMax);
        $thMarginal = ($maxTh >= $marginalMin && $maxTh <= $marginalMax);
        $sideMarginal = ($maxSide >= $marginalMin && $maxSide <= $marginalMax);

        if (($thGood && $sideMarginal) || ($sideGood && $thMarginal)) {
            return 'MARGINAL';
        }

        // Sensor issues
        if ($maxTh < 10 && $maxSide < 10) return 'SENSOR_LOW';
        if ($maxTh > 80 || $maxSide > 80) return 'PRESSURE_HIGH';

        return 'DEFECTIVE';
    }

    /**
     * Clean up memory by removing old entries and forcing garbage collection
     */
    private function cleanupMemory()
    {
        // This function can also be used to clean up stale cycleStates if needed,
        // but the current logic of unsetting keys should be efficient.

        $activeLines = InsDwpDevice::active()->get()->flatMap(function ($device) {
            return $device->getLines();
        })->unique()->toArray();

        $this->lastCumulativeValues = array_intersect_key(
            $this->lastCumulativeValues,
            array_flip($activeLines)
        );

        // Clean up cycleStates for machines that are no longer active
        // (Though the timeout should handle most of this)
        $activeCycleKeys = InsDwpDevice::active()->get()->flatMap(function ($device) {
            $keys = [];
            foreach ($device->config as $lineConfig) {
                $line = strtoupper(trim($lineConfig['line']));
                foreach($lineConfig['list_mechine'] as $listMachine){
                    $machineName = $listMachine['name'];
                    $keys[] = "{$line}-{$machineName}-L";
                    $keys[] = "{$line}-{$machineName}-R";
                }
            }
            return $keys;
        })->unique()->toArray();

        $this->cycleStates = array_intersect_key(
            $this->cycleStates,
            array_flip($activeCycleKeys)
        );


        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        if ($this->option('d')) {
            $memoryUsage = memory_get_usage(true);
            $this->line("Memory cleanup performed. Current usage: " . number_format($memoryUsage / 1024 / 1024, 2) . " MB");
        }
    }

    /**
     * Update device statistics
     */
    private function updateDeviceStats(string $deviceName, bool $success)
    {
        if (!isset($this->deviceStats[$deviceName])) {
            $this->deviceStats[$deviceName] = ['success_count' => 0, 'error_count' => 0, 'last_success' => null, 'last_error' => null];
        }

        if ($success) {
            $this->deviceStats[$deviceName]['success_count']++;
            $this->deviceStats[$deviceName]['last_success'] = now();
        } else {
            $this->deviceStats[$deviceName]['error_count']++;
            $this->deviceStats[$deviceName]['last_error'] = now();
        }

        if ($this->option('v') && $this->pollCycleCount > 0 && $this->pollCycleCount % 100 === 0) {
            $stats = $this->deviceStats[$deviceName];
            $total = $stats['success_count'] + $stats['error_count'];
            $successRate = $total > 0 ? round(($stats['success_count'] / $total) * 100, 1) : 0;

            $this->comment("Device {$deviceName} stats: {$successRate}% success rate ({$stats['success_count']}/{$total})");
        }
    }
}
