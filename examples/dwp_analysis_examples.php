<?php

/**
 * DWP Data Analysis Examples
 *
 * This file demonstrates how to analyze the enhanced DWP cycle data
 * using the new boolean array format for std_error field.
 */

use App\Models\InsDwpCount;

class DwpDataAnalyzer
{
    /**
     * Get all cycles with quality breakdown
     */
    public function getAllCyclesWithQuality($line = null)
    {
        $query = InsDwpCount::query();

        if ($line) {
            $query->where('line', $line);
        }

        $cycles = $query->get()->map(function($cycle) {
            $stdError = json_decode($cycle->std_error, true);
            $pvData = json_decode($cycle->pv, true);

            return [
                'id' => $cycle->id,
                'line' => $cycle->line,
                'machine' => $cycle->mechine,
                'position' => $cycle->position,
                'count' => $cycle->count,
                'duration' => $cycle->duration,
                'created_at' => $cycle->created_at,

                // Quality data from std_error boolean array
                'th_quality' => $stdError[0][0] ?? 0,        // array[0] = toe/heel
                'side_quality' => $stdError[1][0] ?? 0,      // array[1] = side
                'both_good' => ($stdError[0][0] ?? 0) && ($stdError[1][0] ?? 0),

                // Enhanced data from pv field
                'quality_grade' => $pvData['quality']['grade'] ?? 'UNKNOWN',
                'peaks' => $pvData['quality']['peaks'] ?? ['th' => 0, 'side' => 0],
                'cycle_type' => $pvData['quality']['cycle_type'] ?? 'UNKNOWN',
                'sample_count' => $pvData['quality']['sample_count'] ?? 0,
            ];
        });

        return $cycles;
    }

    /**
     * Get quality statistics for a line
     */
    public function getQualityStats($line)
    {
        $cycles = $this->getAllCyclesWithQuality($line);

        $stats = [
            'total_cycles' => $cycles->count(),
            'both_sensors_good' => $cycles->where('both_good', true)->count(),
            'th_only_good' => $cycles->where('th_quality', 1)->where('side_quality', 0)->count(),
            'side_only_good' => $cycles->where('th_quality', 0)->where('side_quality', 1)->count(),
            'both_sensors_bad' => $cycles->where('th_quality', 0)->where('side_quality', 0)->count(),
        ];

        // Calculate percentages
        $total = $stats['total_cycles'];
        if ($total > 0) {
            $stats['quality_rate'] = round(($stats['both_sensors_good'] / $total) * 100, 2);
            $stats['th_success_rate'] = round($cycles->where('th_quality', 1)->count() / $total * 100, 2);
            $stats['side_success_rate'] = round($cycles->where('side_quality', 1)->count() / $total * 100, 2);
        } else {
            $stats['quality_rate'] = 0;
            $stats['th_success_rate'] = 0;
            $stats['side_success_rate'] = 0;
        }

        return $stats;
    }

    /**
     * Find cycles with specific quality patterns
     */
    public function findQualityPatterns($line = null)
    {
        $cycles = $this->getAllCyclesWithQuality($line);

        return [
            'perfect_cycles' => $cycles->where('both_good', true),
            'th_problems' => $cycles->where('th_quality', 0)->where('side_quality', 1),
            'side_problems' => $cycles->where('th_quality', 1)->where('side_quality', 0),
            'total_failures' => $cycles->where('th_quality', 0)->where('side_quality', 0),
            'short_cycles' => $cycles->where('cycle_type', 'SHORT_CYCLE'),
            'overflow_cycles' => $cycles->where('cycle_type', 'OVERFLOW'),
        ];
    }

