<?php

namespace App\Providers;

use App\Contracts\GenerationProvider;
use App\Contracts\GenerationRepository as GenerationRepositoryContract;
use App\Contracts\SpendRepository as SpendRepositoryContract;
use App\Contracts\WalletRepository as WalletRepositoryContract;
use App\Contracts\WebhookRepository as WebhookRepositoryContract;
use App\Repositories\GenerationRepository;
use App\Repositories\SpendRepository;
use App\Repositories\WalletRepository;
use App\Repositories\WebhookRepository;
use App\Services\PixelforgeClient;
use Illuminate\Support\ServiceProvider;

class GenerationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        require base_path('routes/channels.php');
    }

    public function register(): void
    {
        $this->app->bind(GenerationProvider::class, PixelforgeClient::class);
        $this->app->bind(GenerationRepositoryContract::class, GenerationRepository::class);
        $this->app->bind(WalletRepositoryContract::class, WalletRepository::class);
        $this->app->bind(WebhookRepositoryContract::class, WebhookRepository::class);
        $this->app->bind(SpendRepositoryContract::class, SpendRepository::class);
    }
}
