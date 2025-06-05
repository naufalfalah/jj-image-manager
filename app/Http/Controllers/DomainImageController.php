<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\DomainImage;
use Aws\S3\S3Client;
use Illuminate\Http\Request;

class DomainImageController extends Controller
{
    protected $s3;

    public function __construct()
    {
        $this->s3 = new S3Client([
            'version' => 'latest',
            'region' => env('AWS_DEFAULT_REGION'),
            'credentials' => [
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
            ],
        ]);
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
            'files.*' => 'image|mimes:jpeg,png,jpg,gif,svg|max:4096', // 4MB max
        ]);

        $files = $request->file('files');
        if (! $files || count($files) === 0) {
            return response()->json(['error' => 'No files uploaded'], 400);
        }

        $domainName = $request->input('domain_name');
        $domain = Domain::where('name', $domainName)->first();
        if (! $domain) {
            return response()->json(['error' => 'Domain not found'], 404);
        }
        $domainId = $domain->id;

        $bucketName = strtolower($domainName);

        $domainImages = [];
        foreach ($files as $file) {
            // Parse key
            $fileName = $file->getClientOriginalName();
            $key = 'images/'.$fileName;

            // Store the image in S3
            try {
                $result = $this->s3->putObject([
                    'Bucket' => $bucketName,
                    'Key' => $key,
                    'Body' => fopen($file->getPathname(), 'r'),
                    // 'ACL'    => 'public-read',
                    'ContentType' => $file->getMimeType(),
                ]);
                $fileUrl = $result['ObjectURL'];
            } catch (\Exception $e) {
                return response()->json(['error' => 'Failed to upload: '.$e->getMessage()], 500);
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
            'files.*' => 'image|mimes:jpeg,png,jpg,gif,svg|max:4096', // 4MB max
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
        $fileName = $domainImage->name ?? $file->getClientOriginalName();
        $key = 'images/'.$fileName;

        // Overwrite the existing image in S3
        try {
            $result = $this->s3->putObject([
                'Bucket' => $bucketName,
                'Key' => $key,
                'Body' => fopen($file->getPathname(), 'r'),
                // 'ACL'    => 'public-read',
                'ContentType' => $file->getMimeType(),
            ]);
            $fileUrl = $result['ObjectURL'];
        } catch (\Exception $e) {
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

        // Parse the URL to get the key
        $parsed = parse_url($domainImage->url);
        $key = isset($parsed['path']) ? ltrim($parsed['path'], '/') : null;
        if ($key && strpos($key, $bucketName.'/') === 0) {
            $key = substr($key, strlen($bucketName) + 1);
        }

        // Delete the image from S3
        try {
            if ($key) {
                $this->s3->deleteObject([
                    'Bucket' => $bucketName,
                    'Key' => $key,
                ]);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to delete image: '.$e->getMessage()], 500);
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
                $this->s3->copyObject([
                    'Bucket' => $targetBucketName,
                    'CopySource' => "{$sourceBucketName}/{$key}",
                    'Key' => "images/{$domainImage->name}",
                ]);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to copy image: '.$e->getMessage()], 500);
        }

        // Create a new DomainImage record for the copied image
        $copiedImage = DomainImage::create([
            'domain_id' => $targetDomain->id,
            'name' => $domainImage->name,
            'url' => "https://{$targetBucketName}.s3.amazonaws.com/images/{$domainImage->name}",
            'thumbnail' => "https://{$targetBucketName}.s3.amazonaws.com/images/{$domainImage->name}",
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Image copied successfully',
            'image' => $copiedImage,
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
                $this->s3->copyObject([
                    'Bucket' => $targetBucketName,
                    'CopySource' => "{$sourceBucketName}/{$key}",
                    'Key' => "images/{$domainImage->name}",
                ]);
                $this->s3->deleteObject([
                    'Bucket' => $sourceBucketName,
                    'Key' => $key,
                ]);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to move image: '.$e->getMessage()], 500);
        }

        // Update the DomainImage record to point to the new domain
        $domainImage->domain_id = $targetDomain->id;
        $domainImage->url = "https://{$targetBucketName}.s3.amazonaws.com/images/{$domainImage->name}";
        $domainImage->thumbnail = "https://{$targetBucketName}.s3.amazonaws.com/images/{$domainImage->name}";
        $domainImage->save();

        return response()->json([
            'success' => true,
            'message' => 'Image moved successfully',
            'image' => $domainImage,
        ]);
    }
}
