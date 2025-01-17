<?php

namespace App\Models;

use App\InsStc;
use App\InsStcTempControl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class InsStcDSum extends Model
{
    use HasFactory;

    protected $fillable = [
        'ins_stc_device_id',
        'ins_stc_machine_id',

        'user_1_id',
        'user_2_id',
        'started_at',
        'ended_at',

        'preheat',
        'section_1',
        'section_2',
        'section_3',
        'section_4',
        'section_5',
        'section_6',
        'section_7',
        'section_8',
        'postheat',

        'speed',
        'sequence',
        'position',
        'sv_temps',
    ];
    
    protected $casts = [
        'started_at'    => 'datetime',
        'ended_at'      => 'datetime',

        'preheat'       => 'float',
        'section_1'     => 'float',
        'section_2'     => 'float',
        'section_3'     => 'float',
        'section_4'     => 'float',
        'section_5'     => 'float',
        'section_6'     => 'float',
        'section_7'     => 'float',
        'section_8'     => 'float',
        'postheat'      => 'float',

        'speed' => 'float',
    ];

    public function duration(): string
    {
        return InsStc::duration($this->started_at, $this->ended_at);
    }

    public function uploadLatency(): string
    {
        return InsStc::duration($this->ended_at, $this->updated_at);
    }

    public function ins_stc_d_logs(): HasMany
    {
        return $this->hasMany(InsStcDlog::class);
    }

    public function user_1(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function user_2(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ins_stc_machine(): BelongsTo
    {
        return $this->belongsTo(InsStcMachine::class);
    }

    public function ins_stc_device(): BelongsTo
    {
        return $this->belongsTo(InsStcDevice::class);
    }

    public function logTemps(): array
    {
        $dlogs = $this->ins_stc_d_logs->sortBy('taken_at');
        
        // Skip preheat (first 5)
        // Each section has 6 logs, starting from index 5
        $medians = [];
        
        for ($section = 0; $section < 8; $section++) {
            $startIndex = 5 + ($section * 6);
            $sectionLogs = $dlogs->slice($startIndex, 6);
            
            // If section has no logs, return 0
            if ($sectionLogs->isEmpty()) {
                $medians[] = 0;
                continue;
            }
            
            // Get valid temperatures
            $temps = $sectionLogs->pluck('temp')
                ->filter()  // Remove null/empty values
                ->map(function($temp) {
                    return floatval($temp);
                })
                ->values()  // Re-index array
                ->all();
                
            // Calculate median
            if (empty($temps)) {
                $medians[] = 0;
            } else {
                sort($temps);
                $count = count($temps);
                $middle = floor(($count - 1) / 2);
                
                if ($count % 2) {
                    // Odd number of temperatures
                    $medians[] = number_format($temps[$middle], 0);
                } else {
                    // Even number of temperatures
                    $medians[] = number_format((($temps[$middle] + $temps[$middle + 1]) / 2), 0);
                }
            }
        }
        return $medians;
    }

}
