<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\QuestionPreviewInput;
use App\Entity\Player;
use App\Entity\Round;
use App\Enum\FeatureType;
use App\Enum\GameSize;
use App\Enum\Side;
use App\Exception\FunctionalException;
use App\Repository\PossibleAreaConstraintRepository;
use App\Repository\RoundMembershipRepository;

final readonly class QuestionPreviewService
{
    public function __construct(
        private RoundMembershipRepository $memberships,
        private PossibleAreaConstraintRepository $constraints,
    ) {
    }

    /**
     * @return array{
     *     constraintGeoJson: ?string,
     *     currentAreaKm2: float,
     *     projectedAreaKm2: float,
     *     currentPossibleAreaGeoJson: ?string,
     *     projectedPossibleAreaGeoJson: ?string,
     *     excludedPossibleAreaGeoJson: ?string,
     * }
     */
    public function preview(Round $round, Player $asker, QuestionPreviewInput $input): array
    {
        $this->assertSeeker($round, $asker);
        $this->validateInputs($input, $round);

        return $this->constraints->computePreviewArea(
            $round,
            $input->category,
            $input->seekerLat,
            $input->seekerLng,
            $input->endLat,
            $input->endLng,
            $input->radiusMeters,
            $input->featureType ?? null,
            $input->hypotheticalAnswer,
            $input->hypotheticalFeatureId,
            $input->withinMeters,
        );
    }

    private function assertSeeker(Round $round, Player $player): void
    {
        $membership = $this->memberships->findOneByRoundAndPlayer($round, $player);
        if ($membership === null || $membership->getSide() !== Side::Seeker) {
            throw new FunctionalException(message: 'Only a seeker may preview questions.', errorKey: 'question_preview.not_seeker');
        }
    }

    private function validateInputs(QuestionPreviewInput $input, Round $round): void
    {
        match ($input->category) {
            'radar' => $this->validateRadarInputs($input),
            'thermometer' => $this->validateThermometerInputs($input),
            'measuring' => $this->validateMeasuringInputs($input),
            'matching' => $this->validateMatchingInputs($input),
            'tentacles' => $this->validateTentaclesInputs($input, $round),
            default => throw new FunctionalException(message: 'Unsupported question category for preview.', errorKey: 'question_preview.unsupported_category'),
        };
    }

    private function validateRadarInputs(QuestionPreviewInput $input): void
    {
        if ($input->radiusMeters === null) {
            throw new FunctionalException(message: 'Radar preview requires a radius.', errorKey: 'question_preview.radar_requires_radius');
        }
    }

    private function validateThermometerInputs(QuestionPreviewInput $input): void
    {
        if ($input->endLat === null || $input->endLng === null) {
            throw new FunctionalException(message: 'Thermometer preview requires an end point.', errorKey: 'question_preview.thermometer_requires_endpoint');
        }
    }

    private function validateMeasuringInputs(QuestionPreviewInput $input): void
    {
        if ($input->featureType === null) {
            throw new FunctionalException(message: 'Measuring preview requires a feature type.', errorKey: 'question_preview.measuring_requires_feature_type');
        }

        if (in_array($input->featureType, ['sea_level', 'coastline'], true)) {
            throw new FunctionalException(message: 'This feature type is not supported in simulation yet.', errorKey: 'question_preview.unsupported_feature_type');
        }
    }

    private function validateMatchingInputs(QuestionPreviewInput $input): void
    {
        if ($input->featureType === null) {
            throw new FunctionalException(message: 'Matching preview requires a feature type.', errorKey: 'question_preview.matching_requires_feature_type');
        }

        if ($input->hypotheticalFeatureId === null) {
            throw new FunctionalException(message: 'Matching preview requires a chosen feature.', errorKey: 'question_preview.matching_requires_feature');
        }

        $type = FeatureType::tryFrom($input->featureType);
        if ($type === null) {
            throw new FunctionalException(message: 'Unknown feature type.', errorKey: 'question_preview.unknown_feature_type');
        }

        if ($type === FeatureType::Coastline || $input->featureType === 'sea_level') {
            throw new FunctionalException(message: 'This feature type is not supported in simulation yet.', errorKey: 'question_preview.unsupported_feature_type');
        }
    }

    private function validateTentaclesInputs(QuestionPreviewInput $input, Round $round): void
    {
        if ($round->getGame()->getSize() === GameSize::Small) {
            throw new FunctionalException(message: 'Tentacles is not available in Small games.', errorKey: 'question_preview.tentacles_not_in_small');
        }

        if ($input->withinMeters === null) {
            throw new FunctionalException(message: 'Tentacles preview requires a range.', errorKey: 'question_preview.tentacles_requires_range');
        }

        if ($input->hypotheticalAnswer !== 'none') {
            if ($input->featureType === null) {
                throw new FunctionalException(message: 'Tentacles preview requires a feature type.', errorKey: 'question_preview.tentacles_requires_feature_type');
            }

            if ($input->hypotheticalFeatureId === null) {
                throw new FunctionalException(message: 'Tentacles preview requires a chosen feature.', errorKey: 'question_preview.tentacles_requires_feature');
            }

            $type = FeatureType::tryFrom($input->featureType);
            if ($type === null) {
                throw new FunctionalException(message: 'Unknown feature type.', errorKey: 'question_preview.unknown_feature_type');
            }
        }
    }
}
