<?php

use App\Http\Controllers\DownloadController;
use App\Http\Resources\InsRtcMetricResource;
use App\Http\Resources\InsRtcRecipeResource;
use App\Models\InsRtcMetric;
use App\Models\InsRtcRecipe;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Cache;  // ← TAMBAHAN: Import Cache
use Livewire\Volt\Volt;

Volt::route('/', 'home')->name('home');
Volt::route('/inventory', 'inventory.index')->name('inventory');
Volt::route('/inventory/help', 'inventory.help')->name('inventory.help');

Volt::route('/announcements/{id}', 'announcements.show')->name('announcements.show');

// Insights routes
Route::prefix('insights')->group(function () {

    Route::name('insights.')->group(function () {

        Volt::route('/ss/{id}', 'insights.ss.index')->name('ss'); // slideshow
    });

    Route::name('insights.rtc.')->group(function () {

        Volt::route('/rtc/manage/authorizations', 'insights.rtc.manage.auths')->name('manage.auths');
        Volt::route('/rtc/manage/devices', 'insights.rtc.manage.devices')->name('manage.devices');
        Volt::route('/rtc/manage/recipes', 'insights.rtc.manage.recipes')->name('manage.recipes');
        Volt::route('/rtc/manage', 'insights.rtc.manage.index')->name('manage.index');
        Volt::route('/rtc/slideshows', 'insights.rtc.slideshows')->name('slideshows');
        Volt::route('/rtc', 'insights.rtc.index')->name('index');

        Route::get('/rtc/metric/{device_id}', function (string $device_id) {
            $metric = InsRtcMetric::join('ins_rtc_clumps', 'ins_rtc_clumps.id', '=', 'ins_rtc_metrics.ins_rtc_clump_id')
                ->where('ins_rtc_clumps.ins_rtc_device_id', $device_id)
                ->latest('dt_client')
                ->first();

            return $metric ? new InsRtcMetricResource($metric) : abort(404);
        })->name('metric');

        Route::get('/rtc/recipe/{recipe_id}', function (string $recipe_id) {
            return new InsRtcRecipeResource(InsRtcRecipe::findOrFail($recipe_id));
        })->name('recipe');

    });

    Route::name('insights.ctc.')->group(function () {

        Volt::route('/ctc/manage/authorizations', 'insights.ctc.manage.auths')->name('manage.auths');
        Volt::route('/ctc/manage/machines', 'insights.ctc.manage.machines')->name('manage.machines');
        Volt::route('/ctc/manage/recipes', 'insights.ctc.manage.recipes')->name('manage.recipes');
        Volt::route('/ctc/manage', 'insights.ctc.manage.index')->name('manage.index');
        Volt::route('/ctc/data/realtime', 'insights.ctc.data.realtime')->name('data.realtime');
        Volt::route('/ctc/data/batch', 'insights.ctc.data.batch')->name('data.batch');
        Volt::route('/ctc/data', 'insights.ctc.data.index')->name('data.index');
        Volt::route('/ctc/slideshows', 'insights.ctc.slides.slideshows')->name('slideshows');
        Volt::route('/ctc/slides/realtime', 'insights.ctc.slides.sliderealtime')->name('slides.realtime');
        Route::get('/ctc', function () {
            return redirect()->route('insights.ctc.data.index');
        })->name('index');

        // ============================================================
        // ROUTE METRIC - FIXED: Baca dari Cache Real-time
        // ============================================================
        Route::get('/ctc/metric/{device_id}', function (string $device_id) {
            
            // PRIORITAS 1: Coba ambil dari cache real-time (diisi oleh InsCtcPoll)
            $cacheKey = "ctc_realtime_{$device_id}";
            $cachedData = Cache::get($cacheKey);
            
            if ($cachedData) {
                // Data real-time dari cache tersedia
                return response()->json([
                    'device_id' => $device_id,
                    'sensor_left' => $cachedData['sensor_left'] ?? 0,
                    'sensor_right' => $cachedData['sensor_right'] ?? 0,
                    'recipe_id' => $cachedData['recipe_id'] ?? 0,
                    'dt_client' => $cachedData['timestamp'] ?? now()->toISOString(),
                    'is_correcting' => $cachedData['is_correcting'] ?? false,
                    'std_min' => $cachedData['std_min'] ?? 0,
                    'std_max' => $cachedData['std_max'] ?? 0,
                    'std_mid' => $cachedData['std_mid'] ?? 0,
                    'action_left' => 0,
                    'action_right' => 0,
                    'batch_code' => 'N/A',
                    'source' => 'cache', // Debug: menandakan data dari cache
                ]);
            }
            
            // PRIORITAS 2: Fallback ke database jika cache kosong
            $metric = \App\Models\InsCtcMetric::where('ins_ctc_machine_id', $device_id)
                ->with(['ins_ctc_recipe', 'ins_ctc_machine', 'ins_rubber_batch'])
                ->latest('created_at')
                ->first();
    
            // Jika tidak ada data sama sekali
            if (!$metric) {
                return response()->json([
                    'device_id' => $device_id,
                    'sensor_left' => 0,
                    'sensor_right' => 0,
                    'recipe_id' => 0,
                    'dt_client' => now()->toISOString(),
                    'is_correcting' => false,
                    'batch_code' => 'N/A',
                    'source' => 'none',
                    'message' => 'Waiting for data...'
                ]);
            }
            
            // FIX: Ambil data array dengan cara yang aman
            $dataArray = $metric->data; // Get array copy
            
            if (!$dataArray || !is_array($dataArray) || count($dataArray) === 0) {
                return response()->json([
                    'device_id' => $device_id,
                    'sensor_left' => 0,
                    'sensor_right' => 0,
                    'recipe_id' => $metric->ins_ctc_recipe_id ?? 0,
                    'dt_client' => now()->toISOString(),
                    'is_correcting' => false,
                    'batch_code' => $metric->ins_rubber_batch->code ?? 'N/A',
                    'source' => 'database',
                ]);
            }
            
            // Ambil data point terakhir dengan aman (tanpa end())
            $lastPoint = $dataArray[count($dataArray) - 1];
            
            return response()->json([
                'device_id' => $device_id,
                'sensor_left' => round($lastPoint[4] ?? 0, 2),
                'sensor_right' => round($lastPoint[5] ?? 0, 2),
                'recipe_id' => $lastPoint[6] ?? $metric->ins_ctc_recipe_id ?? 0,
                'dt_client' => $lastPoint[0] ?? $metric->created_at->toISOString(),
                'is_correcting' => (bool) ($lastPoint[1] ?? false),
                'action_left' => (int) ($lastPoint[2] ?? 0),
                'action_right' => (int) ($lastPoint[3] ?? 0),
                'batch_code' => $metric->ins_rubber_batch->code ?? 'N/A',
                'source' => 'database', // Debug: menandakan data dari database (delay 60s)
            ]);
        })->name('metric');
        
        // ============================================================
        // ROUTE RECIPE - FIXED: Hapus duplikat, optimasi cache
        // ============================================================
        Route::get('/ctc/recipe/{recipe_id}', function (string $recipe_id) {
            // Cache 60 detik (recipe jarang berubah)
            return Cache::remember("ctc_recipe_{$recipe_id}", 60, function () use ($recipe_id) {
                $recipe = \App\Models\InsCtcRecipe::find($recipe_id);
                
                if (!$recipe) {
                    return response()->json([
                        'id' => $recipe_id,
                        'name' => 'Unknown Recipe',
                        'og_rs' => '000',
                        'std_min' => 0,
                        'std_max' => 0,
                        'std_mid' => 0,
                    ]);
                }
                
                return response()->json([
                    'id' => $recipe->id,
                    'name' => $recipe->name,
                    'og_rs' => str_pad($recipe->og_rs ?? $recipe->id, 3, '0', STR_PAD_LEFT),
                    'std_min' => round($recipe->std_min, 2),
                    'std_max' => round($recipe->std_max, 2),
                    'std_mid' => round($recipe->std_mid, 2),
                ]);
            });
        })->name('recipe');

    });
    // ============================================================
    // END CTC ROUTES
    // ============================================================

    Route::name('insights.ldc.')->group(function () {

        Volt::route('/ldc/manage/authorizations', 'insights.ldc.manage.auths')->name('manage.auths');
        Volt::route('/ldc/manage/machines', 'insights.ldc.manage.machines')->name('manage.machines');
        Volt::route('/ldc/manage', 'insights.ldc.manage.index')->name('manage.index');
        Volt::route('/ldc/data', 'insights.ldc.data.index')->name('data.index');
        Volt::route('/ldc/create', 'insights.ldc.create.index')->name('create.index');
        Route::get('/ldc', function () {
            if (auth()->check()) {
                return redirect()->route('insights.ldc.create.index');
            }

            return redirect()->route('insights.ldc.data.index');
        })->name('index');
    });

    Route::name('insights.omv.')->group(function () {

        Volt::route('/omv/manage/authorizations', 'insights.omv.manage.auths')->name('manage.auths');
        Volt::route('/omv/manage/recipes', 'insights.omv.manage.recipes')->name('manage.recipes');
        Volt::route('/omv/manage', 'insights.omv.manage.index')->name('manage.index');
        Volt::route('/omv/data', 'insights.omv.data.index')->name('data.index');
        Volt::route('/omv/create', 'insights.omv.create.index')->name('create.index');
        Route::get('/omv', function () {
            if (auth()->check()) {
                return redirect()->route('insights.omv.create.index');
            }

            return redirect()->route('insights.omv.data.index');
        })->name('index');
    });

    Route::name('insights.rdc.')->group(function () {

        Volt::route('/rdc/manage/authorizations', 'insights.rdc.manage.auths')->name('manage.auths');
        Volt::route('/rdc/manage/machines', 'insights.rdc.manage.machines')->name('manage.machines');
        Volt::route('/rdc/manage', 'insights.rdc.manage.index')->name('manage.index');
        Volt::route('/rdc/data', 'insights.rdc.data.index')->name('data.index');
        Volt::route('/rdc/queue', 'insights.rdc.queue.index')->name('queue.index');
        Route::get('/rdc', function () {
            if (auth()->check()) {
                return redirect()->route('insights.rdc.queue.index');
            }

            return redirect()->route('insights.rdc.data.index');
        })->name('index');

    });

    Route::name('insights.clm.')->group(function () {

        Volt::route('/clm/', 'insights.clm.index')->name('index');
    });

    Route::name('insights.stc.')->group(function () {

        Volt::route('/stc/manage/authorizations', 'insights.stc.manage.auths')->name('manage.auths');
        Volt::route('/stc/manage/machines', 'insights.stc.manage.machines')->name('manage.machines');
        Volt::route('/stc/manage/devices', 'insights.stc.manage.devices')->name('manage.devices');
        Volt::route('/stc/manage', 'insights.stc.manage.index')->name('manage.index');
        Volt::route('/stc/data/adjustments', 'insights.stc.data.adjustments')->name('data.adjustments');
        Volt::route('/stc/data', 'insights.stc.data.index')->name('data.index');
        Volt::route('/stc/create', 'insights.stc.create.index')->name('create.index');
        Route::get('/stc', function () {
            if (auth()->check()) {
                return redirect()->route('insights.stc.create.index');
            }

            return redirect()->route('insights.stc.data.index');
        })->name('index');

    });

    Route::name('insights.erd.')->group(function () {

        Volt::route('/erd/manage/authorizations', 'insights.erd.manage.auths')->name('manage.auths');
        Volt::route('/erd/manage/machines', 'insights.erd.manage.machines')->name('manage.machines');
        Volt::route('/erd/manage/devices', 'insights.erd.manage.devices')->name('manage.devices');
        Volt::route('/erd/manage', 'insights.erd.manage.index')->name('manage.index');
        Volt::route('/erd/summary', 'insights.erd.summary.index')->name('summary.index');
        Volt::route('/erd', 'insights.erd.index')->name('index');

    });

    Route::name('insights.dwp.')->group(function () {

        Volt::route('/dwp/manage/authorizations', 'insights.dwp.manage.auths')->name('manage.auths');
        Volt::route('/dwp/manage/devices', 'insights.dwp.manage.devices')->name('manage.devices');
        Volt::route('/dwp/manage', 'insights.dwp.manage.index')->name('manage.index');
        Volt::route('/dwp/manage/standart-pv', 'insights.dwp.manage.standart-pv')->name('manage.standart-pv');
        Volt::route('/dwp/data/fullscreen', 'insights.dwp.data.dashboard-fullscreen')->name('data.dashboard-fullscreen');
        Volt::route('/dwp/data', 'insights.dwp.data.index')->name('data.index');
        Route::get('/dwp', function () {
            return redirect()->route('insights.dwp.data.index');
        })->name('index');

    });
    Volt::route('/', 'insights.index')->name('insights');
});

