<?php

namespace App\Http\Controllers\ControlPanel;

use App\Http\Controllers\Controller;
use App\Support\ControlPanel\ActionRegistry;
use App\Support\ControlPanel\HubMap;
use Illuminate\View\View;

class HubController extends Controller
{
    public function index(HubMap $hub, ActionRegistry $registry): View
    {
        $launch = $registry->find('win.launch-claude');

        return view('control-panel.hub', [
            'map' => $hub->load((string) config('control_panel.hub.map_path', storage_path('app/hub/map.json'))),
            'projects' => config('control_panel.projects', []),
            // Start buttons only when the dashboard's own launch action is usable.
            'canLaunch' => $launch !== null && $launch->enabled,
        ]);
    }
}
