<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use Illuminate\Http\Request;

class DomainController extends Controller
{
    public function index()
    {
        $domains = Domain::all();
        foreach ($domains as $domain) {
            $domain->image_count = $domain->images()->count();
        }

        return response()->json([
            'success' => true,
            'domains' => $domains,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:domains,name',
        ]);

        $domainName = $request->input('name');
        $domain = new Domain;
        $domain->name = $domainName;
        $domain->status = 'active';
        $domain->save();

        return response()->json([
            'success' => true,
            'message' => 'Domain added successfully',
            'domain' => $domain,
        ]);
    }

    public function destroy($id)
    {
        $domain = Domain::find($id);
        if (!$domain) {
            return response()->json([
                'error' => 'Domain not found'
            ], 404);
        }

        $domain->delete();

        return response()->json([
            'success' => true,
            'message' => 'Domain deleted successfully',
        ]);
    }
}
