-- DWP Data Analysis SQL Queries
-- Using the boolean array format for std_error: [[th_quality],[side_quality]]
-- where array[0][0] = toe/heel quality (1=good, 0=bad)
-- where array[1][0] = side quality (1=good, 0=bad)

-- =============================================
-- Basic Quality Queries
-- =============================================

-- Get all cycles where both sensors are good
SELECT * FROM ins_dwp_counts
WHERE JSON_EXTRACT(std_error, '$[0][0]') = 1
  AND JSON_EXTRACT(std_error, '$[1][0]') = 1;

-- Get cycles where both sensors are bad
SELECT * FROM ins_dwp_counts
WHERE JSON_EXTRACT(std_error, '$[0][0]') = 0
  AND JSON_EXTRACT(std_error, '$[1][0]') = 0;

-- Get cycles where only toe/heel is good
SELECT * FROM ins_dwp_counts
WHERE JSON_EXTRACT(std_error, '$[0][0]') = 1
  AND JSON_EXTRACT(std_error, '$[1][0]') = 0;

-- Get cycles where only side is good
SELECT * FROM ins_dwp_counts
WHERE JSON_EXTRACT(std_error, '$[0][0]') = 0
  AND JSON_EXTRACT(std_error, '$[1][0]') = 1;

-- =============================================
-- Quality Statistics by Line
-- =============================================

-- Count quality distribution by line
SELECT
    line,
    COUNT(*) as total_cycles,
    SUM(JSON_EXTRACT(std_error, '$[0][0]')) as th_good_count,
    SUM(JSON_EXTRACT(std_error, '$[1][0]')) as side_good_count,
    SUM(JSON_EXTRACT(std_error, '$[0][0]') * JSON_EXTRACT(std_error, '$[1][0]')) as both_good_count,
    ROUND(SUM(JSON_EXTRACT(std_error, '$[0][0]')) / COUNT(*) * 100, 2) as th_success_rate,
    ROUND(SUM(JSON_EXTRACT(std_error, '$[1][0]')) / COUNT(*) * 100, 2) as side_success_rate,
    ROUND(SUM(JSON_EXTRACT(std_error, '$[0][0]') * JSON_EXTRACT(std_error, '$[1][0]')) / COUNT(*) * 100, 2) as overall_quality_rate
FROM ins_dwp_counts
GROUP BY line
ORDER BY overall_quality_rate DESC;

-- =============================================
-- Machine Performance Analysis
-- =============================================

-- Machine performance by line and machine
SELECT
    line,
    mechine,
    position,
    COUNT(*) as total_cycles,
    SUM(JSON_EXTRACT(std_error, '$[0][0]') * JSON_EXTRACT(std_error, '$[1][0]')) as good_cycles,
    ROUND(SUM(JSON_EXTRACT(std_error, '$[0][0]') * JSON_EXTRACT(std_error, '$[1][0]')) / COUNT(*) * 100, 2) as quality_rate
FROM ins_dwp_counts
GROUP BY line, mechine, position
ORDER BY line, mechine, position;

-- Find machines with quality issues (below 80% quality rate)
SELECT
    line,
    mechine,
    COUNT(*) as total_cycles,
    SUM(JSON_EXTRACT(std_error, '$[0][0]') * JSON_EXTRACT(std_error, '$[1][0]')) as good_cycles,
    ROUND(SUM(JSON_EXTRACT(std_error, '$[0][0]') * JSON_EXTRACT(std_error, '$[1][0]')) / COUNT(*) * 100, 2) as quality_rate
FROM ins_dwp_counts
GROUP BY line, mechine
HAVING quality_rate < 80
ORDER BY quality_rate ASC;

-- =============================================
-- Time-Based Quality Analysis
-- =============================================

-- Hourly quality trends for last 24 hours
SELECT
    HOUR(created_at) as hour_of_day,
    COUNT(*) as total_cycles,
    SUM(JSON_EXTRACT(std_error, '$[0][0]') * JSON_EXTRACT(std_error, '$[1][0]')) as good_cycles,
    ROUND(SUM(JSON_EXTRACT(std_error, '$[0][0]') * JSON_EXTRACT(std_error, '$[1][0]')) / COUNT(*) * 100, 2) as quality_rate
FROM ins_dwp_counts
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY HOUR(created_at)
ORDER BY hour_of_day;

