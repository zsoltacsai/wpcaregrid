<?php

namespace App\Http\Controllers\Owner;

use App\AiProviders\ProviderHealthChecker;
use App\Http\Controllers\Controller;
use App\Owner\AdminAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class AiProviderHealthController extends Controller
{
    public function check(Request $request, string $provider, ProviderHealthChecker $checker): RedirectResponse
    {
        $result = $checker->check($provider);
        AdminAudit::record(
            $request->user('owner'),
            'ai_provider.health_checked',
            'ai_provider',
            $provider,
            array_filter([
                'provider_id' => $provider,
                'status' => $result->status->value,
                'generation' => $result->generation,
                'error_category' => $result->errorCategory,
            ], static fn (mixed $value): bool => $value !== null),
        );

        return to_route('owner.integrations.index')->with('status', __('owner.integrations.ai.health.'.$result->status->value));
    }
}
