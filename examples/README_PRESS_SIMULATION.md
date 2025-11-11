# DWP Press Simulation & Monitoring

This directory contains realistic simulation tools for Deep-Well Press (DWP) manufacturing operations that match actual production conditions.

## 🏭 Real Manufacturing Conditions

The simulation models actual press operations with:

- **4 Machines Running Simultaneously** (different Modbus addresses)
- **15-20 Second Cycle Times** (actual average: 16 seconds)
- **Variable L/R Position Gap** (2-8 seconds between Left and Right positions)
- **Realistic Quality Patterns** (based on shift changes, machine wear, etc.)
- **Proper Press Waveforms** (ramp up → peak pressure → controlled release)

## 🚀 Quick Start

### 1. Generate Basic Simulation Data
```bash
# Generate 200 cycles for Line A (all 4 machines)
php artisan app:simulate-dwp-data --count=200 --line=LINEA --days=1

# Generate data for multiple lines
php artisan app:simulate-dwp-data --count=500 --line=LINEB --days=2
php artisan app:simulate-dwp-data --count=300 --line=LINEC --days=1
```

### 2. Run Comprehensive Simulation
```bash
# Complete simulation with analysis
php examples/run_dwp_simulation.php
```

### 3. Real-time Press Monitoring
```bash
# Live manufacturing simulation (press Ctrl+C to stop)
php examples/realtime_press_monitor.php
```

## 📊 Data Structure

### STD_ERROR Boolean Array Format
```json
{
  "std_error": "[[1],[0]]"  // [TH_Quality][Side_Quality]: 1=Good, 0=Bad
}
```

### Enhanced PV Data Format
```json
{
  "pv": {
    "waveforms": [
      [0,5,12,25,38,42,35,20,8,0,...],  // TH sensor (30 points)
      [0,3,8,18,28,35,30,15,5,0,...]   // Side sensor (30 points)
    ],
    "quality": {
      "grade": "EXCELLENT",
      "peaks": {"th": 42, "side": 35},
      "cycle_type": "COMPLETE",
      "sample_count": 30,
      "actual_cycle_time": 16,
      "modbus_address": {
        "th_l": 100, "th_r": 101, 
        "side_l": 102, "side_r": 103
      }
    }
  }
}
```

## 🔍 Quality Analysis Examples

### 1. Basic Quality Queries
```sql
-- Both sensors good (no data loss)
SELECT * FROM ins_dwp_counts
WHERE JSON_EXTRACT(std_error, '$[0][0]') = 1 
  AND JSON_EXTRACT(std_error, '$[1][0]') = 1;

-- Find TH sensor issues
SELECT * FROM ins_dwp_counts
WHERE JSON_EXTRACT(std_error, '$[0][0]') = 0;

-- Quality rate by machine
SELECT 
    mechine,
    COUNT(*) as total_cycles,
    SUM(JSON_EXTRACT(std_error, '$[0][0]') * JSON_EXTRACT(std_error, '$[1][0]')) as good_cycles,
    ROUND(SUM(JSON_EXTRACT(std_error, '$[0][0]') * JSON_EXTRACT(std_error, '$[1][0]')) / COUNT(*) * 100, 2) as quality_rate
FROM ins_dwp_counts 
GROUP BY mechine 
ORDER BY quality_rate DESC;
```

### 2. Laravel Eloquent Examples
```php
use App\Models\InsDwpCount;

// Get all cycles (zero data loss!)
$allCycles = InsDwpCount::where('line', 'LINEA')->get();

// Quality cycles only
$qualityCycles = InsDwpCount::whereRaw("JSON_EXTRACT(std_error, '$[0][0]') = 1")
    ->whereRaw("JSON_EXTRACT(std_error, '$[1][0]') = 1")
    ->get();

// Machine performance analysis
$machineStats = InsDwpCount::selectRaw("
    mechine,
    COUNT(*) as total,
    SUM(JSON_EXTRACT(std_error, '$[0][0]')) as th_good,
    SUM(JSON_EXTRACT(std_error, '$[1][0]')) as side_good,
    ROUND(AVG(duration), 2) as avg_cycle_time
")->where('line', 'LINEA')
  ->groupBy('mechine')
  ->get();

// Find timing patterns (L/R gap analysis)
$cycles = InsDwpCount::where('line', 'LINEA')
    ->where('created_at', '>=', now()->subHour())
    ->orderBy('created_at')
    ->get()
    ->groupBy(['mechine', function($cycle) {
        return $cycle->created_at->format('Y-m-d H:i');
    }]);

foreach($cycles as $machine => $timeGroups) {
    foreach($timeGroups as $time => $machineCycles) {
        $leftCycle = $machineCycles->where('position', 'L')->first();
        $rightCycle = $machineCycles->where('position', 'R')->first();
        
        if($leftCycle && $rightCycle) {
            $gap = abs($leftCycle->created_at->diffInSeconds($rightCycle->created_at));
            echo "Machine {$machine}: L/R gap = {$gap} seconds\n";
        }
    }
}
```

