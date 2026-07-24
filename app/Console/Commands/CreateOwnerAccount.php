<?php

namespace App\Console\Commands;

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

#[Signature('owner:create')]
#[Description('Provision the single Owner Account')]
class CreateOwnerAccount extends Command
{
    public function __construct(private CreateNewUser $createNewUser)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->error('An Owner Account already exists.');

            return self::FAILURE;
        }

        $name = (string) $this->ask('Owner name');
        $email = (string) $this->ask('Owner email');
        $password = (string) $this->secret('Recovery password');

        try {
            $this->createNewUser->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $password,
            ]);
        } catch (QueryException) {
            $this->error('An Owner Account already exists.');

            return self::FAILURE;
        }

        $this->info('Owner Account created. Register a passkey after signing in.');

        return self::SUCCESS;
    }
}
