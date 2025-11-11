<?php

namespace App\Console\Commands;

use App\Models\InsDwpCount;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SimulateDwpData extends Command
{
    protected $signature = 'app:simulate-dwp-data {--count=100 : Number of cycles to simulate} {--line=LINEA : Production line} {--days=1 : Spread data over how many days}';

    protected $description = 'Simulate realistic DWP cycle data for testing and development';

    // Quality configuration
    protected $goodValueMin = 30;
    protected $goodValueMax = 45;
    protected $excellentMin = 32;
    protected $excellentMax = 42;

    // Simulation patterns
    protected $qualityPatterns = [
        'excellent' => 0.60,    // 60% excellent cycles
        'good' => 0.25,         // 25% good cycles
        'marginal' => 0.10,     // 10% marginal cycles
        'defective' => 0.03,    // 3% defective cycles
        'sensor_issues' => 0.02 // 2% sensor issues
    ];

    public function handle()
    {
        $count = (int) $this->option('count');
        $line = strtoupper($this->option('line'));
        $days = (int) $this->option('days');

        $this->info("🏭 Simulating {$count} DWP cycles for Line {$line} (4 machines running simultaneously)");
        $this->info("📅 Spreading data over {$days} day(s)");
        $this->info("⏱️  Actual press timing: 15-20 sec cycles, L/R positions with gap times");

        $this->generateRealisticCycles($count, $line, $days);

        $this->info("✅ Simulation complete!");
        $this->showSimulationSummary($line);
    }

    private function generateRealisticCycles($count, $line, $days)
    {
        $startTime = now()->subDays($days);
        $machines = [1, 2, 3, 4]; // 4 machines running simultaneously
        $positions = ['L', 'R'];

        // Get last cumulative count for this line
        $lastCount = InsDwpCount::where('line', $line)->max('count') ?? 0;
        $currentCount = $lastCount;

        $progressBar = $this->output->createProgressBar($count);
        $progressBar->start();

        // Simulate realistic production over time
        $currentTime = $startTime->copy();
        $cyclesGenerated = 0;

        while ($cyclesGenerated < $count) {
            // Each machine operates independently with 15-20 second cycles
            foreach ($machines as $machine) {
                if ($cyclesGenerated >= $count) break;

                // Actual cycle time: 15-20 seconds (mostly 16 seconds)
                $baseCycleTime = 16; // seconds
                $cycleVariation = rand(-1, 4); // -1 to +4 seconds variation
                $actualCycleTime = $baseCycleTime + $cycleVariation;

                // L position starts first
                $leftStartTime = $currentTime->copy()->addSeconds(rand(0, 30)); // Random start offset
                $this->createRealisticCycleData($line, $machine, 'L', ++$currentCount, $leftStartTime, $actualCycleTime);

                // R position has gap time (not standard) - can be 2-8 seconds after L starts
                $gapTime = rand(2, 8); // Variable gap between L and R positions
                $rightStartTime = $leftStartTime->copy()->addSeconds($gapTime);
                $this->createRealisticCycleData($line, $machine, 'R', ++$currentCount, $rightStartTime, $actualCycleTime);

                $cyclesGenerated += 2; // Both L and R positions
                $progressBar->advance(2);

                // Next cycle for this machine starts after current cycle completes
                $currentTime->addSeconds($actualCycleTime + rand(1, 3)); // Small gap between cycles
            }

            // Add realistic production breaks (not continuous operation)
            if (rand(1, 100) <= 5) { // 5% chance of short break
                $currentTime->addMinutes(rand(2, 10)); // 2-10 minute break
            }
        }

        $progressBar->finish();
        $this->line('');
    }

    private function createRealisticCycleData($line, $machine, $position, $cumulativeCount, $timestamp, $actualCycleTime)
    {
        // Determine quality pattern for this cycle
        $qualityType = $this->selectQualityType();

        // Generate sensor data based on quality type
        $sensorData = $this->generateSensorData($qualityType);

        // Generate realistic waveform based on actual press timing
        $waveforms = $this->generatePressWaveforms($sensorData['th_peak'], $sensorData['side_peak'], $qualityType, $actualCycleTime);

        // Determine boolean quality indicators
        $thQuality = ($sensorData['th_peak'] >= $this->goodValueMin && $sensorData['th_peak'] <= $this->goodValueMax) ? 1 : 0;
        $sideQuality = ($sensorData['side_peak'] >= $this->goodValueMin && $sensorData['side_peak'] <= $this->goodValueMax) ? 1 : 0;

        // Create enhanced PV data
        $enhancedPvData = [
            'waveforms' => $waveforms,
            'quality' => [
                'grade' => $qualityType,
                'peaks' => ['th' => $sensorData['th_peak'], 'side' => $sensorData['side_peak']],
                'cycle_type' => $this->getCycleType($qualityType),
                'sample_count' => count($waveforms[0]),
                'actual_cycle_time' => $actualCycleTime
            ]
        ];

        // Create std_error boolean array [[th_quality],[side_quality]]
        $stdErrorBooleanArray = [[$thQuality], [$sideQuality]];

        InsDwpCount::create([
            'mechine' => $machine,
            'line' => $line,
            'count' => $cumulativeCount,
            'pv' => json_encode($enhancedPvData),
            'position' => $position,
            'duration' => $actualCycleTime, // Use actual press cycle time
            'incremental' => 1,
            'std_error' => json_encode($stdErrorBooleanArray),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    private function selectQualityType()
    {
        $rand = mt_rand() / mt_getrandmax();
        $cumulative = 0;

        foreach ($this->qualityPatterns as $type => $probability) {
            $cumulative += $probability;
            if ($rand <= $cumulative) {
                return strtoupper($type);
            }
        }

        return 'EXCELLENT'; // Fallback
    }

    private function generateSensorData($qualityType)
    {
        switch ($qualityType) {
            case 'EXCELLENT':
                return [
                    'th_peak' => rand($this->excellentMin, $this->excellentMax),
                    'side_peak' => rand($this->excellentMin, $this->excellentMax)
                ];

            case 'GOOD':
                return [
                    'th_peak' => rand($this->goodValueMin, $this->goodValueMax),
                    'side_peak' => rand($this->goodValueMin, $this->goodValueMax)
                ];

            case 'MARGINAL':
                // One sensor good, one marginal
                if (rand(0, 1)) {
                    return [
                        'th_peak' => rand($this->goodValueMin, $this->goodValueMax),
                        'side_peak' => rand(20, 29) // Below good range
                    ];
                } else {
                    return [
                        'th_peak' => rand(46, 55), // Above good range
                        'side_peak' => rand($this->goodValueMin, $this->goodValueMax)
                    ];
                }

            case 'SENSOR_ISSUES':
                // Simulate sensor problems
                $issueType = rand(1, 3);
                switch ($issueType) {
                    case 1: // Both sensors low
                        return ['th_peak' => rand(5, 15), 'side_peak' => rand(5, 15)];
                    case 2: // One sensor dead
                        return ['th_peak' => rand(30, 45), 'side_peak' => rand(0, 5)];
                    case 3: // Pressure too high
                        return ['th_peak' => rand(80, 100), 'side_peak' => rand(80, 100)];
                }
                break;

            case 'DEFECTIVE':
            default:
                // Random poor values
                return [
                    'th_peak' => rand(10, 25),
                    'side_peak' => rand(50, 75)
                ];
        }
    }

    private function generatePressWaveforms($thPeak, $sidePeak, $qualityType, $actualCycleTime)
    {
        $length = 30; // Fixed length as in original code

        // Create realistic press waveforms based on actual timing
        $thWaveform = $this->createPressWaveformPattern($thPeak, $qualityType, $actualCycleTime);
        $sideWaveform = $this->createPressWaveformPattern($sidePeak, $qualityType, $actualCycleTime);

        return [$thWaveform, $sideWaveform];
    }

    private function createPressWaveformPattern($peak, $qualityType, $actualCycleTime)
    {
        $waveform = [];
        $length = 30;

        // Create realistic press patterns based on 15-20 second cycles
        // Press typically has: ramp up -> peak pressure -> hold -> release
        $rampUpPhase = (int)($length * 0.3);  // 30% ramp up
        $peakPhase = (int)($length * 0.4);    // 40% at peak
        $releasePhase = $length - $rampUpPhase - $peakPhase; // 30% release

        switch ($qualityType) {
            case 'EXCELLENT':
            case 'GOOD':
                // Smooth press curve with proper phases
                for ($i = 0; $i < $length; $i++) {
                    if ($i < $rampUpPhase) {
                        // Ramp up phase - smooth increase
                        $progress = $i / $rampUpPhase;
                        $value = $peak * $progress * $progress; // Quadratic ramp
                        $noise = rand(-1, 1);
                    } elseif ($i < $rampUpPhase + $peakPhase) {
                        // Peak phase - maintain pressure with slight variation
                        $value = $peak;
                        $noise = rand(-2, 2);
                    } else {
                        // Release phase - smooth decrease
                        $releaseProgress = ($i - $rampUpPhase - $peakPhase) / $releasePhase;
                        $value = $peak * (1 - $releaseProgress * $releaseProgress); // Quadratic release
                        $noise = rand(-1, 1);
                    }
                    $waveform[] = max(0, (int)($value + $noise));
                }
                break;

            case 'MARGINAL':
                // Irregular press pattern with pressure variations
                for ($i = 0; $i < $length; $i++) {
                    if ($i < $rampUpPhase) {
                        $progress = $i / $rampUpPhase;
                        $value = $peak * $progress;
                        $noise = rand(-3, 5); // More variation
                    } elseif ($i < $rampUpPhase + $peakPhase) {
                        $value = $peak;
                        $noise = rand(-8, 8); // Pressure instability
                        $spike = rand(0, 5) == 0 ? rand(5, 15) : 0; // Occasional pressure spikes
                        $noise += $spike;
                    } else {
                        $releaseProgress = ($i - $rampUpPhase - $peakPhase) / $releasePhase;
                        $value = $peak * (1 - $releaseProgress);
                        $noise = rand(-5, 3);
                    }
                    $waveform[] = max(0, (int)($value + $noise));
                }
                break;

            case 'SENSOR_ISSUES':
                if ($peak < 10) {
                    // Low/dead sensor - mostly low values
                    for ($i = 0; $i < $length; $i++) {
                        $waveform[] = rand(0, 8);
                    }
                } else {
                    // Pressure system issues - erratic readings
                    for ($i = 0; $i < $length; $i++) {
                        $waveform[] = max(0, $peak + rand(-25, 25));
                    }
                }
                break;

            case 'DEFECTIVE':
            default:
                // Poor press cycle - irregular pattern
                for ($i = 0; $i < $length; $i++) {
                    $baseValue = rand(0, $peak);
                    $noise = rand(-10, 15);
                    $waveform[] = max(0, $baseValue + $noise);
                }
                break;
        }

        return $waveform;
    }



    private function getCycleType($qualityType)
    {
        switch ($qualityType) {
            case 'SENSOR_ISSUES':
                return rand(0, 1) ? 'SHORT_CYCLE' : 'COMPLETE';
            default:
                return 'COMPLETE';
        }
    }

    private function getActualPressDuration($qualityType)
    {
        // Actual press cycle times: 15-20 seconds (mostly 16)
        switch ($qualityType) {
            case 'EXCELLENT':
            case 'GOOD':
                return rand(15, 18); // Normal press cycle time
            case 'MARGINAL':
                return rand(14, 20); // Slightly variable timing
            case 'SENSOR_ISSUES':
                return rand(12, 16); // May abort early due to sensor issues
            case 'DEFECTIVE':
                return rand(13, 21); // More variable timing
            default:
                return 16; // Standard cycle time
        }
    }

    private function showSimulationSummary($line)
    {
        // Get recent simulated data for all machines
        $cycles = InsDwpCount::where('line', $line)
            ->where('created_at', '>=', now()->subHour())
            ->get();

        if ($cycles->isEmpty()) {
            $this->warn('No recent cycles found for summary');
            return;
        }

        $this->info("\n📊 Simulation Summary:");
        $this->line("Line: {$line} (All 4 machines)");
        $this->line("Total Cycles: " . $cycles->count());

        // Show machine distribution
        $machineStats = $cycles->groupBy('mechine')->map->count();
        $this->line("Machine Distribution:");
        foreach ($machineStats as $machine => $count) {
            $this->line("  Machine {$machine}: {$count} cycles");
        }

        // Analyze quality distribution
        $qualityStats = [
            'both_good' => 0,
            'th_only' => 0,
            'side_only' => 0,
            'both_bad' => 0,
        ];

        $gradeStats = [];

        foreach ($cycles as $cycle) {
            $stdError = json_decode($cycle->std_error, true);
            $pvData = json_decode($cycle->pv, true);

            $thGood = $stdError[0][0] ?? 0;
            $sideGood = $stdError[1][0] ?? 0;
            $grade = $pvData['quality']['grade'] ?? 'UNKNOWN';

            // Quality stats
            if ($thGood && $sideGood) $qualityStats['both_good']++;
            elseif ($thGood && !$sideGood) $qualityStats['th_only']++;
            elseif (!$thGood && $sideGood) $qualityStats['side_only']++;
            else $qualityStats['both_bad']++;

            // Grade stats
            $gradeStats[$grade] = ($gradeStats[$grade] ?? 0) + 1;
        }

        $total = $cycles->count();
        $this->line("\n🎯 Quality Distribution:");
        $this->line("Both Sensors Good: {$qualityStats['both_good']} (" . round($qualityStats['both_good']/$total*100, 1) . "%)");
        $this->line("Toe/Heel Only Good: {$qualityStats['th_only']} (" . round($qualityStats['th_only']/$total*100, 1) . "%)");
        $this->line("Side Only Good: {$qualityStats['side_only']} (" . round($qualityStats['side_only']/$total*100, 1) . "%)");
        $this->line("Both Sensors Bad: {$qualityStats['both_bad']} (" . round($qualityStats['both_bad']/$total*100, 1) . "%)");

        $this->line("\n📈 Quality Grades:");
        foreach ($gradeStats as $grade => $count) {
            $percentage = round($count/$total*100, 1);
            $this->line("{$grade}: {$count} ({$percentage}%)");
        }

        // Sample data preview
        $this->line("\n🔍 Sample Data Preview (Recent Cycles):");
        $sampleCycles = $cycles->sortByDesc('created_at')->take(8);

        foreach ($sampleCycles as $cycle) {
            $stdError = json_decode($cycle->std_error, true);
            $pvData = json_decode($cycle->pv, true);

            $thQuality = $stdError[0][0] ? 'GOOD' : 'BAD';
            $sideQuality = $stdError[1][0] ? 'GOOD' : 'BAD';
            $grade = $pvData['quality']['grade'];
            $peaks = $pvData['quality']['peaks'];
            $actualCycleTime = $pvData['quality']['actual_cycle_time'] ?? $cycle->duration;

            $this->line("M{$cycle->mechine}-{$cycle->position}: {$grade} - TH:{$peaks['th']}({$thQuality}) Side:{$peaks['side']}({$sideQuality}) - {$actualCycleTime}sec - {$cycle->created_at->format('H:i:s')}");
        }

        // Show timing analysis
        $avgDuration = round($cycles->avg('duration'), 1);
        $minDuration = $cycles->min('duration');
        $maxDuration = $cycles->max('duration');

        $this->line("\n⏱️  Press Timing Analysis:");
        $this->line("Average Cycle Time: {$avgDuration} seconds");
        $this->line("Range: {$minDuration}-{$maxDuration} seconds");

        // Show position gap analysis
        $positionGaps = [];
        $machineGroups = $cycles->groupBy(['mechine', function($cycle) {
            return $cycle->created_at->format('Y-m-d H:i');
        }]);

        foreach ($machineGroups as $machineId => $timeGroups) {
            foreach ($timeGroups as $timeGroup => $machineCycles) {
                $leftCycle = $machineCycles->where('position', 'L')->first();
                $rightCycle = $machineCycles->where('position', 'R')->first();

                if ($leftCycle && $rightCycle) {
                    $gap = abs($leftCycle->created_at->diffInSeconds($rightCycle->created_at));
                    if ($gap <= 10) { // Only count realistic gaps
                        $positionGaps[] = $gap;
                    }
                }
            }
        }

        if (!empty($positionGaps)) {
            $avgGap = round(array_sum($positionGaps) / count($positionGaps), 1);
            $this->line("Average L/R Position Gap: {$avgGap} seconds");
        }

        $this->info("\n✨ Use the DwpDataAnalyzer class to perform detailed analysis on this realistic data!");
        $this->comment("Example: php artisan tinker");
        $this->comment("\$analyzer = new App\\Console\\Commands\\DwpDataAnalyzer();");
        $this->comment("\$stats = \$analyzer->getQualityStats('{$line}');");
    }
}
