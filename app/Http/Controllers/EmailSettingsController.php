<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Installer\EnvWriter;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Read/write the mailer settings (the `MAIL_*` keys) in the project `.env`, plus a test-send.
 *
 * Mounted under the authenticated `/v1/admin` group (auth + `content.manage`). Writes go
 * through the framework's atomic {@see EnvWriter}; the password is never read back to the client
 * (only whether one is set) and is only rewritten when a new value is submitted. Note: a running
 * process keeps its boot-time env, so saved changes apply on the next request/restart — which is
 * why the test-send builds a FRESH transport from the just-written `.env` rather than the
 * boot-time mailer config.
 */
final class EmailSettingsController
{
    /** Public field name → env key. The password is handled separately (write-only). */
    private const FIELDS = [
        'mailer' => 'MAIL_MAILER',
        'host' => 'MAIL_HOST',
        'port' => 'MAIL_PORT',
        'username' => 'MAIL_USERNAME',
        'encryption' => 'MAIL_ENCRYPTION',
        'from' => 'MAIL_FROM',
        'from_name' => 'MAIL_FROM_NAME',
        'bcc' => 'MAIL_BCC',
        'logo_url' => 'MAIL_LOGO_URL',
    ];

    public function __construct(private readonly ApplicationContext $context)
    {
    }

    private function env(): EnvWriter
    {
        return new EnvWriter(base_path($this->context, '.env'));
    }

    #[ApiOperation(
        summary: 'Get email settings',
        description: 'Current MAIL_* values from .env. The password is never returned — only '
            . '`password_set`. Requires `content.manage`.',
        tags: ['Lemma Settings'],
    )]
    #[ApiResponse(200, description: 'Current email settings (password omitted).')]
    public function show(): JsonResponse
    {
        $env = $this->env();
        $settings = [];
        foreach (self::FIELDS as $field => $key) {
            $settings[$field] = $env->get($key) ?? '';
        }
        $settings['password_set'] = ($env->get('MAIL_PASSWORD') ?? '') !== '';

        return $this->ok(['settings' => $settings]);
    }

    #[ApiOperation(
        summary: 'Update email settings',
        description: 'Writes the submitted MAIL_* values to .env. MAIL_PASSWORD is only rewritten '
            . 'when a non-empty `password` is supplied. Requires `content.manage`.',
        tags: ['Lemma Settings'],
    )]
    #[ApiResponse(200, description: 'Settings saved.')]
    #[ApiResponse(422, description: 'Invalid field (e.g. malformed from address or port).')]
    public function update(Request $request): JsonResponse
    {
        $body = $this->body($request);

        $from = isset($body['from']) ? trim((string) $body['from']) : null;
        if ($from !== null && $from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            return $this->error('“From” must be a valid email address.', ['from' => 'Enter a valid email.']);
        }
        if (isset($body['port']) && (string) $body['port'] !== '' && !ctype_digit((string) $body['port'])) {
            return $this->error('Port must be a number.', ['port' => 'Enter a number.']);
        }

        $pairs = [];
        foreach (self::FIELDS as $field => $key) {
            if (array_key_exists($field, $body)) {
                $pairs[$key] = trim((string) ($body[$field] ?? ''));
            }
        }
        // Password is write-only: only persist when a new, non-empty value is provided.
        if (isset($body['password']) && (string) $body['password'] !== '') {
            $pairs['MAIL_PASSWORD'] = (string) $body['password'];
        }

        if ($pairs !== []) {
            $this->env()->setMany($pairs);
        }

        return $this->ok(
            ['message' => 'Email settings saved.'],
            'Email settings saved. Changes apply on the next request (a restart may be needed).',
        );
    }

    #[ApiOperation(
        summary: 'Send a test email',
        description: 'Sends a test email to `to` using a transport built from the CURRENT .env '
            . 'MAIL_* values (so it verifies just-saved SMTP settings). Requires `content.manage`.',
        tags: ['Lemma Settings'],
    )]
    #[ApiResponse(200, description: 'Test email sent.')]
    #[ApiResponse(422, description: 'Invalid recipient, missing host, or transport failure.')]
    public function test(Request $request): JsonResponse
    {
        $body = $this->body($request);
        $to = trim((string) ($body['to'] ?? ''));
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return $this->error('Enter a valid recipient email.', ['to' => 'Enter a valid email.']);
        }

        $env = $this->env();
        $host = (string) ($env->get('MAIL_HOST') ?? '');
        if ($host === '') {
            return $this->error('Set the SMTP host before sending a test email.');
        }
        $port = (int) ($env->get('MAIL_PORT') ?? '587');
        $from = (string) ($env->get('MAIL_FROM') ?? '');
        $from = $from !== '' ? $from : 'no-reply@example.com';
        $fromName = (string) ($env->get('MAIL_FROM_NAME') ?? 'Lemma');
        $encryption = strtolower((string) ($env->get('MAIL_ENCRYPTION') ?? ''));

        try {
            // ssl = implicit TLS (port 465); tls = STARTTLS (null lets Symfony auto-negotiate);
            // anything else = no encryption.
            $tls = match ($encryption) {
                'ssl' => true,
                'tls' => null,
                default => false,
            };
            $transport = new EsmtpTransport($host, $port, $tls);
            $username = (string) ($env->get('MAIL_USERNAME') ?? '');
            $password = (string) ($env->get('MAIL_PASSWORD') ?? '');
            if ($username !== '') {
                $transport->setUsername($username);
            }
            if ($password !== '') {
                $transport->setPassword($password);
            }

            $email = (new Email())
                ->from(new Address($from, $fromName))
                ->to($to)
                ->subject('Lemma test email')
                ->text(
                    "This is a test email from your Lemma admin email settings.\n\n"
                    . 'If you received it, your SMTP configuration is working.'
                );

            $transport->send($email);
        } catch (\Throwable $e) {
            return $this->error('Test email failed: ' . $e->getMessage());
        }

        return $this->ok(['message' => "Test email sent to {$to}."], "Test email sent to {$to}.");
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        $decoded = json_decode((string) $request->getContent(), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $data
     */
    private function ok(array $data, string $message = 'OK'): JsonResponse
    {
        return new JsonResponse(['success' => true, 'message' => $message, 'data' => $data]);
    }

    /**
     * @param array<string,string> $errors
     */
    private function error(string $message, array $errors = [], int $status = 422): JsonResponse
    {
        $payload = ['success' => false, 'message' => $message];
        if ($errors !== []) {
            // Match the framework validation envelope ({ errors: { field: [msg] } }).
            $payload['errors'] = array_map(static fn(string $m): array => [$m], $errors);
        }
        return new JsonResponse($payload, $status);
    }
}
