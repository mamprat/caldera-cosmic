<?php

/**
 * Real-time DWP Press Monitoring Simulation
 *
 * Simulates actual press operations with:
 * - 4 machines running simultaneously
 * - 15-20 second cycle times (actual 16 seconds)
 * - Variable L/R position gap timing (2-8 seconds)
 * - Real-time quality monitoring
 * - Live production dashboard
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Bootstrap Laravel application
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\InsDwpCount;
use Carbon\Carbon;

class RealtimePressMonitor
{
    private $machines = [1, 2, 3, 4];
    private $positions = ['L', 'R'];
    private $line = 'LINEA';
    private $isRunning = true;

    // Press timing configuration
    private $baseCycleTime = 16; // seconds
    private $cycleVariation = [-1, 0, 1, 2, 3, 4]; // variation in seconds
    private $positionGapRange = [2, 8]; // L/R gap in seconds

    // Machine states
    private $machineStates = [];
    private $lastCumulativeCount = 0;

    // Quality patterns (realistic manufacturing)
    private $qualityPatterns = [
        'EXCELLENT' => 0.65,
        'GOOD' => 0.25,
        'MARGINAL' => 0.07,
        'DEFECTIVE' => 0.02,
        'SENSOR_ISSUES' => 0.01
    ];

    public function __construct()
    {
        $this->initializeMachineStates();
        $this->lastCumulativeCount = InsDwpCount::where('line', $this->line)->max('count') ?? 0;
    }

    public function startMonitoring()
    {
        $this->printHeader();
        $this->setupSignalHandlers();

        echo "🔴 Starting real-time press monitoring simulation...\n";
        echo "Press Ctrl+C to stop\n\n";

        $startTime = microtime(true);
        $cycleCount = 0;

        while ($this->isRunning) {
            $currentTime = microtime(true);

            // Update all machine states
            foreach ($this->machines as $machine) {
                $this->updateMachineState($machine, $currentTime);
            }

            // Display live status every 2 seconds
            if ((int)$currentTime % 2 == 0) {
                $this->displayLiveStatus($currentTime - $startTime, $cycleCount);
            }

            usleep(100000); // 100ms update interval
        }

        $this->printShutdown();
    }

    private function initializeMachineStates()
    {
        foreach ($this->machines as $machine) {
            $this->machineStates[$machine] = [
                'L' => ['state' => 'idle', 'next_start' => microtime(true) + rand(1, 10)],
                'R' => ['state' => 'idle', 'next_start' => null],
                'current_cycle_time' => 16,
                'cycles_completed' => 0,
                'quality_stats' => ['excellent' => 0, 'good' => 0, 'marginal' => 0, 'defective' => 0],
                'last_cycle' => null
            ];
        }
    }

    private function updateMachineState($machine, $currentTime)
    {
        $machineState = &$this->machineStates[$machine];

        foreach ($this->positions as $position) {
            $positionState = &$machineState[$position];

            switch ($positionState['state']) {
                case 'idle':
                    if (isset($positionState['next_start']) && $currentTime >= $positionState['next_start']) {
                        $this->startPresscycle($machine, $position, $currentTime);
                    }
                    break;

                case 'pressing':
                    if ($currentTime >= $positionState['end_time']) {
                        $this->completePressycle($machine, $position, $currentTime);
                    }
                    break;
            }
        }
    }

    private function startPressycle($machine, $position, $currentTime)
    {
        $machineState = &$this->machineStates[$machine];
        $positionState = &$machineState[$position];

        // Determine actual cycle time (15-20 seconds, mostly 16)
        $cycleVariation = $this->cycleVariation[array_rand($this->cycleVariation)];
        $actualCycleTime = $this->baseCycleTime + $cycleVariation;
        $machineState['current_cycle_time'] = $actualCycleTime;

        // Start the press cycle
        $positionState['state'] = 'pressing';
        $positionState['start_time'] = $currentTime;
        $positionState['end_time'] = $currentTime + $actualCycleTime;
        $positionState['quality_type'] = $this->determineQualityType();

        // If this is L position, schedule R position with gap
        if ($position == 'L') {
            $gapTime = rand($this->positionGapRange[0], $this->positionGapRange[1]);
            $machineState['R']['next_start'] = $currentTime + $gapTime;
        }

        $this->logEvent("M{$machine}-{$position}: Started press cycle ({$actualCycleTime}s) - {$positionState['quality_type']}");
    }

    private function completePressycle($machine, $position, $currentTime)
    {
        $machineState = &$this->machineStates[$machine];
        $positionState = &$machineState[$position];

        // Generate cycle data
        $cycleData = $this->generateCycleData($machine, $position, $positionState['quality_type'], $machineState['current_cycle_time']);

        // Save to database
        $this->saveCycleToDatabase($machine, $position, $cycleData, $currentTime - $positionState['start_time']);

        // Update statistics
        $machineState['cycles_completed']++;
        $qualityGrade = strtolower($positionState['quality_type']);
        if (isset($machineState['quality_stats'][$qualityGrade])) {
            $machineState['quality_stats'][$qualityGrade]++;
        }
        $machineState['last_cycle'] = $currentTime;

        // Reset position state
        $positionState['state'] = 'idle';

        // Schedule next cycle (only for L position, R follows with gap)
        if ($position == 'L') {
            $nextCycleDelay = rand(1, 4); // 1-4 seconds between cycles
            $positionState['next_start'] = $currentTime + $nextCycleDelay;
        } else {
            $positionState['next_start'] = null; // R position waits for L to schedule it
        }

        $this->logEvent("M{$machine}-{$position}: Completed press cycle - {$positionState['quality_type']} - Total: {$machineState['cycles_completed']}");
    }

    private function determineQualityType()
    {
        $rand = mt_rand() / mt_getrandmax();
        $cumulative = 0;

        foreach ($this->qualityPatterns as $type => $probability) {
            $cumulative += $probability;
            if ($rand <= $cumulative) {
                return $type;
            }
        }

        return 'EXCELLENT';
    }

    private function generateCycleData($machine, $position, $qualityType, $actualCycleTime)
    {
        // Generate sensor peaks based on quality
        switch ($qualityType) {
            case 'EXCELLENT':
                $thPeak = rand(32, 42);
                $sidePeak = rand(32, 42);
                break;
            case 'GOOD':
                $thPeak = rand(30, 45);
                $sidePeak = rand(30, 45);
                break;
            case 'MARGINAL':
                if (rand(0, 1)) {
                    $thPeak = rand(30, 45);
                    $sidePeak = rand(20, 29);
                } else {
                    $thPeak = rand(46, 55);
                    $sidePeak = rand(30, 45);
                }
                break;
            case 'SENSOR_ISSUES':
                $thPeak = rand(5, 15);
                $sidePeak = rand(5, 15);
                break;
            default: // DEFECTIVE
                $thPeak = rand(10, 25);
                $sidePeak = rand(50, 75);
        }

        // Generate realistic press waveforms
        $waveforms = $this->generatePressWaveforms($thPeak, $sidePeak, $qualityType, $actualCycleTime);

        // Quality indicators
        $thQuality = ($thPeak >= 30 && $thPeak <= 45) ? 1 : 0;
        $sideQuality = ($sidePeak >= 30 && $sidePeak <= 45) ? 1 : 0;

        return [
            'th_peak' => $thPeak,
            'side_peak' => $sidePeak,
            'th_quality' => $thQuality,
            'side_quality' => $sideQuality,
            'waveforms' => $waveforms,
            'quality_type' => $qualityType,
            'actual_cycle_time' => $actualCycleTime
        ];
    }

    private function generatePressWaveforms($thPeak, $sidePeak, $qualityType, $actualCycleTime)
    {
        $length = 30;
        $thWaveform = $this->createPressWaveform($thPeak, $qualityType);
        $sideWaveform = $this->createPressWaveform($sidePeak, $qualityType);

        return [$thWaveform, $sideWaveform];
    }

    private function createPressWaveform($peak, $qualityType)
    {
        $length = 30;
        $waveform = [];

        // Press phases: ramp up -> peak -> release
        $rampUp = (int)($length * 0.3);
        $peakPhase = (int)($length * 0.4);
        $release = $length - $rampUp - $peakPhase;

        for ($i = 0; $i < $length; $i++) {
            if ($i < $rampUp) {
                $progress = $i / $rampUp;
                $value = $peak * $progress * $progress;
                $noise = ($qualityType == 'EXCELLENT') ? rand(-1, 1) : rand(-3, 3);
            } elseif ($i < $rampUp + $peakPhase) {
                $value = $peak;
                $noise = ($qualityType == 'EXCELLENT') ? rand(-2, 2) : rand(-8, 8);
            } else {
                $releaseProgress = ($i - $rampUp - $peakPhase) / $release;
                $value = $peak * (1 - $releaseProgress * $releaseProgress);
                $noise = ($qualityType == 'EXCELLENT') ? rand(-1, 1) : rand(-5, 5);
            }

            $waveform[] = max(0, (int)($value + $noise));
        }

        return $waveform;
    }

    private function saveCycleToDatabase($machine, $position, $cycleData, $actualDuration)
    {
        $this->lastCumulativeCount++;

        $pvData = [
            'waveforms' => $cycleData['waveforms'],
            'quality' => [
                'grade' => $cycleData['quality_type'],
                'peaks' => ['th' => $cycleData['th_peak'], 'side' => $cycleData['side_peak']],
                'cycle_type' => 'COMPLETE',
                'sample_count' => 30,
                'actual_cycle_time' => $cycleData['actual_cycle_time']
            ]
        ];

        $stdError = [[$cycleData['th_quality']], [$cycleData['side_quality']]];

        InsDwpCount::create([
            'mechine' => $machine,
            'line' => $this->line,
            'count' => $this->lastCumulativeCount,
            'pv' => json_encode($pvData),
            'position' => $position,
            'duration' => (int)$actualDuration,
            'incremental' => 1,
            'std_error' => json_encode($stdError),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function displayLiveStatus($runTime, $cycleCount)
    {
        $this->clearScreen();
        $this->printHeader();

        echo "🔴 LIVE PRODUCTION MONITORING - Runtime: " . $this->formatTime($runTime) . "\n";
        echo "Line: {$this->line} | Total Cycles: {$this->lastCumulativeCount}\n";
        echo str_repeat("=", 80) . "\n\n";

        // Machine status display
        foreach ($this->machines as $machine) {
            $this->displayMachineStatus($machine);
        }

        echo "\n" . str_repeat("=", 80) . "\n";
        $this->displayOverallStats();
        echo "\n";
    }

    private function displayMachineStatus($machine)
    {
        $state = $this->machineStates[$machine];
        $lState = $state['L']['state'];
        $rState = $state['R']['state'];

        // Status indicators
        $lStatus = $lState == 'pressing' ? '🔴 PRESSING' : '⚫ IDLE';
        $rStatus = $rState == 'pressing' ? '🔴 PRESSING' : '⚫ IDLE';

        // Progress bars for active cycles
        $lProgress = '';
        $rProgress = '';
        $currentTime = microtime(true);

        if ($lState == 'pressing') {
            $elapsed = $currentTime - $state['L']['start_time'];
            $total = $state['L']['end_time'] - $state['L']['start_time'];
            $percent = min(100, ($elapsed / $total) * 100);
            $bars = (int)($percent / 5);
            $lProgress = ' [' . str_repeat('█', $bars) . str_repeat('░', 20 - $bars) . '] ' . (int)$percent . '%';
        }

        if ($rState == 'pressing') {
            $elapsed = $currentTime - $state['R']['start_time'];
            $total = $state['R']['end_time'] - $state['R']['start_time'];
            $percent = min(100, ($elapsed / $total) * 100);
            $bars = (int)($percent / 5);
            $rProgress = ' [' . str_repeat('█', $bars) . str_repeat('░', 20 - $bars) . '] ' . (int)$percent . '%';
        }

        // Quality stats
        $totalCycles = array_sum($state['quality_stats']);
        $excellentRate = $totalCycles > 0 ? round(($state['quality_stats']['excellent'] / $totalCycles) * 100, 1) : 0;

        echo sprintf("🔧 Machine %d | Cycles: %3d | Quality: %5.1f%% | L: %s%s\n",
            $machine,
            $state['cycles_completed'],
            $excellentRate,
            $lStatus,
            $lProgress
        );

        echo sprintf("             |           |          | R: %s%s\n",
            $rStatus,
            $rProgress
        );
        echo "\n";
    }

    private function displayOverallStats()
    {
        // Calculate overall statistics
        $totalCycles = 0;
        $totalExcellent = 0;
        $totalGood = 0;
        $activeMachines = 0;

        foreach ($this->machines as $machine) {
            $state = $this->machineStates[$machine];
            $machineTotal = array_sum($state['quality_stats']);
            $totalCycles += $machineTotal;
            $totalExcellent += $state['quality_stats']['excellent'];
            $totalGood += $state['quality_stats']['good'];

            if ($state['L']['state'] == 'pressing' || $state['R']['state'] == 'pressing') {
                $activeMachines++;
            }
        }

        $overallQuality = $totalCycles > 0 ? round((($totalExcellent + $totalGood) / $totalCycles) * 100, 1) : 0;

        echo "📊 OVERALL PRODUCTION STATUS\n";
        echo "Active Machines: {$activeMachines}/4 | Total Cycles: {$totalCycles} | Quality Rate: {$overallQuality}%\n";

        // Recent cycles display
        $recentCycles = InsDwpCount::where('line', $this->line)
            ->where('created_at', '>=', now()->subMinutes(5))
            ->orderBy('created_at', 'desc')
            ->take(6)
            ->get();

        if ($recentCycles->count() > 0) {
            echo "\n📈 RECENT CYCLES (Last 5 minutes):\n";
            foreach ($recentCycles as $cycle) {
                $stdError = json_decode($cycle->std_error, true);
                $pvData = json_decode($cycle->pv, true);

                $status = (($stdError[0][0] ?? 0) && ($stdError[1][0] ?? 0)) ? '✅' : '❌';
                $grade = $pvData['quality']['grade'] ?? 'UNKNOWN';
                $peaks = $pvData['quality']['peaks'] ?? ['th' => 0, 'side' => 0];

                echo sprintf("%s M%d-%s %s TH:%d Side:%d %s\n",
                    $status,
                    $cycle->mechine,
                    $cycle->position,
                    str_pad($grade, 9),
                    $peaks['th'],
                    $peaks['side'],
                    $cycle->created_at->format('H:i:s')
                );
            }
        }
    }

    private function logEvent($message)
    {
        // Log to file or console if verbose mode enabled
        // For now, just store in memory for recent events display
    }

    private function formatTime($seconds)
    {
        $mins = (int)($seconds / 60);
        $secs = (int)($seconds % 60);
        return sprintf('%02d:%02d', $mins, $secs);
    }

    private function clearScreen()
    {
        if (PHP_OS_FAMILY === 'Windows') {
            system('cls');
        } else {
            system('clear');
        }
    }

    private function printHeader()
    {
        echo "🏭 DWP REAL-TIME PRESS MONITORING SYSTEM\n";
        echo "Real Manufacturing Conditions:\n";
        echo "• 4 Machines Running Simultaneously\n";
        echo "• 15-20 Second Cycle Times (Actual 16s)\n";
        echo "• Variable L/R Position Gap (2-8s)\n";
        echo "• Live Quality Monitoring\n\n";
    }

    private function setupSignalHandlers()
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'handleShutdown']);
            pcntl_signal(SIGTERM, [$this, 'handleShutdown']);
        }
    }

    public function handleShutdown($signal = null)
    {
        $this->isRunning = false;
    }

    private function printShutdown()
    {
        echo "\n\n🛑 Monitoring stopped.\n";
        echo "Final Statistics:\n";

        foreach ($this->machines as $machine) {
            $state = $this->machineStates[$machine];
            $total = array_sum($state['quality_stats']);
            echo "Machine {$machine}: {$state['cycles_completed']} cycles completed, {$total} quality samples\n";
        }

        echo "\nTotal cycles saved to database: {$this->lastCumulativeCount}\n";
        echo "Thank you for monitoring! 🏭\n";
    }
}

// Run the real-time monitor
echo "🚀 Starting DWP Real-time Press Monitor...\n";
echo "This simulates actual manufacturing conditions with realistic timing.\n\n";

$monitor = new RealtimePressMonitor();
$monitor->startMonitoring();
