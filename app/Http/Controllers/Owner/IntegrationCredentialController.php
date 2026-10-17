<?php

namespace App\Http\Controllers\Owner;

use App\AiProviders\ProviderCredentialState;
use App\AiProviders\ProviderRegistry;
use App\Http\Controllers\Controller;
use App\Owner\AdminAudit;
use App\Owner\IntegrationCredentials;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class IntegrationCredentialController extends Controller
{
    public function index(IntegrationCredentials $credentials, ProviderRegistry $providers, ProviderCredentialState $providerState): View
    {
        $aiProviders = [];
        foreach ($providers->all() as $providerId => $provider) {
            $aiProviders[$providerId] = $provider + ['state' => $providerState->state($providerId)];
        }

        return view('owner.integrations', [
            'fields' => array_diff_key(IntegrationCredentials::FIELDS, array_fill_keys(array_map(
                fn (string $providerId): string => $providers->get($providerId)['credential_id'],
                array_keys($providers->all()),
            ), true)),
            'aiProviders' => $aiProviders,
            'configured' => $credentials->configured(),
            // Pass only a boolean. Never expose the configured key or a derived
            // value from it to the view, response, audit log, or browser.
            'appKeyConfigured' => filled(config('app.key')),
        ]);
    }

    public function store(Request $request, IntegrationCredentials $credentials, ProviderRegistry $providers, ProviderCredentialState $providerState): RedirectResponse
    {
        $aiCredentialIds = array_map(fn (string $providerId): string => $providers->get($providerId)['credential_id'], array_keys($providers->all()));
        $isAiCredential = in_array($request->input('id'), $aiCredentialIds, true);
        $valueRules = ['required', 'string', 'max:8192', $isAiCredential
            ? 'not_regex:/[\x00-\x1F\x7F]/'
            : 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/'];
        $validator = Validator::make($request->only(['id', 'value']), [
            'id' => ['required', 'string', 'in:'.implode(',', array_keys(IntegrationCredentials::FIELDS))],
            'value' => $valueRules,
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        $data = $validator->validated();
        if ($isAiCredential) {
            $passwordValidator = Validator::make($request->only('current_password'), [
                'current_password' => ['required', 'string', 'max:255'],
            ]);
            if ($passwordValidator->fails()) {
                return back()->withErrors($passwordValidator);
            }
            if (! Hash::check($passwordValidator->validated()['current_password'], (string) $request->user('owner')?->getAuthPassword())) {
                return back()->withErrors(['current_password' => __('owner.integrations.ai.password_failed')]);
            }
        }

        DB::transaction(function () use ($credentials, $data, $request, $isAiCredential, $providerState, $providers): void {
            $providerId = IntegrationCredentials::FIELDS[$data['id']]['provider_id'] ?? null;
            $isSzamlazz = $providerId === 'szamlazz_hu';
            $action = $isSzamlazz && DB::table('integration_credentials')->where('id', $data['id'])->exists()
                ? 'replaced'
                : 'created';
            if ($isAiCredential) {
                $action = DB::table('integration_credentials')->where('id', $data['id'])->exists() ? 'replaced' : 'created';
                $providerId = $this->providerForCredential($data['id'], $providers);
            }
            $credentials->store($data['id'], $data['value']);
            $generation = $isAiCredential ? $providerState->rotate($providerId, true) : null;
            AdminAudit::record(
                $request->user('owner'),
                $isAiCredential
                    ? ($action === 'replaced' ? 'ai_provider.credential_replaced' : 'ai_provider.credential_created')
                    : 'integration_credential.updated',
                'integration_credential',
                $data['id'],
                $isAiCredential
                    ? ['provider_id' => $providerId, 'generation' => $generation]
                    : ($isSzamlazz ? ['provider_id' => $providerId, 'action' => $action] : []),
            );
        });

        return to_route('owner.integrations.index')->with('status', __('owner.integrations.saved'));
    }

    public function destroy(Request $request, string $id, IntegrationCredentials $credentials, ProviderRegistry $providers, ProviderCredentialState $providerState): RedirectResponse
    {
        abort_unless(isset(IntegrationCredentials::FIELDS[$id]), 404);
        $isAiCredential = in_array($id, array_map(fn (string $providerId): string => $providers->get($providerId)['credential_id'], array_keys($providers->all())), true);
        if ($isAiCredential) {
            $passwordValidator = Validator::make($request->only('current_password'), [
                'current_password' => ['required', 'string', 'max:255'],
            ]);
            if ($passwordValidator->fails()) {
                return back()->withErrors($passwordValidator);
            }
            if (! Hash::check($passwordValidator->validated()['current_password'], (string) $request->user('owner')?->getAuthPassword())) {
                return back()->withErrors(['current_password' => __('owner.integrations.ai.password_failed')]);
            }
        }

        DB::transaction(function () use ($credentials, $id, $request, $isAiCredential, $providers, $providerState): void {
            $credentials->clear($id);
            $providerId = IntegrationCredentials::FIELDS[$id]['provider_id'] ?? null;
            $generation = null;
            if ($isAiCredential) {
                $providerId = $this->providerForCredential($id, $providers);
                $generation = $providerState->rotate($providerId, false);
            }
            AdminAudit::record(
                $request->user('owner'),
                $isAiCredential ? 'ai_provider.credential_removed' : 'integration_credential.cleared',
                'integration_credential',
                $id,
                $isAiCredential
                    ? ['provider_id' => $providerId, 'generation' => $generation]
                    : ($providerId ? ['provider_id' => $providerId, 'action' => 'deleted'] : []),
            );
        });

        return to_route('owner.integrations.index')->with('status', $isAiCredential ? __('owner.integrations.ai.removed') : __('owner.integrations.cleared'));
    }

    private function providerForCredential(string $credentialId, ProviderRegistry $providers): string
    {
        foreach ($providers->all() as $providerId => $provider) {
            if ($provider['credential_id'] === $credentialId) {
                return $providerId;
            }
        }

        abort(404);
    }
}
