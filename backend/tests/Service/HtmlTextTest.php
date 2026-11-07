<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\HtmlText;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HtmlTextTest extends TestCase
{
    #[Test]
    public function itConvertsBlockTagsToLineBreaksAndListItemsToBullets(): void
    {
        $html = '<p>First line</p><p><b>Second</b> line</p><br /><ul><li>One</li><li>Two</li></ul>';

        $text = HtmlText::strip($html);

        self::assertSame("First line\nSecond line\n\n- One\n- Two", $text);
    }

    #[Test]
    public function itDecodesEntitiesAndCollapsesWhitespace(): void
    {
        $html = "<p>En raison&nbsp;d'une manifestation,&nbsp; des   perturbations</p>\n<p>suivent</p>";

        $text = HtmlText::strip($html);

        self::assertSame("En raison d'une manifestation, des perturbations\n\nsuivent", $text);
    }

    #[Test]
    public function itReturnsAnEmptyStringForPlainEmptyInput(): void
    {
        self::assertSame('', HtmlText::strip(''));
        self::assertSame('', HtmlText::strip("  \n "));
    }

    #[Test]
    public function itCapsTheResultAtFourThousandCharacters(): void
    {
        $text = HtmlText::strip('<p>' . str_repeat('a', 5000) . '</p>');

        self::assertSame(4000, mb_strlen($text));
    }
}
