<?php

/**
 * DWP Data Simulation and Analysis Runner
 *
 * This script demonstrates how to:
 * 1. Simulate realistic DWP manufacturing data
 * 2. Analyze the generated data
 * 3. Show various quality reports
 *
 * Usage: php examples/run_dwp_simulation.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Bootstrap Laravel application
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\InsDwpCount;
use Carbon\Carbon;

class DwpSimulationRunner
{
    private $lines = ['LINEA', 'LINEB', 'LINEC'];
    private $machines = [1, 2, 3, 4]; // 4 machines running simultaneously
    private $positions = ['L', 'R'];

    public function run()
    {
        $this->printHeader();

        // Clean old simulation data
        $this->cleanOldData();

        // Generate simulation data
        $this->generateSimulationData();

        // Analyze the generated data
        $this->analyzeData();

        // Show quality reports
        $this->showQualityReports();

        // Show real-time monitoring simulation
        $this->showRealtimeMonitoring();

        $this->printFooter();
    }

    private function printHeader()
    {
        echo "\n";
        echo "🏭 DWP Manufacturing Data Simulation & Analysis\n";
        echo "=" . str_repeat("=", 50) . "\n";
        echo "Simulating realistic Deep-Well Press cycle data...\n\n";
    }

    private function cleanOldData()
    {
        echo "🧹 Cleaning old simulation data...\n";
        InsDwpCount::where('created_at', '>=', now()->subDays(2))->delete();
        echo "✅ Cleaned old data\n\n";
    }

    private function generateSimulationData()
    {
        echo "📊 Generating simulation data for multiple lines...\n\n";

        foreach ($this->lines as $line) {
            foreach ($this->machines as $machine) {
                $this->simulateMachineData($line, $machine);
            }
        }

        echo "✅ Simulation data generation complete!\n\n";
    }

    private function simulateMachineData($line, $machine)
    {
        echo "   → Generating data for Line {$line}, Machine {$machine}...\n";

        // Get last cumulative count for this line
        $lastCount = InsDwpCount::where('line', $line)->max('count') ?? 0;

        // Generate 24 hours of data with realistic 15-20 second cycles
        // Each machine completes ~3-4 cycles per minute (including L/R positions)
        $cyclesPerHour = rand(180, 240); // 180-240 cycles per hour (90-120 complete press cycles)
        $totalCycles = $cyclesPerHour; // For 1 hour of data per machine

        $startTime = now()->subHour();
        $currentTime = $startTime->copy();
        $currentCount = $lastCount;

        for ($i = 1; $i <= $totalCycles / 2; $i++) { // Divide by 2 since we create L and R together
            // Actual press cycle time: 15-20 seconds (mostly 16)
            $pressCycleTime = rand(15, 20);

            // L position starts first
            $leftStartTime = $currentTime->copy();
            $this->createRealisticCycle($line, $machine, 'L', ++$currentCount, $leftStartTime, $pressCycleTime);

            // R position has variable gap time (2-8 seconds after L starts)
            $gapTime = rand(2, 8);
            $rightStartTime = $leftStartTime->copy()->addSeconds($gapTime);
            $this->createRealisticCycle($line, $machine, 'R', ++$currentCount, $rightStartTime, $pressCycleTime);

            // Next cycle starts after current cycle completes + small gap
            $currentTime->addSeconds($pressCycleTime + rand(1, 4));

            // Occasional production pauses (realistic manufacturing)
            if (rand(1, 20) == 1) {
                $currentTime->addSeconds(rand(30, 120)); // 30sec - 2min pause
            }
        }

        echo "     ✓ Generated " . ($totalCycles) . " press cycles with realistic timing\n";
    }

    private function createRealisticCycle($line, $machine, $position, $cumulativeCount, $timestamp, $actualCycleTime = 16)
    {
        // Determine quality based on realistic patterns
        $qualityType = $this->determineQualityPattern($timestamp);

        // Generate sensor data
        $sensorData = $this->generateRealisticSensorData($qualityType, $timestamp);

        // Generate waveforms with actual press timing
        $waveforms = $this->generateRealisticPressWaveforms($sensorData['th_peak'], $sensorData['side_peak'], $qualityType, $actualCycleTime);

        // Determine boolean quality
        $thQuality = ($sensorData['th_peak'] >= 30 && $sensorData['th_peak'] <= 45) ? 1 : 0;
        $sideQuality = ($sensorData['side_peak'] >= 30 && $sensorData['side_peak'] <= 45) ? 1 : 0;

        // Create PV data structure
        $pvData = [
            'waveforms' => $waveforms,
            'quality' => [
                'grade' => $qualityType,
                'peaks' => ['th' => $sensorData['th_peak'], 'side' => $sensorData['side_peak']],
                'cycle_type' => 'COMPLETE',
                'sample_count' => 30,
                'actual_cycle_time' => $actualCycleTime
            ]
        ];

        // Create std_error boolean array
        $stdError = [[$thQuality], [$sideQuality]];

        InsDwpCount::create([
            'mechine' => $machine,
            'line' => $line,
            'count' => $cumulativeCount,
            'pv' => json_encode($pvData),
            'position' => $position,
            'duration' => $actualCycleTime, // Use actual press cycle time
            'incremental' => 1,
            'std_error' => json_encode($stdError),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    private function determineQualityPattern($timestamp)
    {
        $hour = $timestamp->hour;

        // Simulate shift patterns - quality tends to be worse during shift changes
        if (in_array($hour, [6, 7, 14, 15, 22, 23])) {
            // Shift change - more quality issues
            $patterns = [
                'EXCELLENT' => 0.40,
                'GOOD' => 0.30,
                'MARGINAL' => 0.20,
                'DEFECTIVE' => 0.07,
                'SENSOR_ISSUES' => 0.03
            ];
        } else {
            // Normal operation
            $patterns = [
                'EXCELLENT' => 0.65,
                'GOOD' => 0.25,
                'MARGINAL' => 0.07,
                'DEFECTIVE' => 0.02,
                'SENSOR_ISSUES' => 0.01
            ];
        }

        $rand = mt_rand() / mt_getrandmax();
        $cumulative = 0;

        foreach ($patterns as $type => $probability) {
            $cumulative += $probability;
            if ($rand <= $cumulative) {
                return $type;
            }
        }

        return 'EXCELLENT';
    }

    private function generateRealisticSensorData($qualityType, $timestamp)
    {
        switch ($qualityType) {
            case 'EXCELLENT':
                return [
                    'th_peak' => rand(32, 42),
                    'side_peak' => rand(32, 42)
                ];
            case 'GOOD':
                return [
                    'th_peak' => rand(30, 45),
                    'side_peak' => rand(30, 45)
                ];
            case 'MARGINAL':
                if (rand(0, 1)) {
                    return ['th_peak' => rand(30, 45), 'side_peak' => rand(20, 29)];
                } else {
                    return ['th_peak' => rand(46, 55), 'side_peak' => rand(30, 45)];
                }
            case 'SENSOR_ISSUES':
                $issue = rand(1, 3);
                switch ($issue) {
                    case 1: return ['th_peak' => rand(5, 15), 'side_peak' => rand(5, 15)];
                    case 2: return ['th_peak' => rand(30, 45), 'side_peak' => rand(0, 5)];
                    case 3: return ['th_peak' => rand(80, 100), 'side_peak' => rand(80, 100)];
                }
                break;
            case 'DEFECTIVE':
            default:
                return ['th_peak' => rand(10, 25), 'side_peak' => rand(50, 75)];
        }
    }

    private function generateRealisticPressWaveforms($thPeak, $sidePeak, $qualityType, $actualCycleTime)
    {
        $length = 30; // Fixed normalized length

        // Create realistic press waveforms based on actual 15-20 second cycles
        $thWaveform = $this->createPressWaveform($thPeak, $qualityType, $actualCycleTime);
        $sideWaveform = $this->createPressWaveform($sidePeak, $qualityType, $actualCycleTime);

        return [$thWaveform, $sideWaveform];
    }

    private function createPressWaveform($peak, $qualityType, $actualCycleTime)
    {
        $length = 30;
        $waveform = [];

        // Press phases: ramp up (30%) -> peak pressure (40%) -> release (30%)
        $rampUpPhase = (int)($length * 0.3);
        $peakPhase = (int)($length * 0.4);
        $releasePhase = $length - $rampUpPhase - $peakPhase;

        switch ($qualityType) {
            case 'EXCELLENT':
            case 'GOOD':
                // Clean press cycle with proper phases
                for ($i = 0; $i < $length; $i++) {
                    if ($i < $rampUpPhase) {
                        // Smooth ramp up
                        $progress = $i / $rampUpPhase;
                        $value = $peak * $progress * $progress;
                        $noise = rand(-1, 1);
                    } elseif ($i < $rampUpPhase + $peakPhase) {
                        // Stable peak pressure
                        $value = $peak;
                        $noise = rand(-2, 2);
                    } else {
                        // Controlled release
                        $releaseProgress = ($i - $rampUpPhase - $peakPhase) / $releasePhase;
                        $value = $peak * (1 - $releaseProgress * $releaseProgress);
                        $noise = rand(-1, 1);
                    }
                    $waveform[] = max(0, (int)($value + $noise));
                }
                break;

            case 'MARGINAL':
                // Unstable pressure with variations
                for ($i = 0; $i < $length; $i++) {
                    if ($i < $rampUpPhase) {
                        $progress = $i / $rampUpPhase;
                        $value = $peak * $progress;
                        $noise = rand(-3, 5);
                    } elseif ($i < $rampUpPhase + $peakPhase) {
                        $value = $peak;
                        $noise = rand(-8, 8);
                        // Pressure spikes
                        if (rand(0, 3) == 0) $noise += rand(5, 15);
                    } else {
                        $releaseProgress = ($i - $rampUpPhase - $peakPhase) / $releasePhase;
                        $value = $peak * (1 - $releaseProgress);
                        $noise = rand(-5, 3);
                    }
                    $waveform[] = max(0, (int)($value + $noise));
                }
                break;

            default:
                // Poor quality - irregular pattern
                for ($i = 0; $i < $length; $i++) {
                    $baseValue = rand(0, $peak);
                    $noise = rand(-10, 15);
                    $waveform[] = max(0, $baseValue + $noise);
                }
                break;
        }

        return $waveform;
    }



    private function analyzeData()
    {
        echo "📈 Analyzing generated data...\n\n";

        $totalCycles = InsDwpCount::count();
        echo "Total Cycles Generated: {$totalCycles}\n";

        foreach ($this->lines as $line) {
            $lineStats = $this->getLineStatistics($line);
            $this->printLineStats($line, $lineStats);
        }

        echo "\n";
    }

    private function getLineStatistics($line)
    {
        $cycles = InsDwpCount::where('line', $line)->get();

        $stats = [
            'total' => $cycles->count(),
            'both_good' => 0,
            'th_only_good' => 0,
            'side_only_good' => 0,
            'both_bad' => 0,
            'by_grade' => []
        ];

        foreach ($cycles as $cycle) {
            $stdError = json_decode($cycle->std_error, true);
            $pvData = json_decode($cycle->pv, true);

            $thGood = $stdError[0][0] ?? 0;
            $sideGood = $stdError[1][0] ?? 0;
            $grade = $pvData['quality']['grade'] ?? 'UNKNOWN';

            if ($thGood && $sideGood) $stats['both_good']++;
            elseif ($thGood && !$sideGood) $stats['th_only_good']++;
            elseif (!$thGood && $sideGood) $stats['side_only_good']++;
            else $stats['both_bad']++;

            $stats['by_grade'][$grade] = ($stats['by_grade'][$grade] ?? 0) + 1;
        }

        return $stats;
    }

    private function printLineStats($line, $stats)
    {
        echo "📊 Line {$line} Statistics:\n";
        echo "  Total Cycles: {$stats['total']}\n";

        if ($stats['total'] > 0) {
            $qualityRate = round(($stats['both_good'] / $stats['total']) * 100, 1);
            echo "  Quality Rate: {$qualityRate}% ({$stats['both_good']}/{$stats['total']})\n";
            echo "  Both Good: {$stats['both_good']}\n";
            echo "  TH Only Good: {$stats['th_only_good']}\n";
            echo "  Side Only Good: {$stats['side_only_good']}\n";
            echo "  Both Bad: {$stats['both_bad']}\n";

            echo "  Quality Grades:\n";
            foreach ($stats['by_grade'] as $grade => $count) {
                $percentage = round(($count / $stats['total']) * 100, 1);
                echo "    {$grade}: {$count} ({$percentage}%)\n";
            }
        }
        echo "\n";
    }

    private function showQualityReports()
    {
        echo "📋 Quality Analysis Reports:\n";
        echo "=" . str_repeat("=", 30) . "\n\n";

        // Show hourly quality trends
        $this->showHourlyTrends();

        // Show machine comparison
        $this->showMachineComparison();

        // Show position analysis
        $this->showPositionAnalysis();
    }

    private function showHourlyTrends()
    {
        echo "⏰ Hourly Quality Trends (Last 24 Hours):\n";

        $hourlyStats = InsDwpCount::where('created_at', '>=', now()->subDay())
            ->get()
            ->groupBy(function($cycle) {
                return $cycle->created_at->format('H');
            })
            ->map(function($cycles) {
                $total = $cycles->count();
                $goodCount = 0;

                foreach ($cycles as $cycle) {
                    $stdError = json_decode($cycle->std_error, true);
                    if (($stdError[0][0] ?? 0) && ($stdError[1][0] ?? 0)) {
                        $goodCount++;
                    }
                }

                return [
                    'total' => $total,
                    'good' => $goodCount,
                    'rate' => $total > 0 ? round(($goodCount / $total) * 100, 1) : 0
                ];
            })
            ->sortKeys();

        foreach ($hourlyStats as $hour => $stats) {
            $bar = str_repeat('█', (int)($stats['rate'] / 5));
            echo sprintf("  %02d:00 - %5.1f%% %s (%d/%d)\n",
                $hour, $stats['rate'], $bar, $stats['good'], $stats['total']);
        }

        echo "\n";
    }

    private function showMachineComparison()
    {
        echo "🔧 Machine Performance Comparison:\n";

        foreach ($this->lines as $line) {
            echo "  Line {$line}:\n";

            foreach ($this->machines as $machine) {
                $cycles = InsDwpCount::where('line', $line)
                    ->where('mechine', $machine)
                    ->get();

                if ($cycles->count() > 0) {
                    $goodCount = 0;
                    foreach ($cycles as $cycle) {
                        $stdError = json_decode($cycle->std_error, true);
                        if (($stdError[0][0] ?? 0) && ($stdError[1][0] ?? 0)) {
                            $goodCount++;
                        }
                    }

                    $rate = round(($goodCount / $cycles->count()) * 100, 1);
                    $status = $rate >= 90 ? '🟢' : ($rate >= 75 ? '🟡' : '🔴');

                    echo "    Machine {$machine}: {$status} {$rate}% ({$goodCount}/{$cycles->count()})\n";
                }
            }
        }

        echo "\n";
    }

    private function showPositionAnalysis()
    {
        echo "🔄 Position (L/R) Analysis:\n";

        $positionStats = InsDwpCount::get()
            ->groupBy('position')
            ->map(function($cycles) {
                $total = $cycles->count();
                $goodCount = 0;

                foreach ($cycles as $cycle) {
                    $stdError = json_decode($cycle->std_error, true);
                    if (($stdError[0][0] ?? 0) && ($stdError[1][0] ?? 0)) {
                        $goodCount++;
                    }
                }

                return [
                    'total' => $total,
                    'good' => $goodCount,
                    'rate' => $total > 0 ? round(($goodCount / $total) * 100, 1) : 0
                ];
            });

        foreach ($positionStats as $position => $stats) {
            echo "  Position {$position}: {$stats['rate']}% quality ({$stats['good']}/{$stats['total']})\n";
        }

        echo "\n";
    }

    private function showRealtimeMonitoring()
    {
        echo "🔴 Real-time Monitoring Simulation:\n";
        echo "=" . str_repeat("=", 35) . "\n";
        echo "Simulating live production monitoring...\n\n";

        // Get recent cycles for each line
        foreach ($this->lines as $line) {
            $recentCycles = InsDwpCount::where('line', $line)
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->get();

            echo "📺 Line {$line} - Last 5 Cycles:\n";

            foreach ($recentCycles as $cycle) {
                $stdError = json_decode($cycle->std_error, true);
                $pvData = json_decode($cycle->pv, true);

                $thQuality = ($stdError[0][0] ?? 0) ? '✅' : '❌';
                $sideQuality = ($stdError[1][0] ?? 0) ? '✅' : '❌';
                $grade = $pvData['quality']['grade'];
                $peaks = $pvData['quality']['peaks'];

                $overallStatus = (($stdError[0][0] ?? 0) && ($stdError[1][0] ?? 0)) ? '🟢' : '🔴';

                echo sprintf("  %s M%d-%s %s TH:%d%s Side:%d%s [%s] %s\n",
                    $overallStatus,
                    $cycle->mechine,
                    $cycle->position,
                    $grade,
                    $peaks['th'],
                    $thQuality,
                    $peaks['side'],
                    $sideQuality,
                    $cycle->created_at->format('H:i:s')
                );
            }
            echo "\n";
        }
    }

    private function printFooter()
    {
        echo "🎯 Simulation Complete!\n";
        echo "=" . str_repeat("=", 20) . "\n\n";

        echo "💡 Next Steps:\n";
        echo "1. Use Laravel Tinker to explore the data:\n";
        echo "   php artisan tinker\n";
        echo "   >>> InsDwpCount::count()\n";
        echo "   >>> InsDwpCount::whereRaw(\"JSON_EXTRACT(std_error, '$[0][0]') = 1\")->count()\n\n";

        echo "2. Query examples:\n";
        echo "   // Both sensors good\n";
        echo "   InsDwpCount::whereRaw(\"JSON_EXTRACT(std_error, '$[0][0]') = 1\")\n";
        echo "              ->whereRaw(\"JSON_EXTRACT(std_error, '$[1][0]') = 1\")->get()\n\n";

        echo "   // Find quality issues\n";
        echo "   InsDwpCount::whereRaw(\"JSON_EXTRACT(std_error, '$[0][0]') = 0\")\n";
        echo "              ->orWhereRaw(\"JSON_EXTRACT(std_error, '$[1][0]') = 0\")->get()\n\n";

        echo "3. Run the InsDwpPoll command to see live polling:\n";
        echo "   php artisan app:ins-dwp-poll --v\n\n";

        echo "🚀 Happy analyzing!\n";
    }
}

// Run the simulation
$runner = new DwpSimulationRunner();
$runner->run();
