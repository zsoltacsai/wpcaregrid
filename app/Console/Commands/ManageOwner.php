<?php

namespace App\Console\Commands;

use App\Mail\StaffInvitation;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Create an owner login, or remove the owner role. Owners are created only
 * here (a server-side act), never from a web page. The new owner sets their
 * own password from an invitation link; no password is generated or shown.
 *
 *   php artisan owner:user owner@wpcaregrid.com --name="Owner"
 *   php artisan owner:user owner@wpcaregrid.com            (new invitation link)
 *   php artisan owner:user owner@wpcaregrid.com --remove
 */
class ManageOwner extends Command
{
    protected $signature = 'owner:user
        {email : Owner login email}
        {--name= : Display name for a new owner}
        {--remove : Remove the owner role from this login}';

    protected $description = 'Create an owner login (by invitation link) or remove owner access';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid email.');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if ($this->option('remove')) {
            $wasOwner = $user?->isOwner() === true;
            if ($wasOwner) {
                $user->forceFill(['role' => null])->save();
                Password::broker('staff_invitations')->deleteToken($user);
            }
            $this->info($wasOwner ? "Owner access removed from {$email}." : "No owner login {$email}.");

            return self::SUCCESS;
        }

        if ($user && ! $user->isOwner()) {
            // Customer and operator logins stay what they are; an owner gets a login of their own.
            $this->error('This email already belongs to a customer or operator login. Owners need their own login.');

            return self::FAILURE;
        }

        if (! $user) {
            $user = new User(['name' => $this->option('name') ?: $email, 'email' => $email, 'password' => Str::password(64)]);
            $user->forceFill(['role' => User::ROLE_OWNER, 'email_verified_at' => null])->save();
            $this->info("Owner {$email} created.");
        }

        if ($user->hasVerifiedEmail()) {
            $this->info("{$email} is already an active owner. Use \"Forgot password\" on the sign-in page to change the password.");

            return self::SUCCESS;
        }

        $token = Password::broker('staff_invitations')->createToken($user);
        Mail::to($user->email)->queue(new StaffInvitation($user, $token));

        // Single use and expiring; shown once so an owner can be set up before mail is configured.
        $this->warn('Invitation link (shown once, valid '.(int) (config('auth.passwords.staff_invitations.expire') / 60).' hours):');
        $this->line(StaffInvitation::url($user, $token));

        return self::SUCCESS;
    }
}
