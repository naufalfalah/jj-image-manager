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
        if (! $domain) {
            return response()->json(['error' => 'Domain not found'], 404);
        }

        $images = DomainImage::where('domain_id', $domain->id)
            ->latest()
            ->get();

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
            'files.*' => 'image|mimes:jpeg,png,jpg,gif,svg|max:4096', // 4MB max
        ]);

        $domainName = $request->input('domain_name');
        $domain = Domain::where('name', $domainName)->first();
        if (! $domain) {
            return response()->json(['error' => 'Domain not found'], 404);
        }
        $domainId = $domain->id;

        $files = $request->file('files');
        if (! $files || count($files) === 0) {
            return response()->json(['error' => 'No files uploaded'], 400);
        }

        $domainImages = [];
        foreach ($files as $file) {
            // Store the image in S3
            $imagePath = $file->store('images', 's3');
            $fileUrl = Storage::disk('s3')->url($imagePath);
            $fileName = basename($fileUrl);
            
            $domainImage = DomainImage::create([
                'domain_id' => $domainId,
                'name' => $fileName,
                'url' => $fileUrl,
                'thumbnail' => $fileUrl,
            ]);
            $domainImages[] = $domainImage;
        }

        return response()->json([
            'success' => true,
            'message' => 'Images uploaded successfully',
            'images' => $domainImages,
        ]);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'files' => 'required|array',
            'files.*' => 'image|mimes:jpeg,png,jpg,gif,svg|max:4096', // 4MB max
        ]);

        $domainImage = DomainImage::find($id);
        if (!$domainImage) {
            return response()->json([
                'error' => 'Image not found'
            ], 404);
        }

        $files = $request->file('files');
        if (!$files || count($files) === 0) {
            return response()->json(['error' => 'No files uploaded'], 400);
        }

        // Overwrite the existing image in S3
        $file = $files[0];
        $fileName = $domainImage->name ?? $file->getClientOriginalName();
        $imagePath = $file->storeAs('images', $fileName, 's3');
        $fileUrl = Storage::disk('s3')->url($imagePath);
        
        $domainImage->url = $fileUrl;
        $domainImage->thumbnail = $fileUrl;
        $domainImage->save();

        return response()->json([
            'success' => true,
            'message' => 'Image updated successfully',
            'image' => $domainImage,
        ]);
    }

    public function destroy($id)
    {
        $domainImage = DomainImage::find($id);
        if (!$domainImage) {
            return response()->json([
                'error' => 'Image not found'
            ], 404);
        }

        $domainImage->delete();

        // Delete the image from S3
        $imagePath = $domainImage->url;
        if (Storage::disk('s3')->exists($imagePath)) {
            Storage::disk('s3')->delete($imagePath);
        }

        return response()->json([
            'success' => true,
            'message' => 'Image deleted successfully',
        ]);
    }
}
