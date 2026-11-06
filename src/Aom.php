<?php

namespace Nuvori\AomClient;

use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

class Aom
{
    private const API = 'https://aom.nuvori.nl/api/v1';

    public function isConfigured(): bool
    {
        return filled(config('aom.install_token'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, UploadedFile>  $files
     */
    public function post(string $path, array $data, array $files = []): ?Response
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $request = Http::baseUrl(self::API)
            ->acceptJson()
            ->withToken(config('aom.install_token'))
            ->timeout(5);

        foreach ($files as $name => $file) {
            $request->attach($name, $file->get(), $file->getClientOriginalName());
        }

        return $request->post(ltrim($path, '/'), $data);
    }
}
