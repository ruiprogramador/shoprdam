<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        // dd("Vendor Dashboard accessed");
        return Inertia::render('Vendor/Dashboard/Index');
    }
}
