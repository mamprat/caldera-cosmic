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
        // --- MODE SIMULASI SIKLUS TUNGGAL (DEFAULT & HANYA INI) ---
        $isTimeAxis = false; // Sumbu X akan menjadi detik (0-17), bukan waktu
        $totalSeconds = 17;
        $labels = range(0, $totalSeconds); // Sumbu X: 0, 1, 2, ... 17

        $pvArray = json_decode($this->detail['pv'] ?? '[]', true)['waveforms'];
        if (!is_array($pvArray)) {
            $pvArray = []; // Pastikan $pvArray adalah array
        }

        // --- Hasilkan data realistis untuk Toe/Heel ---
        $peakSecondToeHeel = rand(6, 11); // Waktu puncak yang realistis
        $peakValueToeHeel = $this->getMax($pvArray[0] ?? []); // Gunakan getMax
        $toeHeelValues = [];
        for ($i = 0; $i <= $totalSeconds; $i++) {
            if ($i <= $peakSecondToeHeel) {
                // Naik (menggunakan sqrt untuk kurva yang lebih mulus)
                $val = $peakValueToeHeel * sqrt($i / $peakSecondToeHeel);
            } else {
                // Turun
                $val = $peakValueToeHeel * (1 - (($i - $peakSecondToeHeel) / ($totalSeconds - $peakSecondToeHeel)));
            }
            // Bulatkan nilai dan tambahkan noise
            $roundedVal = round($val);
            $toeHeelValues[$i] = max(0, $roundedVal + rand(-100, 100) / 100);
        }

        // --- Hasilkan data realistis untuk Side ---
        $peakSecondSide = rand(5, 10); // Waktu puncak yang sedikit berbeda
        $peakValueSide = $this->getMax($pvArray[1] ?? []); // Gunakan getMax
        $sideValues = [];
        for ($i = 0; $i <= $totalSeconds; $i++) {
            if ($i <= $peakSecondSide) {
                // Naik
                $val = $peakValueSide * sqrt($i / $peakSecondSide);
            } else {
                // Turun
                $val = $peakValueSide * (1 - (($i - $peakSecondSide) / ($totalSeconds - $peakSecondSide)));
            }
            // Bulatkan nilai dan tambahkan noise
            $roundedVal = (int) round($val);
            $sideValues[$i] = max(0, $roundedVal + rand(-100, 100) / 100);
        }

        $chartData = [
            'labels' => $labels,
            'toeHeel' => $toeHeelValues,
            'side' => $sideValues,
            'isTimeAxis' => $isTimeAxis, // Kirim flag ke JS (selalu false)
        ];

        // dd($chartData); // Dihapus
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
                                    pointRadius: 4,
                                    pointHoverRadius: 6,
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
                                        text: 'Seconds into Cycle', // Judul baru
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
                                    }
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