    /**
     * Get quality trends over time (hourly breakdown)
     */
    public function getQualityTrends($line, $hours = 24)
    {
        $startTime = now()->subHours($hours);

        $cycles = InsDwpCount::where('line', $line)
            ->where('created_at', '>=', $startTime)
            ->orderBy('created_at')
            ->get();

        $trends = [];

        foreach ($cycles as $cycle) {
            $hour = $cycle->created_at->format('Y-m-d H:00');
            $stdError = json_decode($cycle->std_error, true);

            if (!isset($trends[$hour])) {
                $trends[$hour] = [
                    'total' => 0,
                    'th_good' => 0,
                    'side_good' => 0,
                    'both_good' => 0,
                ];
            }

            $trends[$hour]['total']++;

            if ($stdError[0][0] ?? 0) $trends[$hour]['th_good']++;
            if ($stdError[1][0] ?? 0) $trends[$hour]['side_good']++;
            if (($stdError[0][0] ?? 0) && ($stdError[1][0] ?? 0)) $trends[$hour]['both_good']++;
        }

        // Calculate percentages for each hour
        foreach ($trends as $hour => &$data) {
            if ($data['total'] > 0) {
                $data['th_rate'] = round(($data['th_good'] / $data['total']) * 100, 1);
                $data['side_rate'] = round(($data['side_good'] / $data['total']) * 100, 1);
                $data['quality_rate'] = round(($data['both_good'] / $data['total']) * 100, 1);
            }
        }

        return $trends;
    }

    /**
     * Generate quality report for a machine
     */
    public function generateMachineQualityReport($line, $machine, $position = null)
    {
        $query = InsDwpCount::where('line', $line)
            ->where('mechine', $machine);

        if ($position) {
            $query->where('position', $position);
        }

        $cycles = $query->orderBy('created_at', 'desc')
            ->take(100) // Last 100 cycles
            ->get();

        $report = [
            'machine_info' => [
                'line' => $line,
                'machine' => $machine,
                'position' => $position ?: 'BOTH',
                'last_cycle' => $cycles->first()?->created_at,
                'cycles_analyzed' => $cycles->count(),
            ],
            'quality_summary' => [
                'excellent' => 0,
                'good' => 0,
                'marginal' => 0,
                'defective' => 0,
                'sensor_issues' => 0,
            ],
            'sensor_performance' => [
                'th_success_count' => 0,
                'side_success_count' => 0,
                'both_success_count' => 0,
            ],
            'recent_issues' => [],
        ];

        foreach ($cycles as $cycle) {
            $stdError = json_decode($cycle->std_error, true);
            $pvData = json_decode($cycle->pv, true);

            $thGood = $stdError[0][0] ?? 0;
            $sideGood = $stdError[1][0] ?? 0;
            $grade = $pvData['quality']['grade'] ?? 'UNKNOWN';

            // Count quality grades
            switch (strtoupper($grade)) {
                case 'EXCELLENT':
                    $report['quality_summary']['excellent']++;
                    break;
                case 'GOOD':
                    $report['quality_summary']['good']++;
                    break;
                case 'MARGINAL':
                    $report['quality_summary']['marginal']++;
                    break;
                case 'SENSOR_LOW':
                case 'SHORT_CYCLE':
                    $report['quality_summary']['sensor_issues']++;
                    break;
                default:
                    $report['quality_summary']['defective']++;
            }

            // Count sensor performance
            if ($thGood) $report['sensor_performance']['th_success_count']++;
            if ($sideGood) $report['sensor_performance']['side_success_count']++;
            if ($thGood && $sideGood) $report['sensor_performance']['both_success_count']++;

            // Collect recent issues (last 10 problematic cycles)
            if (!$thGood || !$sideGood) {
                if (count($report['recent_issues']) < 10) {
                    $report['recent_issues'][] = [
                        'cycle_id' => $cycle->id,
                        'time' => $cycle->created_at,
                        'grade' => $grade,
                        'th_quality' => $thGood,
                        'side_quality' => $sideGood,
                        'peaks' => $pvData['quality']['peaks'] ?? ['th' => 0, 'side' => 0],
                    ];
                }
            }
        }

        // Calculate percentages
        $total = $cycles->count();
        if ($total > 0) {
            $report['sensor_performance']['th_success_rate'] = round(($report['sensor_performance']['th_success_count'] / $total) * 100, 1);
            $report['sensor_performance']['side_success_rate'] = round(($report['sensor_performance']['side_success_count'] / $total) * 100, 1);
            $report['sensor_performance']['overall_quality_rate'] = round(($report['sensor_performance']['both_success_count'] / $total) * 100, 1);
        }

        return $report;
    }

