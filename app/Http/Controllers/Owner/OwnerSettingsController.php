<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Owner\AdminAudit;
use App\Owner\IntegrationCredentials;
use App\Owner\OwnerSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

final class OwnerSettingsController extends Controller
{
    public function show(OwnerSettings $settings, IntegrationCredentials $credentials, ?string $section = null): View
    {
        $section ??= 'general';
        abort_unless(in_array($section, OwnerSettings::GROUPS, true), 404);
        $smtpPassword = $credentials->configured()['smtp_password'];
        $values = $settings->group($section);
        $settingSources = [];
        foreach (array_keys($values) as $id) {
            $settingSources[$id] = $settings->hasStored($id) ? 'owner' : 'config';
        }

        return view('owner.settings', [
            'section' => $section,
            'sections' => OwnerSettings::GROUPS,
            'values' => $values,
            'settingSources' => $settingSources,
            'localeSuggestions' => OwnerSettings::localeCurrencySuggestions(),
            'countries' => (array) __('countries'),
            'passwordConfigured' => $smtpPassword['owner_managed'] || $smtpPassword['environment_fallback'],
            'currentMailer' => (string) config('mail.default'),
            'testMailAvailable' => ! in_array(config('mail.default'), ['log', 'array'], true),
        ]);
    }

    public function update(string $section, Request $request, OwnerSettings $settings): RedirectResponse
    {
        abort_unless(in_array($section, OwnerSettings::GROUPS, true), 404);
        try {
            $settings->updateGroup($section, (array) $request->input('settings', []), $request->user('owner'));
        } catch (ValidationException $exception) {
            // Never flash arbitrary settings input (including unsupported secret-like keys) into the session.
            return back()->withErrors($exception->errors())->withInput([]);
        }

        return to_route('owner.settings.index', ['section' => $section])->with('status', __('owner.settings.saved'));
    }

    public function sendTest(Request $request): RedirectResponse
    {
        try {
            $data = Validator::make($request->only('recipient'), [
                'recipient' => ['required', 'email:rfc', 'max:255'],
            ])->validate();
        } catch (ValidationException $exception) {
            // Do not flash arbitrary extra request fields, which could contain a pasted secret.
            return back()->withErrors($exception->errors())->withInput([]);
        }
        /** @var User $actor */
        $actor = $request->user('owner');

        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            AdminAudit::record($actor, 'owner_settings.mail_test_failed', 'owner_setting', 'mail', ['group' => 'mail', 'result' => 'failed']);

            return back()->with('error', __('owner.settings.mail.test_unavailable'));
        }

        try {
            Mail::raw(__('owner.settings.mail.test_body'), function ($message) use ($data): void {
                $message->to($data['recipient'])->subject(__('owner.settings.mail.test_subject'));
            });
            AdminAudit::record($actor, 'owner_settings.mail_test_sent', 'owner_setting', 'mail', ['group' => 'mail', 'result' => 'sent']);

            return back()->with('status', __('owner.settings.mail.test_sent'));
        } catch (Throwable $exception) {
            Log::warning('Owner mail test failed.', ['exception_class' => $exception::class]);
            AdminAudit::record($actor, 'owner_settings.mail_test_failed', 'owner_setting', 'mail', ['group' => 'mail', 'result' => 'failed']);

            return back()->with('error', __('owner.settings.mail.test_failed'));
        }
    }
}
