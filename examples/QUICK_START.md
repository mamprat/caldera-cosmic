# DWP Realistic Press Simulation - Quick Start Guide

## Overview

This enhanced DWP polling system now captures **ALL manufacturing cycles** with realistic press timing patterns:
- ✅ **Zero Data Loss** - Every cycle is saved
- ⏱️ **Realistic Timing** - 15-20 second cycles (actual 16s)
- 🔄 **Variable L/R Gap** - 2-8 seconds between positions
- 🏭 **4 Machines** - Running simultaneously
- 📊 **Quality Tracking** - Boolean array format for easy analysis

---

## Data Structure (Clean & Simple)

### Boolean Quality Array Format
```json
"std_error": "[[th_quality],[side_quality]]"

Examples:
- Both good:     [[1],[1]]
- Both bad:      [[0],[0]]
- TH good only:  [[1],[0]]
- Side good only:[[0],[1]]
```

### Enhanced PV Field
```json
"pv": {
  "waveforms": [[th_30_points], [side_30_points]],
  "quality": {
    "grade": "EXCELLENT",
    "peaks": {"th": 42, "side": 40},
    "cycle_type": "COMPLETE",
    "sample_count": 30,
    "actual_cycle_time": 16
  }
}
```

---

## Quick Start - Generate Simulation Data

### Option 1: Simple Simulation (Single Line)
```bash
# Generate 200 cycles for LINEA spread over 1 day
php artisan app:simulate-dwp-data --count=200 --line=LINEA --days=1

# Generate 500 cycles for LINEB spread over 7 days
php artisan app:simulate-dwp-data --count=500 --line=LINEB --days=7
```

### Option 2: Comprehensive Simulation (All Lines)
```bash
# Generates realistic data for LINEA, LINEB, LINEC with all 4 machines
php examples/run_dwp_simulation.php
```

### Option 3: Real-time Monitoring Simulation
```bash
# Live simulation showing 4 machines running simultaneously
php examples/realtime_press_monitor.php
```

---

## Press Timing Characteristics (Actual Manufacturing)

### 1. Cycle Time: 15-20 seconds (typically 16s)
```
Standard:  16 seconds
Range:     15-20 seconds
Variation: Random ±1 to +4 seconds
```

### 2. L/R Position Gap: 2-8 seconds (NOT standardized)
```
- L position starts first
- R position starts 2-8 seconds later (variable)
- Gap time is NOT consistent (realistic manufacturing)
```

### 3. Multiple Machines: 4 machines running simultaneously
```
Machine 1, 2, 3, 4 operate independently
Each has different modbus addresses (only for polling)
All contribute to the same production line count
```

### 4. Press Waveform Pattern
```
Phase 1: Ramp Up    (30% of cycle) - Pressure increases
Phase 2: Peak Hold  (40% of cycle) - Maintain peak pressure
Phase 3: Release    (30% of cycle) - Pressure decreases
```

---

## Data Analysis Examples

### 1. Count All Cycles vs Quality Cycles
```php
// Total cycles (no data loss!)
$totalCycles = InsDwpCount::count();

// Both sensors good
$goodCycles = InsDwpCount::whereRaw("JSON_EXTRACT(std_error, '$[0][0]') = 1")
    ->whereRaw("JSON_EXTRACT(std_error, '$[1][0]') = 1")
    ->count();

$qualityRate = round($goodCycles / $totalCycles * 100, 1);
echo "Quality Rate: {$qualityRate}%\n";
```

### 2. Find Sensor-Specific Issues
```php
// TH sensor problems
$thIssues = InsDwpCount::whereRaw("JSON_EXTRACT(std_error, '$[0][0]') = 0")->count();

// Side sensor problems
$sideIssues = InsDwpCount::whereRaw("JSON_EXTRACT(std_error, '$[1][0]') = 0")->count();

echo "TH Sensor Issues: {$thIssues}\n";
echo "Side Sensor Issues: {$sideIssues}\n";
```

### 3. Quality Stats by Line
```sql
SELECT
    line,
    COUNT(*) as total_cycles,
    SUM(JSON_EXTRACT(std_error, '$[0][0]') * JSON_EXTRACT(std_error, '$[1][0]')) as good_cycles,
    ROUND(SUM(JSON_EXTRACT(std_error, '$[0][0]') * JSON_EXTRACT(std_error, '$[1][0]')) / COUNT(*) * 100, 2) as quality_rate
FROM ins_dwp_counts
GROUP BY line
ORDER BY quality_rate DESC;
```

### 4. Hourly Quality Trends
```sql
SELECT
    HOUR(created_at) as hour,
    COUNT(*) as cycles,
    SUM(JSON_EXTRACT(std_error, '$[0][0]') * JSON_EXTRACT(std_error, '$[1][0]')) as good,
    ROUND(SUM(JSON_EXTRACT(std_error, '$[0][0]') * JSON_EXTRACT(std_error, '$[1][0]')) / COUNT(*) * 100, 1) as rate
FROM ins_dwp_counts
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY HOUR(created_at)
ORDER BY hour;
```

### 5. Machine Performance Comparison
```sql
SELECT
    mechine,
    COUNT(*) as total_cycles,
    SUM(JSON_EXTRACT(std_error, '$[0][0]') * JSON_EXTRACT(std_error, '$[1][0]')) as good_cycles,
    ROUND(AVG(duration), 1) as avg_cycle_time,
    ROUND(SUM(JSON_EXTRACT(std_error, '$[0][0]') * JSON_EXTRACT(std_error, '$[1][0]')) / COUNT(*) * 100, 1) as quality_rate
FROM ins_dwp_counts
WHERE line = 'LINEA'
GROUP BY mechine
ORDER BY quality_rate DESC;
```

