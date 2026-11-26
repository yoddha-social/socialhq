<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Access\BuiltInRole;
use App\SocialHq\Access\Roles;
use Illuminate\Support\Facades\DB;

class CreateWorkspace
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function execute(User $user, array $data): Workspace
    {
        $workspace = DB::transaction(function () use ($user, $data): Workspace {
            $workspace = Workspace::create([
                'name' => data_get($data, 'name'),
                'account_id' => $user->account_id,
                'user_id' => $user->id,
            ]);

            // Social HQ: the creator gets the Admin role (Roles::membershipColumns()).
            $workspace->members()->attach($user->id, Roles::membershipColumns(Roles::builtIn($workspace->account_id, BuiltInRole::Admin)));
            $user->switchWorkspace($workspace);

            return $workspace;
        });

        return $workspace;
    }
}
