<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\DomainImage;
use App\Services\S3Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DomainImageController extends Controller
{
    protected $s3Service;

    public function __construct(S3Service $s3Service)
    {
        $this->s3Service = $s3Service;
    }

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
            'files.*' => 'image|mimes:jpeg,png,jpg,gif,svg,webp|max:4096', // 4MB max
        ]);

        $files = $request->file('files');
        if (! $files || count($files) === 0) {
            return response()->json([
                'error' => 'No files uploaded',
            ], 400);
        }

        $domainName = $request->input('domain_name');
        $domain = Domain::where('name', $domainName)->first();
        if (! $domain) {
            return response()->json([
                'error' => 'Domain not found',
            ], 404);
        }
        $domainId = $domain->id;

        $bucketName = strtolower($domainName);

        $domainImages = [];
        foreach ($files as $file) {
            // Parse key
            $fileName = time().'_'.$file->getClientOriginalName();
            $key = 'images/'.$fileName;

            // Store the image in S3
            try {
                $fileUrl = $this->s3Service->uploadFile(
                    $bucketName,
                    $key,
                    $file->getPathname(),
                    $file->getMimeType()
                );
            } catch (\Exception $e) {
                Log::error("Failed to upload image to S3: {$bucketName}/{$key} - Error: ".$e->getMessage());

                return response()->json([
                    'error' => 'Failed to upload: '.$e->getMessage(),
                ], 500);
            }

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
            'files.*' => 'image|mimes:jpeg,png,jpg,gif,svg,webp|max:4096', // 4MB max
        ]);

        $files = $request->file('files');
        if (! $files || count($files) === 0) {
            return response()->json([
                'error' => 'No files uploaded',
            ], 400);
        }

        $domainImage = DomainImage::find($id);
        if (! $domainImage) {
            return response()->json([
                'error' => 'Image not found',
            ], 404);
        }

        $domain = Domain::find($domainImage->domain_id);
        if (! $domain) {
            return response()->json(['error' => 'Domain not found'], 404);
        }
        $bucketName = strtolower($domain->name);

        // Parse key
        $file = $files[0];
        $fileName = $domainImage->name ?? time().'_'.$file->getClientOriginalName();
        $key = 'images/'.$fileName;

        // Overwrite the existing image in S3
        try {
            $fileUrl = $this->s3Service->uploadFile(
                $bucketName,
                $key,
                $file->getPathname(),
                $file->getMimeType()
            );
        } catch (\Exception $e) {
            Log::error("Failed to upload image to S3: {$bucketName}/{$key} - Error: ".$e->getMessage());

            return response()->json(['error' => 'Failed to upload: '.$e->getMessage()], 500);
        }

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
        if (! $domainImage) {
            return response()->json([
                'error' => 'Image not found',
            ], 404);
        }

        $domain = Domain::find($domainImage->domain_id);
        if (! $domain) {
            return response()->json([
                'error' => 'Domain not found',
            ], 404);
        }
        $bucketName = strtolower($domain->name);

        if ($this->s3Service->checkObjectExists($bucketName, 'images/'.$domainImage->name)) {
            // Parse the URL to get the key
            $parsed = parse_url($domainImage->url);
            $key = isset($parsed['path']) ? ltrim($parsed['path'], '/') : null;
            if ($key && strpos($key, $bucketName.'/') === 0) {
                $key = substr($key, strlen($bucketName) + 1);
            }

            // Delete the image from S3
            try {
                if ($key) {
                    $this->s3Service->deleteObject(
                        $bucketName,
                        $key
                    );
                }
            } catch (\Exception $e) {
                Log::error("Failed to delete image from S3: {$bucketName}/{$key} - Error: ".$e->getMessage());

                return response()->json([
                    'error' => 'Failed to delete image: '.$e->getMessage(),
                ], 500);
            }
        }

        $domainImage->delete();

        return response()->json([
            'success' => true,
            'message' => 'Image deleted successfully',
        ]);
    }

    public function copy(Request $request, $id)
    {
        $request->validate([
            'target_domain_name' => 'required|string|max:255',
        ]);

        $domainImage = DomainImage::find($id);
        if (! $domainImage) {
            return response()->json(['error' => 'Image not found'], 404);
        }

        $sourceDomain = Domain::find($domainImage->domain_id);
        if (! $sourceDomain) {
            return response()->json(['error' => 'Source domain not found'], 404);
        }
        $sourceBucketName = strtolower($sourceDomain->name);

        $targetDomainName = $request->input('target_domain_name');
        $targetDomain = Domain::where('name', $targetDomainName)->first();
        if (! $targetDomain) {
            return response()->json(['error' => 'Target domain not found'], 404);
        }
        $targetBucketName = strtolower($targetDomain->name);

        // Parse the URL to get the key
        $parsed = parse_url($domainImage->url);
        $key = isset($parsed['path']) ? ltrim($parsed['path'], '/') : null;
        if ($key && strpos($key, $sourceBucketName.'/') === 0) {
            $key = substr($key, strlen($sourceBucketName) + 1);
        }

        // Copy the image in S3
        try {
            if ($key) {
                $fileUrl = $this->s3Service->copyObject(
                    $sourceBucketName,
                    $key,
                    $targetBucketName,
                    "images/{$domainImage->name}"
                );
            }
        } catch (\Exception $e) {
            Log::error("Failed to copy image in S3: {$sourceBucketName}/{$key} to {$targetBucketName}/images/{$domainImage->name} - Error: ".$e->getMessage());

            return response()->json([
                'error' => 'Failed to copy image: '.$e->getMessage(),
            ], 500);
        }

        // check if image exist on db
        $existingImage = DomainImage::where('domain_id', $targetDomain->id)
            ->where('name', $domainImage->name)
            ->first();

        if ($existingImage) {
            $existingImage->url = $fileUrl;
            $existingImage->thumbnail = $fileUrl;
            $existingImage->save();
        } else {
            $copiedImage = DomainImage::create([
                'domain_id' => $targetDomain->id,
                'name' => $domainImage->name,
                'url' => $fileUrl,
                'thumbnail' => $fileUrl,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Image copied successfully',
            'image' => $existingImage ?? $copiedImage,
            'overwrite' => isset($existingImage) ?? false,
        ]);
    }

    public function move(Request $request, $id)
    {
        $request->validate([
            'target_domain_name' => 'required|string|max:255',
        ]);

        $domainImage = DomainImage::find($id);
        if (! $domainImage) {
            return response()->json(['error' => 'Image not found'], 404);
        }

        $sourceDomain = Domain::find($domainImage->domain_id);
        if (! $sourceDomain) {
            return response()->json(['error' => 'Source domain not found'], 404);
        }
        $sourceBucketName = strtolower($sourceDomain->name);

        $targetDomainName = $request->input('target_domain_name');
        $targetDomain = Domain::where('name', $targetDomainName)->first();
        if (! $targetDomain) {
            return response()->json(['error' => 'Target domain not found'], 404);
        }
        $targetBucketName = strtolower($targetDomain->name);

        // Parse the URL to get the key
        $parsed = parse_url($domainImage->url);
        $key = isset($parsed['path']) ? ltrim($parsed['path'], '/') : null;
        if ($key && strpos($key, $sourceBucketName.'/') === 0) {
            $key = substr($key, strlen($sourceBucketName) + 1);
        }

        // Move the image in S3
        try {
            if ($key) {
                $fileUrl = $this->s3Service->copyObject(
                    $sourceBucketName,
                    $key,
                    $targetBucketName,
                    "images/{$domainImage->name}"
                );
                $this->s3Service->deleteObject(
                    $sourceBucketName,
                    $key
                );
            }
        } catch (\Exception $e) {
            Log::error("Failed to move image in S3: {$sourceBucketName}/{$key} to {$targetBucketName}/images/{$domainImage->name} - Error: ".$e->getMessage());

            return response()->json([
                'error' => 'Failed to move image: '.$e->getMessage(),
            ], 500);
        }

        // check if image exist on db
        $existingImage = DomainImage::where('domain_id', $targetDomain->id)
            ->where('name', $domainImage->name)
            ->first();

        // Update the DomainImage record to point to the new domain
        if ($existingImage) {
            $existingImage->url = $fileUrl;
            $existingImage->thumbnail = $fileUrl;
            $existingImage->save();
            $domainImage->delete();
        } else {
            $domainImage->domain_id = $targetDomain->id;
            $domainImage->url = $fileUrl;
            $domainImage->thumbnail = $fileUrl;
            $domainImage->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Image moved successfully',
            'image' => $domainImage,
            'overwrite' => isset($existingImage) ?? false,
        ]);
    }
}
