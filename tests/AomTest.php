<?php

namespace Nuvori\AomClient\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Nuvori\AomClient\Aom;

class AomTest extends TestCase
{
    public function test_without_config_nothing_is_sent(): void
    {
        Http::fake();

        $this->assertFalse(app(Aom::class)->isConfigured());
        $this->assertNull(app(Aom::class)->post('errors', ['errors' => []]));

        Http::assertNothingSent();
    }

    public function test_it_posts_to_aom_with_the_install_token(): void
    {
        config(['aom.install_token' => 'aom_ins_secret']);
        Http::fake(['*' => Http::response(['ok' => true], 201)]);

        $response = app(Aom::class)->post('/feedback', ['message' => 'Hi']);

        $this->assertSame(201, $response->status());
        Http::assertSent(fn (Request $request) => $request->url() === 'https://aom.nuvori.nl/api/v1/feedback'
            && $request->hasHeader('Authorization', 'Bearer aom_ins_secret')
            && $request['message'] === 'Hi');
    }

    public function test_the_config_can_be_published(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'aom-config'])->assertSuccessful();

        $this->assertFileExists(config_path('aom.php'));
        unlink(config_path('aom.php'));
    }
}
