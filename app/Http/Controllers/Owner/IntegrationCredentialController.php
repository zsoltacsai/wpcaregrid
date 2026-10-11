<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Owner\AdminAudit;
use App\Owner\IntegrationCredentials;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class IntegrationCredentialController extends Controller
{
    public function index(IntegrationCredentials $credentials): View
    {
        return view('owner.integrations', [
            'fields' => IntegrationCredentials::FIELDS,
            'configured' => $credentials->configured(),
            // Pass only a boolean. Never expose the configured key or a derived
            // value from it to the view, response, audit log, or browser.
            'appKeyConfigured' => filled(config('app.key')),
        ]);
    }

    public function store(Request $request, IntegrationCredentials $credentials): RedirectResponse
    {
        $validator = Validator::make($request->only(['id', 'value']), [
            'id' => ['required', 'string', 'in:'.implode(',', array_keys(IntegrationCredentials::FIELDS))],
            'value' => ['required', 'string', 'max:8192', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/'],
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        $data = $validator->validated();
        DB::transaction(function () use ($credentials, $data, $request): void {
            $providerId = IntegrationCredentials::FIELDS[$data['id']]['provider_id'] ?? null;
            $isSzamlazz = $providerId === 'szamlazz_hu';
            $action = $isSzamlazz && DB::table('integration_credentials')->where('id', $data['id'])->exists()
                ? 'replaced'
                : 'created';
            $credentials->store($data['id'], $data['value']);
            AdminAudit::record(
                $request->user('owner'),
                'integration_credential.updated',
                'integration_credential',
                $data['id'],
                $isSzamlazz ? ['provider_id' => $providerId, 'action' => $action] : [],
            );
        });

        return to_route('owner.integrations.index')->with('status', __('owner.integrations.saved'));
    }

    public function destroy(Request $request, string $id, IntegrationCredentials $credentials): RedirectResponse
    {
        abort_unless(isset(IntegrationCredentials::FIELDS[$id]), 404);

        DB::transaction(function () use ($credentials, $id, $request): void {
            $credentials->clear($id);
            $providerId = IntegrationCredentials::FIELDS[$id]['provider_id'] ?? null;
            AdminAudit::record(
                $request->user('owner'),
                'integration_credential.cleared',
                'integration_credential',
                $id,
                $providerId ? ['provider_id' => $providerId, 'action' => 'deleted'] : [],
            );
        });

        return to_route('owner.integrations.index')->with('status', __('owner.integrations.cleared'));
    }
}
