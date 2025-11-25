<?php

namespace App\Console\Commands;

use App\Models\InsDwpDevice;
use App\Models\InsDwpCount;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use ModbusTcpClient\Composer\Read\ReadRegistersBuilder;
use ModbusTcpClient\Network\NonBlockingClient;

class InsDwpPollTest extends Command
{
    // Configuration variables
    protected $pollIntervalSeconds = 1;
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
    protected $signature = 'app:ins-dwp-poll-test {--v : Verbose output} {--d : Debug output}';

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

        $this->info('✓ InsDwpPoll started - monitoring ' . count($devices) . ' devices');
        if ($this->option('v')) {
            $this->comment('Devices:');
            foreach ($devices as $device) {
                $lines = implode(', ', $device->getLines());
                $this->comment("  → {$device->name} ({$device->ip_address}) - Lines: {$lines}");
            }
        }

        $this->initializeLastValues($devices);

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
                    // $cycleReadings += $readings;
                    // $this->updateDeviceStats($device->name, true);
                } catch (\Throwable $th) {
                    $this->error("✗ Error polling {$device->name} ({$device->ip_address}): " . $th->getMessage(). $th->getLine());
                    $cycleErrors++;
                    $this->updateDeviceStats($device->name, false);
                }
            }

            $this->totalReadings += $cycleReadings;
            $this->totalErrors += $cycleErrors;

            if ($this->option('v') && ($cycleReadings > 0 || $cycleErrors > 0)) {
                $cycleTime = microtime(true) - $cycleStartTime;
                $this->info("Cycle #{$this->pollCycleCount}: {$cycleReadings} new readings saved, {$cycleErrors} errors, " .
                            number_format($cycleTime * 1000, 2) . "ms");
            }

            sleep($this->pollIntervalSeconds);
            $this->pollCycleCount++;

            if ($this->pollCycleCount % $this->memoryCleanupInterval === 0) {
                $this->cleanupMemory();
            }
        }
    }

    /**
     * Initialize last cumulative values from database
     */
    private function initializeLastValues($devices)
    {
        foreach ($devices as $device) {
            foreach ($device->getLines() as $line) {
                $lastCount = InsDwpCount::latestForLine($line);
                $this->lastCumulativeValues[$line] = $lastCount ? $lastCount->count : 0;
                if ($this->option('d')) {
                    $this->line("Initialized line {$line} with last cumulative: {$this->lastCumulativeValues[$line]}");
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
        $responses = [];

        foreach ($device->config as $lineConfig) {
            $line = strtoupper(trim($lineConfig['line']));
            foreach ($lineConfig['list_mechine'] as $listMachine) {
                $machineName = $listMachine['name'];
                $ip = $device->ip_address;
                try {
                    // Build separate requests for each metric (like InsStcPoll)
                    $th_l_req = ReadRegistersBuilder::newReadInputRegisters("tcp://{$ip}:{$this->modbusPort}", $unit_id)
                        ->int16($listMachine['addr_th_l'], 'toe_heel_left')->build();
                    $th_r_req = ReadRegistersBuilder::newReadInputRegisters("tcp://{$ip}:{$this->modbusPort}", $unit_id)
                        ->int16($listMachine['addr_th_r'], 'toe_heel_right')->build();
                    $side_l_req = ReadRegistersBuilder::newReadInputRegisters("tcp://{$ip}:{$this->modbusPort}", $unit_id)
                        ->int16($listMachine['addr_side_l'], 'side_left')->build();
                    $side_r_req = ReadRegistersBuilder::newReadInputRegisters("tcp://{$ip}:{$this->modbusPort}", $unit_id)
                        ->int16($listMachine['addr_side_r'], 'side_right')->build();

                    $th_l_resp = (new NonBlockingClient(['readTimeoutSec' => $this->modbusTimeoutSeconds]))->sendRequests($th_l_req)->getData();
                    $th_r_resp = (new NonBlockingClient(['readTimeoutSec' => $this->modbusTimeoutSeconds]))->sendRequests($th_r_req)->getData();
                    $side_l_resp = (new NonBlockingClient(['readTimeoutSec' => $this->modbusTimeoutSeconds]))->sendRequests($side_l_req)->getData();
                    $side_r_resp = (new NonBlockingClient(['readTimeoutSec' => $this->modbusTimeoutSeconds]))->sendRequests($side_r_req)->getData();

                    // Merge all metric data into one array
                    $data = array_merge(
                        $th_l_resp,
                        $th_r_resp,
                        $side_l_resp,
                        $side_r_resp
                    );

                    $positionsData = [
                        'L' => [
                            'toe_heel' => $data['toe_heel_left'] ?? 0,
                            'side' => $data['side_left'] ?? 0,
                        ],
                        'R' => [
                            'toe_heel' => $data['toe_heel_right'] ?? 0,
                            'side' => $data['side_right'] ?? 0,
                        ],
                    ];
                    $savedCount = $this->processMachineCycle($line, $machineName, $positionsData);
                } catch (\Throwable $e) {
                    $this->error("    ✗ Error reading machine {$machineName} on line {$line}: " . $e->getMessage());
                    continue;
                }
            }
        }

        return $savedCount;
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
     * Process a single position (L/R) with change-of-state sampling.
     * Samples are recorded only when the value changes, using seconds as timestamp.
     * Starts when either sensor ≥ 10, ends when both ≤ 2 for 2 consecutive polls.
     */
    private function processPositionCycle(string $line, string $machineName, string $position, array $data)
    {
        $toeHeelValue = (int) $data['toe_heel'];
        $sideValue = (int) $data['side'];
        $cycleKey = "{$line}-{$machineName}-{$position}";
        $endThreshold = 0;
        $minSamples = 3;

        // Initialize state if not exists
        if (!isset($this->cycleStates[$cycleKey])) {
            $this->cycleStates[$cycleKey] = [
                'state' => 'idle',
                'last_saved_th_value' => null, // Track last saved TH value
                'last_saved_side_value' => null, // Track last saved Side value
                'last_saved_th_time' => null, // Track time of last saved TH sample
                'last_saved_side_time' => null, // Track time of last saved Side sample
            ];
        }
        $state = &$this->cycleStates[$cycleKey];

        // Failsafe timeout
        if ($state['state'] !== 'idle' && (time() - ($state['start_time'] ?? 0)) > $this->cycleTimeoutSeconds) {
            if ($this->option('d')) $this->warn("Cycle {$cycleKey} timed out.");
            $state = ['state' => 'idle']; // Reset state completely
            return 0;
        }

        if ($state['state'] === 'idle') {
            if ($toeHeelValue >= $this->cycleStartThreshold || $sideValue >= $this->cycleStartThreshold) {
                $now = time(); // Use seconds for simplicity
                $state = [
                    'state' => 'active',
                    'start_time' => $now,
                    'th_buffer' => [['value' => $toeHeelValue, 'ts' => $now]], // Start with current value
                    'side_buffer' => [['value' => $sideValue, 'ts' => $now]], // Start with current value
                    'end_count' => 0,
                    'last_saved_th_value' => $toeHeelValue, // Initialize tracking
                    'last_saved_side_value' => $sideValue,
                    'last_saved_th_time' => $now,
                    'last_saved_side_time' => $now,
                ];
                if ($this->option('d')) {
                    $this->line("Cycle started for {$cycleKey}: TH={$toeHeelValue}, Side={$sideValue} @ {$now}");
                }
            }
            return 0;
        }

        if ($state['state'] === 'active') {
            $shouldEnd = false;

            // Debounced end condition (both sensors <= threshold for 2 polls)
            if ($toeHeelValue <= $endThreshold && $sideValue <= $endThreshold) {
                $state['end_count']++;
                $shouldEnd = $state['end_count'] >= 2;
            } else {
                $now = time(); // Current time in seconds

                // Check for Toe/Heel change
                if ($toeHeelValue !== $state['last_saved_th_value']) {
                    $state['th_buffer'][] = ['value' => $toeHeelValue, 'ts' => $now];
                    $state['last_saved_th_value'] = $toeHeelValue;
                    $state['last_saved_th_time'] = $now;
                }

                // Check for Side change
                if ($sideValue !== $state['last_saved_side_value']) {
                    $state['side_buffer'][] = ['value' => $sideValue, 'ts' => $now];
                    $state['last_saved_side_value'] = $sideValue;
                    $state['last_saved_side_time'] = $now;
                }

                $state['end_count'] = 0; // Reset counter if not at end
                $shouldEnd = false;
            }

            if ($shouldEnd) {
                // Ensure we have enough samples
                if (count($state['th_buffer']) < $minSamples) {
                    if ($this->option('d')) {
                        $this->line("Cycle {$cycleKey} too short (" . count($state['th_buffer']) . " samples). Discarded.");
                    }
                    $saved = $this->saveEnhancedCycle($line, $machineName, $position, $state, 'SHORT_CYCLE');
                    if ($this->option('d')) {
                        $this->line("⚠️ Short cycle saved for {$cycleKey} (" . count($state['th_buffer']) . " samples)");
                    }
                    $state = ['state' => 'idle']; // Reset state
                    return $saved;
                }

                // Append final zero ONLY if different from last saved value
                $now = time();
                $finalThValue = 0;
                $finalSideValue = 0;

                if ($finalThValue !== $state['last_saved_th_value']) {
                    $state['th_buffer'][] = ['value' => $finalThValue, 'ts' => $now];
                }
                if ($finalSideValue !== $state['last_saved_side_value']) {
                    $state['side_buffer'][] = ['value' => $finalSideValue, 'ts' => $now];
                }

                $durationInSeconds = time() - $state['start_time'];
                $saved = $this->saveEnhancedCycle($line, $machineName, $position, $state, 'COMPLETE', $durationInSeconds);

                $state = ['state' => 'idle']; // Reset state
                return $saved;
            }

            // Prevent buffer overflow (optional, can be removed if not needed)
            if (count($state['th_buffer']) > 100) {
                $now = time();
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

    private function resampleAndRepeatWaveform(array $waveform, int $totalDurationSec = 100): array
    {
        if (count($waveform) < 2) {
            return array_fill(0, $totalDurationSec, $waveform[0]['value'] ?? 0);
        }

        // Extract timestamps and values
        $ts = array_column($waveform, 'ts'); // Now in SECONDS
        $vals = array_column($waveform, 'value');

        $startTs = $ts[0];
        $endTs = $ts[count($ts) - 1];
        $cycleDurationSec = $endTs - $startTs; // Already in seconds

        $output = [];

        for ($sec = 0; $sec < $totalDurationSec; $sec++) {
            // Map current second to position within cycle
            $timeInCycleSec = fmod($sec, $cycleDurationSec); // 0 to cycleDurationSec
            $absoluteTimeSec = $startTs + $timeInCycleSec;

            // Find which segment of the cycle we're in
            $j = 0;
            while ($j < count($ts) - 2 && $absoluteTimeSec >= $ts[$j + 1]) {
                $j++;
            }

            // If at end of cycle, use last value
            if ($absoluteTimeSec >= $ts[count($ts) - 1]) {
                $output[] = $vals[count($vals) - 1];
                continue;
            }

            // Linear interpolation between ts[j] and ts[j+1]
            $t0 = $ts[$j];
            $t1 = $ts[$j + 1];
            $v0 = $vals[$j];
            $v1 = $vals[$j + 1];

            if ($t1 == $t0) {
                $value = $v0;
            } else {
                $alpha = ($absoluteTimeSec - $t0) / ($t1 - $t0);
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

        // Calculate actual duration from timestamps in buffer
        $actualDuration = 0;
        if (!empty($state['th_buffer'])) {
            $firstTs = $state['th_buffer'][0]['ts'];
            $lastTs = $state['th_buffer'][count($state['th_buffer']) - 1]['ts'];
            $actualDuration = max(1, $lastTs - $firstTs); // Ensure at least 1 second
        }

        // Use actual duration from timestamps
        $targetDuration = $actualDuration;

        // Extract buffers and resample to actual duration
        $thBuffer = $this->resampleAndRepeatWaveform($state['th_buffer'], $targetDuration) ?? [];
        $sideBuffer = $this->resampleAndRepeatWaveform($state['side_buffer'], $targetDuration) ?? [];

        // Get peak values
        $maxTh = !empty($thBuffer) ? max($thBuffer) : 0;
        $maxSide = !empty($sideBuffer) ? max($sideBuffer) : 0;

        // Determine quality grade
        $qualityGrade = $this->determineQualityGrade($maxTh, $maxSide, $cycleType);

        // Boolean quality indicators
        $thQuality = ($maxTh >= $this->goodValueMin && $maxTh <= $this->goodValueMax) ? 1 : 0;
        $sideQuality = ($maxSide >= $this->goodValueMin && $maxSide <= $this->goodValueMax) ? 1 : 0;

        // ✅ Build PV with embedded timestamps
        $enhancedPvData = [
            'cycle_type' => $cycleType,
            'waveforms' => [
                'th'   => $thBuffer,
                'side' => $sideBuffer,
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
            'duration' => $targetDuration, // Use actual duration
            'incremental' => 1,
            'std_error' => json_encode($stdErrorBooleanArray),
        ]);

        $count->save();
        $this->lastCumulativeValues[$line] = $newCumulative;

        // Enhanced logging
        $statusIcon = ($thQuality && $sideQuality) ? '✅' : ($qualityGrade === 'DEFECTIVE' ? '❌' : '⚠️');
        if ($this->option('v')) {
            $this->line("{$statusIcon} Saved {$qualityGrade} cycle for {$line}-{$machineName}-{$position}. " .
                       "Peaks: TH={$maxTh}({$thQuality}), Side={$maxSide}({$sideQuality}). Duration: {$targetDuration}s. Total: {$newCumulative}");
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