// Download route
Route::name('download.')->group(function () {

    Route::get('/download/ins-stc-d-logs/{token}', [DownloadController::class, 'insStcDLogs'])->name('ins-stc-d-logs');
    Route::get('/download/inv-stocks/{token}', [DownloadController::class, 'invStocks'])->name('inv-stocks');
    Route::get('/download/inv-circs/{token}', [DownloadController::class, 'invCircs'])->name('inv-circs');
    Route::get('/download/inv-items/{token}', [DownloadController::class, 'invItems'])->name('inv-items');
    Route::get('/download/inv-items-backup/{token}', [DownloadController::class, 'invItemsBackup'])->name('inv-items-backup');
    Route::get('/download/ins-rtc-metrics', [DownloadController::class, 'insRtcMetrics'])->name('ins-rtc-metrics');
    Route::get('/download/ins-rtc-clumps', [DownloadController::class, 'insRtcClumps'])->name('ins-rtc-clumps');
    Route::get('/download/ins-ldc-hides', [DownloadController::class, 'insLdcHides'])->name('ins-ldc-hides');
});

// All routes that needs to be authenticated
Route::middleware('auth')->group(function () {

    Volt::route('/notifications', 'notifications')->name('notifications');

    // Account routes
    Route::prefix('account')->group(function () {

        Route::name('account.')->group(function () {

            Volt::route('/general', 'account.general')->name('general');
            Volt::route('/password', 'account.password')->name('password');
            Volt::route('/language', 'account.language')->name('language');
            Volt::route('/theme', 'account.theme')->name('theme');
            Volt::route('/edit', 'account.edit')->name('edit');
            Volt::route('/insecure-password', 'account.insecure-password')->name('insecure-password');

        });

        Volt::route('/', 'account.index')->name('account');

    });

    // inventory routes
    Route::prefix('inventory')->group(function () {

        Route::name('inventory.items.')->group(function () {

            Route::middleware('can:create,'.\App\Models\InvItem::class)->group(function () {
                Volt::route('/items/create', 'inventory.items.create')->name('create');
            });
            Volt::route('/items/bulk-operation', 'inventory.items.bulk-operation.index')->name('bulk-operation.index');
            Volt::route('/items/bulk-operation/create-new', 'inventory.items.bulk-operation.create-new')->name('bulk-operation.create-new');
            Volt::route('/items/bulk-operation/update-basic', 'inventory.items.bulk-operation.update-basic')->name('bulk-operation.update-basic');
            Volt::route('/items/bulk-operation/update-location', 'inventory.items.bulk-operation.update-location')->name('bulk-operation.update-location');
            Volt::route('/items/bulk-operation/update-stock', 'inventory.items.bulk-operation.update-stock')->name('bulk-operation.update-stock');
            Volt::route('/items/bulk-operation/update-limit', 'inventory.items.bulk-operation.update-limit')->name('bulk-operation.update-limit');
            Volt::route('/items/bulk-operation/update-status', 'inventory.items.bulk-operation.update-status')->name('bulk-operation.update-status');
            Volt::route('/items/bulk-operation/pull-photos', 'inventory.items.bulk-operation.pull-photos')->name('bulk-operation.pull-photos');
            Volt::route('/items/summary', 'inventory.items.summary')->name('summary');
            Volt::route('/items/{id}', 'inventory.items.show')->name('show');
            Volt::route('/items/{id}/edit', 'inventory.items.edit')->name('edit');
            Volt::route('/items/', 'inventory.items.index')->name('index');

        });

        Route::name('inventory.circs.')->group(function () {

            Volt::route('/circs/bulk-operation', 'inventory.circs.bulk-operation.index')->name('bulk-operation.index');
            Volt::route('/circs/bulk-operation/circ-only', 'inventory.circs.bulk-operation.circ-only')->name('bulk-operation.circ-only');
            Volt::route('/circs/bulk-operation/with-item', 'inventory.circs.bulk-operation.with-item')->name('bulk-operation.with-item');
            Volt::route('/circs/summary/', 'inventory.circs.summary')->name('summary');
            Volt::route('/circs/create', 'inventory.circs.create')->name('create');
            Volt::route('/circs/print', 'inventory.circs.print')->name('print');
            Volt::route('/circs', 'inventory.circs.index')->name('index');

        });

        Route::name('inventory.manage.')->group(function () {
            Volt::route('/manage', 'inventory.manage.index')->name('index');
            Volt::route('/manage/auths', 'inventory.manage.auths')->name('auths');
            Volt::route('/manage/areas', 'inventory.manage.areas')->name('areas');
            Volt::route('/manage/currs', 'inventory.manage.currs')->name('currs');
        });

    });

    // Administration routes
    Route::prefix('admin')->middleware('can:superuser')->group(function () {

        Route::name('admin.')->group(function () {

            Volt::route('/account-manage', 'admin.account.manage')->name('account-manage');
            Volt::route('/daemon-manage', 'admin.daemon.manage')->name('daemon-manage');

        });

        Route::view('/', 'livewire.admin.index')->name('admin');
    });

});

require __DIR__.'/auth.php';