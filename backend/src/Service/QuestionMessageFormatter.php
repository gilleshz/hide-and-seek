<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\MessagePayload;
use App\Entity\AskedQuestion;
use App\Entity\Feature;
use App\Enum\Edition;
use App\Enum\FeatureType;
use App\Enum\MeasuringResult;
use App\Enum\PhotoTarget;
use App\Enum\QuestionCategory;
use App\GeoDistance;
use App\Repository\FeatureRepository;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;

final readonly class QuestionMessageFormatter
{
    private const float METERS_PER_MILE = 1609.344;

    public function __construct(private FeatureRepository $features)
    {
    }

    public function askBody(AskedQuestion $question): MessagePayload
    {
        return match ($question->getCategory()) {
            QuestionCategory::Radar => $this->radarAskBody($question),
            QuestionCategory::Matching => $this->matchingAsk($question),
            QuestionCategory::Measuring => $this->measuringAskBody($question),
            QuestionCategory::Tentacles => $this->tentaclesAskBody($question),
            QuestionCategory::Photos => $this->photosAskBody($question),
            QuestionCategory::Thermometer => throw new \LogicException(
                'Thermometer questions use thermometerStartBody and thermometerCompleteBody.',
            ),
        };
    }

    public function thermometerStartBody(AskedQuestion $question): MessagePayload
    {
        $distanceMeters = $question->getDistanceMeters();
        if ($distanceMeters === null) {
            throw new \LogicException('Thermometer question is missing its distance.');
        }

        $edition = $this->edition($question);
        $formattedDist = $this->formatDistance($distanceMeters, $edition);

        return new MessagePayload(
            bodyKey: 'question.thermometer.start',
            bodyArgs: ['distance' => $formattedDist],
            body: sprintf("I'm starting a %s thermometer...", $formattedDist),
        );
    }

    public function thermometerCompleteBody(AskedQuestion $question): MessagePayload
    {
        $start = $question->getStartPoint();
        $end = $question->getEndPoint();
        if ($start === null || $end === null) {
            throw new \LogicException('Thermometer question is missing its start or end point.');
        }

        $edition = $this->edition($question);
        $formattedDist = $this->formatDistance(GeoDistance::metersBetween($start, $end), $edition);

        return new MessagePayload(
            bodyKey: 'question.thermometer.complete',
            bodyArgs: ['distance' => $formattedDist],
            body: sprintf("I've just traveled %s. Am I hotter or colder now?", $formattedDist),
        );
    }

    public function answerBody(AskedQuestion $question): MessagePayload
    {
        return match ($question->getCategory()) {
            QuestionCategory::Radar => $this->boolAnswer(
                $question->getRadarAnswer(),
                'question.answer.yes_within_range',
                'Yes, within range',
                'question.answer.no_not_within_range',
                'No, not within range',
            ),
            QuestionCategory::Thermometer => $this->enumAnswer($question->getThermometerAnswer()?->value),
            QuestionCategory::Matching => $question->getTransitLineUuid() !== null
                ? $this->transitLineMatchingAnswer($question)
                : $this->boolAnswer(
                    $question->getMatchingAnswer(),
                    'question.answer.same',
                    'Same',
                    'question.answer.different',
                    'Different',
                ),
            QuestionCategory::Measuring => $question->isSeaLevel()
                ? $this->seaLevelAnswerBody($question)
                : $this->enumAnswer($question->getMeasuringAnswer()?->value),
            QuestionCategory::Tentacles => $this->tentaclesAnswerBody($question),
            QuestionCategory::Photos => new MessagePayload(
                bodyKey: 'question.answer.photo_in_chat',
                body: 'Photo will be posted in chat.',
            ),
        };
    }

    public function formatDistance(float $meters, Edition $edition): string
    {
        return match ($edition) {
            Edition::Metric => $meters < 1000.0
                ? sprintf('%d m', (int) round($meters))
                : sprintf('%s km', self::trimmed($meters / 1000.0)),
            Edition::Imperial => sprintf('%s mi', self::trimmed($meters / self::METERS_PER_MILE)),
        };
    }

    private function radarAskBody(AskedQuestion $question): MessagePayload
    {
        $radiusMeters = $question->getRadiusMeters();
        if ($radiusMeters === null) {
            throw new \LogicException('Radar question is missing its radius.');
        }

        $edition = $this->edition($question);
        $formattedDistance = $this->formatDistance($radiusMeters, $edition);

        return new MessagePayload(
            bodyKey: 'question.radar.ask',
            bodyArgs: ['distance' => $formattedDistance],
            body: sprintf('Are you within %s of me?', $formattedDistance),
        );
    }

    private function matchingAsk(AskedQuestion $question): MessagePayload
    {
        return match (true) {
            $question->getTransitLineUuid() !== null => $this->transitLineAskBody($question),
            $question->isStationNameLength() => $this->stationNameLengthAskBody($question),
            default => $this->matchingAskBody($question),
        };
    }

    private function stationNameLengthAskBody(AskedQuestion $question): MessagePayload
    {
        $name = $this->nearestSeekerFeature($question)?->getName();
        $count = $name !== null ? mb_strlen(trim($name)) : 0;

        return new MessagePayload(
            bodyKey: 'question.matching.ask_station_name_length',
            bodyArgs: ['name' => $name ?? '', 'count' => (string) $count],
            body: sprintf(
                "My nearest station is %s (%d characters). Is your nearest station's name the same length?",
                $name ?? '',
                $count,
            ),
        );
    }

    private function matchingAskBody(AskedQuestion $question): MessagePayload
    {
        $type = $question->getFeatureType();
        if ($type === null) {
            throw new \LogicException('Feature question is missing its feature type.');
        }

        $name = $this->nearestSeekerFeature($question)?->getName();
        $suffix = $name === null ? 'no_name' : 'with_name';

        return new MessagePayload(
            bodyKey: 'question.matching.ask_' . $suffix . '.' . $type->value,
            bodyArgs: $name !== null ? ['name' => $name] : [],
            body: $this->matchingAskText($type, $name),
        );
    }

    private function matchingAskText(FeatureType $type, ?string $name): string
    {
        $label = $type->label();
        if ($type->adminRank() !== null) {
            return $name === null
                ? sprintf('Are you in the same %s as me?', $label)
                : sprintf('Are you in the same %s as me? (%s)', $label, $name);
        }

        return $name === null
            ? sprintf('Is your nearest %s the same as mine?', $label)
            : sprintf('My nearest %s is %s. Is your nearest %s the same as mine?', $label, $name, $label);
    }

    private function transitLineAskBody(AskedQuestion $question): MessagePayload
    {
        $label = $question->getTransitLineLabel() ?? '';

        return new MessagePayload(
            bodyKey: 'question.matching.ask_transit_line',
            bodyArgs: ['line' => $label],
            body: sprintf('I am riding %s. Does it stop at your station?', $label),
        );
    }

    private function transitLineMatchingAnswer(AskedQuestion $question): MessagePayload
    {
        $label = $question->getTransitLineLabel() ?? '';

        return match ($question->getMatchingAnswer()) {
            true => new MessagePayload(
                bodyKey: 'question.answer.transit_line_yes',
                bodyArgs: ['line' => $label],
                body: sprintf('Yes, %s stops at my station.', $label),
            ),
            false => new MessagePayload(
                bodyKey: 'question.answer.transit_line_no',
                bodyArgs: ['line' => $label],
                body: sprintf('No, %s does not stop at my station.', $label),
            ),
            null => new MessagePayload(bodyKey: 'question.answer.no_answer', body: 'No answer available'),
        };
    }

    private function measuringAskBody(AskedQuestion $question): MessagePayload
    {
        if ($question->isSeaLevel()) {
            return new MessagePayload(
                bodyKey: 'question.measuring.ask.sea_level',
                body: 'Are you closer to or further from sea level than I am?',
            );
        }

        $type = $question->getFeatureType();
        if ($type === null) {
            throw new \LogicException('Feature question is missing its feature type.');
        }

        $label = $type->label();
        $nearest = $this->nearestSeekerFeature($question);

        if ($nearest === null) {
            return new MessagePayload(
                bodyKey: 'question.measuring.ask_no_name.' . $type->value,
                body: sprintf(
                    'Are you closer to or further from your nearest %s than I am to mine?',
                    $label,
                ),
            );
        }

        $edition = $this->edition($question);
        $distance = $this->formatDistance(
            $this->features->distanceToFeature($nearest, $this->seekerPoint($question)),
            $edition,
        );
        $name = $nearest->getName();

        $suffix = $name === null ? 'ask_with_feature_no_name' : 'ask_with_name';

        $lead = $name === null
            ? sprintf("I'm %s from my nearest %s.", $distance, $label)
            : sprintf("I'm %s from my nearest %s (%s).", $distance, $label, $name);

        return new MessagePayload(
            bodyKey: 'question.measuring.' . $suffix . '.' . $type->value,
            bodyArgs: $name !== null
                ? ['distance' => $distance, 'name' => $name]
                : ['distance' => $distance],
            body: sprintf('%s Are you closer to or further from your nearest %s?', $lead, $label),
        );
    }

    private function tentaclesAskBody(AskedQuestion $question): MessagePayload
    {
        $withinMeters = $question->getWithinMeters();
        if ($withinMeters === null) {
            throw new \LogicException('Tentacles question is missing its range.');
        }

        $type = $question->getFeatureType();
        if ($type === null) {
            throw new \LogicException('Feature question is missing its feature type.');
        }

        $edition = $this->edition($question);
        $range = $this->formatDistance($withinMeters, $edition);
        $label = $type->label();

        return new MessagePayload(
            bodyKey: 'question.tentacles.ask.' . $type->value,
            bodyArgs: ['range' => $range],
            body: sprintf(
                'Within %s of me, which %s are you nearest to? (You must also be within %s.)',
                $range,
                $label,
                $range,
            ),
        );
    }

    private function photosAskBody(AskedQuestion $question): MessagePayload
    {
        $target = $question->getPhotoTarget();
        if ($target === null) {
            throw new \LogicException('Photo question is missing its target.');
        }

        $edition = $this->edition($question);
        $targetLabel = $target->label($edition);
        $bodyKey = $target === PhotoTarget::StreetsTraced
            ? 'question.photos.ask.streets_traced_' . $edition->value
            : 'question.photos.ask.' . $target->value;

        $ask = sprintf('Send me a photo of %s.', $targetLabel);
        $requirement = $target->requirement();

        return new MessagePayload(
            bodyKey: $bodyKey,
            body: $requirement === null ? $ask : $ask . ' ' . $requirement,
        );
    }

    private function nearestSeekerFeature(AskedQuestion $question): ?Feature
    {
        $type = $question->getFeatureType();
        if ($type === null) {
            throw new \LogicException('Feature question is missing its feature type.');
        }

        $game = $question->getRound()->getGame();

        return $this->features->findNearestWithin($game, $type, $this->seekerPoint($question), 1)[0] ?? null;
    }

    private function seekerPoint(AskedQuestion $question): Point
    {
        $seekerPoint = $question->getSeekerPoint();
        if ($seekerPoint === null) {
            throw new \LogicException('Feature question is missing its seeker point.');
        }

        return $seekerPoint;
    }

    private function edition(AskedQuestion $question): Edition
    {
        return $question->getRound()->getGame()->getEdition();
    }

    private function boolAnswer(
        ?bool $answer,
        string $yesKey,
        string $yesText,
        string $noKey,
        string $noText,
    ): MessagePayload {
        return match ($answer) {
            true => new MessagePayload(bodyKey: $yesKey, body: $yesText),
            false => new MessagePayload(bodyKey: $noKey, body: $noText),
            null => new MessagePayload(bodyKey: 'question.answer.no_answer', body: 'No answer available'),
        };
    }

    private function seaLevelAnswerBody(AskedQuestion $question): MessagePayload
    {
        return match ($question->getMeasuringAnswer()) {
            MeasuringResult::Closer => new MessagePayload(
                bodyKey: 'question.answer.sea_level_closer',
                body: 'Closer to sea level',
            ),
            MeasuringResult::Further => new MessagePayload(
                bodyKey: 'question.answer.sea_level_further',
                body: 'Further from sea level',
            ),
            null => new MessagePayload(bodyKey: 'question.answer.no_answer', body: 'No answer available'),
        };
    }

    private function enumAnswer(?string $value): MessagePayload
    {
        if ($value === null) {
            return new MessagePayload(bodyKey: 'question.answer.no_answer', body: 'No answer available');
        }

        return new MessagePayload(
            bodyKey: 'question.answer.' . $value,
            body: ucfirst($value),
        );
    }

    private function tentaclesAnswerBody(AskedQuestion $question): MessagePayload
    {
        $answer = $question->getTentaclesAnswer();

        if ($answer === null) {
            return new MessagePayload(bodyKey: 'question.answer.no_answer', body: 'No answer available');
        }

        return new MessagePayload(
            bodyKey: 'question.answer.tentacles_answer',
            bodyArgs: ['answer' => $answer],
            body: $answer,
        );
    }

    private static function trimmed(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
