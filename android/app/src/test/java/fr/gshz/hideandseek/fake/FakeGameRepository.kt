package fr.gshz.hideandseek.fake

import fr.gshz.hideandseek.domain.model.Edition
import fr.gshz.hideandseek.domain.model.GameSize
import fr.gshz.hideandseek.domain.model.GameSummary
import fr.gshz.hideandseek.domain.model.GtfsRoute
import fr.gshz.hideandseek.domain.model.GtfsSourceState
import fr.gshz.hideandseek.domain.model.JoinResult
import fr.gshz.hideandseek.domain.model.LeaderboardEntry
import fr.gshz.hideandseek.domain.model.Player
import fr.gshz.hideandseek.domain.model.Side
import fr.gshz.hideandseek.domain.model.TeamResult
import fr.gshz.hideandseek.domain.model.TransitLine
import fr.gshz.hideandseek.domain.repository.AreaInfo
import fr.gshz.hideandseek.domain.repository.GameBoundary
import fr.gshz.hideandseek.domain.repository.GameRepository
import fr.gshz.hideandseek.domain.repository.GtfsLineSelection
import kotlinx.coroutines.CompletableDeferred

class FakeGameRepository : GameRepository {
    var createGameResult: Result<GameSummary> =
        Result.success(GameSummary("game-1", "Berlin", GameSize.Medium, Edition.Metric, "round-1", joinCode = "ABCDEF"))
    var getGameResult: Result<GameSummary> =
        Result.success(GameSummary("game-1", "Berlin", GameSize.Medium, Edition.Metric, "round-1", joinCode = "ABCDEF"))
    var joinGameResult: Result<JoinResult> =
        Result.success(JoinResult("player-1", "Alice", "game-1", "round-1", "token", listOf("roster")))
    var lastJoinGameUuid: String? = null
        private set
    var lastJoinDisplayName: String? = null
        private set
    var lastPassword: String? = null
        private set
    var listPlayersResult: Result<List<Player>> = Result.success(emptyList())
    var listPlayersCalls = 0
    var leaderboardResult: Result<List<LeaderboardEntry>> = Result.success(emptyList())
    var leaderboardCalls = 0
    var chooseTeamResult: Result<TeamResult> =
        Result.success(TeamResult("player-1", "round-1", Side.Seeker, "token", listOf("team")))
    var discoverTransitLinesResult: Result<List<TransitLine>> = Result.success(emptyList())
    var boundaryPreviewResult: Result<String> = Result.success(
        "{\"type\":\"FeatureCollection\",\"features\":[]}",
    )
    var transitLinePreviewResult: Result<String> = Result.success(
        "{\"type\":\"FeatureCollection\",\"features\":[]}",
    )

    var uploadGtfsFromUrlResult: Result<GtfsSourceState> =
        Result.success(GtfsSourceState("src-1", "test", emptyList()))
    var uploadGtfsFromFileResult: Result<GtfsSourceState> =
        Result.success(GtfsSourceState("src-1", "test", emptyList()))

    override suspend fun createGame(
        name: String,
        size: GameSize,
        edition: Edition,
        boundary: GameBoundary,
        areas: List<AreaInfo>?,
        selectedTransitLines: List<TransitLine>?,
        selectedGtfsLines: List<GtfsLineSelection>?,
    ): GameSummary = createGameResult.getOrThrow()

    override suspend fun searchAreas(query: String): List<AreaInfo> = emptyList()

    override suspend fun discoverTransitLines(areas: List<AreaInfo>, routeTypes: List<String>?): List<TransitLine> =
        discoverTransitLinesResult.getOrThrow()

    var boundaryPreviewCalls = 0

    override suspend fun boundaryPreview(areas: List<AreaInfo>): String {
        boundaryPreviewCalls++
        return boundaryPreviewResult.getOrThrow()
    }

    var transitLinePreviewOsmIds: Set<String>? = null
    var transitLinePreviewCalls = 0

    override suspend fun transitLinePreview(osmIds: Set<String>): String {
        transitLinePreviewOsmIds = osmIds
        transitLinePreviewCalls++
        return transitLinePreviewResult.getOrThrow()
    }

    override suspend fun uploadGtfsFromUrl(url: String, name: String): GtfsSourceState =
        uploadGtfsFromUrlResult.getOrThrow()

    override suspend fun uploadGtfsFromFile(
        fileBytes: ByteArray,
        fileName: String,
        name: String,
    ): GtfsSourceState = uploadGtfsFromFileResult.getOrThrow()

    var getGameGate: CompletableDeferred<Unit>? = null
    var getGameCalls = 0

    override suspend fun getGame(gameUuid: String): GameSummary {
        getGameCalls++
        getGameGate?.await()
        return getGameResult.getOrThrow()
    }

    var getTransitOverlayResult: Result<String?> = Result.success(null)

    override suspend fun getTransitOverlay(gameUuid: String): String? = getTransitOverlayResult.getOrThrow()

    override suspend fun joinGame(gameUuid: String, displayName: String, password: String): JoinResult {
        lastJoinGameUuid = gameUuid
        lastJoinDisplayName = displayName
        lastPassword = password
        return joinGameResult.getOrThrow()
    }

    var removePlayerResult: Result<Unit> = Result.success(Unit)
    val removePlayerCalls = mutableListOf<Pair<String, String>>()

    override suspend fun removePlayer(gameUuid: String, playerUuid: String) {
        removePlayerCalls += gameUuid to playerUuid
        removePlayerResult.getOrThrow()
    }

    // Lets a test hold three concurrent refreshes inside the cache's single-flight window.
    var listPlayersGate: CompletableDeferred<Unit>? = null

    override suspend fun listPlayers(gameUuid: String): List<Player> {
        listPlayersCalls++
        listPlayersGate?.await()
        return listPlayersResult.getOrThrow()
    }

    override suspend fun leaderboard(gameUuid: String): List<LeaderboardEntry> {
        leaderboardCalls++
        return leaderboardResult.getOrThrow()
    }

    override suspend fun chooseTeam(roundUuid: String, playerUuid: String, side: Side): TeamResult =
        chooseTeamResult.getOrThrow()

    override suspend fun updateConfig(
        gameUuid: String,
        name: String?,
        size: GameSize?,
        edition: Edition?,
    ): GameSummary = GameSummary(
        gameUuid, name ?: "Berlin", size ?: GameSize.Medium, edition ?: Edition.Metric, "round-1",
    )

    var leaveGameCalled = false
    var deleteGameCalled = false

    override suspend fun leaveGame(gameUuid: String, playerUuid: String) {
        leaveGameCalled = true
    }

    override suspend fun deleteGame(gameUuid: String, playerUuid: String) {
        deleteGameCalled = true
    }
}
