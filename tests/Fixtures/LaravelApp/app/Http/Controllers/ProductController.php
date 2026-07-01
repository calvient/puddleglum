<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Models\Product;
use Calvient\Puddleglum\Attributes\GlumRequest;
use Calvient\Puddleglum\Attributes\GlumResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    #[GlumRequest(['search?' => 'string', 'include_archived?' => 'boolean'])]
    #[GlumResponse('PaginatedResponse<Product>')]
    public function index(Request $request)
    {
    }

    #[GlumResponse(['product' => 'Product', 'message' => 'string'])]
    public function store(StoreProductRequest $request)
    {
    }

    #[GlumResponse(['product' => 'Product', 'message' => 'string'])]
    public function show(Product $product)
    {
    }

    public function destroy(Product $product)
    {
    }
}
