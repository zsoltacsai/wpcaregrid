<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\MonitoringAlertDelivery;
use App\Models\MonitoringIncident;
use App\Models\MonitoringMaintenanceWindow;
use App\Models\MonitoringResult;
use App\Models\MonitoringTransition;
use App\Owner\AdminAudit;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MonitoringController extends Controller
{
    public function index(): View
    {
        return view('owner.monitoring.index', [
            'incidents' => MonitoringIncident::query()->where('status', 'open')->latest('last_observed_at')->paginate(30),
            'platformResults' => MonitoringResult::query()->where('scope', 'platform')->latest('observed_at')->paginate(30, ['*'], 'checks'),
            'transitions' => MonitoringTransition::query()->latest('id')->paginate(30, ['*'], 'history'),
            'windows' => MonitoringMaintenanceWindow::query()->whereNull('ended_at')->where('ends_at', '>', now())->latest('starts_at')->get(),
            'deliveryFailures' => MonitoringAlertDelivery::query()->where('status', 'failed')->latest('updated_at')->limit(20)->get(),
        ]);
    }

    public function storeMaintenance(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'scope' => ['required', Rule::in(['platform', 'website'])],
            'website_uuid' => ['nullable', 'uuid', 'required_if:scope,website', 'exists:websites,uuid'],
            'check_family' => ['nullable', Rule::in(['website', 'connector', 'backup', 'security', 'platform', 'provider'])],
            'reason' => ['required', 'string', 'min:8', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);
        $starts = CarbonImmutable::parse($data['starts_at'], 'UTC')->utc();
        $ends = CarbonImmutable::parse($data['ends_at'], 'UTC')->utc();
        $maxHours = $data['scope'] === 'website' ? 24 : 2;
        abort_unless($starts->greaterThanOrEqualTo(now()->subMinute()) && $ends->diffInRealHours($starts, true) <= $maxHours, 422);

        $window = MonitoringMaintenanceWindow::query()->create([
            'scope' => $data['scope'],
            'subject_type' => $data['scope'] === 'website' ? 'website' : null,
            'subject_id' => $data['scope'] === 'website' ? $data['website_uuid'] : null,
            'check_family' => $data['check_family'] ?? null,
            'created_by' => $request->user('owner')->id,
            'reason' => $data['reason'],
            'starts_at' => $starts,
            'ends_at' => $ends,
        ]);

        AdminAudit::record($request->user('owner'), 'monitoring.maintenance_created', 'maintenance_window', (string) $window->id, [
            'scope' => $window->scope->value,
            'subject_id' => $window->subject_id,
            'check_family' => $window->check_family,
            'starts_at' => $starts->toIso8601String(),
            'ends_at' => $ends->toIso8601String(),
        ]);

        return to_route('owner.monitoring.index')->with('status', __('m10.maintenance_created'));
    }
}
