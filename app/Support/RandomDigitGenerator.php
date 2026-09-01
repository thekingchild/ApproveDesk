<?php

namespace App\Support;

class RandomDigitGenerator
{
    public function next(int $length): string
    {
        $minimum = 10 ** ($length - 1);
        $maximum = (10 ** $length) - 1;

        return (string) random_int($minimum, $maximum);
    }
}
