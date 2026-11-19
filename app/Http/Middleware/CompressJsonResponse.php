<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Compresses large JSON API responses with gzip when supported by the client.
 *
 * Cuts 50-100 item list payload transfer sizes by 70-85% on PHP dev/CGI servers
 * that do not run behind Nginx/Apache gzip modules.
 */
class CompressJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (
            !$request->is('api/*')
            || $response instanceof BinaryFileResponse
            || $response instanceof StreamedResponse
            || $response->getStatusCode() === 204
            || $response->getStatusCode() === 304
            || $response->headers->has('Content-Encoding')
            || !function_exists('gzencode')
        ) {
            return $response;
        }

        // Skip if PHP's built-in output compression is already active.
        $zlibOff = ini_get('zlib.output_compression');
        if ($zlibOff && strtolower((string)$zlibOff) !== 'off' && $zlibOff !== '0') {
            return $response;
        }

        $acceptEncoding = strtolower((string)$request->header('Accept-Encoding', ''));
        if (!str_contains($acceptEncoding, 'gzip')) {
            return $response;
        }

        $contentType = strtolower((string)$response->headers->get('Content-Type', ''));
        if (!str_contains($contentType, 'application/json') && !str_contains($contentType, '+json')) {
            return $response;
        }

        $content = $response->getContent();
        if (!is_string($content) || strlen($content) < 2048) {
            return $response;
        }

        $compressed = gzencode($content, 5);
        if ($compressed === false) {
            return $response;
        }

        $response->setContent($compressed);
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Content-Length', (string)strlen($compressed));
        $response->headers->set('Vary', 'Accept-Encoding', false);

        return $response;
    }
}
