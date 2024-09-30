<?php

use App\Models\User;
use Illuminate\Support\Str;
use App\Models\InsOmvMetric;
use App\Models\InsOmvRecipe;
use Illuminate\Http\Request;
use App\Models\InsOmvCapture;
use App\Models\InsRubberBatch;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

Route::get('/omv-recipes', function() {
    $recipes = InsOmvRecipe::all()->map(function($recipe) {
        // Parse the steps JSON field
        $steps = json_decode($recipe->steps);

        // Parse the capture_points JSON field
        $capture_points = json_decode($recipe->capture_points);

        return [
            'id' => $recipe->id,
            'type' => $recipe->type,
            'name' => $recipe->name,
            'capture_points' => $capture_points,
            'steps' => $steps,
        ];
    });

    return response()->json($recipes);
});

Route::post('/omv-metric', function (Request $request) {
    $validator = Validator::make($request->all(), [
        'recipe_id'         => 'required|exists:ins_omv_recipes,id',
        'code'              => 'nullable|string|max:20',
        'line'              => 'required|integer|min:1|max:99',
        'team'              => 'required|in:A,B,C',
        'user_1_emp_id'     => 'required|exists:users,emp_id',
        'user_2_emp_id'     => 'nullable|string',
        'eval'              => 'required|in:too_soon,on_time,too_late',
        'start_at'          => 'required|date_format:Y-m-d H:i:s',
        'end_at'            => 'required|date_format:Y-m-d H:i:s',
        'images'            => 'nullable|array',
        'images.*.step_index' => 'required|integer',
        'images.*.taken_at' => 'required|numeric',
        'images.*.image'    => [
            'required',
            'string',
            'regex:/^data:image\/(?:jpeg|png|jpg|gif);base64,/',
            function ($attribute, $value, $fail) {
                $imageData = base64_decode(explode(',', $value)[1]);
                $image = imagecreatefromstring($imageData);
                if (!$image) {
                    $fail('The '.$attribute.' must be a valid base64 encoded image.');
                }
            },
        ],
        'amps'              => 'nullable|array',
        'amps.*.taken_at'   => 'required|numeric',
        'amps.*.value'      => 'required|integer',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status'        => 'invalid',
            'msg'           => $validator->errors()->all(),
        ], 400);
    } 
    
    $validated = $validator->validated();
    $user1 = User::where('emp_id', $validated['user_1_emp_id'])->first();
    $user2 = User::where('emp_id', $validated['user_2_emp_id'])->first();

    $errors = [];

    if (!$user1) {
        $errors[] = "The emp_id '{$validated['user_1_emp_id']}' on user_1_emp_id does not exist.";
    }

     if (!empty($errors)) {
        return response()->json([
            'status' => 'invalid',
            'msg' => $errors,
        ], 400);
    }

    $code = strtoupper(trim($validated['code']));
    $batch = null;
    if ($code) {
        $batch = InsRubberBatch::firstOrCreate(['code' => $code]);
        if ($batch) {
            $batch->omv_eval = strtolower($validated['eval']);
            $batch->save();
        }
    }

    $amps = $validated['amps']; 
    $filteredAmps = [];

    // limit the array if it's too big then just return empty filteredamps altogether
    if (count($amps) < 3000) {
        $maxTakenAt = null;
        // Traverse the array from the last element to the first
        for ($i = count($amps) - 1; $i >= 0; $i--) {
            $current = $amps[$i];
            if ($maxTakenAt === null || $current['taken_at'] <= $maxTakenAt) {
                $filteredAmps[] = $current;
                $maxTakenAt = $current['taken_at'];
            } else {
                // We found an increase in `taken_at`, discard everything before this point
                break;
            }
        }
    }
    $filteredAmps = array_reverse($filteredAmps);

    $omvMetric = new InsOmvMetric();
    $omvMetric->ins_omv_recipe_id = $validated['recipe_id'];
    $omvMetric->line = $validated['line'];
    $omvMetric->team = $validated['team'];
    $omvMetric->user_1_id = $user1->id;
    $omvMetric->user_2_id = $user2->id ?? null;
    $omvMetric->eval = strtolower($validated['eval']); // converting eval to lowercase
    $omvMetric->start_at = $validated['start_at'];
    $omvMetric->end_at = $validated['end_at'];
    $omvMetric->data = json_encode(['amps' => $filteredAmps]);
    $omvMetric->ins_rubber_batch_id = ($batch->id ?? false) ?: null;
    $omvMetric->save();
    
    $captureMessages = [];
    
    foreach ($validated['images'] as $index => $image) {
        try {
            $imageData = base64_decode(explode(',', $image['image'])[1]);
            $extension = explode('/', mime_content_type($image['image']))[1];
            
            $fileName = sprintf(
                '%s_%s_%s_%s.%s',
                $omvMetric->id,
                $image['step_index'],
                $image['taken_at'],
                Str::random(8),
                $extension
            );
            
            if (!Storage::put('/public/omv-captures/'.$fileName, $imageData)) {
                throw new Exception("Failed to save image file.");
            }
    
            $omvCapture = new InsOmvCapture();
            $omvCapture->ins_omv_metric_id = $omvMetric->id;
            $omvCapture->file_name = $fileName;
            $omvCapture->save();
    
        } catch (Exception $e) {
            $captureMessages[] = "Error saving capture {$index}: " . $e->getMessage();
        }
    }
    
    $responseMessage = 'OMV Metric saved successfully.';
    if (!empty($captureMessages)) {
        $responseMessage .= ' However, there were issues with some captures: ' . implode(', ', $captureMessages);
    }
    
    return response()->json([
        'status' => 'valid',
        'msg' => $responseMessage,
    ], 200);
});
