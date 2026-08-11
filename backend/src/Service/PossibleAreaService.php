<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ManualConstraintDraft;
use App\Entity\AskedQuestion;
use App\Entity\PossibleAreaConstraint;
use App\Entity\Round;
use App\Entity\RoundMembership;
use App\Enum\ConstraintSource;
use App\Enum\MeasuringResult;
use App\Enum\QuestionCategory;
use App\Enum\ThermometerResult;
use App\Repository\GameTransitLineRepository;
use App\Repository\PossibleAreaConstraintRepository;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

readonly class PossibleAreaService
{
    public function __construct(
        private PossibleAreaConstraintRepository $constraints,
        private GameTransitLineRepository $transitLines,
        private MercureJwtService $mercure,
        private HubInterface $hub,
    ) {
    }

    /**
     * @param array<string, string|int>|null $labelArgs
     */
    public function addConstraint(Round $round, string $label, string $geometryWkt, ?string $labelKey = null, ?array $labelArgs = null): void
    {
        $this->constraints->insertConstraint($round, $geometryWkt, $label, $labelKey, $labelArgs);
    }

    public function addManualConstraint(Round $round, RoundMembership $creator, ManualConstraintDraft $draft): string
    {
        $uuid = $this->constraints->insertManualConstraint($round, $creator, $draft);

        $this->hub->publish(new Update(
            $this->mercure->possibleAreaTopic($round->getGame(), $round),
            json_encode([
                'type' => 'manual-constraint-added',
                'uuid' => $uuid,
                'mode' => $draft->mode->value,
                'geoJson' => $draft->ringGeoJson,
            ], JSON_THROW_ON_ERROR),
            private: true,
        ));

        return $uuid;
    }

    public function deleteManualConstraint(PossibleAreaConstraint $constraint): void
    {
        if ($constraint->getSource() !== ConstraintSource::Manual) {
            throw new \LogicException('Only manual constraints may be deleted through this path.');
        }

        $round = $constraint->getRound();
        $uuid = $constraint->getUuid();
        $topic = $this->mercure->possibleAreaTopic($round->getGame(), $round);
        $this->constraints->remove($constraint);

        $this->hub->publish(new Update(
            $topic,
            json_encode([
                'type' => 'manual-constraint-removed',
                'uuid' => $uuid,
            ], JSON_THROW_ON_ERROR),
            private: true,
        ));
    }

    /**
     * A Move abandons the station every banked deduction was measured against, so keeping them would
     * let the overlay exclude wherever the hiders went next.
     */
    public function clearConstraints(Round $round): void
    {
        $this->constraints->deleteByRound($round);

        $this->hub->publish(new Update(
            $this->mercure->possibleAreaTopic($round->getGame(), $round),
            json_encode(['type' => 'possible-area-cleared'], JSON_THROW_ON_ERROR),
            private: true,
        ));
    }

    public function computeCurrent(Round $round): ?string
    {
        return $this->constraints->computePossibleArea($round);
    }

    public function computeExclusion(Round $round): ?string
    {
        return $this->constraints->computeExclusion($round);
    }

    public function computeAfterReveal(AskedQuestion $question, Point $hiderPoint): void
    {
        match ($question->getCategory()) {
            QuestionCategory::Radar => $this->computeRadarConstraint($question),
            QuestionCategory::Thermometer => $this->computeThermometerConstraint($question),
            QuestionCategory::Matching => $this->computeMatchingConstraint($question),
            QuestionCategory::Measuring => $this->computeMeasuringConstraint($question),
            QuestionCategory::Tentacles => $this->computeTentaclesConstraint($question, $hiderPoint),
            QuestionCategory::Photos => null, // manual photo-into-chat, no geometry constraint
        };

        $round = $question->getRound();
        $game = $round->getGame();
        $this->hub->publish(new Update(
            $this->mercure->possibleAreaTopic($game, $round),
            json_encode([
                'type' => 'possible-area',
                'questionUuid' => $question->getUuid(),
            ], JSON_THROW_ON_ERROR),
            private: true,
        ));
    }

    private function computeRadarConstraint(AskedQuestion $question): void
    {
        $seekerPoint = $question->getSeekerPoint();
        $radiusMeters = $question->getRadiusMeters();
        $radarAnswer = $question->getRadarAnswer();

        if ($seekerPoint === null || $radiusMeters === null || $radarAnswer === null) {
            throw new \LogicException('Radar question is missing seeker point, radius, or answer.');
        }

        $label = sprintf('Radar (%dm): %s', (int) round($radiusMeters), $radarAnswer ? 'yes' : 'no');
        $labelKey = 'constraint.radar';
        $labelArgs = ['meters' => (int) round($radiusMeters), 'answer' => $radarAnswer ? 'yes' : 'no'];

        // "yes" keeps the disk; "no" keeps its complement (the rules invert the region).
        $this->constraints->insertDiskConstraint(
            round: $question->getRound(),
            point: $seekerPoint,
            radiusMeters: $radiusMeters,
            inside: $radarAnswer,
            label: $label,
            labelKey: $labelKey,
            labelArgs: $labelArgs,
        );
    }

    private function computeThermometerConstraint(AskedQuestion $question): void
    {
        $startPoint = $question->getStartPoint();
        $endPoint = $question->getEndPoint();
        $thermometerAnswer = $question->getThermometerAnswer();

        if ($startPoint === null || $endPoint === null || $thermometerAnswer === null) {
            throw new \LogicException('Thermometer question is missing start/end points or answer.');
        }

        $label = sprintf('Thermometer: %s', $thermometerAnswer->value);
        $labelKey = 'constraint.thermometer';
        $labelArgs = ['result' => $thermometerAnswer->value];

        $this->constraints->insertHalfPlaneConstraint(
            round: $question->getRound(),
            start: $startPoint,
            end: $endPoint,
            isHotter: $thermometerAnswer === ThermometerResult::Hotter,
            label: $label,
            labelKey: $labelKey,
            labelArgs: $labelArgs,
        );
    }

    private function computeMatchingConstraint(AskedQuestion $question): void
    {
        if ($question->getTransitLineUuid() !== null) {
            $this->computeTransitLineConstraint($question);

            return;
        }
        if ($question->isStationNameLength()) {
            $this->computeStationNameLengthConstraint($question);

            return;
        }

        $answer = $question->getMatchingAnswer();
        [$type, $seeker] = $this->featureContext($question);
        if ($answer === null) {
            return;
        }

        $label = sprintf('Matching (%s): %s', $type->value, $answer ? 'same' : 'different');
        $labelKey = 'constraint.matching';
        $labelArgs = ['feature' => $type->value, 'result' => $answer ? 'same' : 'different'];

        if ($type->geometryKind() === 'areal') {
            $this->constraints->insertMatchingArealConstraint(
                round: $question->getRound(),
                type: $type,
                seeker: $seeker,
                isSame: $answer,
                label: $label,
                labelKey: $labelKey,
                labelArgs: $labelArgs,
            );
        } else {
            $this->constraints->insertMatchingConstraint(
                round: $question->getRound(),
                type: $type,
                seeker: $seeker,
                isSame: $answer,
                label: $label,
                labelKey: $labelKey,
                labelArgs: $labelArgs,
            );
        }
    }

    private function computeStationNameLengthConstraint(AskedQuestion $question): void
    {
        $answer = $question->getMatchingAnswer();
        if ($answer === null) {
            return;
        }

        [$type, $seeker] = $this->featureContext($question);
        $this->constraints->insertStationNameLengthConstraint(
            round: $question->getRound(),
            type: $type,
            seeker: $seeker,
            isSame: $answer,
            label: sprintf('Station name length: %s', $answer ? 'same' : 'different'),
            labelKey: 'constraint.station_name_length',
            labelArgs: ['result' => $answer ? 'same' : 'different'],
        );
    }

    private function computeTransitLineConstraint(AskedQuestion $question): void
    {
        $answer = $question->getMatchingAnswer();
        $lineUuid = $question->getTransitLineUuid();
        if ($answer === null || $lineUuid === null) {
            return;
        }

        $line = $this->transitLines->findOneByGameAndUuid($question->getRound()->getGame(), $lineUuid);
        if ($line === null) {
            return;
        }

        $this->constraints->insertTransitLineConstraint(
            round: $question->getRound(),
            ref: $line->getRef(),
            serves: $answer,
            label: sprintf('Transit line: %s', $answer ? 'stops' : 'does not stop'),
            labelKey: 'constraint.transit_line',
            labelArgs: ['result' => $answer ? 'serves' : 'excludes'],
        );
    }

    private function computeMeasuringConstraint(AskedQuestion $question): void
    {
        if ($question->isSeaLevel()) {
            return;
        }

        $answer = $question->getMeasuringAnswer();
        [$type, $seeker] = $this->featureContext($question);
        if ($answer === null) {
            return;
        }

        $label = sprintf('Measuring (%s): %s', $type->value, $answer->value);
        $labelKey = 'constraint.measuring';
        $labelArgs = ['feature' => $type->value, 'result' => $answer->value];
        $isCloser = $answer === MeasuringResult::Closer;

        if ($type->geometryKind() === 'linear') {
            $this->constraints->insertMeasuringLinearConstraint(
                round: $question->getRound(),
                type: $type,
                seeker: $seeker,
                isCloser: $isCloser,
                label: $label,
                labelKey: $labelKey,
                labelArgs: $labelArgs,
            );
        } else {
            $this->constraints->insertMeasuringConstraint(
                round: $question->getRound(),
                type: $type,
                seeker: $seeker,
                isCloser: $isCloser,
                label: $label,
                labelKey: $labelKey,
                labelArgs: $labelArgs,
            );
        }
    }

    private function computeTentaclesConstraint(AskedQuestion $question, Point $hiderPoint): void
    {
        [$type, $seeker] = $this->featureContext($question);
        $range = $question->getWithinMeters();
        $answer = $question->getTentaclesAnswer();
        if ($range === null || $answer === null) {
            return;
        }

        if ($answer === AskedQuestion::TENTACLES_NOT_WITHIN_REACH) {
            $label = sprintf('Tentacles (%s): not within reach', $type->value);
            $labelKey = 'constraint.tentacles_not_within_reach';
            $labelArgs = ['feature' => $type->value];
            $this->constraints->insertDiskConstraint(
                round: $question->getRound(),
                point: $seeker,
                radiusMeters: $range,
                inside: false,
                label: $label,
                labelKey: $labelKey,
                labelArgs: $labelArgs,
            );

            return;
        }

        $label = sprintf('Tentacles (%s): %s', $type->value, $answer);
        $labelKey = 'constraint.tentacles';
        $labelArgs = ['feature' => $type->value, 'answer' => $answer];
        $this->constraints->insertTentaclesCellConstraint(
            round: $question->getRound(),
            type: $type,
            hider: $hiderPoint,
            seeker: $seeker,
            rangeMeters: $range,
            label: $label,
            labelKey: $labelKey,
            labelArgs: $labelArgs,
        );
    }

    /**
     * @return array{0: \App\Enum\FeatureType, 1: Point}
     */
    private function featureContext(AskedQuestion $question): array
    {
        $type = $question->getFeatureType();
        $seeker = $question->getSeekerPoint();
        if ($type === null || $seeker === null) {
            throw new \LogicException('Feature question is missing its type or seeker point.');
        }

        return [$type, $seeker];
    }
}
