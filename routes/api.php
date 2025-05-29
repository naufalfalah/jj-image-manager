<?php

use App\Models\Domain;
use App\Models\DomainImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/domains', function () {
    $domains = Domain::all();
    foreach ($domains as $domain) {
        $domain->image_count = $domain->images()->count();
    }
    return response()->json([
        'domains' => $domains,
    ]);
});

Route::post('/domains', function (Request $request) {
    $domainName = $request->input('name');
    if (!$domainName) {
        return response()->json(['error' => 'Domain name is required'], 400);
    }
    $domain = new Domain();
    $domain->name = $domainName;
    $domain->status = 'active';
    $domain->save();
    return response()->json(['message' => 'Domain added successfully', 'domain' => $domain]);
});

Route::get('/domains/images', function (Request $request) {
    $domainName = $request->input('domain_name');
    $domain = Domain::where('name', $domainName)->first();
    if (!$domain) {
        return response()->json(['error' => 'Domain not found'], 404);
    }
    
    $images = DomainImage::where('domain_id', $domain->id)->get();
    
    return response()->json([
        'images' => $images,
    ]);
});

Route::post('/domains/images', function (Request $request) {
    $domainName = $request->input('domain_name');
    $domain = Domain::where('name', $domainName)->first();
    if (!$domain) {
        return response()->json(['error' => 'Domain not found'], 404);
    }
    $domainId = $domain->id;
    
    // upload image files to S3 or any other storage
    

    $imagePath = $request->file('image')->store('images', 's3');

    return response()->json(['url' => Storage::disk('s3')->url($imagePath)]);

    $files = $request->file('files');
    if (!$files || count($files) === 0) {
        return response()->json(['error' => 'No files uploaded'], 400);
    }

    if (!$domainId || !$imageName || !$imageUrl || !$thumbnailUrl) {
        return response()->json(['error' => 'All fields are required'], 400);
    }

    $image = new DomainImage();
    $image->domain_id = $domainId;
    $image->name = $imageName;
    $image->url = $imageUrl;
    $image->thumbnail = $thumbnailUrl;
    $image->save();

    return response()->json(['message' => 'Domain image added successfully', 'image' => $image]);
});
