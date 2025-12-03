<?php

namespace Database\Seeders;

use App\Models\InsDwpCount;
use App\Models\InsDwpDevice;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InsDwpCountSeeder extends Seeder
{
    /**
     * The delay in microseconds between each insert.
     * 1,000,000 microseconds = 1 second.
     * @var int
     */
    private int $insertDelayMicroseconds = 200000; // 0.2 seconds

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // testing delay info
        $this->command->info('🚀 Starting DWP count data generation (real-time simulation)...');
        $this->command->info("🕒 A delay of " . ($this->insertDelayMicroseconds / 1000) . "ms will be applied between each record.");

    // Use default values for data generation
    // Generate data from today back to N days ago (inclusive)
    $days = 5; // now generates data for today and the previous 5 days
        $density = 'medium';
        $resetProbability = 0.02;

        // Get all lines from active devices
        $lines = $this->getAllLines();
        if (empty($lines)) {
            $this->command->error('❌ No active devices found. Run InsDwpDeviceSeeder first.');
            return;
        }

        $this->command->info("📊 Generating data for " . count($lines) . " lines over {$days} days");

        $totalRecords = 0;

        foreach ($lines as $line) {
            $this->command->info("  → Processing line {$line}");
            $recordCount = $this->generateDataForLine($line, $days, $density, $resetProbability);
            $totalRecords += $recordCount;
        }

        $this->command->info("✅ Generated {$totalRecords} count records across " . count($lines) . " lines");
    }

    /**
     * Get all lines from active devices
     */
    private function getAllLines(): array
    {
        return InsDwpDevice::active()
            ->get()
            ->flatMap(fn($device) => $device->getLines())
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Generate realistic data for a single line
     */
    private function generateDataForLine(string $line, int $days, string $density, float $resetProbability): int
    {
        $recordCount = 0;
        $currentCumulative = rand(1000, 10000); // Random starting cumulative
        $hasReset = false;
        
        // Prevent error when $days is less than 10
        $resetDay = $days > 10 ? rand(5, $days - 5) : 1; 

        // Define line characteristics
        $lineProfile = $this->getLineProfile($line);

        // Generate data for each day
        // Iterate from 0 .. $days so dayOffset=0 is today, dayOffset=1 is yesterday, etc.
        for ($dayOffset = 0; $dayOffset <= $days; $dayOffset++) {
            $currentDate = Carbon::now()->subDays($dayOffset);

            // Check if this should be a reset day
            if (!$hasReset && $dayOffset <= $resetDay && rand(1, 100) <= ($resetProbability * 100)) {
                $currentCumulative = $this->simulateCounterReset($line, $currentDate, $currentCumulative);
                $hasReset = true;
                $recordCount++;
                continue;
            }
            // Generate data points for the day
            $dayRecords = $this->generateDayData($line, $currentDate, $lineProfile, $density, $currentCumulative);
            $recordCount += count($dayRecords);

            // Update cumulative for next day
            if (!empty($dayRecords)) {
                $lastRecord = end($dayRecords);
                if($lastRecord) {
                    $currentCumulative = $lastRecord->count;
                }
            }
        }

        return $recordCount;
    }

    /**
     * Get production profile for a line
     */
    private function getLineProfile(string $line): array
    {
        $profiles = [
            'A1' => ['type' => 'high', 'base_rate' => 100, 'variation' => 50],
            'B1' => ['type' => 'high', 'base_rate' => 120, 'variation' => 60],
            'A2' => ['type' => 'medium', 'base_rate' => 60, 'variation' => 30],
            'G1' => ['type' => 'medium', 'base_rate' => 70, 'variation' => 35],
            'B2' => ['type' => 'variable', 'base_rate' => 40, 'variation' => 40],
            'B3' => ['type' => 'variable', 'base_rate' => 45, 'variation' => 35],
            'G2' => ['type' => 'low', 'base_rate' => 30, 'variation' => 20],
        ];

        return $profiles[$line] ?? ['type' => 'medium', 'base_rate' => 50, 'variation' => 25];
    }

    /**
     * Generate data for a single day
     */
    private function generateDayData(string $line, Carbon $date, array $profile, string $density, int &$currentCumulative): array
    {
        $records = [];

        $intervals = match($density) {
            'high' => 5,
            'medium' => 10,
            'low' => 15,
            default => 10
        };

        $isWeekend = $date->isWeekend();
        $startHour = $isWeekend ? 9 : 7;
        $endHour = $isWeekend ? 16 : 18;

        for ($hour = $startHour; $hour <= $endHour; $hour++) {
            $pointsInHour = 60 / $intervals;

            for ($point = 0; $point < $pointsInHour; $point++) {
                $minute = $point * $intervals;
                $timestamp = $date->copy()->setTime($hour, $minute, rand(0, 59));

                $incremental = $this->calculateIncremental($hour, $profile, $isWeekend);

                if ($incremental > 0) {
                    $currentCumulative += $incremental;
                    $records[] = $this->createCountRecord([
                        'line' => $line,
                        'count' => $currentCumulative,
                        'incremental' => $incremental,
                        'created_at' => $timestamp,
                    ]);
                }
            }
        }

        // // Add some night activity
        // if (rand(1, 100) <= 30) {
        //     $nightIncremental = $this->calculateNightIncremental($profile);
        //     if ($nightIncremental > 0) {
        //         $currentCumulative += $nightIncremental;
        //         $nightTime = $date->copy()->setTime(rand(22, 23), rand(0, 59), rand(0, 59));
                
        //         $records[] = $this->createCountRecord([
        //             'line' => $line,
        //             'count' => $currentCumulative,
        //             'incremental' => $nightIncremental, // <-- FIXED BUG HERE
        //             'created_at' => $nightTime,      // <-- FIXED BUG HERE
        //         ]);
        //     }
        // }

        return array_filter($records);
    }

    /**
     * Calculate incremental value based on time and profile
     */
    private function calculateIncremental(int $hour, array $profile, bool $isWeekend): int
    {
        $baseRate = $profile['base_rate'];
        $variation = $profile['variation'];

        $timeMultiplier = match(true) {
            $hour >= 8 && $hour <= 11 => 1.2,
            $hour >= 12 && $hour <= 13 => 0.7,
            $hour >= 14 && $hour <= 16 => 1.1,
            $hour >= 17 && $hour <= 18 => 0.8,
            default => 1.0
        };

        if ($isWeekend) {
            $timeMultiplier *= 0.6;
        }

        $randomFactor = (rand(-$variation, $variation) / 100) + 1;
        $incremental = (int) round($baseRate * $timeMultiplier * $randomFactor);

        return max(0, $incremental);
    }

    /**
     * Calculate night incremental activity
     */
    private function calculateNightIncremental(array $profile): int
    {
        $baseRate = $profile['base_rate'];
        $nightRate = (int) round($baseRate * 0.15);

        return rand(1, max(1, $nightRate));
    }

    /**
     * Simulate a counter reset event
     */
    private function simulateCounterReset(string $line, Carbon $date, int $previousCumulative): int
    {
        $resetTime = $date->copy()->setTime(rand(6, 8), rand(0, 59), rand(0, 59));
        $newStart = rand(0, 100);

        $this->createCountRecord([
            'line' => $line,
            'count' => $newStart,
            'incremental' => $newStart,
            'created_at' => $resetTime,
        ]);

        $this->command->comment("    🔄 Counter reset for line {$line} at {$resetTime->format('Y-m-d H:i')} (was {$previousCumulative}, now {$newStart})");

        return $newStart;
    }

    /**
     * NEW HELPER METHOD
     * Creates a single count record and pauses execution.
     */
    private function createCountRecord(array $data): ?InsDwpCount
    {
        $position = ["L", "R"];
        $duration = rand(5, 20);

        try {
            $record = InsDwpCount::create(array_merge([
                'mechine'   => rand(1, 4),
                'position'  => $position[array_rand($position)],
                'duration'  => $duration,
                'pv'        => json_encode([rand(30, 45), rand(38, 45)]),
                'std_error' => json_encode([rand(0, 1), rand(0, 1)]),
            ], $data));

            // This is the key part that slows down the seeder
            usleep($this->insertDelayMicroseconds);

            return $record;

        } catch (\Exception $e) {
            $this->command->error('DB Error: ' . $e->getMessage());
            return null;
        }
    }
}