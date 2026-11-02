<?php

namespace Nuvori\AomClient;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class Aom
{
    public function isConfigured(): bool
    {
        return filled(config('aom.url')) && filled(config('aom.install_token'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function post(string $path, array $data): ?Response
    {
        if (! $this->isConfigured()) {
            return null;
        }

        return Http::baseUrl(rtrim(config('aom.url'), '/').'/api/v1')
            ->acceptJson()
            ->withToken(config('aom.install_token'))
            ->timeout(5)
            ->post(ltrim($path, '/'), $data);
    }
}
