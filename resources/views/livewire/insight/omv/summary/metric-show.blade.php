<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use App\Models\InsOmvMetric;
use App\InsOmv;

new #[Layout('layouts.app')] 
class extends Component {
    public int $id;

    #[On('metric-show')]
    public function showMetric(int $id)
    {
        $metric = InsOmvMetric::find($id);
        
        if ($metric) {

            $this->id = $metric->id;
            $data = json_decode($metric->data, true) ?: [ 'amps' => [] ];
            
            if ($data['amps']) {
                // Koleksi durasi di setiap step resep dan di inkrementalkan
                $steps = json_decode($metric->ins_omv_recipe->steps, true) ?: [];
                $step_durations = [];
                $inc_durations = 0;

                foreach ($steps as $step) {
                    $inc_durations += $step['duration'];
                    $step_durations[] = $inc_durations;
                }

                // koleksi titik foto
                $capture_points = json_decode($metric->ins_omv_recipe->capture_points, true) ?: [];

                $this->js(
                    "
                    let modalOptions = " .
                        json_encode(InsOmv::getChartOptions($data['amps'], $metric->start_at, $step_durations, $capture_points, 100)) .
                        ";

                    // Render modal chart
                    const modalChartContainer = \$wire.\$el.querySelector('#modal-chart-container');
                    modalChartContainer.innerHTML = '<div id=\"modal-chart\"></div>';
                    let modalChart = new ApexCharts(modalChartContainer.querySelector('#modal-chart'), modalOptions);
                    modalChart.render();
                ",
                );
            }

        } else {
            $this->handleNotFound();
        }
    }

    public function customReset()
    {
        $this->reset(['id', 'amps']);
    }

    public function handleNotFound()
    {
        $this->js('$dispatch("close")');
        $this->js('notyfError("' . __('Tidak ditemukan') . '")');
        $this->dispatch('updated');
    }
};

?>
<div>
    <div class="p-6">
        <div class="flex justify-between items-start">
            <h2 class="text-lg font-medium text-neutral-900 dark:text-neutral-100">
                {{ __('Rincian') }}
            </h2>
            <x-text-button type="button" x-on:click="$dispatch('close')"><i class="fa fa-times"></i></x-text-button>
        </div>
        <div class="h-80 bg-white dark:brightness-75 text-neutral-900 rounded overflow-hidden my-8"
            id="modal-chart-container" wire:key="modal-chart-container" wire:ignore>
        </div>
    </div>
    <x-spinner-bg wire:loading.class.remove="hidden" wire:target.except="userq"></x-spinner-bg>
    <x-spinner wire:loading.class.remove="hidden" wire:target.except="userq" class="hidden"></x-spinner>
</div>