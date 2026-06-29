<?php

declare(strict_types=1);

namespace Glueful\Lemma\Importers;

use Glueful\Auth\PasswordHasher;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\ImportExport\Support\ImportContext;
use Glueful\Extensions\ImportExport\Support\ImportOptions;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;
use Glueful\Lemma\Importers\Concerns\RequiresImportersCapability;

/**
 * Bulk-creates users from a CSV — one user per row.
 *
 * Maps columns to user/profile/role fields via the `options.mapping` bag (field => column). Account
 * fields (`username`, `email`, `password`, `status`) go through {@see UserRepository::create()} (the
 * password is hashed here; a missing one is randomly generated so the account exists but the user
 * must reset), profile fields (`first_name`, `last_name`) through `updateProfile()`, and `roles`
 * (a comma-separated list of slugs) are assigned via Aegis. `username` + `email` are required and
 * checked for uniqueness; `dry_run` validates (format + duplicates) without writing. Create-only.
 */
final class CsvUserImporter extends AbstractCsvImporter
{
    use RequiresImportersCapability;

    /** Mappable fields (account + profile + roles). */
    public const FIELDS = ['username', 'email', 'password', 'status', 'first_name', 'last_name', 'roles'];
    private const REQUIRED = ['username', 'email'];

    public function __construct(
        ApplicationContext $context,
        Connection $db,
        private readonly UserRepository $users,
        private readonly AegisPermissionProvider $aegis,
        private readonly CapabilityRegistry $capabilities,
    ) {
        parent::__construct($context, $db);
    }

    public function key(): string
    {
        return 'csv.users';
    }

    public function label(): string
    {
        return 'Users (CSV)';
    }

    protected function assertEnabled(): void
    {
        $this->assertImportersEnabled($this->capabilities);
    }

    protected function validatePlan(array $header, ImportOptions $options): void
    {
        $this->assertEnabled();
        $mapping = $this->mappingOption($options->options);
        if ($mapping === []) {
            throw new \InvalidArgumentException('A column mapping is required.');
        }
        foreach (self::REQUIRED as $field) {
            if (!isset($mapping[$field])) {
                throw new \InvalidArgumentException(sprintf('The "%s" field must be mapped to a column.', $field));
            }
        }
        foreach ($mapping as $field => $column) {
            if (in_array($field, self::FIELDS, true) && !in_array($column, $header, true)) {
                throw new \InvalidArgumentException(
                    sprintf('CSV has no column "%s" (mapped to "%s").', $column, $field),
                );
            }
        }
    }

    protected function planMetadata(ImportOptions $options): array
    {
        return ['format' => 'csv', 'target' => 'users'];
    }

    protected function prepare(ImportContext $context): array
    {
        return ['mapping' => $this->mappingOption($context->options)];
    }

    protected function importRow(array $row, array $prepared, ImportContext $context): void
    {
        $mapping = $prepared['mapping'];
        $username = $this->value($row, $mapping, 'username');
        $email = $this->value($row, $mapping, 'email');

        if ($username === '' || $email === '') {
            throw new \InvalidArgumentException('Both username and email are required.');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException(sprintf('Invalid email "%s".', $email));
        }
        if ($this->users->emailExists($email)) {
            throw new \InvalidArgumentException(sprintf('A user with email "%s" already exists.', $email));
        }
        if ($this->users->usernameExists($username)) {
            throw new \InvalidArgumentException(sprintf('A user with username "%s" already exists.', $username));
        }

        if ($context->mode !== 'commit') {
            return;
        }

        $password = $this->value($row, $mapping, 'password');
        $status = $this->value($row, $mapping, 'status');
        $uuid = $this->users->create([
            'username' => $username,
            'email' => $email,
            // create() does not hash; a missing password becomes a random one (user must reset).
            'password' => (new PasswordHasher())->hash($password !== '' ? $password : bin2hex(random_bytes(8))),
            'status' => $status !== '' ? $status : 'active',
            'email_verified_at' => date('Y-m-d H:i:s'),
        ]);

        $profile = array_filter([
            'first_name' => $this->value($row, $mapping, 'first_name'),
            'last_name' => $this->value($row, $mapping, 'last_name'),
        ], static fn(string $v): bool => $v !== '');
        if ($profile !== []) {
            $this->users->updateProfile($uuid, $profile);
        }

        foreach ($this->roles($row, $mapping) as $slug) {
            $this->aegis->assignRole($uuid, $slug);
        }
    }

    protected function errorCode(): string
    {
        return 'csv_user_import_failed';
    }

    /**
     * @param array<string,string> $row
     * @param array<string,string> $mapping
     */
    private function value(array $row, array $mapping, string $field): string
    {
        $column = $mapping[$field] ?? null;
        return $column !== null ? trim((string) ($row[$column] ?? '')) : '';
    }

    /**
     * @param array<string,string> $row
     * @param array<string,string> $mapping
     * @return list<string>
     */
    private function roles(array $row, array $mapping): array
    {
        $raw = $this->value($row, $mapping, 'roles');
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(
            array_map(static fn(string $s): string => trim($s), explode(',', $raw)),
            static fn(string $s): bool => $s !== '',
        ));
    }
}
