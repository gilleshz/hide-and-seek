<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;

final class AccountPasswordApiTest extends ApiTestCase
{
    private const string NAME = 'password-user';

    #[Test]
    public function itChangesThePasswordWhenTheCurrentOneMatches(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/accounts', self::AUTH + [
            'json' => ['name' => self::NAME, 'password' => 'old-password'],
        ]);
        self::assertResponseStatusCodeSame(204);

        $client->request('POST', '/api/account/password', self::AUTH + [
            'json' => ['name' => self::NAME, 'currentPassword' => 'old-password', 'newPassword' => 'new-password'],
        ]);
        self::assertResponseStatusCodeSame(204);
    }

    #[Test]
    public function theNewPasswordTakesEffect(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/accounts', self::AUTH + [
            'json' => ['name' => self::NAME, 'password' => 'old-password'],
        ]);
        self::assertResponseStatusCodeSame(204);

        $client->request('POST', '/api/account/password', self::AUTH + [
            'json' => ['name' => self::NAME, 'currentPassword' => 'old-password', 'newPassword' => 'new-password'],
        ]);
        self::assertResponseStatusCodeSame(204);

        $client->request('POST', '/api/accounts', self::AUTH + [
            'json' => ['name' => self::NAME, 'password' => 'new-password'],
        ]);
        self::assertResponseStatusCodeSame(204);

        $client->request('POST', '/api/accounts', self::AUTH + [
            'json' => ['name' => self::NAME, 'password' => 'old-password'],
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'account.name_taken']);
    }

    #[Test]
    public function anUnknownNameAndAWrongCurrentPasswordAreIndistinguishable(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/accounts', self::AUTH + [
            'json' => ['name' => self::NAME, 'password' => 'correct-horse'],
        ]);
        self::assertResponseStatusCodeSame(204);

        $wrongCurrent = $client->request('POST', '/api/account/password', self::AUTH + [
            'json' => ['name' => self::NAME, 'currentPassword' => 'wrong-password', 'newPassword' => 'new-password'],
        ])->toArray(false);
        self::assertResponseStatusCodeSame(400);

        $unknownName = $client->request('POST', '/api/account/password', self::AUTH + [
            'json' => ['name' => 'no-such-player', 'currentPassword' => 'whatever', 'newPassword' => 'new-password'],
        ])->toArray(false);
        self::assertResponseStatusCodeSame(400);

        self::assertSame('account.password_invalid', $wrongCurrent['errorKey']);
        self::assertSame($wrongCurrent['errorKey'], $unknownName['errorKey']);
    }

    #[Test]
    public function itRejectsInvalidBodies(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/accounts', self::AUTH + [
            'json' => ['name' => self::NAME, 'password' => 'correct-horse'],
        ]);
        self::assertResponseStatusCodeSame(204);

        foreach (
            [
                ['name' => self::NAME, 'newPassword' => 'new-password'],
                ['name' => self::NAME, 'currentPassword' => '', 'newPassword' => 'new-password'],
                ['name' => self::NAME, 'currentPassword' => 'abc', 'newPassword' => 'new-password'],
                ['name' => self::NAME, 'currentPassword' => 'correct-horse', 'newPassword' => \str_repeat('x', 65)],
                ['name' => '   ', 'currentPassword' => 'correct-horse', 'newPassword' => 'new-password'],
            ] as $body
        ) {
            $client->request('POST', '/api/account/password', self::AUTH + ['json' => $body]);
            self::assertResponseStatusCodeSame(422, 'Invalid change-password payloads must 422.');
        }
    }

    #[Test]
    public function itRequiresTheApiKey(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/account/password', [
            'json' => ['name' => self::NAME, 'currentPassword' => 'correct-horse', 'newPassword' => 'new-password'],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function aFailedChangeDoesNotCreateAnAccount(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/account/password', self::AUTH + [
            'json' => ['name' => 'ghost-player', 'currentPassword' => 'old-password', 'newPassword' => 'new-password'],
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertJsonContains(['errorKey' => 'account.password_invalid']);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $rows = $entityManager->getConnection()->fetchAllAssociative(
            "select name from accounts where name = 'ghost-player'",
        );
        self::assertCount(0, $rows);
    }
}
