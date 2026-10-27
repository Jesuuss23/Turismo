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
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\IncidentController;
use App\Http\Controllers\Api\RoomingController;
use App\Http\Controllers\Api\MobileOperatorController;
use App\Http\Controllers\Api\VoucherController;
use App\Http\Controllers\Api\SupplierExtranetController;
use App\Http\Controllers\Api\FinanceReceivableController;
use App\Http\Controllers\Api\FinancePaymentController;
use App\Http\Controllers\Api\FinancePayableController;
use App\Http\Controllers\Api\FinanceInvoiceController;
use App\Http\Controllers\Api\ChannelB2BController;
use App\Http\Controllers\Api\ChannelEcomController;
use App\Http\Controllers\Api\ChannelCMSController;
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

        // 05. Módulo de Operaciones
        Route::get('/ops/orders', [OrderController::class, 'index']);
        Route::get('/ops/orders/kpis', [OrderController::class, 'kpis']);
        Route::post('/ops/orders', [OrderController::class, 'store']);
        Route::get('/ops/orders/{code}', [OrderController::class, 'show']);
        Route::patch('/ops/order-tasks/{id}', [OrderController::class, 'toggleTask']);
        Route::post('/ops/orders/{code}/close', [OrderController::class, 'close']);

        Route::get('/ops/resources', [AssignmentController::class, 'getResources']);
        Route::put('/ops/assignments/{id}/resources', [AssignmentController::class, 'assignResources']);
        Route::post('/ops/assignments/{id}/confirm', [AssignmentController::class, 'confirm']);
        Route::post('/ops/assignments/{id}/release', [AssignmentController::class, 'release']);

        Route::get('/ops/incidents', [IncidentController::class, 'index']);
        Route::post('/ops/incidents', [IncidentController::class, 'store']);
        Route::post('/ops/incidents/{id}/resolve', [IncidentController::class, 'resolve']);  

        // Vouchers (PROC-035)
        Route::get('/ops/vouchers', [VoucherController::class, 'index']);
        Route::post('/ops/vouchers', [VoucherController::class, 'store']);
        Route::post('/ops/vouchers/{code}/redeem', [VoucherController::class, 'redeem']);
        Route::post('/ops/vouchers/{code}/void', [VoucherController::class, 'voidVoucher']);
        // 05. Móvil Operador & Offline Sync (PROC-056 / PROC-058)
        Route::get('/mobile/operator/bootstrap', [MobileOperatorController::class, 'bootstrap']);
        Route::post('/mobile/operator/actions', [MobileOperatorController::class, 'handleAction']);

        Route::get('/admin/users', function (\Illuminate\Http\Request $request) {
            $tenantId = app('current_tenant_id');
            $users = \App\Models\User::where('tenant_id', $tenantId)
                ->select('id', 'name', 'email', 'code', 'status', 'created_at')
                ->orderBy('name')
                ->get()
                ->map(function ($u) {
                    $u->role = ($u->code === 'U01') ? 'tenant_admin' : 'sales';
                    return $u;
                });
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

            // 06. Extranet de Proveedores

            Route::prefix('supplier')->group(function () {
                Route::get('/me', [SupplierExtranetController::class, 'me']);
                Route::get('/dashboard', [SupplierExtranetController::class, 'dashboard']);
                Route::get('/requests', [SupplierExtranetController::class, 'requests']);
                Route::post('/requests/{id}/confirm', [SupplierExtranetController::class, 'confirmRequest']);
                Route::post('/requests/{id}/reject', [SupplierExtranetController::class, 'rejectRequest']);
                Route::post('/requests/{id}/alternative', [SupplierExtranetController::class, 'proposeAlternative']);

                Route::get('/rooming-lists', [SupplierExtranetController::class, 'roomingLists']);
                Route::post('/rooming-lists/{id}/acknowledge', [SupplierExtranetController::class, 'acknowledgeRooming']);
                Route::post('/rooming-lists/{id}/confirm', [SupplierExtranetController::class, 'confirmRooming']);
                Route::post('/rooming-rooms/{id}/observations', [SupplierExtranetController::class, 'observeRoom']);

                Route::get('/vouchers', [SupplierExtranetController::class, 'vouchers']);
                Route::post('/vouchers/redeem', [SupplierExtranetController::class, 'redeemVoucher']);

                Route::get('/payables', [SupplierExtranetController::class, 'payables']);
                Route::post('/payables/{id}/invoices', [SupplierExtranetController::class, 'uploadInvoice']);

                Route::get('/incidents', [SupplierExtranetController::class, 'incidents']);
                Route::post('/incidents', [SupplierExtranetController::class, 'reportIncident']);

                Route::get('/contract', [SupplierExtranetController::class, 'contract']);
                Route::post('/rate-requests', [SupplierExtranetController::class, 'requestRateUpdate']);
            });

            // 07. Finanzas, Tesorería y Facturación
            Route::prefix('finance')->group(function () {
                // CxC e Inbound
                Route::get('/receivables', [FinanceReceivableController::class, 'index']);
                Route::get('/receivables/summary', [FinanceReceivableController::class, 'summary']);
                Route::post('/receivables/advances', [FinanceReceivableController::class, 'requestAdvance']);
                Route::post('/receivables/{code}/reminders', [FinanceReceivableController::class, 'sendReminder']);

                // Pagos y Aplicación
                Route::get('/payments', [FinancePaymentController::class, 'index']);
                Route::post('/payments', [FinancePaymentController::class, 'store']);
                Route::post('/payments/{code}/revert', [FinancePaymentController::class, 'revert']);

                // CxP, Costos y Outbound
                Route::get('/payables', [FinancePayableController::class, 'index']);
                Route::post('/costs', [FinancePayableController::class, 'storeCost']);
                Route::post('/payables/{code}/approve', [FinancePayableController::class, 'approve']);
                Route::post('/payables/{code}/schedule', [FinancePayableController::class, 'schedule']);
                Route::post('/payables/{code}/payouts', [FinancePayableController::class, 'payout']);

                // Facturación SUNAT
                Route::get('/invoices', [FinanceInvoiceController::class, 'index']);
                Route::post('/invoices', [FinanceInvoiceController::class, 'store']);
            });

                // 08. Canales y Portales
            Route::prefix('channels')->group(function () {
                // B2B
                Route::get('/b2b/summary', [ChannelB2BController::class, 'summary']);
                Route::get('/b2b/agencies', [ChannelB2BController::class, 'agencies']);
                Route::post('/b2b/agencies', [ChannelB2BController::class, 'storeAgency']);
                Route::post('/b2b/agencies/{id}/block', [ChannelB2BController::class, 'blockAgency']);
                Route::post('/b2b/agencies/{id}/unblock', [ChannelB2BController::class, 'unblockAgency']);
                Route::get('/b2b/price-lists', [ChannelB2BController::class, 'priceLists']);

                // E-commerce Gestión
                Route::get('/ecommerce/summary', [ChannelEcomController::class, 'summary']);
                Route::get('/ecommerce/orders', [ChannelEcomController::class, 'orders']);
                Route::post('/ecommerce/orders/{code}/cancel', [ChannelEcomController::class, 'cancelOrder']);

                // CMS
                Route::get('/cms/contents', [ChannelCMSController::class, 'index']);
                Route::post('/cms/contents', [ChannelCMSController::class, 'store']);
                Route::post('/cms/contents/{id}/publish', [ChannelCMSController::class, 'publish']);
            });
    });
});
