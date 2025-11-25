<?php

use Livewire\Volt\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use App\Models\InsDwpCount;
use Carbon\Carbon;

new class extends Component {
    public int $id = 0;
    public array $detail = [];

    // Filter dan properti yang tidak terpakai telah dihapus
    #[Url]
    public string $line = "g5";

    #[Url]
    public string $mechine = "";

    #[Url]
    public ?int $device_id = null;

    // Properti posisi disimpan untuk judul, tetapi bukan lagi filter URL
    public string $position = "L";

    public function mount()
    {
        // Logika filter tanggal telah dihapus
    }

    #[On("pressure-detail-load")]
    public function loadPresureDetail($id)
    {
        $this->id = $id;
        $data = InsDwpCount::find($id);
        if ($data) {
            $this->detail = $data->toArray();
            $this->mechine = $data->mechine;
            $this->position = $data->position;
            // Selalu render simulasi saat data baru dimuat
            $this->renderPressureChartClient();
        }
    }

    // Fungsi 'updated' untuk filter telah dihapus

    private function getMax(array $array)
    {
        if (empty($array)) {
            return 0;
        }

        // Filter out non-numeric values
        $numericArray = array_filter($array, 'is_numeric');

        if (empty($numericArray)) {
            return 0;
        }

        // Get max value from the numeric array
        return max($numericArray);
    }

    private function renderPressureChartClient()
    {
        $isTimeAxis = false;
        $pvRaw = json_decode($this->detail['pv'] ?? '[]', true);
        $waveforms = $pvRaw['waveforms'] ?? [];
        $duration = (int) ($this->detail['duration'] ?? 0);
        // Get values and timestamps
        $toeHeelValuesRaw = $waveforms[0] ?? [];
        $sideValuesRaw = $waveforms[1] ?? [];
        $timestampsRaw = $pvRaw['timestamps'] ?? [];

        // Repeat/hold values for each second
        $toeHeelValues = $this->repeatWaveform($toeHeelValuesRaw, $timestampsRaw, $duration);
        $sideValues = $this->repeatWaveform($sideValuesRaw, $timestampsRaw, $duration);

        // X axis: seconds from 0 to duration
        $labels = range(1, $duration);

        // 4. Siapkan data untuk Chart.js
        $chartData = [
            'labels' => $labels,
            'toeHeel' => $toeHeelValues,
            'side' => $sideValues,
            'isTimeAxis' => $isTimeAxis,
        ];

        $chartDataJson = json_encode($chartData);

        $this->js(
            "
            (function(){
                try {
                    const data = " . $chartDataJson . ";
                    const isTimeAxis = data.isTimeAxis; // Ambil flag

                    function isDarkModeLocal(){
                        try{ return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches || document.documentElement.classList.contains('dark'); }catch(e){return false}
                    }
                    const theme = {
                        textColor: isDarkModeLocal() ? '#e6edf3' : '#0f172a',
                        gridColor: isDarkModeLocal() ? 'rgba(255,255,255,0.06)' : 'rgba(15,23,42,0.06)'
                    };

                    const ctx = document.getElementById('pressureChart').getContext('2d');
                    if (!ctx) {
                        console.warn('[Pressure Chart] canvas not found');
                        return;
                    }

                    if (window.__pressureChart instanceof Chart) {
                        try { window.__pressureChart.destroy(); } catch(e){}
                    }

                    const hasData = data && data.labels && data.labels.length > 0;
                    window.__pressureChart = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: hasData ? data.labels : [],
                            datasets: [
                                {
                                    label: 'Toe/Heel (kg)',
                                    data: hasData ? data.toeHeel : [],
                                    borderColor: '#ef4444',
                                    backgroundColor: 'rgba(239, 68, 68, 0.1)',
                                    fill: false,
                                    tension: 0.4,
                                    stepped: false,
                                    borderWidth: 2,
                                    pointBackgroundColor: '#ef4444',
                                    pointBorderColor: '#fff',
                                    pointBorderWidth: 1
                                },
                                {
                                    label: 'Side (kg)',
                                    data: hasData ? data.side : [],
                                    borderColor: '#10b981',
                                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                                    pointRadius: 4,
                                    pointHoverRadius: 6,
                                    fill: false,
                                    tension: 0.4,
                                    stepped: false,
                                    borderWidth: 2,
                                    pointBackgroundColor: '#10b981',
                                    pointBorderColor: '#fff',
                                    pointBorderWidth: 1
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: {
                                mode: 'index',
                                intersect: false
                            },
                            scales: {
                                x: isTimeAxis ? {
                                    // --- KONFIGURASI SUMBU X TIPE WAKTU (tidak akan pernah terpakai) ---
                                    type: 'time',
                                    time: {
                                        unit: 'hour',
                                        tooltipFormat: 'MMM d, HH:mm:ss',
                                        displayFormats: {
                                            hour: 'HH:mm',
                                            day: 'MMM d'
                                        }
                                    },
                                    title: {
                                        display: true,
                                        text: 'Time',
                                        color: theme.textColor
                                    },
                                    grid: {
                                        color: theme.gridColor,
                                        drawOnChartArea: true,
                                        drawTicks: false
                                    },
                                    ticks: {
                                        color: theme.textColor,
                                        autoSkip: true,
                                        maxTicksLimit: 12
                                    }
                                } : {
                                    // --- KONFIGURASI SUMBU X TIPE LINEAR (default) ---
                                    type: 'linear', // Gunakan 'linear' karena labelnya 0, 1, 2...
                                    title: {
                                        display: true,
                                        text: 'Time (seconds)',
                                        color: theme.textColor
                                    },
                                    grid: {
                                        color: theme.gridColor,
                                        drawOnChartArea: true,
                                        drawTicks: false
                                    },
                                    ticks: {
                                        color: theme.textColor,
                                        stepSize: 1 // Tampilkan 1, 2, 3...
                                    },
                                },
                                y: {
                                    // --- KONFIGURASI SUMBU Y (tetap sama) ---
                                    beginAtZero: false,
                                    title: {
                                        display: true,
                                        text: 'Pressure (kg)',
                                        color: theme.textColor
                                    },
                                    grid: {
                                        color: theme.gridColor,
                                        drawOnChartArea: true,
                                        drawTicks: false
                                    },
                                    ticks: {
                                        color: theme.textColor,
                                        callback: function(value, index, ticks) {
                                            return value + '';
                                        }
                                    }
                                }
                            },
                            plugins: {
                                legend: {
                                    display: true,
                                    position: 'right',
                                    labels: {
                                        color: theme.textColor,
                                        usePointStyle: true
                                    }
                                },
                                tooltip: {
                                    mode: 'index',
                                    intersect: false,
                                    position: 'nearest',
                                    bodyColor: theme.textColor,
                                    titleColor: theme.textColor,
                                    callbacks: {
                                        label: function(context) {
                                            let label = context.dataset.label || '';
                                            if (label) {
                                                label += ': ';
                                            }
                                            if (context.parsed.y !== null) {
                                                // Tambahkan 'kg' untuk sumbu Y, dan 's' untuk sumbu X
                                                let yLabel = context.parsed.y.toFixed(2) + ' kg';
                                                let xLabel = ' (at ' + context.parsed.x + 's)';
                                                label += yLabel + xLabel;
                                            }
                                            return label;
                                        }
                                    }
                                }
                            }
                        }
                    });

                } catch (e) {
                    console.error('[Pressure Chart] injected chart render error', e);
                }
            })();
            "
        );
    }

    private function repeatWaveform(array $valuesRaw, array $timestampsRaw, int $duration): array {
        $count = count($valuesRaw);
        if ($count === 0 || count($timestampsRaw) !== $count) {
            return [];
        }
        // Normalize timestamps to seconds from start
        $startTs = (int)($timestampsRaw[0] / 1000);
        $secValueMap = [];
        $maxSec = 0;
        for ($i = 0; $i < $count; $i++) {
            $sec = (int)($timestampsRaw[$i] / 1000) - $startTs;
            $secValueMap[$sec] = $valuesRaw[$i];
            if ($sec > $maxSec) $maxSec = $sec;
        }
        // Build result array with repeated/held values
        $result = [];
        $lastValue = 0;
        for ($sec = 0; $sec <= $maxSec; $sec++) {
            if (isset($secValueMap[$sec])) {
                $lastValue = $secValueMap[$sec];
            }
            $result[] = $lastValue;
        }
        // add 0 for lasting seconds up to duration
        for ($sec = $maxSec + 1; $sec < $duration; $sec++) {
            $result[] = 0;
        }

        // Ensure result has exactly $duration elements (labels are 1..$duration)
        // - pad with trailing zeros if too short
        // - trim if longer
        if (count($result) < $duration) {
            $result = array_pad($result, $duration, 0);
        } elseif (count($result) > $duration) {
            $result = array_slice($result, 0, $duration);
        }

        // Always set the last duration slot to zero so the chart ends at 0
        if ($duration > 0) {
            $result[$duration - 1] = 0;
        }

        return $result;
    }
};
?>

<div class="p-4 bg-white dark:bg-gray-900 rounded-lg shadow-md relative">
    <div class="grid grid-cols-2 gap-0">
        <div>
            <h1 class="text-xl font-semibold text-gray-800 dark:text-gray-200 mb-4">
                Pressure Monitoring: {{ $this->mechine }} ({{ $this->position }})
            </h1>
        </div>
    </div>
    <div class="h-80 relative">
        <canvas id="pressureChart"></canvas>
    </div>
    <x-spinner-bg wire:loading.class.remove="hidden" wire:target.except="userq"></x-spinner-bg>
    <x-spinner wire:loading.class.remove="hidden" wire:target.except="userq" class="hidden"></x-spinner>
</div>