-- Daily quality trends
SELECT
    DATE(created_at) as production_date,
    COUNT(*) as total_cycles,
    SUM(JSON_EXTRACT(std_error, '$[0][0]')) as th_good,
    SUM(JSON_EXTRACT(std_error, '$[1][0]')) as side_good,
    SUM(JSON_EXTRACT(std_error, '$[0][0]') * JSON_EXTRACT(std_error, '$[1][0]')) as both_good,
    ROUND(SUM(JSON_EXTRACT(std_error, '$[0][0]') * JSON_EXTRACT(std_error, '$[1][0]')) / COUNT(*) * 100, 2) as quality_rate
FROM ins_dwp_counts
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY DATE(created_at)
ORDER BY production_date DESC;

-- =============================================
-- Sensor-Specific Analysis
-- =============================================

-- Find cycles with toe/heel sensor issues
SELECT
    id, line, mechine, position, created_at,
    JSON_EXTRACT(pv, '$.quality.peaks.th') as th_peak,
    JSON_EXTRACT(pv, '$.quality.peaks.side') as side_peak,
    JSON_EXTRACT(pv, '$.quality.grade') as quality_grade
FROM ins_dwp_counts
WHERE JSON_EXTRACT(std_error, '$[0][0]') = 0
ORDER BY created_at DESC
LIMIT 20;

-- Find cycles with side sensor issues
SELECT
    id, line, mechine, position, created_at,
    JSON_EXTRACT(pv, '$.quality.peaks.th') as th_peak,
    JSON_EXTRACT(pv, '$.quality.peaks.side') as side_peak,
    JSON_EXTRACT(pv, '$.quality.grade') as quality_grade
FROM ins_dwp_counts
WHERE JSON_EXTRACT(std_error, '$[1][0]') = 0
ORDER BY created_at DESC
LIMIT 20;

-- Compare sensor performance
SELECT
    'Toe/Heel Sensor' as sensor_type,
    COUNT(*) as total_cycles,
    SUM(JSON_EXTRACT(std_error, '$[0][0]')) as good_readings,
    ROUND(SUM(JSON_EXTRACT(std_error, '$[0][0]')) / COUNT(*) * 100, 2) as success_rate
FROM ins_dwp_counts
UNION ALL
SELECT
    'Side Sensor' as sensor_type,
    COUNT(*) as total_cycles,
    SUM(JSON_EXTRACT(std_error, '$[1][0]')) as good_readings,
    ROUND(SUM(JSON_EXTRACT(std_error, '$[1][0]')) / COUNT(*) * 100, 2) as success_rate
FROM ins_dwp_counts;

-- =============================================
-- Quality Pattern Analysis
-- =============================================

-- Quality pattern distribution
SELECT
    CASE
        WHEN JSON_EXTRACT(std_error, '$[0][0]') = 1 AND JSON_EXTRACT(std_error, '$[1][0]') = 1 THEN 'Both Good'
        WHEN JSON_EXTRACT(std_error, '$[0][0]') = 1 AND JSON_EXTRACT(std_error, '$[1][0]') = 0 THEN 'TH Good, Side Bad'
        WHEN JSON_EXTRACT(std_error, '$[0][0]') = 0 AND JSON_EXTRACT(std_error, '$[1][0]') = 1 THEN 'TH Bad, Side Good'
        ELSE 'Both Bad'
    END as quality_pattern,
    COUNT(*) as cycle_count,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM ins_dwp_counts), 2) as percentage
FROM ins_dwp_counts
GROUP BY quality_pattern
ORDER BY cycle_count DESC;

-- Quality grades from PV field
SELECT
    JSON_EXTRACT(pv, '$.quality.grade') as quality_grade,
    COUNT(*) as count,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM ins_dwp_counts), 2) as percentage
FROM ins_dwp_counts
GROUP BY JSON_EXTRACT(pv, '$.quality.grade')
ORDER BY count DESC;

-- =============================================
-- Advanced Analysis Queries
-- =============================================

