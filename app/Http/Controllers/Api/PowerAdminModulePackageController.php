<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ModulePackage;
use App\Services\ModulePackageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PowerAdminModulePackageController extends Controller
{
    public function __construct(
        private readonly ModulePackageService $packages
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'packages' => $this->packages->listPackages(activeOnly: false),
            'available_module_keys' => \App\Models\Hub::PRODUCT_MODULE_KEYS,
            'formula' => 'Package one-time price replaces individual module enable invoices for covered modules. Recurring charges still use per-module catalogue rates for enabled modules.',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'module_keys' => ['required', 'array', 'min:1'],
            'module_keys.*' => ['string'],
        ]);

        try {
            $package = $this->packages->create($validated);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Module package created.',
            'package' => $this->packages->serialize($package),
        ], 201);
    }

    public function update(Request $request, ModulePackage $modulePackage): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'module_keys' => ['sometimes', 'array', 'min:1'],
            'module_keys.*' => ['string'],
        ]);

        try {
            $package = $this->packages->update($modulePackage, $validated);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Module package updated.',
            'package' => $this->packages->serialize($package),
        ]);
    }

    public function destroy(ModulePackage $modulePackage): JsonResponse
    {
        $this->packages->delete($modulePackage);

        return response()->json([
            'message' => 'Module package deleted.',
        ]);
    }
}
