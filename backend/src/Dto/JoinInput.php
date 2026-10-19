<?php

declare(strict_types=1);

namespace App\Dto;

use App\Serializer\Group;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class JoinInput
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 80, normalizer: 'trim')]
    #[Groups([Group::JOIN_WRITE])]
    public string $name = '';

    /** Opaque per-device credential; the Android app sends sha256(ANDROID_ID). */
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 255)]
    #[Groups([Group::JOIN_WRITE])]
    public string $deviceKey = '';
}
