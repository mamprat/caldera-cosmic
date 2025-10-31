<?php

namespace App\Console\Commands;

use App\Models\InsDwpDevice;
use App\Models\InsDwpCount;
use Carbon\Carbon;
use Illuminate\Console\Command;
use ModbusTcpClient\Composer\Read\ReadRegistersBuilder;
use ModbusTcpClient\Network\NonBlockingClient;

class InsDwpPoll extends Command
{
    // Configuration variables
    protected $pollIntervalSeconds = 1;
    protected $modbusTimeoutSeconds = 2;
    protected $modbusPort = 503;

    // Cycle detection configuration
    protected $cycleStartThreshold = 10; // Value to detect the start of a cycle (now used by 'capturing' state)
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
    protected $signature = 'app:ins-dwp-poll {--v : Verbose output} {--d : Debug output}';

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
                    $cycleReadings += $readings;
                    $this->updateDeviceStats($device->name, true);
                } catch (\Throwable $th) {
                    $this->error("✗ Error polling {$device->name} ({$device->ip_address}): " . $th->getMessage());
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
        $savedReadingsCount = 0;

        foreach ($device->config as $lineConfig) {
            $line = strtoupper(trim($lineConfig['line']));
            
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
                    $savedReadingsCount += $this->processMachineCycle(
                        $line, 
                        $machineName,
                        [
                            'L' => ['toe_heel' => $response['toe_heel_left'], 'side' => $response['side_left']],
                            'R' => ['toe_heel' => $response['toe_heel_right'], 'side' => $response['side_right']]
                        ]
                    );

                } catch (\Exception $e) {
                    $this->error("    ✗ Error reading machine {$machineName} on line {$line}: " . $e->getMessage());
                    continue;
                }
            }
        }
        
        return $savedReadingsCount;
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

        // Initialize or load state
        if (!isset($this->cycleStates[$cycleKey])) {
            $this->cycleStates[$cycleKey] = ['state' => 'idle'];
        }
        $state = &$this->cycleStates[$cycleKey]; // reference for in-place update

        // Failsafe timeout
        if ($state['state'] !== 'idle' && (time() - ($state['start_time'] ?? 0)) > $this->cycleTimeoutSeconds) {
            if ($this->option('d')) {
                $this->warn("Cycle {$cycleKey} timed out. Discarding.");
            }
            $state = ['state' => 'idle'];
        }

        // ----------------------------
        // STATE: IDLE
        // ----------------------------
        if ($state['state'] === 'idle') {
            // Start cycle if EITHER sensor crosses threshold (≥10)
            if ($toeHeelValue >= $this->cycleStartThreshold || $sideValue >= $this->cycleStartThreshold) {
                $state = [
                    'state' => 'active',
                    'start_time' => time(),
                    'th_buffer' => [$toeHeelValue],
                    'side_buffer' => [$sideValue],
                ];
                if ($this->option('d')) {
                    $this->line("Cycle started for {$cycleKey}: TH={$toeHeelValue}, Side={$sideValue}");
                }
            }
            return 0; // nothing saved yet
        }

        // ----------------------------
        // STATE: ACTIVE (buffering)
        // ----------------------------
        if ($state['state'] === 'active') {
            // Append current values
            $state['th_buffer'][] = $toeHeelValue;
            $state['side_buffer'][] = $sideValue;

            // Check if cycle should end: both signals ≤ 0 (or very low)
            $endThreshold = 0; // or 2 if you want hysteresis
            $shouldEnd = ($toeHeelValue <= $endThreshold && $sideValue <= $endThreshold);

            if ($shouldEnd) {
                // Extract peaks
                $maxTh = max($state['th_buffer']);
                $maxSide = max($state['side_buffer']);

                // Validate: both peaks must be in [30, 45]
                $isValid = (
                    $maxTh >= $this->goodValueMin && $maxTh <= $this->goodValueMax &&
                    $maxSide >= $this->goodValueMin && $maxSide <= $this->goodValueMax
                );

                if ($isValid) {
                    // Save full buffers as pv
                    $collectedData = [
                        $state['th_buffer'],
                        $state['side_buffer']
                    ];

                    // Save to DB (returns 1 on success)
                    $saved = $this->saveSuccessfulCycle($line, $machineName, $position, $collectedData, 0);

                    if ($this->option('d')) {
                        $this->line("✅ Valid cycle saved for {$cycleKey}. TH peak: {$maxTh}, Side peak: {$maxSide}");
                    }

                    // Reset state
                    $state = ['state' => 'idle'];
                    return $saved;
                } else {
                    // Invalid cycle (e.g., noise, partial press)
                    if ($this->option('d')) {
                        $this->line("❌ Invalid cycle for {$cycleKey}. TH peak: {$maxTh}, Side peak: {$maxSide} → discarded.");
                    }
                    $state = ['state' => 'idle'];
                    return 0;
                }
            }

            // Optional: prevent infinite buffering (e.g., max 100 samples)
            $maxBufferSize = 100;
            if (count($state['th_buffer']) > $maxBufferSize) {
                if ($this->option('d')) {
                    $this->warn("Buffer overflow for {$cycleKey}. Resetting.");
                }
                $state = ['state' => 'idle'];
            }

            return 0; // still buffering
        }

        return 0;
    }


    private function saveSuccessfulCycle(string $line, string $machineName, string $position, array $collectedData, int $duration)
    {
        $lastCumulative = $this->lastCumulativeValues[$line] ?? 0;
        $newCumulative = $lastCumulative + 1;

        $count = new InsDwpCount([
            'mechine' => (int) trim($machineName, "mc"),
            'line' => $line,
            'count' => $newCumulative,
            'pv' => json_encode($collectedData), // e.g., [[10,20,34,...], [10,15,30,...]]
            'position' => $position,
            'duration' => $duration,
            'incremental' => 1,
            'std_error' => json_encode([0, 0]),
        ]);
        $count->save();

        $this->lastCumulativeValues[$line] = $newCumulative;

        if ($this->option('v')) {
            $thPeak = max($collectedData[0]);
            $sidePeak = max($collectedData[1]);
            $this->line("✓ Saved cycle for {$line}-{$machineName}-{$position}. TH peak: {$thPeak}, Side peak: {$sidePeak}. Total: {$newCumulative}");
        }

        return 1;
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