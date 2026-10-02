<?php

namespace App\Providers;

use App\Support\ClinicSettings;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();
        View::composer(['layouts.*', 'store.*', 'orders.*', 'billing.*'], function ($view) {
            $settings = ClinicSettings::all();
            $view->with('clinic', $settings);
        });
    }
}