### 6. Access Waveform and Quality Data
```php
$cycle = InsDwpCount::find(1);

// Get std_error boolean array
$stdError = json_decode($cycle->std_error, true);
$thQuality = $stdError[0][0];     // 1 or 0
$sideQuality = $stdError[1][0];   // 1 or 0

// Get enhanced PV data
$pvData = json_decode($cycle->pv, true);
$thWaveform = $pvData['waveforms'][0];        // 30 points
$sideWaveform = $pvData['waveforms'][1];      // 30 points
$grade = $pvData['quality']['grade'];         // EXCELLENT, GOOD, etc
$thPeak = $pvData['quality']['peaks']['th'];  // Peak pressure value
$sidePeak = $pvData['quality']['peaks']['side'];
$actualTime = $pvData['quality']['actual_cycle_time']; // 15-20 seconds

echo "Quality: " . ($thQuality && $sideQuality ? 'GOOD' : 'BAD') . "\n";
echo "Grade: {$grade}\n";
echo "Peaks: TH={$thPeak}, Side={$sidePeak}\n";
echo "Cycle Time: {$actualTime}s\n";
```

---

## Quality Grades Explained

| Grade | Description | TH Range | Side Range | std_error | Typical % |
|-------|-------------|----------|------------|-----------|-----------|
| EXCELLENT | Optimal quality | 32-42 | 32-42 | [[1],[1]] | 60-65% |
| GOOD | Acceptable quality | 30-45 | 30-45 | [[1],[1]] | 20-25% |
| MARGINAL | One sensor marginal | 30-45 or 25-55 | 25-55 or 30-45 | [[1],[0]] or [[0],[1]] | 7-10% |
| DEFECTIVE | Poor quality | Outside range | Outside range | [[0],[0]] | 2-3% |
| SENSOR_ISSUES | Sensor malfunction | <10 or >80 | <10 or >80 | [[0],[0]] | 1-2% |
| SHORT_CYCLE | Aborted early | - | - | [[0],[0]] | Rare |
| OVERFLOW | Buffer overflow | - | - | [[0],[0]] | Rare |

---

## Real Production Usage

### Start Enhanced Polling (No Data Loss!)
```bash
# Run with verbose output
php artisan app:ins-dwp-poll --v

# Run with debug output
php artisan app:ins-dwp-poll --v --d
```

### Monitor Output
```
✅ Saved EXCELLENT cycle for LINEA-mc1-L. Peaks: TH=38(1), Side=40(1). Total: 12345
⚠️  Saved MARGINAL cycle for LINEA-mc2-R. Peaks: TH=42(1), Side=28(0). Total: 12346
❌ Saved DEFECTIVE cycle for LINEA-mc3-L. Peaks: TH=22(0), Side=65(0). Total: 12347
```

**Legend:**
- ✅ Both sensors good
- ⚠️  One sensor has issues
- ❌ Both sensors have issues
- (1) = Good quality
- (0) = Bad quality

---

## Benefits of Enhanced System

### Before Enhancement ❌
```
❌ Only saved "good quality" cycles
❌ Lost 15-40% of actual production data
❌ No visibility into quality trends
❌ Couldn't identify sensor degradation
❌ Incomplete production counts
```

### After Enhancement ✅
```
✅ Saves ALL cycles (100% data capture)
✅ Complete production counts
✅ Quality tracking with boolean flags
✅ Sensor performance analysis
✅ Quality trend monitoring
✅ Root cause analysis capability
✅ No database schema changes needed
```

---

## Key Features

### 1. Zero Data Loss
Every press cycle is captured and saved, regardless of quality.

### 2. Simple Quality Checking
Boolean array format makes it easy to filter and analyze:
```php
// Good quality cycles
WHERE JSON_EXTRACT(std_error, '$[0][0]') = 1 
  AND JSON_EXTRACT(std_error, '$[1][0]') = 1

// Any quality issues
WHERE JSON_EXTRACT(std_error, '$[0][0]') = 0 
   OR JSON_EXTRACT(std_error, '$[1][0]') = 0
```

### 3. Realistic Timing Simulation
- 15-20 second press cycles (actual 16s)
- Variable 2-8 second L/R gap
- 4 machines running simultaneously
- Realistic production breaks and variations

### 4. Rich Analysis Data
- Full 30-point pressure waveforms
- Peak pressure values
- Quality grades and classifications
- Actual cycle timing
- Sample counts

---

## Quick Commands Cheat Sheet

```bash
# Generate sample data
php artisan app:simulate-dwp-data --count=200 --line=LINEA

# Comprehensive simulation
php examples/run_dwp_simulation.php

# Real-time monitor
php examples/realtime_press_monitor.php

# Start actual polling
php artisan app:ins-dwp-poll --v

# Laravel Tinker for analysis
php artisan tinker
>>> InsDwpCount::count()
>>> InsDwpCount::whereRaw("JSON_EXTRACT(std_error, '$[0][0]') = 1")->count()
```

---

## Troubleshooting

### No cycles being saved?
1. Check device configuration in `ins_dwp_devices` table
2. Verify modbus connectivity
3. Check polling command is running with `--d` flag

### Quality rates too low?
1. Review sensor calibration (30-45 is good range)
2. Check for consistent sensor issues
3. Analyze quality trends over time

### Performance issues?
1. Add indexes on `line`, `mechine`, `created_at` columns
2. Use pagination for large queries
3. Archive old data periodically

---

## Next Steps

1. **Generate Test Data**: Run simulation to understand data structure
2. **Analyze Patterns**: Use SQL queries to explore quality trends
3. **Build Dashboards**: Create real-time monitoring views
4. **Set Alerts**: Configure notifications for quality issues
5. **Start Polling**: Deploy enhanced polling command to production

---

🎯 **Remember**: With this enhanced system, you never lose production data again!