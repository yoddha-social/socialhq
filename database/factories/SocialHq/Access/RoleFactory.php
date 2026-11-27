<?php

declare(strict_types=1);

namespace Database\Factories\SocialHq\Access;

use App\Models\Account;
use App\SocialHq\Access\Permission;
use App\SocialHq\Access\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A custom role. Built-in roles come from Roles::ensureBuiltIns(), never from here.
 *
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        return [
            'account_id' => fn (): string => Account::factory()->create()->id,
            'key' => null,
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'description' => fake()->sentence(6),
            'permissions' => [Permission::PostsCreate],
            'position' => 100,
        ];
    }

    /**
     * @param  list<string>  $permissions
     */
    public function withPermissions(array $permissions): static
    {
        return $this->state(fn (): array => ['permissions' => $permissions]);
    }
}
