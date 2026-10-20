<?php

declare(strict_types=1);

namespace App\Tests\Dto;

use App\Dto\GameInput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class GameInputValidationTest extends TestCase
{
    #[Test]
    public function itAcceptsTheParisMetroAndRerBranchCount(): void
    {
        $input = new GameInput();
        $input->name = 'Paris';
        $input->selectedTransitLines = array_fill(0, 107, [
            'osmType' => 'relation',
            'osmId' => 1,
            'ref' => 'A',
        ]);

        self::assertCount(0, $this->validator()->validate($input));
    }

    #[Test]
    public function itRejectsMoreThanTheSelectionCap(): void
    {
        $input = new GameInput();
        $input->name = 'Paris';
        $input->selectedTransitLines = array_fill(0, 251, [
            'osmType' => 'relation',
            'osmId' => 1,
            'ref' => 'A',
        ]);

        self::assertNotCount(0, $this->validator()->validate($input));
    }

    private function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }
}
