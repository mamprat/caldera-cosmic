<?php

use Livewire\Volt\Component;
use Livewire\Attributes\On;
use App\Models\InsCtcMetric;
use Carbon\Carbon;
use Illuminate\Support\Facades\Response;

new class extends Component {
    public int $id = 0;
    public bool $header = true;
    public array $batch = [
        "id" => 0,
        "rubber_batch_code" => "",
        "machine_line" => "",
        "mcs" => "",
        "recipe_id" => 0,
        "recipe_name" => "",
        "recipe_component" => "",
        "t_avg_left" => 0,
        "t_avg_right" => 0,
        "t_avg" => 0,
        "t_mae_left" => 0,
        "t_mae_right" => 0,
        "t_mae" => 0,
        "t_ssd_left" => 0,
        "t_ssd_right" => 0,
        "t_ssd" => 0,
        "t_balance" => 0,
        "correction_uptime" => 0,
        "correction_rate" => 0,
        "quality_status" => "fail",
        "data" => "",
        "started_at" => "",
        "ended_at" => "",
        "duration" => "",
        "shift" => "",
        "corrections_left" => 0,
        "corrections_right" => 0,
        "corrections_total" => 0,
        "recipe_std_min" => null,
        "recipe_std_mid" => null,
        "recipe_std_max" => null,
        "actual_std_min" => null,
        "actual_std_mid" => null,
        "actual_std_max" => null,
    ];

    public $metric = null;
    private const CORRECTION_THRESHOLD = 0.05;
    
    public function getCanDownloadBatchCsvProperty(): bool
    {
        $user = auth()->user();
        if (!$user) return false;
        if ($user->id === 1) return true;
        
        try {
            $auth = \App\Models\InsCtcAuth::where('user_id', $user->id)->first();
            if ($auth) {
                $actions = json_decode($auth->actions ?? '[]', true);
                return in_array('batch-detail-download', $actions);
            }
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function mount()
    {
        if ($this->id) {
            $this->loadMetric($this->id);
            $this->header = false;
        }
    }

    #[On("metric-detail-load")]
    public function loadMetric($id)
    {
        $this->id = $id;
        $this->metric = InsCtcMetric::with(["ins_ctc_machine", "ins_ctc_recipe", "ins_rubber_batch"])->find($id);

        if ($this->metric) {
            $correctionsByType = $this->countCorrectionsByType($this->metric->data);
            
            $this->batch = [
                "id" => $this->metric->id,
                "rubber_batch_code" => $this->metric->ins_rubber_batch->code ?? "N/A",
                "machine_line" => $this->metric->ins_ctc_machine->line ?? "N/A",
                "mcs" => $this->metric->ins_rubber_batch->mcs ?? "N/A",
                "recipe_id" => $this->metric->ins_ctc_recipe->id ?? "N/A",
                "recipe_name" => $this->metric->ins_ctc_recipe->name ?? "N/A",
                "recipe_component" => $this->metric->ins_ctc_recipe->component_model ?? "N/A",
                "recipe_std_min" => $this->metric->recipe_std_min ?? null,
                "recipe_std_mid" => $this->metric->recipe_std_mid ?? null,
                "recipe_std_max" => $this->metric->recipe_std_max ?? null,
                "actual_std_min" => $this->metric->actual_std_min ?? null,
                "actual_std_mid" => $this->metric->actual_std_mid ?? null,
                "actual_std_max" => $this->metric->actual_std_max ?? null,
                "t_avg_left" => $this->metric->t_avg_left,
                "t_avg_right" => $this->metric->t_avg_right,
                "t_avg" => $this->metric->t_avg,
                "t_mae_left" => $this->metric->t_mae_left,
                "t_mae_right" => $this->metric->t_mae_right,
                "t_mae" => $this->metric->t_mae,
                "t_ssd_left" => $this->metric->t_ssd_left,
                "t_ssd_right" => $this->metric->t_ssd_right,
                "t_ssd" => $this->metric->t_ssd,
                "t_balance" => $this->metric->t_balance,
                "correction_uptime" => $this->metric->correction_uptime,
                "correction_rate" => $this->metric->correction_rate,
                "quality_status" => $this->metric->t_mae <= 1.0 ? "pass" : "fail",
                "data" => $this->metric->data,
                "started_at" => $this->getStartedAt($this->metric->data),
                "ended_at" => $this->getEndedAt($this->metric->data),
                "duration" => $this->calculateDuration($this->metric->data),
                "shift" => $this->determineShift($this->metric->data),
                "corrections_left" => $this->countCorrections($this->metric->data, "left"),
                "corrections_right" => $this->countCorrections($this->metric->data, "right"),
                "corrections_total" => $this->countCorrections($this->metric->data, "total"),
                "thick_left" => $correctionsByType['thick_left'],
                "thick_right" => $correctionsByType['thick_right'],
                "thin_left" => $correctionsByType['thin_left'],
                "thin_right" => $correctionsByType['thin_right'],
            ];

            $this->generateChart();
        } else {
            $this->handleNotFound();
        }
    }

    private function getStartedAt($data): string
    {
        if (!$data || !is_array($data) || count($data) === 0) return "N/A";
        $firstTimestamp = $data[0][0] ?? null;
        if (!$firstTimestamp) return "N/A";
        try {
            return Carbon::parse($firstTimestamp)->format("H:i:s");
        } catch (Exception $e) {
            return "N/A";
        }
    }

    private function getEndedAt($data): string
    {
        if (!$data || !is_array($data) || count($data) === 0) return "N/A";
        $lastTimestamp = $data[count($data) - 1][0] ?? null;
        if (!$lastTimestamp) return "N/A";
        try {
            return Carbon::parse($lastTimestamp)->format("H:i:s");
        } catch (Exception $e) {
            return "N/A";
        }
    }

    private function calculateDuration($data): string
    {
        if (!$data || !is_array($data) || count($data) < 2) return "00:00:00";
        $firstTimestamp = $data[0][0] ?? null;
        $lastTimestamp = $data[count($data) - 1][0] ?? null;
        if (!$firstTimestamp || !$lastTimestamp) return "00:00:00";
        try {
            $start = Carbon::parse($firstTimestamp);
            $end = Carbon::parse($lastTimestamp);
            $interval = $start->diff($end);
            return sprintf("%02d:%02d:%02d", $interval->h, $interval->i, $interval->s);
        } catch (Exception $e) {
            return "00:00:00";
        }
    }

    private function determineShift($data): string
    {
        if (!$data || !is_array($data) || count($data) === 0) return "N/A";
        $firstTimestamp = $data[0][0] ?? null;
        if (!$firstTimestamp) return "N/A";
        try {
            $hour = Carbon::parse($firstTimestamp)->format("H");
            $hour = (int) $hour;
            if ($hour >= 6 && $hour < 14) return "1";
            elseif ($hour >= 14 && $hour < 22) return "2";
            else return "3";
        } catch (Exception $e) {
            return "N/A";
        }
    }

    private function countCorrections($data, $type = "total"): int
    {
        if (!$data || !is_array($data)) return 0;
        $leftCount = 0;
        $rightCount = 0;
        foreach ($data as $index => $point) {
            $actionLeft = $point[2] ?? 0;
            $actionRight = $point[3] ?? 0;
            if ($actionLeft == 1 || $actionLeft == 2) {
                $effectiveChange = $this->calculateEffectiveChange($data, $index, 'left');
                if ($effectiveChange !== null && isset($effectiveChange['abs_change'])) {
                    if ($effectiveChange['abs_change'] >= self::CORRECTION_THRESHOLD) {
                        $leftCount++;
                    }
                }
            }
            if ($actionRight == 1 || $actionRight == 2) {
                $effectiveChange = $this->calculateEffectiveChange($data, $index, 'right');
                if ($effectiveChange !== null && isset($effectiveChange['abs_change'])) {
                    if ($effectiveChange['abs_change'] >= self::CORRECTION_THRESHOLD) {
                        $rightCount++;
                    }
                }
            }
        }
        switch ($type) {
            case "left": return $leftCount;
            case "right": return $rightCount;
            case "total":
            default: return $leftCount + $rightCount;
        }
    }

    private function countCorrectionsByType($data): array
    {
        if (!$data || !is_array($data)) {
            return ['thick_left' => 0, 'thick_right' => 0, 'thin_left' => 0, 'thin_right' => 0];
        }
        $thickLeft = 0; $thickRight = 0; $thinLeft = 0; $thinRight = 0;
        foreach ($data as $index => $point) {
            $actionLeft = isset($point[2]) ? (int)$point[2] : 0;
            $actionRight = isset($point[3]) ? (int)$point[3] : 0;
            if ($actionLeft !== 0) {
                $effectiveChange = $this->calculateEffectiveChange($data, $index, 'left');
                if ($effectiveChange !== null && isset($effectiveChange['abs_change'])) {
                    if ($effectiveChange['abs_change'] >= self::CORRECTION_THRESHOLD) {
                        if ($actionLeft === 2) $thickLeft++;
                        elseif ($actionLeft === 1) $thinLeft++;
                    }
                }
            }
            if ($actionRight !== 0) {
                $effectiveChange = $this->calculateEffectiveChange($data, $index, 'right');
                if ($effectiveChange !== null && isset($effectiveChange['abs_change'])) {
                    if ($effectiveChange['abs_change'] >= self::CORRECTION_THRESHOLD) {
                        if ($actionRight === 2) $thickRight++;
                        elseif ($actionRight === 1) $thinRight++;
                    }
                }
            }
        }
        return ['thick_left' => $thickLeft, 'thick_right' => $thickRight, 'thin_left' => $thinLeft, 'thin_right' => $thinRight];
    }

    private function calculateEffectiveChange($data, $dataIndex, $side): ?array
    {
        // Validasi index
        if ($dataIndex < 0 || $dataIndex >= count($data)) {
            return null;
        }
        
        $currentPoint = $data[$dataIndex];
        $currentValue = $side === 'left' ? ($currentPoint[4] ?? 0) : ($currentPoint[5] ?? 0);
        $currentAction = $side === 'left' ? ($currentPoint[2] ?? 0) : ($currentPoint[3] ?? 0);
        
        // Jika tidak ada action, return null
        if ($currentAction == 0) {
            return null;
        }
        
        // ✅ FIX #1: Perluas search range untuk stabilized value
        $searchRange = min(15, count($data) - $dataIndex - 1);
        
        // Jika search range terlalu kecil, return null
        if ($searchRange < 5) {
            return null;
        }
        
        // ✅ FIX #2: Cari nilai yang sudah stabil (skip transition period)
        $futureValue = null;
        $foundIndex = -1;
        
        // Mulai dari point ke-5 (skip 1-4 untuk transition)
        for ($i = 5; $i <= $searchRange; $i++) {
            $futurePoint = $data[$dataIndex + $i];
            $futureAction = $side === 'left' ? ($futurePoint[2] ?? 0) : ($futurePoint[3] ?? 0);
            $futureVal = $side === 'left' ? ($futurePoint[4] ?? 0) : ($futurePoint[5] ?? 0);
            
            // Ambil nilai saat:
            // 1. Tidak ada trigger baru (sudah stabil), ATAU
            // 2. Sudah di point ke-10 (cukup waktu untuk stabilize)
            if ($futureAction == 0 || $i >= 10) {
                $futureValue = $futureVal;
                $foundIndex = $i;
                break;
            }
        }
        
        // Jika tidak ketemu future value yang valid
        if ($futureValue === null || $futureValue == 0) {
            return null;
        }
        
        // ✅ FIX #3: Hitung perubahan DENGAN ARAH (signed change)
        $change = $futureValue - $currentValue; // TIDAK PAKAI abs()
        
        // ✅ FIX #4: Validasi konsistensi antara action dan arah perubahan
        $expectedDirection = null;
        $actualDirection = null;
        $isConsistent = true;
        
        if ($currentAction == 1) { // Menipiskan
            $expectedDirection = 'turun';
            $actualDirection = $change < 0 ? 'turun' : 'naik';
            $isConsistent = ($change < 0); // Seharusnya negatif (turun)
            
        } elseif ($currentAction == 2) { // Menebalkan
            $expectedDirection = 'naik';
            $actualDirection = $change > 0 ? 'naik' : 'turun';
            $isConsistent = ($change > 0); // Seharusnya positif (naik)
        }
        
        // ✅ FIX #5: Log anomaly untuk debugging
        if (!$isConsistent && abs($change) > 0.1) { // Threshold 0.01mm untuk noise
            \Log::warning('CTC Trigger Anomaly Detected', [
                'side' => $side,
                'action' => $currentAction == 1 ? 'Menipiskan' : 'Menebalkan',
                'current_value' => $currentValue,
                'future_value' => $futureValue,
                'change' => $change,
                'expected' => $expectedDirection,
                'actual' => $actualDirection,
                'data_index' => $dataIndex,
                'found_at_index' => $foundIndex,
            ]);
        }
        
        // ✅ FIX #6: Return array dengan info lengkap
        return [
            'change' => $change,                    // Signed value (bisa ± )
            'abs_change' => abs($change),           // Absolute value untuk display
            'direction' => $actualDirection,        // 'naik' atau 'turun'
            'is_consistent' => $isConsistent,       // true/false
            'expected_direction' => $expectedDirection,
        ];
    }

    public function downloadCsv()
    {
        if (!$this->canDownloadBatchCsv) {
            $this->js('toast("' . __("Anda tidak memiliki akses") . '", { type: "danger" })');
            return;
        }
        if (!$this->metric) {
            $this->js('toast("' . __("Data tidak ditemukan") . '", { type: "danger" })');
            return;
        }
        $batchCode = $this->batch["rubber_batch_code"];
        $line = $this->batch["machine_line"];
        $safeBatchCode = preg_replace('/[^A-Za-z0-9_\-]/', '_', $batchCode);
        $safeLine = preg_replace('/[^A-Za-z0-9_\-]/', '_', $line);
        $timestamp = now()->format('Ymd_His');
        $filename = "batch_{$safeBatchCode}_line{$safeLine}_{$timestamp}.csv";
        $data = $this->metric->data;
        $batchInfo = $this->batch;
        return Response::streamDownload(function () use ($data, $batchInfo) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
            $recipeFullName = $batchInfo['recipe_name'];
            if (!empty($batchInfo['recipe_component']) && $batchInfo['recipe_component'] !== 'N/A') {
                $recipeFullName .= ' - ' . $batchInfo['recipe_component'];
            }
            fputcsv($file, ['No', 'Timestamp', 'Waktu', 'Sensor_Kiri_mm', 'Sensor_Kanan_mm', 'Trigger_Kiri', 'Trigger_Kanan', 'Trigger_Kiri_Jenis', 'Trigger_Kanan_Jenis', 'Perubahan_Kiri_mm', 'Perubahan_Kanan_mm', 'Dampak_Kiri_Persen', 'Dampak_Kanan_Persen', 'Std_Min', 'Std_Max', 'Std_Mid', 'Is_Correcting', 'Batch_Code', 'Line', 'MCS', 'Recipe_ID', 'Recipe_Name', 'Shift']);
            foreach ($data as $index => $point) {
                $timestamp = $point[0] ?? '';
                $isCorrecting = $point[1] ?? 0;
                $actionLeft = $point[2] ?? 0;
                $actionRight = $point[3] ?? 0;
                $sensorLeft = $point[4] ?? 0;
                $sensorRight = $point[5] ?? 0;
                $recipeId = $point[6] ?? 0;
                $stdMin = $point[7] ?? 0;
                $stdMax = $point[8] ?? 0;
                $stdMid = $point[9] ?? 0;
                $waktu = '';
                try { $waktu = \Carbon\Carbon::parse($timestamp)->format('H:i:s'); } catch (\Exception $e) { $waktu = ''; }
                $triggerLeftLabel = $this->getActionLabel($actionLeft);
                $triggerRightLabel = $this->getActionLabel($actionRight);
                $changeLeft = 0; $percentLeft = 0;
                if ($actionLeft == 1 || $actionLeft == 2) {
                    $effectiveChange = $this->calculateEffectiveChange($data, $index, 'left');
                    if ($effectiveChange !== null) {
                        $changeValue = is_array($effectiveChange) ? ($effectiveChange['change'] ?? 0) : $effectiveChange;
                        $absChange = is_array($effectiveChange) ? ($effectiveChange['abs_change'] ?? abs($changeValue)) : abs($changeValue);
                        
                        if ($absChange >= self::CORRECTION_THRESHOLD) {
                            $changeLeft = $changeValue;
                            $percentLeft = $sensorLeft > 0 ? ($absChange / $sensorLeft) * 100 : 0;
                        }
                    }
                }
                $changeRight = 0; $percentRight = 0;
                if ($actionRight == 1 || $actionRight == 2) {
                    $effectiveChange = $this->calculateEffectiveChange($data, $index, 'right');
                    if ($effectiveChange !== null) {
                        $changeValue = is_array($effectiveChange) ? ($effectiveChange['change'] ?? 0) : $effectiveChange;
                        $absChange = is_array($effectiveChange) ? ($effectiveChange['abs_change'] ?? abs($changeValue)) : abs($changeValue);
                        
                        if ($absChange >= self::CORRECTION_THRESHOLD) {
                            $changeRight = $changeValue;
                            $percentRight = $sensorRight > 0 ? ($absChange / $sensorRight) * 100 : 0;
                        }
                    }
                }
                fputcsv($file, [$index + 1, $timestamp, $waktu, number_format($sensorLeft, 2, '.', ''), number_format($sensorRight, 2, '.', ''), $actionLeft, $actionRight, $triggerLeftLabel, $triggerRightLabel, number_format($changeLeft, 2, '.', ''), number_format($changeRight, 2, '.', ''), number_format($percentLeft, 1, '.', ''), number_format($percentRight, 1, '.', ''), number_format($stdMin, 2, '.', ''), number_format($stdMax, 2, '.', ''), number_format($stdMid, 2, '.', ''), $isCorrecting, $batchInfo['rubber_batch_code'], $batchInfo['machine_line'], $batchInfo['mcs'], $recipeId, $recipeFullName, $batchInfo['shift']]);
            }
            fclose($file);
        }, $filename, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="' . $filename . '"']);
    }

    private function getActionLabel($actionCode): string
    {
        switch ($actionCode) {
            case 1: return 'Menipiskan';
            case 2: return 'Menebalkan';
            default: return '-';
        }
    }

    private function generateChart(): void
    {
        if (empty($this->batch["data"])) {
            return;
        }

        $chartData = $this->prepareChartData($this->batch["data"]);
        $chartOptions = $this->getChartOptions();
        
        $rawDataJson = json_encode($this->batch["data"]);

        $this->js(
            "
            const chartData = " . json_encode($chartData) . ";
            const chartOptions = " . json_encode($chartOptions) . ";
            const rawData = " . $rawDataJson . ";
            const CORRECTION_THRESHOLD = " . self::CORRECTION_THRESHOLD . ";

            // Fungsi untuk mencari data point berdasarkan timestamp
            function findDataPointIndex(timestamp) {
                for (let i = 0; i < rawData.length; i++) {
                    const pointTimestamp = new Date(rawData[i][0]);
                    const targetTimestamp = new Date(timestamp);
                    if (Math.abs(pointTimestamp - targetTimestamp) < 1000) {
                        return i;
                    }
                }
                return -1;
            }

            // Fungsi untuk menghitung perubahan efektif setelah trigger
            function calculateEffectiveChange(dataIndex, side) {
                if (dataIndex < 0 || dataIndex >= rawData.length) return null;
                
                const currentPoint = rawData[dataIndex];
                const currentValue = side === 'left' ? currentPoint[4] : currentPoint[5];
                
                // Cari nilai 3-8 point ke depan untuk melihat efek dari trigger
                let futureValue = null;
                let searchRange = Math.min(8, rawData.length - dataIndex - 1);
                
                for (let i = 3; i <= searchRange; i++) {
                    const futurePoint = rawData[dataIndex + i];
                    const futureAction = side === 'left' ? futurePoint[2] : futurePoint[3];
                    const futureVal = side === 'left' ? futurePoint[4] : futurePoint[5];
                    
                    // Ambil nilai saat tidak ada trigger baru atau di point ke-5
                    if (futureAction === 0 || i === 5) {
                        futureValue = futureVal;
                        break;
                    }
                }
                
                if (futureValue === null) return null;
                
                // Hitung perubahan dengan arah
                const change = futureValue - currentValue;
                return {
                    change: change,
                    abs_change: Math.abs(change)
                };
            }

            // Configure time formatting
            chartOptions.scales.x.ticks = {
                callback: function(value, index, values) {
                    const date = new Date(value);
                    return date.toLocaleTimeString('id-ID', {
                        hour: '2-digit',
                        minute: '2-digit',
                        hour12: false
                    });
                }
            };

            // TOOLTIP CONFIGURATION - Dengan Info Detail
            chartOptions.plugins.tooltip = {
                callbacks: {
                    title: function(context) {
                        if (!context[0]) return '';
                        const date = new Date(context[0].parsed.x);
                        return date.toLocaleTimeString('id-ID', {
                            hour: '2-digit',
                            minute: '2-digit',
                            second: '2-digit',
                            hour12: false
                        });
                    },
                    label: function(context) {
                        const point = context.raw;
                        let lines = [];
                        
                        // Baris 1: Nilai sensor
                        lines.push(context.dataset.label + ': ' + context.parsed.y.toFixed(2) + ' mm');
                        
                        // Jika ada trigger, tampilkan detail
                        if (point && point.action && (point.action === 1 || point.action === 2)) {
                            const side = point.side;
                            const dataIndex = findDataPointIndex(point.x);
                            
                            // Hitung perubahan efektif
                            if (dataIndex >= 0) {
                                const effectiveChange = calculateEffectiveChange(dataIndex, side);
                                if (effectiveChange !== null && effectiveChange.abs_change >= CORRECTION_THRESHOLD) {
                                    const emoji = point.action === 1 ? '▼' : '▲';
                                    const actionType = point.action === 1 ? 'Menipiskan' : 'Menebalkan';
                                    
                                    lines.push('');
                                    lines.push(emoji + ' ' + actionType);
                                    
                                    const absChange = effectiveChange.abs_change;
                                    let isConsistent = true;
                                    
                                    if (effectiveChange.change > 0) {
                                        isConsistent = (point.action === 2);
                                    } else if (effectiveChange.change < 0) {
                                        isConsistent = (point.action === 1);
                                    }

                                    if (!isConsistent) {
                                        lines.push('⚠️ INKONSISTEN');
                                    }
                                    
                                    lines.push('📊 ' + absChange.toFixed(2) + ' mm');
                                    
                                    const percentChange = ((absChange / context.parsed.y) * 100).toFixed(1);
                                    lines.push('📈 ' + percentChange + '%');
                                }
                            }
                        }
                        
                        return lines;
                    },
                    labelColor: function(context) {
                        // Warna kotak sesuai dataset (biru untuk kiri, merah untuk kanan)
                        return {
                            borderColor: context.dataset.borderColor,
                            backgroundColor: context.dataset.borderColor,
                            borderWidth: 2
                        };
                    }
                }
            };

            // DATALABELS - Simbol Berbeda untuk Kritis vs Preventif
            chartOptions.plugins.datalabels = {
                display: function(context) {
                    const point = context.dataset.data[context.dataIndex];
                    if (!point || !point.action || (point.action !== 1 && point.action !== 2)) {
                        return false;
                    }
                    
                    const dataIndex = findDataPointIndex(point.x);
                    if (dataIndex < 0) return false;
                    
                    const effectiveChange = calculateEffectiveChange(dataIndex, point.side);
                    return effectiveChange !== null && effectiveChange.abs_change >= CORRECTION_THRESHOLD;
                },
                
                formatter: function(value, context) {
                    const point = context.dataset.data[context.dataIndex];
                    if (!point || !point.action) return '';
                    
                    // Ambil data mentah untuk menentukan kondisi
                    const dataIndex = findDataPointIndex(point.x);
                    if (dataIndex < 0) return '';
                    
                    const rawPoint = rawData[dataIndex];
                    const thickness = point.y;
                    const stdMin = rawPoint[7] || 0;
                    const stdMax = rawPoint[8] || 0;
                    
                    // Tentukan jenis koreksi dan simbol yang sesuai
                    const needsToDecrease = thickness > stdMax;
                    const needsToIncrease = thickness < stdMin;
                    
                    // KRITIS (di luar range): Solid triangle
                    if (needsToDecrease) {
                        return '▼';  // Solid down (terlalu tebal, perlu turunkan)
                    } else if (needsToIncrease) {
                        return '▲';  // Solid up (terlalu tipis, perlu naikkan)
                    }
                    
                    // PREVENTIF (dalam range): Outline triangle
                    // Tentukan arah berdasarkan posisi terhadap target
                    const stdMid = rawPoint[9] || ((stdMin + stdMax) / 2);
                    
                    if (thickness > stdMid) {
                        return '▽';  // Outline down (di atas target, adjustment turun)
                    } else {
                        return '△';  // Outline up (di bawah target, adjustment naik)
                    }
                },

                color: function(context) {
                    const point = context.dataset.data[context.dataIndex];
                    const dataIndex = findDataPointIndex(point.x);
                    if (dataIndex < 0) return context.dataset.borderColor;

                    const rawPoint = rawData[dataIndex];
                    const thickness = point.y;
                    const stdMin = rawPoint[7] || 0;
                    const stdMax = rawPoint[8] || 0;

                    // Dapatkan warna dasar berdasarkan sisi (biru untuk kiri, merah untuk kanan)
                    const baseColor = context.dataset.borderColor;

                    // Jika di luar range → gunakan warna dasar penuh (kritis)
                    if (thickness > stdMax || thickness < stdMin) {
                        return baseColor;
                    }

                    // Jika di dalam range → redupkan (preventif)
                    // Konversi hex ke RGBA dengan opacity
                    if (baseColor === '#3B82F6') {
                        return 'rgba(59, 130, 246, 0.6)'; // Biru redup
                    } else if (baseColor === '#EF4444') {
                        return 'rgba(239, 68, 68, 0.6)';  // Merah redup
                    }

                    return 'rgba(156, 163, 175, 0.6)'; // Abu-abu redup sebagai fallback
                },


                
                font: function(context) {
                    const point = context.dataset.data[context.dataIndex];
                    const dataIndex = findDataPointIndex(point.x);
                    if (dataIndex < 0) return { size: 12, weight: 'normal' };
                    
                    const rawPoint = rawData[dataIndex];
                    const thickness = point.y;
                    const stdMin = rawPoint[7] || 0;
                    const stdMax = rawPoint[8] || 0;
                    
                    // KRITIS: Besar dan tebal
                    if (thickness > stdMax || thickness < stdMin) {
                        return {
                            size: 14,
                            weight: 'bold'
                        };
                    }
                    
                    // PREVENTIF: Kecil dan normal
                    return {
                        size: 10,
                        weight: 'normal'
                    };
                },
                
                align: function(context) {
                    const point = context.dataset.data[context.dataIndex];
                    if (!point) return 'center';
                    
                    const dataIndex = findDataPointIndex(point.x);
                    if (dataIndex < 0) return 'center';
                    
                    const rawPoint = rawData[dataIndex];
                    const thickness = point.y;
                    const stdMin = rawPoint[7] || 0;
                    const stdMax = rawPoint[8] || 0;
                    const stdMid = rawPoint[9] || ((stdMin + stdMax) / 2);
                    
                    // Simbol naik (▲ △) di bawah point
                    // Simbol turun (▼ ▽) di atas point
                    if (thickness > stdMax || thickness > stdMid) {
                        return 'top';    // ▼ atau ▽ di atas
                    } else {
                        return 'bottom'; // ▲ atau △ di bawah
                    }
                },
                
                offset: 6,
                
                // TAMBAHAN BARU: opacity untuk membedakan lebih jelas
                opacity: function(context) {
                    const point = context.dataset.data[context.dataIndex];
                    const dataIndex = findDataPointIndex(point.x);
                    if (dataIndex < 0) return 1.0;
                    
                    const rawPoint = rawData[dataIndex];
                    const thickness = point.y;
                    const stdMin = rawPoint[7] || 0;
                    const stdMax = rawPoint[8] || 0;
                    
                    // KRITIS: Opacity penuh
                    if (thickness > stdMax || thickness < stdMin) {
                        return 1.0;
                    }
                    
                    // PREVENTIF: Sedikit transparan
                    return 0.7;
                }
            };

            // Render chart
            const chartContainer = \$wire.\$el.querySelector('#batch-chart-container');
            if (!chartContainer) {
                console.error('Chart container not found');
                return;
            }
            
            // Destroy existing chart if any
            const existingCanvas = chartContainer.querySelector('#batch-chart');
            if (existingCanvas) {
                const existingChart = Chart.getChart('batch-chart');
                if (existingChart) {
                    existingChart.destroy();
                }
            }
            
            chartContainer.innerHTML = '';
            const canvas = document.createElement('canvas');
            canvas.id = 'batch-chart';
            chartContainer.appendChild(canvas);

            if (typeof Chart === 'undefined') {
                console.error('Chart.js not loaded');
                return;
            }

            const chart = new Chart(canvas, {
                type: 'line',
                data: chartData,
                options: chartOptions,
            });
            
            console.log('Chart rendered successfully');
        ",
        );
    }

    private function prepareChartData($data): array
    {
        // Transform data for Chart.js
        $chartData = [];
        $stdMinData = [];
        $stdMaxData = [];
        $stdMidData = [];

        foreach ($data as $point) {
            // Data format: [timestamp, is_correcting, action_left, action_right, sensor_left, sensor_right, recipe_id, std_min, std_max, std_mid]
            $timestamp = $point[0] ?? null;
            $actionLeft = $point[2] ?? 0;
            $actionRight = $point[3] ?? 0;
            $sensorLeft = $point[4] ?? 0;
            $sensorRight = $point[5] ?? 0;

            // New std values from positions 7, 8, 9
            $stdMin = $point[7] ?? null;
            $stdMax = $point[8] ?? null;
            $stdMid = $point[9] ?? null;

            if ($timestamp && ($sensorLeft > 0 || $sensorRight > 0)) {
                $parsedTime = Carbon::parse($timestamp);

                $chartData[] = [
                    "x" => $parsedTime,
                    "y" => $sensorLeft,
                    "side" => "left",
                    "action" => $actionLeft,
                ];
                $chartData[] = [
                    "x" => $parsedTime,
                    "y" => $sensorRight,
                    "side" => "right",
                    "action" => $actionRight,
                ];

                // Add std data only if values exist
                if ($stdMin !== null) {
                    $stdMinData[] = [
                        "x" => $parsedTime,
                        "y" => $stdMin,
                    ];
                }
                if ($stdMax !== null) {
                    $stdMaxData[] = [
                        "x" => $parsedTime,
                        "y" => $stdMax,
                    ];
                }
                if ($stdMid !== null) {
                    $stdMidData[] = [
                        "x" => $parsedTime,
                        "y" => $stdMid,
                    ];
                }
            }
        }

        // Separate left and right data
        $leftData = array_filter($chartData, fn ($item) => $item["side"] === "left");
        $rightData = array_filter($chartData, fn ($item) => $item["side"] === "right");

        // Build datasets array starting with original sensor data
        $datasets = [
            [
                "label" => "Sensor Kiri",
                "data" => array_values($leftData),
                "borderColor" => "#3B82F6",
                "backgroundColor" => "rgba(59, 130, 246, 0.1)",
                "tension" => 0.1,
                "pointRadius" => 2,
                "pointHoverRadius" => 3,
                "borderWidth" => 1,
            ],
            [
                "label" => "Sensor Kanan",
                "data" => array_values($rightData),
                "borderColor" => "#EF4444",
                "backgroundColor" => "rgba(239, 68, 68, 0.1)",
                "tension" => 0.1,
                "pointRadius" => 2,
                "pointHoverRadius" => 3,
                "borderWidth" => 1,
            ],
        ];

        // Add std datasets only if we have data
        if (! empty($stdMinData)) {
            $datasets[] = [
                "label" => "Std Min",
                "data" => $stdMinData,
                "borderColor" => "#9CA3AF",
                "backgroundColor" => "transparent",
                "tension" => 0.1,
                "pointRadius" => 0,
                "pointHoverRadius" => 2,
                "borderWidth" => 1,
            ];
        }

        if (! empty($stdMaxData)) {
            $datasets[] = [
                "label" => "Std Max",
                "data" => $stdMaxData,
                "borderColor" => "#9CA3AF",
                "backgroundColor" => "transparent",
                "tension" => 0.1,
                "pointRadius" => 0,
                "pointHoverRadius" => 2,
                "borderWidth" => 1,
            ];
        }

        if (! empty($stdMidData)) {
            $datasets[] = [
                "label" => "Std Mid",
                "data" => $stdMidData,
                "borderColor" => "#9CA3AF",
                "backgroundColor" => "transparent",
                "tension" => 0.1,
                "pointRadius" => 0,
                "pointHoverRadius" => 2,
                "borderWidth" => 1,
                "borderDash" => [5, 5], // Dashed line
            ];
        }

        return [
            "datasets" => $datasets,
        ];
    }

    private function getChartOptions(): array
    {
        return [
            "responsive" => true,
            "maintainAspectRatio" => false,
            "scales" => [
                "x" => [
                    "type" => "time",
                    "title" => [
                        "display" => true,
                        "text" => "Waktu",
                    ],
                ],
                "y" => [
                    "title" => [
                        "display" => true,
                        "text" => "Ketebalan (mm)",
                    ],
                    "min" => 0,
                    "max" => 6,
                ],
            ],
            "plugins" => [
                "datalabels" => [
                    "display" => true,
                    "anchor" => "end",
                    "align" => "top",
                ],
                "legend" => [
                    "display" => true,
                    "position" => "top",
                ],
                "zoom" => [
                    "zoom" => [
                        "wheel" => [
                            "enabled" => true,
                        ],
                        "pinch" => [
                            "enabled" => true,
                        ],
                        "mode" => "xy", // or 'y', 'xy'
                    ],
                    "pan" => [
                        "enabled" => true,
                        "mode" => "xy", // or 'y', 'xy'
                    ],
                ],
            ],
        ];
    }

    private function handleNotFound(): void
    {
        $this->js('toast("' . __("Data metrik tidak ditemukan") . '", { type: "danger" })');
        $this->dispatch("updated");
    }
};

?>

<div class="p-6">
    @if ($header)
        <div class="flex justify-between items-start mb-6">
            <h2 class="text-lg font-medium text-neutral-900 dark:text-neutral-100">
                {{ __("Rincian Batch") }}
            </h2>
            <x-text-button type="button" x-on:click="$dispatch('close')">
                <i class="icon-x"></i>
            </x-text-button>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="col-span-2 space-y-6">
            {{-- Chart --}}
            <div class="h-80 overflow-hidden" id="batch-chart-container" wire:key="batch-chart-container" wire:ignore></div>

            {{-- ⭐ UNIFIED TABLE - Compact Design --}}
            <div class="overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700">
                <table class="w-full text-xs table-fixed">
                    <colgroup>
                        <col style="width: 20%;">  {{-- Label --}}
                        <col style="width: 15%;">  {{-- KI/Recipe --}}
                        <col style="width: 15%;">  {{-- KA/Aktual --}}
                        <col style="width: 15%;">  {{-- Combined --}}
                        <col style="width: 35%;">  {{-- Evaluasi/Deviation --}}
                    </colgroup>

                    {{-- Evaluasi Section --}}
                    <thead>
                        <tr class="uppercase text-neutral-500 dark:text-neutral-400 bg-neutral-50 dark:bg-neutral-900/50 border-b border-neutral-200 dark:border-neutral-700">
                            <th class="py-3 px-2 text-left font-semibold">METRIK</th>
                            <th class="py-3 px-2 text-center font-semibold">KI</th>
                            <th class="py-3 px-2 text-center font-semibold">KA</th>
                            <th class="py-3 px-2 text-center font-semibold">±</th>
                            <th class="py-3 px-2 text-left font-semibold">EVALUASI</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        {{-- AVG --}}
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-900/30">
                            <td class="py-2 px-2 font-semibold uppercase text-neutral-600 dark:text-neutral-400">AVG</td>
                            <td class="py-2 px-2 text-center font-mono">{{ number_format($batch["t_avg_left"], 2) }}</td>
                            <td class="py-2 px-2 text-center font-mono">{{ number_format($batch["t_avg_right"], 2) }}</td>
                            <td class="py-2 px-2 text-center font-mono">{{ number_format($batch["t_avg"], 2) }}</td>
                            <td class="py-2 px-2">
                                @php $avgEval = $metric?->avg_evaluation; @endphp
                                <div class="flex items-center gap-1">
                                    <i class="{{ $avgEval['is_good'] ?? false ? 'icon-circle-check text-green-500' : 'icon-circle-x text-red-500' }} text-xs flex-shrink-0"></i>
                                    <span class="{{ $avgEval['color'] ?? '' }} text-xs font-medium whitespace-nowrap">{{ ucfirst($avgEval['status'] ?? '') }}</span>
                                </div>
                            </td>
                        </tr>

                        {{-- MAE --}}
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-900/30">
                            <td class="py-2 px-2 font-semibold uppercase text-neutral-600 dark:text-neutral-400">MAE</td>
                            <td class="py-2 px-2 text-center font-mono">{{ number_format($batch["t_mae_left"], 2) }}</td>
                            <td class="py-2 px-2 text-center font-mono">{{ number_format($batch["t_mae_right"], 2) }}</td>
                            <td class="py-2 px-2 text-center font-mono">{{ number_format($batch["t_mae"], 2) }}</td>
                            <td class="py-2 px-2">
                                @php $maeEval = $metric?->mae_evaluation; @endphp
                                <div class="flex items-center gap-1">
                                    <i class="{{ $maeEval['is_good'] ?? false ? 'icon-circle-check text-green-500' : 'icon-circle-x text-red-500' }} text-xs flex-shrink-0"></i>
                                    <span class="{{ $maeEval['color'] ?? '' }} text-xs font-medium whitespace-nowrap">{{ ucfirst($maeEval['status'] ?? '') }}</span>
                                </div>
                            </td>
                        </tr>

                        {{-- SSD --}}
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-900/30">
                            <td class="py-2 px-2 font-semibold uppercase text-neutral-600 dark:text-neutral-400">SSD</td>
                            <td class="py-2 px-2 text-center font-mono">{{ number_format($batch["t_ssd_left"], 2) }}</td>
                            <td class="py-2 px-2 text-center font-mono">{{ number_format($batch["t_ssd_right"], 2) }}</td>
                            <td class="py-2 px-2 text-center font-mono">{{ number_format($batch["t_ssd"], 2) }}</td>
                            <td class="py-2 px-2">
                                @php $ssdEval = $metric?->ssd_evaluation; @endphp
                                <div class="flex items-center gap-1">
                                    <i class="{{ $ssdEval['is_good'] ?? false ? 'icon-circle-check text-green-500' : 'icon-circle-x text-red-500' }} text-xs flex-shrink-0"></i>
                                    <span class="{{ $ssdEval['color'] ?? '' }} text-xs font-medium whitespace-nowrap">{{ ucfirst($ssdEval['status'] ?? '') }}</span>
                                </div>
                            </td>
                        </tr>

                        {{-- KOREKSI --}}
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-900/30">
                            <td class="py-2 px-2 font-semibold uppercase text-neutral-600 dark:text-neutral-400">KOREKSI</td>
                            <td class="py-2 px-2 text-center font-mono">{{ $batch["corrections_left"] }}</td>
                            <td class="py-2 px-2 text-center font-mono">{{ $batch["corrections_right"] }}</td>
                            <td class="py-2 px-2 text-center font-mono">{{ $batch["corrections_total"] }}</td>
                            <td class="py-2 px-2">
                                @php $correctionEval = $metric?->correction_evaluation; @endphp
                                <div class="flex items-center gap-1">
                                    <i class="{{ $correctionEval['is_good'] ?? false ? 'icon-circle-check text-green-500' : 'icon-circle-x text-red-500' }} text-xs flex-shrink-0"></i>
                                    <span class="{{ $correctionEval['color'] ?? '' }} text-xs font-medium whitespace-nowrap">{{ ucfirst($correctionEval['status'] ?? '') }}</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>

                    {{-- ⭐ Standards Section --}}
                    @if ($batch["recipe_std_min"] !== null && $batch["actual_std_min"] !== null)
                        <thead>
                            <tr class="uppercase text-neutral-500 dark:text-neutral-400 bg-neutral-50 dark:bg-neutral-900/50 border-t-2 border-neutral-300 dark:border-neutral-600 border-b border-neutral-200 dark:border-neutral-700">
                                <th class="py-3 px-2 text-left font-semibold">STANDAR</th>
                                <th class="py-3 px-2 text-center font-semibold">REC</th>
                                <th class="py-3 px-2 text-center font-semibold">ACT</th>
                                <th class="py-3 px-2"></th>
                                <th class="py-3 px-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        {{-- Max --}}
                            <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-900/30">
                                <td class="py-2 px-2 font-semibold uppercase text-neutral-600 dark:text-neutral-400">MAX</td>
                                <td class="py-2 px-2 text-center font-mono">{{ number_format($batch["recipe_std_max"], 2) }}</td>
                                <td class="py-2 px-2 text-center font-mono">{{ number_format($batch["actual_std_max"], 2) }}</td>
                                <td class="py-2 px-2"></td>
                                <td class="py-2 px-2" rowspan="2" style="vertical-align: middle;">
                                    @if ($this->metric && $this->metric->deviation)
                                        @php $deviation = $this->metric->deviation; @endphp
                                        <div class="inline-flex items-center justify-center gap-1 px-1.5 py-1 rounded-md {{ $deviation['bg_color'] ?? 'bg-green-50' }} min-w-[70px] text-center whitespace-nowrap overflow-hidden">
                                            <span class="text-xs font-semibold {{ $deviation['color'] ?? 'text-green-600' }}">
                                                ±{{ ($deviation['mm'] ?? 0) > 0 ? '+' : '' }}{{ number_format($deviation['mm'] ?? 0, 2) }} mm
                                            </span>
                                        </div>
                                    @endif                      
                                </td>
                            </tr>

                            {{-- Min --}}
                            <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-900/30">
                                <td class="py-2 px-2 font-semibold uppercase text-neutral-600 dark:text-neutral-400">MIN</td>
                                <td class="py-2px-2 text-center font-mono">{{ number_format($batch["recipe_std_min"], 2) }}</td>
                                <td class="py-2 px-2 text-center font-mono">{{ number_format($batch["actual_std_min"], 2) }}</td>
                                <td class="py-2 px-2"></td>
                            </tr> 
                        </tbody>
                    @endif
                </table>
            </div>
        </div>

        <div class="space-y-6">
            <div>
                <div class="text-neutral-500 dark:text-neutral-400 text-xs uppercase mb-2">{{ __("Informasi Batch") }}</div>
                <div class="space-y-2 text-sm">
                    <div>
                        <span class="text-neutral-500">{{ __("Batch:") }}</span>
                        <span class="font-medium">{{ $batch["rubber_batch_code"] }}</span>
                    </div>
                    <div>
                        <span class="text-neutral-500">{{ __("MCS:") }}</span>
                        <span class="font-medium">{{ $batch["mcs"] }}</span>
                    </div>
                    <div>
                        <span class="text-neutral-500">{{ __("Line:") }}</span>
                        <span class="font-medium">{{ $batch["machine_line"] }}</span>
                    </div>
                </div>
            </div>

            <div>
                <div class="text-neutral-500 dark:text-neutral-400 text-xs uppercase mb-2">{{ __("Waktu Proses") }}</div>
                <div class="space-y-2 text-sm">
                    <div>
                        <span class="text-neutral-500">{{ __("Mulai:") }}</span>
                        <span class="font-mono">{{ $batch["started_at"] }}</span>
                    </div>
                    <div>
                        <span class="text-neutral-500">{{ __("Selesai:") }}</span>
                        <span class="font-mono">{{ $batch["ended_at"] }}</span>
                    </div>
                    <div>
                        <span class="text-neutral-500">{{ __("Durasi:") }}</span>
                        <span class="font-mono">{{ $batch["duration"] }}</span>
                    </div>
                    <div>
                        <span class="text-neutral-500">{{ __("Shift:") }}</span>
                        <span class="font-medium">{{ $batch["shift"] }}</span>
                    </div>
                </div>
            </div>

            <div>
                <div class="text-neutral-500 dark:text-neutral-400 text-xs uppercase mb-2">{{ __("Koreksi") }}</div>
                <div class="space-y-2 text-sm">
                    <div class="flex gap-x-3">
                        <div>
                            <span class="text-neutral-500">CU:</span>
                            <span class="font-mono">{{ $batch["correction_uptime"] }}%</span>
                        </div>
                        <div>
                            <span class="text-neutral-500">CR:</span>
                            <span class="font-mono">{{ $batch["correction_rate"] }}%</span>
                        </div>
                    </div>
                </div>
            </div>

            <div>
                <div class="text-neutral-500 dark:text-neutral-400 text-xs uppercase mb-2">{{ __("Resep") }}</div>
                <div class="space-y-2 text-sm">
                    <div>
                        <span class="text-neutral-500">{{ __("ID:") }}</span>
                        <span class="font-medium">{{ $batch["recipe_id"] }}</span>
                    </div>
                    <div class="space-y-1">
                        <div class="text-neutral-500">{{ __("Nama:") }}</div>
                        <div class="font-medium">{{ $batch["recipe_name"] }}</div>
                        @if ($batch["recipe_component"] && $batch["recipe_component"] !== "N/A")
                            <div class="font-medium">{{ $batch["recipe_component"] }}</div>
                        @endif
                    </div>
                </div>
                {{-- ⭐ Download Button --}}
                @if ($this->canDownloadBatchCsv)
                    <div class="mt-8 pt-8 border-t border-neutral-200 dark:border-neutral-700">
                        <x-secondary-button wire:click="downloadCsv" class="w-full justify-center text-xs py-2">
                            <i class="icon-download mr-1.5"></i>
                            {{ __("Download CSV") }}
                        </x-secondary-button>
                    </div>
                @endif
            </div>
        </div>
    </div>
    <x-spinner-bg wire:loading.class.remove="hidden" wire:target.except="userq"></x-spinner-bg>
    <x-spinner wire:loading.class.remove="hidden" wire:target.except="userq" class="hidden"></x-spinner>
</div>