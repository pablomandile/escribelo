<?php

namespace App\Providers;

use App\Services\Summarizer\GroqSummarizer;
use App\Services\Summarizer\SummarizerInterface;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SummarizerInterface::class, GroqSummarizer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
