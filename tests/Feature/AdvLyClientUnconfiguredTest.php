<?php

use App\Services\Notifications\AdvLyClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function resolveUnconfiguredAdvLyClient(): AdvLyClient
{
    config()->set('services.advly.token', null);
    config()->set('services.advly.base_url', null);
    app()->forgetInstance(AdvLyClient::class);

    return app(AdvLyClient::class);
}

test('the AdvLy client resolves when the API token is not configured', function () {
    expect(resolveUnconfiguredAdvLyClient())->toBeInstanceOf(AdvLyClient::class);
});

test('sending a WhatsApp message without a configured token is a logged no-op', function () {
    Http::preventStrayRequests();
    Log::spy();

    $client = resolveUnconfiguredAdvLyClient();

    expect($client->sendMessage('+218912345678', 'booking confirmed'))->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'API token is not configured'))
        ->once();
});

test('sending WhatsApp media without a configured token is a logged no-op', function () {
    Http::preventStrayRequests();
    Log::spy();

    $client = resolveUnconfiguredAdvLyClient();

    expect($client->sendMedia('+218912345678', 'invoice', 'https://example.test/invoice.pdf'))->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'API token is not configured'))
        ->once();
});

test('the AdvLy client still sends messages when a token is configured', function () {
    config()->set('services.advly.token', 'configured-token');
    config()->set('services.advly.base_url', 'https://adv.ly/api/v1');
    app()->forgetInstance(AdvLyClient::class);

    Http::fake([
        'adv.ly/*' => Http::response(['status' => true, 'message' => 'ok']),
    ]);

    expect(app(AdvLyClient::class)->sendMessage('+218912345678', 'booking confirmed'))->toBeTrue();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://adv.ly/api/v1/send-message'
        && $request->hasHeader('Authorization', 'Bearer configured-token'));
});
