<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\DomainImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DomainImageController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'domain_name' => 'required|string|max:255',
        ]);

        $domainName = $request->input('domain_name');
        $domain = Domain::where('name', $domainName)->first();
        if (!$domain) {
            return response()->json(['error' => 'Domain not found'], 404);
        }

        $images = DomainImage::where('domain_id', $domain->id)->get();

        return response()->json([
            'success' => true,
            'images' => $images,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'domain_name' => 'required|string|max:255',
            'files' => 'required|array',
            'files.*' => 'image|mimes:jpeg,png,jpg,gif,svg|max:4048', // Adjust max size as needed
        ]);
        
        $domainName = $request->input('domain_name');
        $domain = Domain::where('name', $domainName)->first();
        if (!$domain) {
            return response()->json(['error' => 'Domain not found'], 404);
        }
        $domainId = $domain->id;

        $files = $request->file('files');
        if (!$files || count($files) === 0) {
            return response()->json(['error' => 'No files uploaded'], 400);
        }

        $domainImages = [];
        foreach ($files as $file) {
            $imagePath = $file->store('images', 'public');
            $domainImage = DomainImage::create([
                'domain_id' => $domainId,
                'name' => $file->getClientOriginalName(),
                'url' => Storage::disk('public')->url($imagePath),
                'thumbnail' => Storage::disk('public')->url($imagePath),
            ]);
            $domainImages[] = $domainImage;
        }

        return response()->json([
            'success' => true,
            'message' => 'Images uploaded successfully',
            'images' => $domainImages,
        ]);
    }
}
