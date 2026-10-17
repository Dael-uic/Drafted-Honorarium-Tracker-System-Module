<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class LocalTestAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['Test Admin', 'Super Admin', 'superadmin@uic.edu.ph', 'AdminTest123!', 'Super Admin', null],
            ['Juan', 'Dela Cruz', 'student@test.com', 'StudentTest123!', 'Student', 1],
            ['Test', 'Assistant', 'aa@test.com', 'AATest123!', 'Administrative Assistant', 4],
            ['Test', 'Coordinator', 'coordinator@test.com', 'CoordinatorTest123!', 'Coordinator', 2],
            ['Test', 'Dean', 'dean@test.com', 'DeanTest123!', 'Dean', 3],
            ['Test', 'Registrar', 'registrar@test.com', 'RegistrarTest123!', 'Registrar', 5],
            ['Test', 'Finance', 'finance@test.com', 'FinanceTest123!', 'Finance', 6],
            ['Test', 'Faculty', 'faculty@test.com', 'FacultyTest123!', 'Faculty', null],
        ];

        foreach ($accounts as [$first, $last, $email, $password, $role, $roleId]) {
            $user = User::firstOrNew(['email' => $email]);
            $user->first_name = $first;
            $user->last_name = $last;
            $user->password = $password;
            $user->role = $role;
            $user->role_id = $roleId;
            $user->email_verified_at = now();
            $user->save();

            if ($roleId !== null) {
                $user->roles()->syncWithoutDetaching([$roleId]);
            }

            echo "Created/reset: {$email} ({$role})" . PHP_EOL;
        }
    }
}
