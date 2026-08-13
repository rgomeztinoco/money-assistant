<?php

namespace App\Actions\Security;

use App\Actions\Categorization\InstallStartingTaxonomy;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ProvisionOwnerAccount
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(
        private InstallStartingTaxonomy $installStartingTaxonomy,
    ) {}

    /** @param array<string, string> $input */
    public function handle(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input): User {
            $owner = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $this->installStartingTaxonomy->handle();

            return $owner;
        }, 3);
    }
}
