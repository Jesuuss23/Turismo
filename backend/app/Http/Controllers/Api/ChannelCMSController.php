<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChannelCMSController extends Controller
{
    private function getTenantId(Request $request): string
    {
        return app()->bound('current_tenant_id')
            ? app('current_tenant_id')
            : ($request->user()?->tenant_id ?? DB::table('tenants')->value('id'));
    }

    /**
     * GET /api/v1/cms/contents
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->getTenantId($request);

        $query = DB::table('cms_contents')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->orderBy('updated_at', 'desc');

        if ($request->filled('type') && $request->type !== 'todos') {
            $query->where('type', $request->type);
        }

        if ($request->filled('status') && $request->status !== 'todos') {
            $query->where('status', $request->status);
        }

        return response()->json(['data' => $query->get()]);
    }

    /**
     * POST /api/v1/cms/contents
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        $user = $request->user();

        $validated = $request->validate([
            'title' => 'required|string|max:200',
            'type' => 'required|string|in:Página,Artículo,Banner,Página legal,Landing',
            'channel' => 'required|string',
            'body' => 'required|string',
            'seo_title' => 'nullable|string|max:60',
            'seo_description' => 'nullable|string|max:160',
            'placement' => 'nullable|string|max:40',
        ]);

        $slug = Str::slug($validated['title']);
        $code = 'CMS-' . str_pad((string)(DB::table('cms_contents')->where('tenant_id', $tenantId)->count() + 1), 2, '0', STR_PAD_LEFT);

        $id = Str::uuid7()->toString();
        DB::table('cms_contents')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'code' => $code,
            'title' => $validated['title'],
            'type' => $validated['type'],
            'channel' => $validated['channel'],
            'slug' => $slug,
            'body' => $validated['body'],
            'seo_title' => $validated['seo_title'] ?? $validated['title'],
            'seo_description' => $validated['seo_description'] ?? null,
            'placement' => $validated['placement'] ?? null,
            'status' => 'borrador',
            'author_id' => $user?->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => "Contenido {$code} creado en borrador.", 'id' => $id], 201);
    }

    /**
     * POST /api/v1/cms/contents/{id}/publish
     */
    public function publish(Request $request, string $id): JsonResponse
    {
        $tenantId = $this->getTenantId($request);

        $content = DB::table('cms_contents')->where('tenant_id', $tenantId)->where('id', $id)->first();
        if (!$content) return response()->json(['message' => 'Contenido no encontrado.'], 404);

        if (empty($content->seo_title)) {
            return response()->json(['message' => 'Complete el título SEO antes de publicar (CMS-BR-04).'], 422);
        }

        DB::table('cms_contents')->where('id', $id)->update([
            'status' => 'publicado',
            'published_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => "Contenido {$content->code} publicado con éxito."]);
    }
}