<?php

namespace Database\Factories;

use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Campaign> */
class CampaignFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->sentence(4);

        return [
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'title' => $title,
            'summary' => fake()->sentence(12),
            'description' => fake()->paragraphs(3, true),
            'currency' => 'EUR',
            'goal_cents' => 1_000_000,
            'status' => 'open',
            'allows_recurring' => true,
        ];
    }
}
