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
            $credentials->store($data['id'], $data['value']);
            AdminAudit::record($request->user('owner'), 'integration_credential.updated', 'integration_credential', $data['id']);
        });

        return to_route('owner.integrations.index')->with('status', __('owner.integrations.saved'));
    }

    public function destroy(Request $request, string $id, IntegrationCredentials $credentials): RedirectResponse
    {
        abort_unless(isset(IntegrationCredentials::FIELDS[$id]), 404);

        DB::transaction(function () use ($credentials, $id, $request): void {
            $credentials->clear($id);
            AdminAudit::record($request->user('owner'), 'integration_credential.cleared', 'integration_credential', $id);
        });

        return to_route('owner.integrations.index')->with('status', __('owner.integrations.cleared'));
    }
}
