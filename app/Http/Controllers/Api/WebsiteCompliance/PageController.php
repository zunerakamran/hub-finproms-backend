<?php

namespace App\Http\Controllers\Api\WebsiteCompliance;

use App\Http\Controllers\Controller;
use App\Models\WebsiteCompliance\Page;
use App\Services\WebsiteCompliance\WebsiteComplianceGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PageController extends Controller
{
    public function __construct(
        private readonly WebsiteComplianceGate $gate
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->gate->assertModuleEnabled($request->user());

        $query = Page::with('template');

        if ($request->filled('template_id')) {
            $query->where(function ($q) use ($request) {
                $q->where('template_id', $request->query('template_id'))
                    ->orWhereNull('template_id');
            });
        }

        if ($request->filled('template')) {
            $slug = $request->query('template');
            $query->where(function ($q) use ($slug) {
                $q->whereHas('template', function ($t) use ($slug) {
                    $t->where('slug', $slug)->orWhere('name', $slug);
                })->orWhereNull('template_id');
            });
        }

        $pages = $query->orderBy('id')->get();

        if ($pages->isEmpty()) {
            $pages = Page::with('template')->orderBy('id')->get();
        }

        return response()->json($pages);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->gate->assertModuleEnabled($request->user());

        return response()->json(Page::with(['template', 'sections'])->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $this->gate->assertCan($request->user(), 'wc_manage_templates');

        $request->validate([
            'title' => 'required|string',
            'slug' => 'required|string|unique:wc_pages,slug',
            'template_id' => 'nullable|exists:wc_templates,id',
        ]);
        $page = Page::create($request->only('title', 'slug', 'template_id'));

        return response()->json($page->load('template'), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->gate->assertCan($request->user(), 'wc_manage_templates');

        $page = Page::findOrFail($id);
        $request->validate([
            'title' => 'sometimes|required|string',
            'slug' => 'sometimes|required|string|unique:wc_pages,slug,'.$id,
            'template_id' => 'nullable|exists:wc_templates,id',
        ]);
        $page->update($request->only('title', 'slug', 'template_id'));

        return response()->json($page->load('template'));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->gate->assertCan($request->user(), 'wc_manage_templates');
        Page::findOrFail($id)->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
