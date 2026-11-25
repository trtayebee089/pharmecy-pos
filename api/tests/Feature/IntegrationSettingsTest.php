<?php

use App\Models\Customer;
use App\Models\Setting;
use App\Models\SmsMessage;
use App\Services\Integrations;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

const INTEGRATIONS = '/api/pos/v1/admin/integrations';

function smsSettings(array $over = []): array
{
    return $over + [
        'enabled' => true, 'provider' => 'bulksmsbd', 'method' => 'GET', 'url' => 'http://bulksmsbd.net/api/smsapi', 'bodyType' => 'query',
        'params' => [['key' => 'api_key', 'value' => '{api_key}'], ['key' => 'number', 'value' => '{to}'], ['key' => 'senderid', 'value' => '{sender}'], ['key' => 'message', 'value' => '{message}']],
        'headers' => [], 'senderId' => 'REDPHARMA', 'phoneFormat' => '8801', 'successContains' => '202', 'apiKey' => 'sms-key-123',
    ];
}

it('keeps secrets encrypted and write-only', function () {
    $admin = posUser('Admin');
    $this->actingAs($admin)->putJson(INTEGRATIONS.'/mail', [
        'enabled' => true, 'host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'tls', 'username' => 'shop@example.test',
        'password' => 'smtp-secret-1', 'fromAddress' => 'shop@example.test', 'fromName' => 'Red Pharma',
    ])->assertOk()->assertJsonPath('passwordSet', true)->assertJsonMissingPath('password');

    $stored = json_encode(Setting::where('key', 'integration.mail')->value('value'));
    expect($stored)->not->toContain('smtp-secret-1')
        ->and(app(Integrations::class)->config('mail')['password'])->toBe('smtp-secret-1');

    // Saving without a password keeps it; `clear` removes it.
    $this->actingAs($admin)->putJson(INTEGRATIONS.'/mail', ['enabled' => true, 'host' => 'smtp2.example.test', 'port' => 465, 'encryption' => 'ssl', 'fromAddress' => 'shop@example.test'])
        ->assertOk()->assertJsonPath('passwordSet', true)->assertJsonPath('host', 'smtp2.example.test');
    $this->actingAs($admin)->putJson(INTEGRATIONS.'/mail', ['enabled' => false, 'port' => 465, 'encryption' => 'ssl', 'clear' => ['password']])->assertJsonPath('passwordSet', false);

    $this->actingAs($admin)->getJson(INTEGRATIONS)->assertOk()->assertJsonPath('mail.host', 'smtp2.example.test')
        ->assertJsonPath('smsPresets.bulksmsbd.label', 'BulkSMSBD')->assertJsonStructure(['urls' => ['productFeedXml', 'steadfastWebhook']]);
    expect(json_encode($this->actingAs($admin)->getJson(INTEGRATIONS)->json()))->not->toContain('smtp-secret-1');
});

it('is for settings managers only and validates each group', function () {
    $this->actingAs(posUser('Cashier'))->getJson(INTEGRATIONS)->assertForbidden();
    $this->actingAs(posUser('Admin'))->putJson(INTEGRATIONS.'/mail', ['enabled' => true, 'port' => 587, 'encryption' => 'tls'])->assertJsonValidationErrors(['host', 'fromAddress']);
    $this->actingAs(posUser('Admin'))->putJson(INTEGRATIONS.'/tracking', ['gtmId' => 'nope', 'facebookPixelId' => 'abc'])->assertJsonValidationErrors(['gtmId', 'facebookPixelId']);
    $this->actingAs(posUser('Admin'))->putJson(INTEGRATIONS.'/nothing', [])->assertNotFound();
});

it('applies the saved SMTP server when mail is sent', function () {
    app(Integrations::class)->save('mail', ['enabled' => true, 'host' => 'smtp.example.test', 'port' => 465, 'encryption' => 'ssl', 'username' => 'u', 'password' => 'p', 'fromAddress' => 'shop@example.test', 'fromName' => 'Red Pharma']);
    app('mail.manager'); // first use applies the settings
    expect(config('mail.mailers.smtp.host'))->toBe('smtp.example.test')->and(config('mail.mailers.smtp.scheme'))->toBe('smtps')
        ->and(config('mail.from.address'))->toBe('shop@example.test')->and(config('mail.mailers.smtp.password'))->toBe('p');
});

