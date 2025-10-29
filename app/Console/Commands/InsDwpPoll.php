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
    protected $goodValueMax = 40;        // Max value for a good reading
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
     * NEW: Core state machine logic for a *single position* (L or R).
     * This logic implements your requested flow:
     * 1. Wait for a "good" value.
     * 2. When a "good" value is seen, save it immediately.
     * 3. Change state to 'capturing' to avoid saving more data.
     * 4. Wait for the value to return to 0 (or timeout) to reset the state.
     */
    private function processPositionCycle(string $line, string $machineName, string $position, array $data)
    {
        $toeHeelValue = $data['toe_heel'];
        $sideValue = $data['side'];
        $cycleKey = "{$line}-{$machineName}-{$position}";

        $currentState = $this->cycleStates[$cycleKey] ?? ['state' => 'idle'];
        $savedCount = 0;

        // Failsafe timeout logic
        if ($currentState['state'] === 'capturing' && (time() - $currentState['start_time']) > $this->cycleTimeoutSeconds) {
            if ($this->option('d')) {
                $this->warn("Cycle for {$cycleKey} timed out. Resetting to 'idle'.");
            }
            $this->cycleStates[$cycleKey] = ['state' => 'idle'];
            $currentState = $this->cycleStates[$cycleKey]; // Update local var
        }

        // STATE: IDLE -> Looking for a good value
        if ($currentState['state'] === 'idle') {
            
            // ---
            // **THIS IS THE CORRECTED LOGIC**
            // We now check that toe_heel is in the good range (30-40)
            // AND that side is also active (e.g., above the start threshold of 10)
            // ---
            if (
                ($toeHeelValue >= $this->goodValueMin && $toeHeelValue <= $this->goodValueMax) &&
                ($sideValue >= $this->cycleStartThreshold) 
            ) {
                
                // --- SAVE TO DATABASE ---
                // Both values are good, save them.
                $collectedData = [
                    [$toeHeelValue], // toe_heel_values
                    [$sideValue]     // side_values
                ];

                // Save with a duration of 0
                $savedCount = $this->saveSuccessfulCycle($line, $machineName, $position, $collectedData, 0);

                // --- CHANGE STATE ---
                // Move to 'capturing' state to prevent saving again until this cycle ends.
                $this->cycleStates[$cycleKey] = [
                    'state' => 'capturing',
                    'start_time' => time()
                ];

                if ($this->option('d')) {
                    $this->line("Valid peak found for {$cycleKey}. Toe/Heel: {$toeHeelValue}, Side: {$sideValue}. Saving and moving to 'capturing'.");
                }
            }
        } 
        
        // STATE: CAPTURING -> Waiting for cycle to end
        elseif ($currentState['state'] === 'capturing') {
            
            // Wait for BOTH values to drop to 0 to be safe
            if ($toeHeelValue <= $this->toeHeelEndThreshold && $sideValue <= $this->sideEndThreshold) {
                
                // Cycle ended. Reset to 'idle' to look for the next peak.
                $this->cycleStates[$cycleKey] = ['state' => 'idle'];
                
                if ($this->option('d')) {
                    $this->line("Cycle ended for {$cycleKey}. Resetting to 'idle'.");
                }
            }
        }

        return $savedCount;
    }


    /**
     * Function to handle database insertion and incrementing counts
     * Returns 1 if saved successfully, 0 otherwise
     * NO CHANGES NEEDED HERE - This function works with the new logic.
     */
    private function saveSuccessfulCycle(string $line, string $machineName, string $position, array $collectedData, int $duration)
    {
        // 1. Get the current cumulative count and increment it for the new record.
        $lastCumulative = $this->lastCumulativeValues[$line] ?? 0;
        $newCumulative = $lastCumulative + 1;

        // 2. Prepare data and save to database.
        $count = new InsDwpCount([
            'mechine' => (int) trim($machineName, "mc"),
            'line' => $line,
            'count' => $newCumulative, // The new total count
            'pv' => json_encode($collectedData), // Store the collected arrays
            'position' => $position,
            'duration' => $duration, // Will be 0 with the new logic
            'incremental' => 1, // This represents one successful cycle
            'std_error' => json_encode([0,0]),
        ]);
        $count->save();

        // 3. Update the in-memory cumulative value for the next cycle.
        $this->lastCumulativeValues[$line] = $newCumulative;

        if ($this->option('v')) {
            $toeHeelValues = $collectedData[0];
            $peakValue = max($toeHeelValues); // This will just be the single value we saved
            $this->line("✓ Saved good cycle for {$line}-{$machineName}-{$position}. Peak: {$peakValue}. New total count: {$newCumulative}");
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