<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\SetPostgresTenant;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\OpportunityController;
use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\AfterSalesController;
use App\Http\Controllers\Api\SalesDashboardController;
use App\Http\Controllers\Api\DestinationController;
use App\Http\Controllers\Api\ProductCategoryController;
use App\Http\Controllers\Api\ProviderController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PricingController;
use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\Api\QuotePricingPreviewController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\ReservationController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()]);
});

Route::prefix('v1')->group(function () {
    // Rutas públicas de autenticación
    Route::post('/auth/login', [AuthController::class, 'login']);

    // Rutas protegidas con Sanctum + RLS Tenant
    Route::middleware(['auth:sanctum', SetPostgresTenant::class])->group(function () {


        // 03. Módulo Cotizaciones y Pricing
        Route::post('/quotes/pricing/preview', [QuotePricingPreviewController::class, 'preview']);
        Route::get('/catalog/products/{id}/rate-suggestion', [QuotePricingPreviewController::class, 'suggestRate']);
        
        Route::get('/quotes', [QuoteController::class, 'index']);
        Route::get('/quotes/kpis', [QuoteController::class, 'kpis']);
        Route::post('/quotes', [QuoteController::class, 'store']);
        Route::get('/quotes/{code}', [QuoteController::class, 'show']);
        Route::post('/quotes/{code}/send', [QuoteController::class, 'send']);
        Route::post('/quotes/{code}/internal-approval-requests', [QuoteController::class, 'requestInternalApproval']);
        Route::post('/quotes/{code}/versions/{v}/internal-approval', [QuoteController::class, 'decideInternalApproval']);
        Route::post('/quotes/{code}/approve', [QuoteController::class, 'approve']);
        Route::post('/quotes/{code}/reject', [QuoteController::class, 'reject']);
        Route::post('/quotes/{code}/versions', [QuoteController::class, 'newVersion']);

        // 04. Módulo de Reservas
        Route::post('/quotes/{code}/convert', [ReservationController::class, 'convertFromQuote']);
        Route::get('/reservations', [ReservationController::class, 'index']);
        Route::get('/reservations/kpis', [ReservationController::class, 'kpis']);
        Route::get('/reservations/{code}', [ReservationController::class, 'show']);
        Route::put('/reservas/{code}/passengers/{pid}', [ReservationController::class, 'updatePassenger']);
        Route::post('/reservas/{code}/services/{sid}/confirm', [ReservationController::class, 'confirmService']);
        Route::post('/reservas/{code}/receivables/{rid}/pay', [ReservationController::class, 'payReceivable']);

        Route::post('/reservations', [ReservationController::class, 'storeDirect']);
        Route::post('/reservations/{code}/start-operation', [ReservationController::class, 'startOperation']);
        Route::post('/reservations/{code}/complete', [ReservationController::class, 'completeTrip']);
        Route::post('/reservations/{code}/cancel', [ReservationController::class, 'cancelReservation']);
    
    Route::get('/admin/users', function (\Illuminate\Http\Request $request) {
            $tenantId = app('current_tenant_id');
            $users = \App\Models\User::where('tenant_id', $tenantId)
                ->select('id', 'name', 'email', 'code', 'role', 'status', 'created_at')
                ->orderBy('name')
                ->get();
            return response()->json(['data' => $users]);
        });
        Route::get('/dashboard/sales', [SalesDashboardController::class, 'index']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/me/access', [AuthController::class, 'access']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);


        
        Route::prefix('crm')->group(function () {
            // Submódulo 1: Leads
            Route::get('/leads', [LeadController::class, 'index']);
            Route::post('/leads', [LeadController::class, 'store']);
            Route::post('/leads/{code}/take', [LeadController::class, 'take']);
            Route::get('/leads/duplicates', [LeadController::class, 'checkDuplicates']);
            Route::get('/leads', [LeadController::class, 'index']);
            Route::post('/leads', [LeadController::class, 'store']);
            Route::get('/leads/{code}', [LeadController::class, 'show']);
            Route::post('/leads/{code}/take', [LeadController::class, 'take']);
            Route::post('/leads/{code}/qualify', [LeadController::class, 'qualify']);
            Route::post('/leads/{code}/convert', [LeadController::class, 'convert']);

            // Submódulo 2: oportunidades
            Route::get('/opportunities', [OpportunityController::class, 'index']);
            Route::post('/opportunities', [OpportunityController::class, 'store']);
            Route::get('/opportunities/{code}', [OpportunityController::class, 'show']);
            Route::post('/opportunities/{code}/stage', [OpportunityController::class, 'changeStage']);

            // Submódulo 3: actividades
            Route::get('/activities', [ActivityController::class, 'index']);
            Route::post('/activities', [ActivityController::class, 'store']);
            Route::post('/activities/{code}/complete', [ActivityController::class, 'complete']);
            Route::post('/activities/{code}/reschedule', [ActivityController::class, 'reschedule']);

            // Submódulo 4: clientes 360
            Route::get('/clients', [ClientController::class, 'index']);
            Route::post('/clients', [ClientController::class, 'store']);
            Route::get('/clients/{code}', [ClientController::class, 'show']);
            Route::post('/clients/{code}/contacts', [ClientController::class, 'addContact']);
                

            // Submódulo 5: Postventa y Fidelización
            Route::get('/after-sales/dashboard', [AfterSalesController::class, 'dashboard']);
            Route::post('/surveys', [AfterSalesController::class, 'storeSurvey']);
            Route::post('/cases/{code}/resolve', [AfterSalesController::class, 'resolveCase']);
        });
        
        Route::prefix('catalog')->group(function () {
                Route::get('/destinations', [DestinationController::class, 'index']);
                Route::post('/destinations', [DestinationController::class, 'store']);
                Route::get('/categories', [ProductCategoryController::class, 'index']);

                Route::get('/providers', [ProviderController::class, 'index']);
                Route::post('/providers', [ProviderController::class, 'store']);
                Route::post('/providers/{id}/activate', [ProviderController::class, 'activate']);

                Route::get('/services', [ServiceController::class, 'index']);
                Route::post('/services', [ServiceController::class, 'store']);

                Route::get('/products', [ProductController::class, 'index']);
                Route::post('/products', [ProductController::class, 'store']);
                Route::get('/products/{ref}', [ProductController::class, 'show']);
                Route::get('/products/{ref}/activation-checklist', [ProductController::class, 'checklist']);
                Route::post('/products/{ref}/activate', [ProductController::class, 'activate']);

                Route::get('/rates', [PricingController::class, 'getRates']);
                Route::post('/rates', [PricingController::class, 'storeRate']);

                Route::get('/seasons', [PricingController::class, 'getSeasons']);
                Route::post('/seasons', [PricingController::class, 'storeSeason']);

                Route::get('/price-rules', [PricingController::class, 'getPriceRules']);
                Route::post('/pricing/simulate', [PricingController::class, 'simulate']);

                Route::get('/departures', [AvailabilityController::class, 'getDepartures']);
                Route::post('/departures', [AvailabilityController::class, 'storeDeparture']);

                Route::get('/holds', [AvailabilityController::class, 'getHolds']);
                Route::post('/holds', [AvailabilityController::class, 'storeHold']);
                Route::post('/holds/{id}/confirm', [AvailabilityController::class, 'confirmHold']);
                Route::post('/holds/{id}/release', [AvailabilityController::class, 'releaseHold']);
            });
    });
});
