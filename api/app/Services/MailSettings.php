<?php

namespace App\Services;

use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Points Laravel's mailer at the SMTP server saved in Administration ›
 * Integrations › Email. Applied lazily (when the mailer is first used, see
 * AppServiceProvider), so requests that send no mail never read the setting.
 * When email is switched off, the .env mail settings stay in force.
 */
class MailSettings
{
    public function __construct(private Integrations $integrations) {}

    public function apply(bool $force = false): void
    {
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }
            $c = $this->integrations->config('mail');
        } catch (Throwable) {
            return; // no database yet (install, some artisan commands)
        }
        if (! $c['enabled'] && ! $force) {
            return;
        }
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $c['host'],
            'mail.mailers.smtp.port' => (int) $c['port'],
            // Symfony: "smtps" = implicit TLS (port 465); "smtp" upgrades with STARTTLS when the server offers it.
            'mail.mailers.smtp.scheme' => $c['encryption'] === 'ssl' ? 'smtps' : 'smtp',
            'mail.mailers.smtp.username' => $c['username'] ?: null,
            'mail.mailers.smtp.password' => $c['password'],
            'mail.mailers.smtp.timeout' => 15,
            'mail.from.address' => $c['fromAddress'] ?: config('mail.from.address'),
            'mail.from.name' => $c['fromName'] ?: app(Integrations::class)->brand(),
        ]);
        // A mailer built before the change keeps its old transport.
        if (app()->resolved('mail.manager')) {
            app('mail.manager')->purge('smtp');
        }
    }
}
