<?php

declare(strict_types=1);

namespace App\Tests\Api;

use PHPUnit\Framework\Attributes\Test;

final class AccountApiTest extends ApiTestCase
{
    private const string NAME = 'account-user';

    #[Test]
    public function itCreatesAndVerifiesAnAccountWithoutRevealingWhich(): void
    {
        $client = static::createClient();
        $payload = ['name' => self::NAME, 'password' => 'correct-horse'];

        $client->request('POST', '/api/accounts', self::AUTH + ['json' => $payload]);
        self::assertResponseStatusCodeSame(204);

        $client->request('POST', '/api/accounts', self::AUTH + ['json' => $payload]);
        self::assertResponseStatusCodeSame(204);
    }

    #[Test]
    public function itRejectsAWrongPasswordForAnExistingName(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/accounts', self::AUTH + [
            'json' => ['name' => self::NAME, 'password' => 'correct-horse'],
        ]);
        self::assertResponseStatusCodeSame(204);

        $client->request('POST', '/api/accounts', self::AUTH + [
            'json' => ['name' => self::NAME, 'password' => 'wrong-password'],
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'account.name_taken']);
    }

    #[Test]
    public function itRejectsAPasswordOutsideTheAllowedLength(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/accounts', self::AUTH + [
            'json' => ['name' => self::NAME, 'password' => 'abc'],
        ]);
        self::assertResponseStatusCodeSame(422);

        $client->request('POST', '/api/accounts', self::AUTH + [
            'json' => ['name' => self::NAME, 'password' => \str_repeat('a', 65)],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function itRejectsABlankName(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/accounts', self::AUTH + [
            'json' => ['name' => '   ', 'password' => 'correct-horse'],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function itRequiresTheApiKey(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/accounts', ['json' => ['name' => self::NAME, 'password' => 'correct-horse']]);

        self::assertResponseStatusCodeSame(401);
    }
}
