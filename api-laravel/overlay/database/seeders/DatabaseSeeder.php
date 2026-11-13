<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\Promo;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Demo data. Course ids 1 and 2 match the demo content seeded by the Go content service.
     */
    public function run(): void
    {
        Course::updateOrCreate(['title' => 'Mathematics Fundamentals'], [
            'description' => 'Algebra and linear equations for high school students.',
            'price' => 150000,
        ]);
        Course::updateOrCreate(['title' => 'Physics Intensive'], [
            'description' => 'Mechanics and Newton\'s laws with practice tryouts.',
            'price' => 250000,
        ]);

        Promo::updateOrCreate(['code' => 'WELCOME10'], [
            'type' => 'percent', 'value' => 10, 'max_uses' => 100,
        ]);
        Promo::updateOrCreate(['code' => 'HEMAT25K'], [
            'type' => 'fixed', 'value' => 25000, 'max_uses' => 50,
        ]);
    }
}
