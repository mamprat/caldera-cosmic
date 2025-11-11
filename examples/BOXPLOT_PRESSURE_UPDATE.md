# Boxplot Pressure Chart Update Summary

## Overview
Updated the DWP pressure boxplot chart (`pressure.blade.php`) to support the enhanced PV data structure with waveforms while maintaining backward compatibility with the legacy format.

---

## Key Changes Made

### 1. **Enhanced PV Structure Support**

**Before (Legacy Format Only):**
```php
foreach ($counts as $count) {
    $arrayPv = json_decode($count['pv'], true);
    // Only supported old format
    if (isset($arrayPv[0]) && isset($arrayPv[1])) {
        $toeHeelArray = $arrayPv[0];
        $sideArray = $arrayPv[1];
        // ... process data
    }
}
```

**After (Enhanced + Legacy Support):**
```php
foreach ($counts as $count) {
    $arrayPv = json_decode($count['pv'], true);

    // Check for enhanced PV structure first
    if (isset($arrayPv['waveforms']) && is_array($arrayPv['waveforms'])) {
        // Enhanced format: extract waveforms
        $waveforms = $arrayPv['waveforms'];
        $toeHeelArray = $waveforms[0] ?? [];
        $sideArray = $waveforms[1] ?? [];
    } elseif (isset($arrayPv[0]) && isset($arrayPv[1])) {
        // Legacy format: direct array access
        $toeHeelArray = $arrayPv[0];
        $sideArray = $arrayPv[1];
    } else {
        // Invalid format, skip this record
        continue;
    }

    // Calculate median for each sensor array
    $toeHeelMedian = $this->getMedian($toeHeelArray);
    $sideMedian = $this->getMedian($sideArray);

    // ... rest of processing
}
```

---

## Data Formats Supported

### Enhanced PV Format (New)
```json
{
  "waveforms": [
    [0, 5, 12, 25, 38, 42, 38, 25, 12, 5, 0, ...],  // Toe-Heel waveform (30 points)
    [0, 3, 8, 15, 28, 40, 35, 22, 10, 3, 0, ...]    // Side waveform (30 points)
  ],
  "quality": {
    "grade": "EXCELLENT",
    "peaks": {"th": 42, "side": 40},
    "cycle_type": "COMPLETE",
    "sample_count": 30,
    "actual_cycle_time": 16
  }
}
```

### Legacy Format (Old - Still Supported)
```json
[
  [0, 5, 12, 25, 38, 42, 38, 25, 12, 5, 0, ...],  // Toe-Heel array
  [0, 3, 8, 15, 28, 40, 35, 22, 10, 3, 0, ...]    // Side array
]
```

---

## Boxplot Chart Features

### What the Boxplot Shows
The chart displays the distribution of pressure readings for 4 sensors:
1. **Toe-Heel Left** (L position)
2. **Toe-Heel Right** (R position)
3. **Side Left** (L position)
4. **Side Right** (R position)

### 5-Point Summary
Each boxplot shows:
- **Min**: Minimum pressure value
- **Q1**: First quartile (25th percentile)
- **Median**: Middle value (50th percentile)
- **Q3**: Third quartile (75th percentile)
- **Max**: Maximum pressure value

### Calculation Process
```php
// 1. Extract waveform data (30 points per sensor)
$toeHeelArray = $waveforms[0]; // or $arrayPv[0] for legacy

// 2. Calculate median of the waveform
$toeHeelMedian = $this->getMedian($toeHeelArray);

// 3. Collect medians by position
if ($count['position'] === 'L') {
    $toeheel_left_data[] = $toeHeelMedian;
    $side_left_data[] = $sideMedian;
}

// 4. Calculate 5-point summary for boxplot
$boxplot = $this->getBoxplotSummary($toeheel_left_data);
// Returns: [min, q1, median, q3, max]
```

---

## Benefits of the Update

### 1. **Backward Compatibility**
✅ Works with old format: `[[th_values], [side_values]]`
✅ Works with new format: `{waveforms: [...], quality: {...}}`
✅ No data migration required
✅ Smooth transition period

### 2. **Future-Ready**
✅ Can leverage quality metadata when needed
✅ Access to actual cycle times
✅ Quality grade information available
✅ Peak values pre-calculated

### 3. **Data Integrity**
✅ Invalid records are skipped gracefully
✅ No breaking changes to existing charts
✅ Maintains statistical accuracy

### 4. **Performance**
✅ Same calculation speed
✅ No additional database queries
✅ Efficient data extraction

---

## Chart Visualization

### ApexCharts Boxplot Configuration
```javascript
series: [{
    name: 'Performance',
    data: [
        { x: 'Toe-Heel Left', y: [20, 30, 35, 40, 50] },
        { x: 'Toe-Heel Right', y: [22, 32, 37, 42, 52] },
        { x: 'Side Left', y: [18, 28, 33, 38, 48] },
        { x: 'Side Right', y: [19, 29, 34, 39, 49] }
    ]
}]
```

### Interpretation Guide
```
┌─────────────────────────────────────┐
│  Toe-Heel Left Boxplot              │
│                                     │
│  Max (50) ─────────── ✱             │
│                        │            │
│  Q3 (40)  ────────┐    │            │
│                   │    │            │
│  Median (35) ─────┼────┤            │
│                   │    │            │
│  Q1 (30)  ────────┘    │            │
│                        │            │
│  Min (20) ─────────── ✱             │
│                                     │
└─────────────────────────────────────┘

Interpretation:
- 50% of values are between Q1 (30) and Q3 (40)
- Median is 35 (middle value)
- Range is 20-50 (min to max)
- Spread indicates pressure variation
```

