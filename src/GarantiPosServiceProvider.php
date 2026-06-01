<?php

namespace Developertugrul\GarantiPos;

use Illuminate\Support\ServiceProvider;
use Developertugrul\GarantiPos\Services\GarantiPosService;

class GarantiPosServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/garanti-pos.php' => config_path('garanti-pos.php'),
        ], 'config');
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/garanti-pos.php', 'garanti-pos'
        );

        $this->app->singleton('garanti-pos', function ($app) {
            return new GarantiPosService($app['config']->get('garanti-pos'));
        });
    }
}
