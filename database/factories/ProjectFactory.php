<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Fictional projects only: the name and the key are assembled at runtime.
 * `client_id` is not fillable on the model; factories build models unguarded,
 * so the factory can still set it.
 *
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * @var class-string<Project>
     */
    protected $model = Project::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'name' => 'Example project '.Str::lower(Str::random(8)),
            'key' => self::randomKey(),
            'status' => 'planned',
            'priority' => 'normal',
            'client_visible' => false,
        ];
    }

    public function visible(): static
    {
        return $this->state(['client_visible' => true]);
    }

    /**
     * Six random uppercase ASCII letters.
     */
    public static function randomKey(): string
    {
        $key = '';

        for ($i = 0; $i < 6; $i++) {
            $key .= chr(random_int(65, 90));
        }

        return $key;
    }
}
