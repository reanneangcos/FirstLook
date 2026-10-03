<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateResearcher extends Command
{
    protected $signature = 'researcher:create';

    protected $description = 'Create a researcher account with a password entered privately';

    public function handle(): int
    {
        $data = ['name' => $this->ask('Researcher name'), 'email' => $this->ask('Researcher email'),
            'password' => $this->secret('Password (at least 12 characters)'),
            'password_confirmation' => $this->secret('Confirm password')];
        $validator = Validator::make($data, ['name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'], 'password' => ['required', 'string', 'min:12', 'confirmed']]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password'])]);
        $this->info('Researcher account created. Sign in at /login.');

        return self::SUCCESS;
    }
}
