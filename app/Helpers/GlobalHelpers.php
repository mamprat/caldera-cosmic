<?php

namespace App\Helpers;

class GlobalHelpers {
    public function getMedian(array $array): float|int|null
    {
        $numericArray = array_values(array_filter($array, 'is_numeric'));
        $count = count($numericArray);
        // 2. Return null if no numbers were found (cleaner than 0)
        if ($count === 0) {
            return null;
        }
        sort($numericArray, SORT_NUMERIC);
        $middle = floor(($count - 1) / 2);

        // 5. Calculate median
        if ($count % 2) {
            // Odd number of items: return the middle one
            $median = $numericArray[$middle];
        } else {
            // Even number of items: return the average of the two middle ones
            $median = ($numericArray[$middle] + $numericArray[$middle + 1]) / 2;
        }

        // 6. Return the exact value (float or int), not the rounded one
        return $median;
    }
}
