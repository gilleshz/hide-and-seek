package fr.gshz.hideandseek.domain.repository

import fr.gshz.hideandseek.domain.model.Edition
import fr.gshz.hideandseek.domain.model.GameSize
import fr.gshz.hideandseek.domain.model.GameSummary
import fr.gshz.hideandseek.domain.model.JoinResult
import fr.gshz.hideandseek.domain.model.LeaderboardEntry
import fr.gshz.hideandseek.domain.model.Player
import fr.gshz.hideandseek.domain.model.Side
import fr.gshz.hideandseek.domain.model.TeamResult
import fr.gshz.hideandseek.domain.model.GtfsRoute
import fr.gshz.hideandseek.domain.model.GtfsSourceState
import fr.gshz.hideandseek.domain.model.TransitLine

data class GameBoundary(
    val swLat: Double? = null,
    val swLng: Double? = null,
    val neLat: Double? = null,
    val neLng: Double? = null,
)

data class AreaRef(val osmType: String, val osmId: Int)

data class AreaInfo(val osmId: String, val osmType: String, val displayName: String, val adminLevel: Int?)

data class GtfsLineSelection(
    val sourceUuid: String,
    val route: GtfsRoute,
)

interface GameRepository {
    @Suppress("LongParameterList")
    suspend fun createGame(
        name: String,
        size: GameSize,
        edition: Edition,
        boundary: GameBoundary = GameBoundary(),
        areas: List<AreaInfo>? = null,
        selectedTransitLines: List<TransitLine>? = null,
        selectedGtfsLines: List<GtfsLineSelection>? = null,
    ): GameSummary
    suspend fun searchAreas(query: String): List<AreaInfo>
    suspend fun discoverTransitLines(areas: List<AreaInfo>, routeTypes: List<String>? = null): List<TransitLine>
    suspend fun boundaryPreview(areas: List<AreaInfo>): String
    suspend fun transitLinePreview(osmIds: Set<String>): String
    suspend fun uploadGtfsFromUrl(url: String, name: String): GtfsSourceState
    suspend fun uploadGtfsFromFile(fileBytes: ByteArray, fileName: String, name: String): GtfsSourceState
    suspend fun getGame(gameUuid: String): GameSummary
    suspend fun getTransitOverlay(gameUuid: String): String?
    suspend fun joinGame(gameUuid: String, displayName: String, password: String): JoinResult
    suspend fun removePlayer(gameUuid: String, playerUuid: String)
    suspend fun listPlayers(gameUuid: String): List<Player>
    suspend fun leaderboard(gameUuid: String): List<LeaderboardEntry>
    suspend fun chooseTeam(roundUuid: String, playerUuid: String, side: Side): TeamResult
    suspend fun updateConfig(
        gameUuid: String,
        name: String? = null,
        size: GameSize? = null,
        edition: Edition? = null,
    ): GameSummary

    suspend fun leaveGame(gameUuid: String, playerUuid: String)
    suspend fun deleteGame(gameUuid: String, playerUuid: String)
}
