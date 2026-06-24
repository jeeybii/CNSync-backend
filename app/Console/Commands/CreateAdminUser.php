<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdminUser extends Command
{
    protected $signature = 'admin:create
                            {--email= : Admin email address}
                            {--password= : Admin password}
                            {--first-name= : First name}
                            {--last-name= : Last name}';

    protected $description = 'Create an administrator account';

    public function handle(): int
    {
        $firstName = (string) ($this->option('first-name') ?: $this->ask('First name'));
        $lastName = (string) ($this->option('last-name') ?: $this->ask('Last name'));
        $email = (string) ($this->option('email') ?: $this->ask('Email address'));
        $password = (string) ($this->option('password') ?: $this->secret('Password'));

        if (User::query()->where('email', $email)->exists()) {
            $this->error("A user with email [{$email}] already exists.");

            return self::FAILURE;
        }

        $user = User::query()->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => UserRole::Admin,
            'status' => 'active',
        ]);

        $this->info('Admin account created successfully.');
        $this->table(
            ['ID', 'Name', 'Email', 'Role'],
            [[$user->id, "{$user->first_name} {$user->last_name}", $user->email, $user->role->value]],
        );

        return self::SUCCESS;
    }
}
