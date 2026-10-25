<?php

namespace App\Http\Controllers\Operator;

use App\AdminAccess\AccessLevel;
use App\AdminAccess\ActorType;
use App\AdminAccess\AdminAccessService;
use App\Backups\BackupPolicy;
use App\Backups\RestoreService;
use App\Compatibility\CapabilityReadiness;
use App\Connector\ConnectorError;
use App\Connector\DisconnectWebsite;
use App\Connector\HealthStatus;
use App\Connector\JobQueue;
use App\Connector\JobType;
use App\Connector\PairingCodes;
use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Models\Website;
use App\Operator\BackupState;
use App\Operator\ConnectorState;
use App\Operator\Permission;
use App\Operator\Refusal;
use App\Operator\WebsiteDirectory;
use App\Pricing\ProductType;
use App\Security\SecurityScanner;
use App\Security\SecurityState;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Websites in the operator console. Reads anything a permission allows;
 * changes go through the same services as the customer area.
 */
class WebsiteController extends Controller
{
    public function index(Request $request, WebsiteDirectory $directory, SecurityScanner $security): View
    {
        $filters = WebsiteDirectory::filters($request->query());
        $websites = $directory->paginate($filters);

        return view('operator.websites.index', [
            'websites' => $websites,
            'filters' => $filters,
            'security' => $security->summaries($websites->items()),
            'options' => [
                'status' => HealthStatus::cases(),
                'type' => ProductType::cases(),
                'backup' => BackupState::cases(),
                'security' => SecurityState::cases(),
                'connector' => ConnectorState::cases(),
            ],
        ]);
    }

    public function show(Request $request, Website $website, BackupPolicy $policy, RestoreService $restores, AdminAccessService $access, SecurityScanner $security): View
    {
        $website->load(['customer', 'syntheticFixture', 'subscription', 'activeConnector', 'inventory']);
        $website->loadExists('connectors');
        $user = $request->user();
        $can = fn (Permission $permission) => $user->hasOperatorPermission($permission);
        $connectorState = ConnectorState::of($website);

        $backups = $can(Permission::BackupsView) ? $website->backups()->latest('id')->limit(15)->get() : collect();

        return view('operator.websites.show', [
            'website' => $website,
            'customer' => $website->customer,
            'subscription' => $website->subscription,
            'connector' => $website->activeConnector,
            'inventory' => $website->inventory,
            'capabilitySnapshot' => $website->capabilitySnapshot,
            'connectorState' => $connectorState,
            'security' => $security->summaries([$website])[$website->id],
            'securityConfigured' => $security->isConfigured(),
            'latestVerified' => $website->backups()->usableRecoveryPoint()->latest('verified_at')->first(),
            'backups' => $backups,
            // Offered only for verified backups of this website while its connector reports in;
            // RestoreService checks all of it again (and more) when the restore is requested.
            'restorable' => $can(Permission::BackupsRestore) && $connectorState === ConnectorState::Connected
                ? $backups->filter(fn (Backup $backup) => $backup->isRestorable() && $restores->refusal($website, $backup, $backup->type) === null)->pluck('id')->all()
                : [],
            'restoreIsolationSupported' => app(CapabilityReadiness::class)->restoreIsolationSupported($website),
            'restores' => $can(Permission::BackupsView) ? $website->restores()->with('backup')->latest('id')->limit(5)->get() : collect(),
            'backupPolicy' => $policy->for($website),
            'updateJobs' => $can(Permission::UpdatesView)
                ? $website->jobs()->with('backup')->whereIn('type', [JobType::UpdatePlugin, JobType::UpdateTheme, JobType::UpdateCore])->latest('id')->limit(15)->get()
                : collect(),
            'openAccess' => $website->adminAccessRequests()->with('requestedBy')->whereIn('status', ['pending', 'authorized', 'active'])->latest('id')->get(),
            'maintenanceRefusal' => $access->refusal($website, $user, ActorType::Operator, AccessLevel::Maintenance, false),
            'events' => $can(Permission::AuditView) ? $website->events()->with('actorUser')->latest('id')->limit(20)->get() : collect(),
            'pairingCode' => $this->flashedPairingCode(),
        ]);
    }

    public function requestCompatibilityCheck(Request $request, Website $website, JobQueue $jobs): RedirectResponse
    {
        if ($website->jobs()->where('type', JobType::GetInventory)->whereIn('status', ['queued', 'delivered'])->exists()) {
            return redirect()->route('operator.websites.show', $website)->with('status', __('compatibility.check_pending'));
        }

        try {
            $jobs->queue($website, JobType::GetInventory, requestedBy: 'operator:'.$request->user()->id);
        } catch (ConnectorError) {
            return redirect()->route('operator.websites.show', $website)->with('compatibility_error', __('compatibility.check_unavailable'));
        }

        return redirect()->route('operator.websites.show', $website)->with('status', __('compatibility.check_requested'));
    }

    /** Re-pair: a new one-time pairing code, shown once. The current connector keeps working until it is used. */
    public function pairingCode(Request $request, Website $website, PairingCodes $codes): RedirectResponse
    {
        if (! $website->allowsServiceOperations() && ! $website->allowsSyntheticConnectorOperations()) {
            return back()->with('operator_error', Refusal::flash('SUBSCRIPTION_INACTIVE'));
        }

        $code = $codes->issue($website, $request->user());
        Log::info('Operator issued a pairing code.', ['operator' => $request->user()->id, 'website' => $website->uuid]);

        return redirect()->route('operator.websites.show', $website)
            ->with('pairing_code', ['code' => $code, 'expires_at' => now()->addSeconds(config('connector.pairing_code_ttl'))->toIso8601String()]);
    }

    public function disconnect(Request $request, Website $website, DisconnectWebsite $disconnect): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']]);

        $disconnect($website, 'disconnected_by_operator', 'operator:'.$request->user()->id);

        return redirect()->route('operator.websites.show', $website)->with('status', __('operator.connector.disconnected'));
    }

    /** @return array{code: string, expires_at: Carbon}|null */
    private function flashedPairingCode(): ?array
    {
        $flashed = session('pairing_code');

        if (! is_array($flashed) || ! is_string($flashed['code'] ?? null) || ! is_string($flashed['expires_at'] ?? null)) {
            return null;
        }

        return ['code' => $flashed['code'], 'expires_at' => Carbon::parse($flashed['expires_at'])];
    }
}
