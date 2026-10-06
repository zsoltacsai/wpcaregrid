<?php

namespace App\Http\Controllers\Owner;

use App\Cloudflare\CloudflareDnsException;
use App\Cloudflare\CloudflareDnsService;
use App\Cloudflare\WpCareGridInfrastructureDns;
use App\Http\Controllers\Controller;
use App\Owner\AdminAudit;
use App\Owner\IntegrationCredentials;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class CloudflareDnsController extends Controller
{
    public function index(CloudflareDnsService $dns, IntegrationCredentials $credentials): View
    {
        $configured = $credentials->configured()['cloudflare_api_token'];
        $diagnostic = null;
        $error = null;
        if ($configured['owner_managed'] || $configured['environment_fallback']) {
            try {
                $diagnostic = $dns->diagnose();
            } catch (CloudflareDnsException $exception) {
                $error = $exception->category;
            }
        }

        return view('owner.dns.cloudflare', [
            'configured' => $configured,
            'zones' => $dns->allowedZones(),
            'diagnostic' => $diagnostic,
            'error' => $error,
        ]);
    }

    public function zone(string $zone, CloudflareDnsService $dns): View
    {
        try {
            $records = $dns->listRecords($zone);
            $error = null;
        } catch (CloudflareDnsException $exception) {
            $records = [];
            $error = $exception->category;
        }

        return view('owner.dns.zone', ['zone' => $zone, 'records' => $records, 'error' => $error]);
    }

    public function infrastructure(CloudflareDnsService $dns): View
    {
        $plans = [];
        $error = null;
        foreach (WpCareGridInfrastructureDns::definitions() as $id => $definition) {
            try {
                $plans[$id] = $dns->planInfrastructureEnsure($id);
            } catch (CloudflareDnsException $exception) {
                $error ??= $exception->category;
            }
        }

        return view('owner.dns.infrastructure', [
            'definitions' => WpCareGridInfrastructureDns::definitions(),
            'plans' => $plans,
            'error' => $error,
        ]);
    }

    public function planInfrastructure(Request $request, CloudflareDnsService $dns): RedirectResponse
    {
        $data = $request->validate(['definition_id' => ['required', 'string', 'in:staging_wp_test_a,staging_mail_a']]);
        try {
            $plan = $dns->planInfrastructureEnsure($data['definition_id']);
        } catch (CloudflareDnsException $exception) {
            AdminAudit::record($request->user('owner'), 'dns.infrastructure.failed', 'dns_definition', $data['definition_id'], [
                'operation' => 'preview', 'error' => $exception->category,
            ]);

            return back()->with('dns_error', $exception->category);
        }

        $id = Str::random(40);
        $request->session()->put('cloudflare_dns_previews.'.$id, [
            'kind' => 'infrastructure', 'definition_id' => $data['definition_id'], 'plan' => $plan, 'created_at' => now()->timestamp,
        ]);

        return to_route('owner.dns.cloudflare.infrastructure')->with('dns_preview_id', $id);
    }

    public function applyInfrastructure(Request $request, CloudflareDnsService $dns): RedirectResponse
    {
        $data = $request->validate(['preview_id' => ['required', 'string', 'size:40'], 'confirmed' => ['accepted']]);
        $key = 'cloudflare_dns_previews.'.$data['preview_id'];
        $preview = $request->session()->get($key);
        abort_unless(is_array($preview)
            && ($preview['kind'] ?? null) === 'infrastructure'
            && now()->timestamp - (int) ($preview['created_at'] ?? 0) <= 600, 404);

        $definition = WpCareGridInfrastructureDns::definitions()[$preview['definition_id'] ?? ''] ?? null;
        abort_unless(is_array($definition), 404);
        try {
            $result = $dns->applyInfrastructureEnsure($definition['id'], $preview['plan']);
            if ($result['action'] !== 'unchanged') {
                AdminAudit::record($request->user('owner'), 'dns.infrastructure.applied', 'dns_definition', $definition['id'], [
                    'zone' => $definition['zone'],
                    'type' => $definition['record']['type'],
                    'name' => $definition['record']['name'],
                    'record_id' => (string) ($result['record_id'] ?? $result['result']['id'] ?? 'unknown'),
                    'result' => $result['action'],
                ]);
            }
            $request->session()->forget($key);

            return to_route('owner.dns.cloudflare.infrastructure')->with('status', __('owner.dns.infrastructure.'.($result['action'] === 'unchanged' ? 'unchanged' : 'applied')));
        } catch (CloudflareDnsException $exception) {
            AdminAudit::record($request->user('owner'), 'dns.infrastructure.failed', 'dns_definition', $definition['id'], [
                'zone' => $definition['zone'], 'type' => $definition['record']['type'], 'name' => $definition['record']['name'],
                'operation' => $preview['plan']['action'], 'error' => $exception->category,
            ]);

            return to_route('owner.dns.cloudflare.infrastructure')->with('dns_error', $exception->category);
        }
    }

    public function planEnsure(Request $request, CloudflareDnsService $dns): RedirectResponse
    {
        $data = $request->validate([
            'zone' => ['required', 'string', 'max:253'],
            'type' => ['required', 'string', 'max:8'],
            'name' => ['required', 'string', 'max:253'],
            'content' => ['required', 'string', 'max:4096'],
            'ttl' => ['nullable'],
            'proxied' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'comment' => ['nullable', 'string', 'max:500'],
            'record_id' => ['nullable', 'string', 'max:128'],
        ]);
        try {
            $plan = $dns->planEnsure($data['zone'], $data);
        } catch (CloudflareDnsException $exception) {
            if ($exception->category === 'record_protected') {
                AdminAudit::record($request->user('owner'), 'dns.record.failed', 'dns_zone', $data['zone'], ['operation' => 'ensure', 'error' => $exception->category]);
            }

            return back()->with('dns_error', $exception->category)->withInput($request->except('content'));
        }

        $id = Str::random(40);
        $request->session()->put('cloudflare_dns_previews.'.$id, ['kind' => 'ensure', 'plan' => $plan, 'input' => $data, 'created_at' => now()->timestamp]);

        return redirect()->route('owner.dns.cloudflare.zone', ['zone' => $data['zone']])->with('dns_preview_id', $id);
    }

    public function applyEnsure(Request $request, CloudflareDnsService $dns): RedirectResponse
    {
        $data = $request->validate(['preview_id' => ['required', 'string', 'size:40'], 'confirmed' => ['accepted']]);
        $key = 'cloudflare_dns_previews.'.$data['preview_id'];
        $preview = $request->session()->get($key);
        abort_unless(is_array($preview) && ($preview['kind'] ?? null) === 'ensure' && now()->timestamp - (int) ($preview['created_at'] ?? 0) <= 600, 404);

        try {
            if ($preview['plan']['action'] !== 'unchanged') {
                AdminAudit::record($request->user('owner'), 'dns.record.'.($preview['plan']['action'] === 'created' ? 'create_requested' : 'update_requested'), 'dns_zone', $preview['plan']['zone'], [
                    'type' => $preview['plan']['after']['type'], 'name' => $preview['plan']['after']['name'],
                ]);
            }
            $result = $dns->applyEnsure($preview['input']['zone'], $preview['input'], $preview['plan']);
            if ($result['action'] !== 'unchanged') {
                $event = $result['action'] === 'created' ? 'dns.record.created' : 'dns.record.updated';
                AdminAudit::record($request->user('owner'), $event, 'dns_record', (string) ($result['record_id'] ?? $result['result']['id'] ?? 'unknown'), [
                    'zone' => $result['zone'], 'type' => $result['after']['type'], 'name' => $result['after']['name'], 'result' => $result['action'],
                ]);
            }
            $request->session()->forget($key);

            return to_route('owner.dns.cloudflare.zone', ['zone' => $preview['input']['zone']])->with('status', __('owner.dns.'.($result['action'] === 'unchanged' ? 'unchanged' : 'applied')));
        } catch (CloudflareDnsException $exception) {
            AdminAudit::record($request->user('owner'), 'dns.record.failed', 'dns_zone', $preview['input']['zone'], ['operation' => (string) $preview['plan']['action'], 'error' => $exception->category]);

            return to_route('owner.dns.cloudflare.zone', ['zone' => $preview['input']['zone']])->with('dns_error', $exception->category);
        }
    }

    public function planDelete(Request $request, CloudflareDnsService $dns): RedirectResponse
    {
        $data = $request->validate(['zone' => ['required', 'string', 'max:253'], 'record_id' => ['required', 'string', 'max:128']]);
        try {
            $plan = $dns->planDelete($data['zone'], $data['record_id']);
        } catch (CloudflareDnsException $exception) {
            if ($exception->category === 'record_protected') {
                AdminAudit::record($request->user('owner'), 'dns.record.failed', 'dns_zone', $data['zone'], ['operation' => 'delete', 'error' => $exception->category]);
            }

            return back()->with('dns_error', $exception->category);
        }

        $id = Str::random(40);
        $request->session()->put('cloudflare_dns_previews.'.$id, ['kind' => 'delete', 'plan' => $plan, 'created_at' => now()->timestamp]);

        return to_route('owner.dns.cloudflare.zone', ['zone' => $data['zone']])->with('dns_preview_id', $id);
    }

    public function applyDelete(Request $request, CloudflareDnsService $dns): RedirectResponse
    {
        $data = $request->validate(['preview_id' => ['required', 'string', 'size:40'], 'confirmed' => ['accepted']]);
        $key = 'cloudflare_dns_previews.'.$data['preview_id'];
        $preview = $request->session()->get($key);
        abort_unless(is_array($preview) && ($preview['kind'] ?? null) === 'delete' && now()->timestamp - (int) ($preview['created_at'] ?? 0) <= 600, 404);

        try {
            AdminAudit::record($request->user('owner'), 'dns.record.delete_requested', 'dns_record', $preview['plan']['record_id'], [
                'zone' => $preview['plan']['zone'], 'type' => $preview['plan']['before']['type'], 'name' => $preview['plan']['before']['name'],
            ]);
            $result = $dns->applyDelete($preview['plan']['zone'], $preview['plan']['record_id'], $preview['plan']);
            AdminAudit::record($request->user('owner'), 'dns.record.deleted', 'dns_record', $result['record_id'], [
                'zone' => $result['zone'], 'type' => $result['before']['type'], 'name' => $result['before']['name'], 'result' => 'deleted',
            ]);
            $request->session()->forget($key);

            return to_route('owner.dns.cloudflare.zone', ['zone' => $result['zone']])->with('status', __('owner.dns.deleted'));
        } catch (CloudflareDnsException $exception) {
            AdminAudit::record($request->user('owner'), 'dns.record.failed', 'dns_zone', $preview['plan']['zone'], ['operation' => 'delete', 'error' => $exception->category]);

            return to_route('owner.dns.cloudflare.zone', ['zone' => $preview['plan']['zone']])->with('dns_error', $exception->category);
        }
    }
}
