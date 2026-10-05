<?php

namespace Extensions\Servers\Plesk\Providers;

use Illuminate\Support\ServiceProvider;

class PleskServiceProvider extends ServiceProvider
{
    /**
     * Register the subscription-created email so admins can edit it under Email Templates.
     */
    public function register(): void
    {
        $events = config('email_events', []);

        if (array_key_exists('server.plesk.created', $events)) {
            return;
        }

        $events['server.plesk.created'] = [
            'name' => 'Plesk subscription created',
            'group' => 'Servers',
            'description' => 'Sent when a Plesk hosting subscription is provisioned for an order.',
            'subject' => 'Your hosting account is ready',
            'body' => <<<'BODY'
Your Plesk hosting for {{domain}} has been created and is ready to use.
**Plesk login:**
URL: {{panel_url}}
Username: {{username}}
Password: {{password}}
**FTP / SSH:**
Login: {{ftp_login}}
Password: {{ftp_password}}
You can also log in to Plesk with one click from your order page.
BODY,
            'button_text' => 'Manage hosting',
            'placeholders' => [
                'domain' => 'Subscription domain',
                'panel_url' => 'Plesk URL',
                'username' => 'Plesk customer username',
                'password' => 'Plesk customer password (only for new customers)',
                'ftp_login' => 'FTP / system user login',
                'ftp_password' => 'FTP / system user password',
            ],
        ];

        config(['email_events' => $events]);
    }
}
