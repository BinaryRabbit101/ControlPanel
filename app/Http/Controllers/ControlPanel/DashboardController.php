<?php

namespace App\Http\Controllers\ControlPanel;

use App\Http\Controllers\Controller;
use App\Models\ActionLog;
use App\Support\ControlPanel\ActionRegistry;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(ActionRegistry $registry): View
    {
        $categoryOrder = ['Windows', 'Mini-PC', 'LAN'];

        $actions = collect($registry->all())
            ->reject(fn ($action) => $action->hidden)
            ->groupBy('category')
            ->sortBy(fn ($group, $category) => array_search($category, $categoryOrder, true));

        return view('control-panel.dashboard', [
            'actions' => $actions,
            'logs' => ActionLog::with('user')->latest()->limit(15)->get(),
            'sites' => config('control_panel.sites', []),
            'devices' => config('control_panel.devices', []),
            'projects' => config('control_panel.projects', []),
            'models' => config('control_panel.models', []),
        ]);
    }
}