## ⏱️ Press Timing Analysis

### Cycle Time Distribution
```php
// Analyze actual cycle times
$timingStats = InsDwpCount::selectRaw("
    duration,
    COUNT(*) as count,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM ins_dwp_counts), 2) as percentage
")->groupBy('duration')
  ->orderBy('duration')
  ->get();

foreach($timingStats as $stat) {
    echo "Duration: {$stat->duration}s - Count: {$stat->count} ({$stat->percentage}%)\n";
}

// Expected output:
// Duration: 15s - Count: 45 (12.5%)
// Duration: 16s - Count: 180 (50.0%)  // Most common
// Duration: 17s - Count: 90 (25.0%)
// Duration: 18s - Count: 36 (10.0%)
// Duration: 19s - Count: 7 (1.9%)
// Duration: 20s - Count: 2 (0.6%)
```

### Position Gap Analysis
```php
// Find L/R position gaps per machine
function analyzePositionGaps($line = 'LINEA', $hours = 1) {
    $cycles = InsDwpCount::where('line', $line)
        ->where('created_at', '>=', now()->subHours($hours))
        ->orderBy('created_at')
        ->get()
        ->groupBy('mechine');
    
    foreach($cycles as $machine => $machineCycles) {
        $gaps = [];
        $sortedCycles = $machineCycles->sortBy('created_at');
        
        for($i = 0; $i < $sortedCycles->count() - 1; $i++) {
            $current = $sortedCycles->values()[$i];
            $next = $sortedCycles->values()[$i + 1];
            
            if($current->position == 'L' && $next->position == 'R') {
                $gap = $current->created_at->diffInSeconds($next->created_at);
                if($gap <= 10) $gaps[] = $gap; // Filter realistic gaps
            }
        }
        
        if(!empty($gaps)) {
            $avgGap = round(array_sum($gaps) / count($gaps), 1);
            $minGap = min($gaps);
            $maxGap = max($gaps);
            echo "Machine {$machine}: Avg L/R gap: {$avgGap}s (range: {$minGap}-{$maxGap}s)\n";
        }
    }
}

analyzePositionGaps('LINEA', 2);
```

## 📈 Real-time Monitoring

### Live Production Dashboard
```php
// Simple real-time status check
function getLiveProductionStatus($line) {
    $recentCycles = InsDwpCount::where('line', $line)
        ->where('created_at', '>=', now()->subMinutes(10))
        ->orderBy('created_at', 'desc')
        ->get();
    
    $stats = [
        'total_cycles' => $recentCycles->count(),
        'quality_cycles' => 0,
        'machines_active' => $recentCycles->pluck('mechine')->unique()->count(),
        'avg_cycle_time' => round($recentCycles->avg('duration'), 1),
        'current_rate' => 0
    ];
    
    foreach($recentCycles as $cycle) {
        $stdError = json_decode($cycle->std_error, true);
        if(($stdError[0][0] ?? 0) && ($stdError[1][0] ?? 0)) {
            $stats['quality_cycles']++;
        }
    }
    
    $stats['current_rate'] = $stats['total_cycles'] > 0 
        ? round(($stats['quality_cycles'] / $stats['total_cycles']) * 100, 1) 
        : 0;
    
    return $stats;
}

// Usage
$status = getLiveProductionStatus('LINEA');
echo "Line A Status: {$status['quality_cycles']}/{$status['total_cycles']} quality cycles ({$status['current_rate']}%)\n";
echo "Active Machines: {$status['machines_active']}/4, Avg Cycle: {$status['avg_cycle_time']}s\n";
```

## 🎯 Testing Your Enhanced Polling System

After generating simulation data, test your enhanced polling command:

```bash
# Test the enhanced InsDwpPoll with verbose output
php artisan app:ins-dwp-poll --v --d

# The enhanced system will now:
# ✅ Save ALL cycles (no data loss)
# ✅ Use boolean array format for std_error
# ✅ Include quality metadata in pv field
# ✅ Handle short cycles and sensor issues
# ✅ Maintain cumulative counts properly
```

## 📊 Quality Patterns

The simulation generates realistic quality distributions:

- **65() / max(1, $recent->count()) * 100;
    
    if($qualityRate < $threshold) {
        echo "🚨 QUALITY ALERT: Line {$line} quality rate {$qualityRate}% below threshold {$threshold}%\n";
        
        // Find problem machines
        $problemMachines = $recent->groupBy('mechine')->filter(function($cycles) use($threshold) {
            $good = $cycles->filter(function($cycle) {
                $stdError = json_decode($cycle->std_error, true);
                return ($stdError[0][0] ?? 0) && ($stdError[1][0] ?? 0);
            })->count();
            return ($good / $cycles->count() * 100) < $threshold;
        });