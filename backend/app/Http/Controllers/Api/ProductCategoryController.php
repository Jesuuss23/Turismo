<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use App\Models\ProductType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductCategoryController extends Controller
{
    /**
     * GET /api/v1/catalog/categories
     */
    public function index(): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $categories = ProductCategory::where('tenant_id', $tenantId)
            ->with('subcategories')
            ->orderBy('name')
            ->get();

        $types = ProductType::where('tenant_id', $tenantId)
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'categories' => $categories,
            'types' => $types,
        ]);
    }
}