    /**
     * Quick quality check for dashboard
     */
    public function getQuickQualityStatus($line)
    {
        // Get last 50 cycles
        $recentCycles = InsDwpCount::where('line', $line)
            ->orderBy('created_at', 'desc')
            ->take(50)
            ->get();

        if ($recentCycles->isEmpty()) {
            return ['status' => 'NO_DATA', 'message' => 'No recent cycles found'];
        }

        $goodCount = 0;
        $totalCount = $recentCycles->count();

        foreach ($recentCycles as $cycle) {
            $stdError = json_decode($cycle->std_error, true);
            if (($stdError[0][0] ?? 0) && ($stdError[1][0] ?? 0)) {
                $goodCount++;
            }
        }

        $qualityRate = ($goodCount / $totalCount) * 100;

        if ($qualityRate >= 90) {
            return ['status' => 'EXCELLENT', 'rate' => $qualityRate, 'message' => 'Quality is excellent'];
        } elseif ($qualityRate >= 75) {
            return ['status' => 'GOOD', 'rate' => $qualityRate, 'message' => 'Quality is good'];
        } elseif ($qualityRate >= 50) {
            return ['status' => 'WARNING', 'rate' => $qualityRate, 'message' => 'Quality issues detected'];
        } else {
            return ['status' => 'CRITICAL', 'rate' => $qualityRate, 'message' => 'Critical quality issues'];
        }
    }
}

// Usage Examples:

// Initialize analyzer
$analyzer = new DwpDataAnalyzer();

// Get quality stats for LINEA
$stats = $analyzer->getQualityStats('LINEA');
echo "Line LINEA Quality Rate: {$stats['quality_rate']}%\n";
echo "Total Cycles: {$stats['total_cycles']}\n";
echo "Both Sensors Good: {$stats['both_sensors_good']}\n";

// Find specific quality patterns
$patterns = $analyzer->findQualityPatterns('LINEA');
echo "Perfect Cycles: " . $patterns['perfect_cycles']->count() . "\n";
echo "Toe/Heel Problems: " . $patterns['th_problems']->count() . "\n";
echo "Side Problems: " . $patterns['side_problems']->count() . "\n";

// Get machine quality report
$report = $analyzer->generateMachineQualityReport('LINEA', 1, 'L');
echo "Machine 1-L Quality Rate: {$report['sensor_performance']['overall_quality_rate']}%\n";

// Quick status check for dashboard
$status = $analyzer->getQuickQualityStatus('LINEA');
echo "Current Status: {$status['status']} - {$status['message']}\n";

// Example of direct database queries using the boolean array format:

// Find cycles where toe/heel is good but side is bad
$thGoodSideBad = InsDwpCount::whereRaw("JSON_EXTRACT(std_error, '$[0][0]') = 1")
    ->whereRaw("JSON_EXTRACT(std_error, '$[1][0]') = 0")
    ->get();

// Find cycles where both sensors are good
$bothGood = InsDwpCount::whereRaw("JSON_EXTRACT(std_error, '$[0][0]') = 1")
    ->whereRaw("JSON_EXTRACT(std_error, '$[1][0]') = 1")
    ->get();

// Count good vs bad cycles by sensor
$sensorStats = InsDwpCount::selectRaw("
    SUM(JSON_EXTRACT(std_error, '$[0][0]')) as th_good_count,
    SUM(JSON_EXTRACT(std_error, '$[1][0]')) as side_good_count,
    COUNT(*) as total_cycles
")->where('line', 'LINEA')->first();

echo "Toe/Heel Good: {$sensorStats->th_good_count}/{$sensorStats->total_cycles}\n";
echo "Side Good: {$sensorStats->side_good_count}/{$sensorStats->total_cycles}\n";
