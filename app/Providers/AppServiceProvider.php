<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;

class SystemSettingServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register the settings as a singleton
        $this->app->singleton('system.settings', function ($app) {
            return Cache::remember('system_settings', 3600, function () {
                return SystemSetting::first();
            });
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Clear cache when settings are modified
        $this->registerCacheInvalidation();
        
        // Share settings with all views
        $this->shareSettingsWithViews();
    }

    /**
     * Register cache invalidation for system settings
     */
    protected function registerCacheInvalidation(): void
    {
        $clearCache = function ($settings) {
            Cache::forget('system_settings');
            // Also clear the singleton instance
            if ($this->app->bound('system.settings')) {
                $this->app->forgetInstance('system.settings');
            }
        };

        SystemSetting::created($clearCache);
        SystemSetting::updated($clearCache);
        SystemSetting::deleted($clearCache);
    }

    /**
     * Share system settings with all views
     */
    protected function shareSettingsWithViews(): void
    {
        try {
            View::composer('*', function ($view) {
                $settings = app('system.settings');
                $view->with('systemSettings', $settings);
            });
        } catch (\Exception $e) {
            // Handle gracefully if database doesn't exist or table not created yet
            View::composer('*', function ($view) {
                $view->with('systemSettings', null);
            });
        }
    }
}