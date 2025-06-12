<?php

if (! function_exists('formatBucketName')) {
    /**
     * Replace dots with dashes and convert to lowercase.
     */
    function formatBucketName(string $string): string
    {
        return strtolower(str_replace('.', '-', $string));
    }
}

if (! function_exists('getFileUrl')) {

    function getFileUrl(string $bucketName, string $imageName): string
    {
        return "https://{$bucketName}.s3.amazonaws.com/images/{$imageName}";
    }
}