---

## Usage Examples

### Filter by Machine and Date Range
```php
// URL parameters
?start_at=2024-01-01&end_at=2024-01-31&line=G5&machine=1

// Livewire properties
$this->start_at = '2024-01-01';
$this->end_at = '2024-01-31';
$this->line = 'G5';
$this->machine = '1';

// Chart shows boxplot for Machine 1 in January 2024
```

### Compare Multiple Machines
```
Select: All Machines (leave machine filter empty)
Result: Boxplot shows aggregate data across all machines
```

### Analyze Quality by Position
```
Left Position (L):
- Toe-Heel Left median: 35 kg
- Side Left median: 33 kg

Right Position (R):
- Toe-Heel Right median: 37 kg
- Side Right median: 34 kg

Analysis: Right position shows slightly higher pressure
```

---

## Statistical Insights

### What to Look For

#### 1. **Narrow Boxes (Low Variability)**
```
Q3-Q1 small = Consistent pressure = Good quality
Example: [30, 35, 37, 38, 42] → IQR = 3
```

#### 2. **Wide Boxes (High Variability)**
```
Q3-Q1 large = Inconsistent pressure = Quality issues
Example: [20, 30, 35, 45, 55] → IQR = 15
```

#### 3. **Median Position**
```
Median near Q2 = Symmetric distribution = Normal
Median near Q1 = Right-skewed = Some high outliers
Median near Q3 = Left-skewed = Some low outliers
```

#### 4. **Outliers (Beyond Whiskers)**
```
Values far from box = Abnormal cycles
Need investigation for sensor issues
```

---

## Testing the Updated Chart

### 1. Generate Test Data
```bash
# Create realistic test data with enhanced format
php artisan app:simulate-dwp-data --count=200 --line=G5 --days=7
```

### 2. Access Pressure Chart
```
Navigate to: /insights/dwp/data/pressure
Set filters:
- Start Date: Last week
- End Date: Today
- Line: G5
- Machine: 1
```

### 3. Verify Boxplot Display
✅ Chart shows 4 boxplots (TH-L, TH-R, S-L, S-R)
✅ Each boxplot has 5-point summary visible
✅ Tooltip shows exact values on hover
✅ Data updates when filters change

### 4. Check Data Formats
```php
// Test with old format data
$oldFormat = [[30,35,40], [32,37,42]];
// Should work ✓

// Test with new format data
$newFormat = [
    'waveforms' => [[30,35,40], [32,37,42]],
    'quality' => ['grade' => 'EXCELLENT']
];
// Should work ✓
```

---

## Troubleshooting

### Issue: Chart Not Displaying
**Solution:**
1. Check console for JavaScript errors
2. Verify ApexCharts library is loaded
3. Ensure `$wire.$dispatch('updated')` is called

### Issue: Empty Boxplots
**Solution:**
1. Verify date range has data
2. Check machine/line filters
3. Ensure PV data exists in database

### Issue: Incorrect Values
**Solution:**
1. Check PV JSON structure in database
2. Verify median calculation logic
3. Test with known data samples

---

## Code Quality Improvements

### 1. **Cleaner Data Extraction**
```php
// Clear check for data format
if (isset($arrayPv['waveforms']) && is_array($arrayPv['waveforms'])) {
    // Enhanced format
} elseif (isset($arrayPv[0]) && isset($arrayPv[1])) {
    // Legacy format
} else {
    // Invalid - skip gracefully
    continue;
}
```

### 2. **Robust Error Handling**
```php
// Gracefully handle invalid data
if (empty($toeHeelArray) || empty($sideArray)) {
    continue; // Skip this record
}
```

### 3. **Consistent Median Calculation**
```php
// Reusable median function
private function getMedian(array $array) {
    if (empty($array)) return 0;
    $numericArray = array_filter($array, 'is_numeric');
    if (empty($numericArray)) return 0;
    // ... calculate median
}
```

---

## Migration Notes

### No Breaking Changes
- Existing boxplot charts continue to work
- Old data displays correctly
- New data uses enhanced structure
- Transition is seamless

### Deployment Steps
1. ✅ Deploy updated code
2. ✅ Start enhanced polling (generates new format)
3. ✅ Old data continues to display
4. ✅ New data uses enhanced format
5. ✅ No downtime required

---

## Future Enhancements

### Potential Improvements

#### 1. **Quality-Based Coloring**
```javascript
// Color boxplots based on quality grade
colors: function({ value, seriesIndex, dataPointIndex, w }) {
    if (qualityGrade === 'EXCELLENT') return '#22c55e'; // Green
    if (qualityGrade === 'GOOD') return '#3b82f6';      // Blue
    if (qualityGrade === 'MARGINAL') return '#f59e0b';  // Orange
    return '#ef4444'; // Red for defective
}
```

#### 2. **Cycle Time Correlation**
```
Show correlation between cycle time and pressure distribution
Identify optimal cycle times for quality
```

#### 3. **Machine Comparison View**
```
Side-by-side boxplots for multiple machines
Easy performance comparison
Identify best-performing machines
```

#### 4. **Outlier Details**
```
Click on outlier points to see:
- Timestamp
- Machine
- Position
- Full waveform
- Quality grade
```

---

## Conclusion

The pressure boxplot chart has been successfully updated to:
- ✅ Support enhanced PV data structure
- ✅ Maintain backward compatibility
- ✅ Extract waveforms correctly
- ✅ Handle both old and new formats
- ✅ Provide accurate statistical visualizations

The chart now works seamlessly with the enhanced zero-data-loss system while maintaining all existing functionality.

🎉 **Result: Future-proof boxplot chart with backward compatibility!**