-- Find consecutive quality issues (potential machine problems)
WITH quality_sequences AS (
    SELECT
        id, line, mechine, position, created_at,
        JSON_EXTRACT(std_error, '$[0][0]') * JSON_EXTRACT(std_error, '$[1][0]') as is_good_quality,
        ROW_NUMBER() OVER (PARTITION BY line, mechine, position ORDER BY created_at) as rn
    FROM ins_dwp_counts
    ORDER BY line, mechine, position, created_at
),
quality_groups AS (
    SELECT
        *,
        rn - ROW_NUMBER() OVER (PARTITION BY line, mechine, position, is_good_quality ORDER BY created_at) as grp
    FROM quality_sequences
)
SELECT
    line, mechine, position,
    COUNT(*) as consecutive_count,
    MIN(created_at) as start_time,
    MAX(created_at) as end_time,
    CASE WHEN is_good_quality = 0 THEN 'QUALITY ISSUES' ELSE 'GOOD QUALITY' END as pattern_type
FROM quality_groups
WHERE is_good_quality = 0  -- Only show quality issue streaks
GROUP BY line, mechine, position, is_good_quality, grp
HAVING COUNT(*) >= 3  -- 3 or more consecutive issues
ORDER BY consecutive_count DESC, start_time DESC;

-- Peak value analysis with quality correlation
SELECT
    JSON_EXTRACT(pv, '$.quality.grade') as grade,
    AVG(CAST(JSON_EXTRACT(pv, '$.quality.peaks.th') AS SIGNED)) as avg_th_peak,
    AVG(CAST(JSON_EXTRACT(pv, '$.quality.peaks.side') AS SIGNED)) as avg_side_peak,
    MIN(CAST(JSON_EXTRACT(pv, '$.quality.peaks.th') AS SIGNED)) as min_th_peak,
    MAX(CAST(JSON_EXTRACT(pv, '$.quality.peaks.th') AS SIGNED)) as max_th_peak,
    MIN(CAST(JSON_EXTRACT(pv, '$.quality.peaks.side') AS SIGNED)) as min_side_peak,
    MAX(CAST(JSON_EXTRACT(pv, '$.quality.peaks.side') AS SIGNED)) as max_side_peak,
    COUNT(*) as sample_count
FROM ins_dwp_counts
WHERE JSON_EXTRACT(pv, '$.quality.peaks.th') IS NOT NULL
GROUP BY JSON_EXTRACT(pv, '$.quality.grade')
ORDER BY avg_th_peak DESC;

-- =============================================
-- Real-time Monitoring Queries
-- =============================================

-- Current status - last 10 cycles per line
SELECT
    line,
    mechine,
    position,
    created_at,
    JSON_EXTRACT(std_error, '$[0][0]') as th_quality,
    JSON_EXTRACT(std_error, '$[1][0]') as side_quality,
    JSON_EXTRACT(pv, '$.quality.grade') as grade,
    JSON_EXTRACT(pv, '$.quality.peaks.th') as th_peak,
    JSON_EXTRACT(pv, '$.quality.peaks.side') as side_peak
FROM (
    SELECT *,
           ROW_NUMBER() OVER (PARTITION BY line ORDER BY created_at DESC) as rn
    FROM ins_dwp_counts
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
) ranked
WHERE rn <= 10
ORDER BY line, created_at DESC;

-- Alert conditions - recent quality issues
SELECT
    line,
    mechine,
    position,
    created_at,
    'QUALITY_ISSUE' as alert_type,
    CONCAT('TH:', JSON_EXTRACT(pv, '$.quality.peaks.th'),
           ' Side:', JSON_EXTRACT(pv, '$.quality.peaks.side'),
           ' Grade:', JSON_EXTRACT(pv, '$.quality.grade')) as details
FROM ins_dwp_counts
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
  AND (JSON_EXTRACT(std_error, '$[0][0]') = 0 OR JSON_EXTRACT(std_error, '$[1][0]') = 0)
ORDER BY created_at DESC;

-- =============================================
-- Performance KPI Dashboard Query
-- =============================================

-- Complete KPI summary
SELECT
    'Overall Performance' as metric_category,
    COUNT(*) as total_cycles,
    SUM(JSON_EXTRACT(std_error, '$[0][0]') * JSON_EXTRACT(std_error, '$[1][0]')) as good_cycles,
    ROUND(SUM(JSON_EXTRACT(std_error, '$[0][0]') * JSON_EXTRACT(std_error, '$[1][0]')) / COUNT(*) * 100, 2) as quality_rate,
    ROUND(AVG(duration), 2) as avg_cycle_time,
    COUNT(DISTINCT line) as active_lines,
    COUNT(DISTINCT CONCAT(line, '-', mechine)) as active_machines
FROM ins_dwp_counts
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR);