it('sends a test email and reports a failure without a stack trace', function () {
    Mail::fake();
    $admin = posUser('Admin');
    $this->actingAs($admin)->postJson(INTEGRATIONS.'/mail/test', ['to' => 'me@example.test'])->assertJsonValidationErrors('to'); // email off
    app(Integrations::class)->save('mail', ['enabled' => true, 'host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'tls', 'fromAddress' => 'shop@example.test']);
    $this->actingAs($admin)->postJson(INTEGRATIONS.'/mail/test', ['to' => 'me@example.test'])->assertOk()->assertJsonPath('ok', true);
});

it('sends SMS through any configured HTTP gateway and logs every attempt', function () {
    Http::fake(['bulksmsbd.net/*' => Http::response('{"response_code":202,"success_message":"SMS Submitted Successfully"}')]);
    $this->actingAs(posUser('Admin'))->putJson(INTEGRATIONS.'/sms', smsSettings())->assertOk()->assertJsonPath('apiKeySet', true);

    $this->actingAs(posUser('Admin'))->postJson(INTEGRATIONS.'/sms/test', ['to' => '01712-345678', 'message' => 'হ্যালো test'])->assertOk()->assertJsonPath('ok', true);
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'GET' && $r['number'] === '8801712345678' && $r['api_key'] === 'sms-key-123'
        && $r['senderid'] === 'REDPHARMA' && $r['message'] === 'হ্যালো test');
    expect(SmsMessage::where('status', 'sent')->value('to'))->toBe('01712345678');
});

it('posts JSON with headers and treats a missing success marker as a failure', function () {
    Http::fake(['sms.example.test/*' => Http::response('{"status":"error","reason":"low balance"}')]);
    app(Integrations::class)->save('sms', smsSettings([
        'method' => 'POST', 'url' => 'https://sms.example.test/v2/send', 'bodyType' => 'json', 'phoneFormat' => '+8801', 'successContains' => '"status":"ok"',
        'params' => [['key' => 'to', 'value' => '{to}'], ['key' => 'text', 'value' => '{message}']],
        'headers' => [['key' => 'Authorization', 'value' => 'Bearer {api_key}']],
    ]));

    $this->actingAs(posUser('Admin'))->postJson(INTEGRATIONS.'/sms/test', ['to' => '01712345678'])->assertStatus(422)->assertJsonPath('ok', false);
    Http::assertSent(fn (HttpRequest $r) => $r->isJson() && $r['to'] === '+8801712345678' && $r->hasHeader('Authorization', 'Bearer sms-key-123'));
    expect(SmsMessage::first())->status->toBe('failed')->response->toContain('low balance');
});

it('sends due reminders by SMS once a gateway is set', function () {
    Http::fake(['bulksmsbd.net/*' => Http::response('{"response_code":202}')]);
    $c = Customer::create(['code' => 'C-0901', 'name' => 'Due Customer', 'phone' => '01712345678', 'phone_normalized' => '01712345678']);
    DB::table('party_ledger_entries')->insert(['party_type' => 'customer', 'party_id' => $c->id, 'debit' => 250, 'credit' => 0, 'entry_date' => now()->toDateString(), 'type' => 'opening', 'created_at' => now()]);

    $this->actingAs(posUser('Cashier'))->postJson("/api/pos/v1/customers/{$c->id}/due-reminders", [], ['Idempotency-Key' => 'r1'])->assertStatus(503);
    app(Integrations::class)->save('sms', smsSettings());
    $this->actingAs(posUser('Cashier'))->postJson("/api/pos/v1/customers/{$c->id}/due-reminders", [], ['Idempotency-Key' => 'r2'])->assertOk()->assertJsonPath('queued', true);
    expect(SmsMessage::where('purpose', 'due_reminder')->value('message'))->toContain('Tk 250.00');
});
