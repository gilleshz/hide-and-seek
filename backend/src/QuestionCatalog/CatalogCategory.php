<?php

declare(strict_types=1);

namespace App\QuestionCatalog;

use App\Enum\QuestionCategory;

final readonly class CatalogCategory
{
    /** @param list<CatalogOption> $options */
    public function __construct(
        public QuestionCategory $key,
        public array $options,
    ) {
    }
}
