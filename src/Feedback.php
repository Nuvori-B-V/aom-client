<?php

namespace Nuvori\AomClient;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class Feedback
{
    private const SCRIPTS = ['html-to-image.js', 'feedback.js'];

    public function script(): Response
    {
        $source = collect(self::SCRIPTS)
            ->map(fn (string $file) => file_get_contents(__DIR__.'/../resources/js/'.$file))
            ->implode("\n");

        return response($source, 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'url' => ['required', 'string', 'max:2048'],
            'selector' => ['nullable', 'string', 'max:500'],
            'viewport' => ['nullable', 'string', 'max:50'],
            'screenshot' => ['nullable', 'file', 'mimes:png,jpg,jpeg', 'max:5120'],
        ]);

        try {
            $response = app(Aom::class)->post('feedback', [
                ...collect($data)->except('screenshot')->all(),
                'reporter_name' => $request->user()->name,
                'reporter_email' => $request->user()->email,
                'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            ], array_filter(['screenshot' => $request->file('screenshot')]));
        } catch (ConnectionException) {
            $response = null;
        }

        if ($response === null) {
            return response()->json(['message' => 'AOM is not reachable.'], 503);
        }

        return response()->json($response->json(), $response->status());
    }
}
