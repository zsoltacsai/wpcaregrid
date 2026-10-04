<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Owner\SystemStatus;
use Illuminate\Contracts\View\View;

/** Configuration and health of each part of the system; states only, never secrets. */
class SystemController extends Controller
{
    public function __invoke(SystemStatus $status): View
    {
        return view('owner.system', ['checks' => $status->checks()]);
    }
}
