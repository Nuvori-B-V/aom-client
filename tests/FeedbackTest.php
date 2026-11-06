<?php

namespace Nuvori\AomClient\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

class FeedbackTest extends TestCase
{
    private const TOKEN = 'aom_ins_secret';

    private int $aomStatus = 201;

    protected function setUp(): void
    {
        parent::setUp();

        config(['aom.url' => 'https://aom.test', 'aom.install_token' => self::TOKEN]);
        Http::fake(['*' => fn () => Http::response(['id' => 'FB-1', 'status' => 'new'], $this->aomStatus)]);
    }

    public function test_feedback_is_forwarded_to_aom_with_the_reporter_and_screenshot(): void
    {
        $this->actingAs($this->user())
            ->withHeader('User-Agent', 'Firefox/140')
            ->post('/aom/feedback', [
                'message' => 'The save button does nothing',
                'url' => 'https://crm.test/projects/12',
                'selector' => '#save',
                'viewport' => '1440x900',
                'screenshot' => UploadedFile::fake()->image('screenshot.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertExactJson(['id' => 'FB-1', 'status' => 'new']);

        Http::assertSent(function (Request $request) {
            $fields = collect($request->data())->pluck('contents', 'name');

            return $request->url() === 'https://aom.test/api/v1/feedback'
                && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
                && $request->isMultipart()
                && $request->hasFile('screenshot')
                && $fields['message'] === 'The save button does nothing'
                && $fields['url'] === 'https://crm.test/projects/12'
                && $fields['selector'] === '#save'
                && $fields['viewport'] === '1440x900'
                && $fields['user_agent'] === 'Firefox/140'
                && $fields['reporter_name'] === 'Jane Doe'
                && $fields['reporter_email'] === 'jane@example.com';
        });
    }

    public function test_a_user_without_a_name_column_reports_with_their_display_name(): void
    {
        $user = (new User)->forceFill(['id' => 2, 'display_name' => 'Piet Jansen', 'email' => 'piet@example.com']);

        $this->actingAs($user)
            ->postJson('/aom/feedback', ['message' => 'Hi', 'url' => 'https://crm.test'])
            ->assertCreated();

        Http::assertSent(fn (Request $request) => $request['reporter_name'] === 'Piet Jansen');
    }

    public function test_aom_errors_are_passed_on_to_the_widget(): void
    {
        $this->aomStatus = 429;

        $this->actingAs($this->user())
            ->postJson('/aom/feedback', ['message' => 'Hi', 'url' => 'https://crm.test'])
            ->assertStatus(429);
    }

    public function test_guests_cannot_send_feedback(): void
    {
        $this->postJson('/aom/feedback', ['message' => 'Hi', 'url' => 'https://crm.test'])->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_a_message_is_required(): void
    {
        $this->actingAs($this->user())
            ->postJson('/aom/feedback', ['url' => 'https://crm.test'])
            ->assertJsonValidationErrors('message');

        Http::assertNothingSent();
    }

    public function test_without_config_feedback_is_not_sent(): void
    {
        config(['aom.url' => null]);

        $this->actingAs($this->user())
            ->postJson('/aom/feedback', ['message' => 'Hi', 'url' => 'https://crm.test'])
            ->assertStatus(503);

        Http::assertNothingSent();
    }

    public function test_the_widget_loads_only_for_signed_in_users_of_a_configured_app(): void
    {
        $this->assertSame('', trim(view('aom::feedback')->render()));

        $this->actingAs($this->user());
        $this->assertStringContainsString('/aom/feedback.js', view('aom::feedback')->render());
        $this->assertStringContainsString('/aom/feedback.css', view('aom::feedback')->render());

        config(['aom.url' => null]);
        $this->assertSame('', trim(view('aom::feedback')->render()));
    }

    public function test_the_install_token_never_reaches_the_browser(): void
    {
        $this->actingAs($this->user());

        $responses = [
            view('aom::feedback')->render(),
            $this->get('/aom/feedback.js')->assertOk()->assertHeader('Content-Type', 'text/javascript; charset=utf-8')->getContent(),
            $this->get('/aom/feedback.css')->assertOk()->assertHeader('Content-Type', 'text/css; charset=utf-8')->getContent(),
            $this->postJson('/aom/feedback', ['message' => 'Hi', 'url' => 'https://crm.test'])->assertCreated()->getContent(),
        ];

        foreach ($responses as $response) {
            $this->assertStringNotContainsString(self::TOKEN, $response);
        }
    }

    private function user(): User
    {
        return (new User)->forceFill(['id' => 1, 'name' => 'Jane Doe', 'email' => 'jane@example.com']);
    }
}
