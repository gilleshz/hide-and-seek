package fr.gshz.hideandseek.data.mapper

import fr.gshz.hideandseek.data.remote.dto.GameDto
import fr.gshz.hideandseek.data.remote.dto.JoinDto
import fr.gshz.hideandseek.data.remote.dto.LeaderboardEntryDto
import fr.gshz.hideandseek.data.remote.dto.SubscriberTokenDto
import fr.gshz.hideandseek.domain.model.LeaderboardEntry
import fr.gshz.hideandseek.data.remote.dto.PlayerDto
import fr.gshz.hideandseek.data.remote.dto.TeamDto
import fr.gshz.hideandseek.data.remote.dto.TransitLineRef
import fr.gshz.hideandseek.domain.model.TransitLine
import fr.gshz.hideandseek.domain.model.Edition
import fr.gshz.hideandseek.domain.model.GameSize
import fr.gshz.hideandseek.domain.model.GameSummary
import fr.gshz.hideandseek.domain.model.JoinResult
import fr.gshz.hideandseek.domain.model.Player
import fr.gshz.hideandseek.domain.model.Side
import fr.gshz.hideandseek.domain.model.TeamResult
import fr.gshz.hideandseek.domain.model.TokenRefresh

fun GameDto.toDomain() = GameSummary(
    uuid = uuid,
    name = name,
    size = GameSize.fromWireValue(size),
    edition = Edition.fromWireValue(edition),
    roundUuid = roundUuid,
    boundarySet = boundarySwLat != null && boundarySwLng != null && boundaryNeLat != null && boundaryNeLng != null,
    boundarySwLat = boundarySwLat,
    boundarySwLng = boundarySwLng,
    boundaryNeLat = boundaryNeLat,
    boundaryNeLng = boundaryNeLng,
    transitTilesPath = transitTilesPath,
    boundaryGeoJson = boundaryGeoJson,
    joinCode = joinCode,
    defaultHidingPeriodMinutes = defaultHidingPeriodMinutes,
    selectedTransitLines = selectedTransitLines.map { it.toTransitLine() },
)

fun TransitLineRef.toTransitLine() = TransitLine(
    osmId = osmId.toString(),
    osmType = osmType,
    ref = ref,
    name = name,
    nameEn = nameEn,
    colour = colour,
    routeType = routeType,
    network = network,
    operator = operator,
)

fun JoinDto.toDomain() = JoinResult(
    playerUuid = playerUuid,
    displayName = displayName,
    gameUuid = gameUuid,
    roundUuid = roundUuid,
    mercureToken = mercureToken,
    topics = topics,
)

fun SubscriberTokenDto.toDomain() = TokenRefresh(mercureToken, topics)

fun PlayerDto.toDomain() = Player(
    uuid = uuid,
    displayName = displayName,
    side = side?.let(Side::fromWireValue),
)

fun LeaderboardEntryDto.toDomain() = LeaderboardEntry(
    roundUuid = roundUuid,
    roundNumber = roundNumber,
    hiderNames = hiderNames,
    hidingTimeSeconds = hidingTimeSeconds,
    scoreSeconds = scoreSeconds,
    bonusMinutes = bonusMinutes,
    bonusPercent = bonusPercent,
)

fun TeamDto.toDomain() = TeamResult(
    playerUuid = playerUuid,
    roundUuid = roundUuid,
    side = Side.fromWireValue(side),
    mercureToken = mercureToken,
    topics = topics,
)
