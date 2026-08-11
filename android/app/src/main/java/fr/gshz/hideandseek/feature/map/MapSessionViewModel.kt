package fr.gshz.hideandseek.feature.map

import android.util.Log
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.core.util.serverErrorArgs
import fr.gshz.hideandseek.core.util.serverErrorKey
import fr.gshz.hideandseek.core.util.toErrorType
import fr.gshz.hideandseek.domain.model.Edition
import fr.gshz.hideandseek.domain.model.GameSize
import fr.gshz.hideandseek.domain.model.GameSummary
import fr.gshz.hideandseek.domain.model.LocationUpdate
import fr.gshz.hideandseek.domain.model.Round
import fr.gshz.hideandseek.domain.model.RoundStatus
import fr.gshz.hideandseek.domain.model.ScoreDeclaration
import fr.gshz.hideandseek.domain.model.Side
import fr.gshz.hideandseek.domain.model.TransitLine
import fr.gshz.hideandseek.domain.repository.LocationRepository
import fr.gshz.hideandseek.domain.repository.RoundRepository
import fr.gshz.hideandseek.domain.repository.TimerEvent
import java.io.IOException
import java.time.OffsetDateTime
import java.time.format.DateTimeParseException
import javax.inject.Inject
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.Job
import kotlinx.coroutines.currentCoroutineContext
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asSharedFlow
import kotlinx.coroutines.flow.channelFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.drop
import kotlinx.coroutines.flow.flatMapLatest
import kotlinx.coroutines.flow.flow
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import retrofit2.HttpException

/**
 * The game's identity (name, edition, boundary, keys), the players on the map, the round clock and
 * the lobby navigation: everything one "game session" owns.
 */
@HiltViewModel
class MapSessionViewModel @Inject constructor(
    private val locationRepository: LocationRepository,
    private val roundRepository: RoundRepository,
    private val sessionEvents: MapSessionEvents,
    private val gameInfoSource: MapGameInfoSource,
    private val roundRefreshFlow: RoundRefreshFlow,
) : ViewModel() {

    private val gameUuid: String = sessionEvents.gameUuid

    private val gameInfo = MutableStateFlow(GameInfo())
    private val roster = MutableStateFlow<Map<String, String>>(emptyMap())
    private val selfPlayerUuid = MutableStateFlow<String?>(null)
    private val locations = MutableStateFlow<Map<String, LocationUpdate>>(emptyMap())
    private val rosterHealAttempted = mutableSetOf<String>()
    private val transitOverlayCache = mutableMapOf<String, String>()

    private val selfGps: StateFlow<ZonePin?> = locationRepository.lastKnownLocation
        .map { loc -> loc?.let { ZonePin(it.latitude, it.longitude) } }
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(STOP_TIMEOUT_MS), null)

    private val roundState = MutableStateFlow<Round?>(null)
    private val roundInteraction = MutableStateFlow(RoundInteraction())
    private val selectedMapStyle = MutableStateFlow(MapStyle.Standard)

    private var loadGameInfoJob: Job? = null

    /** A new round created elsewhere means this player must return to the lobby to pick a team. */
    private val _navigateToLobby = MutableSharedFlow<Unit>(replay = 0, extraBufferCapacity = 1)
    val navigateToLobby: SharedFlow<Unit> = _navigateToLobby.asSharedFlow()

    @OptIn(ExperimentalCoroutinesApi::class)
    private val round: StateFlow<Round?> = roundRefreshFlow.refresh.flatMapLatest {
        channelFlow {
            val roundUuid = sessionEvents.sessionRepository.currentSession()?.roundUuid
            // A rekey restarts this: the previous round's timer must not linger until the first poll lands.
            if (roundUuid != roundState.value?.roundUuid) roundState.value = null
            if (roundUuid != null) launch { pollRound(roundUuid) }
            roundState.collect { send(it) }
        }
    }.stateIn(viewModelScope, SharingStarted.WhileSubscribed(STOP_TIMEOUT_MS), null)

    private suspend fun pollRound(roundUuid: String) {
        while (currentCoroutineContext().isActive) {
            try {
                roundState.value = roundRepository.getRound(roundUuid)
            } catch (e: IOException) {
                Log.w(TAG, "Failed to poll round", e)
            } catch (e: HttpException) {
                sessionEvents.handleSessionExpiry(e)
                Log.w(TAG, "Failed to poll round", e)
            }
            delay(POLL_INTERVAL_MS)
        }
    }

    private val ticker: StateFlow<Long> = flow {
        while (currentCoroutineContext().isActive) {
            emit(System.currentTimeMillis())
            delay(TICK_INTERVAL_MS)
        }
    }.stateIn(viewModelScope, SharingStarted.WhileSubscribed(STOP_TIMEOUT_MS), System.currentTimeMillis())

    private data class PlayersPart(
        val info: GameInfo,
        val roster: Map<String, String>,
        val selfUuid: String?,
        val locations: Map<String, LocationUpdate>,
        val selfGps: ZonePin?,
    )

    private data class RoundPart(
        val round: Round?,
        val nowMillis: Long,
        val interaction: RoundInteraction,
    )

    val uiState: StateFlow<MapSessionUiState> = combine(
        combine(gameInfo, roster, selfPlayerUuid, locations, selfGps) { info, roster, self, locs, gps ->
            PlayersPart(info, roster, self, locs, gps)
        },
        combine(round, ticker, roundInteraction) { round, nowMillis, interaction ->
            RoundPart(round, nowMillis, interaction)
        },
        selectedMapStyle,
    ) { players, roundPart, style ->
        val info = players.info
        val currentRound = roundPart.round
        val nowMillis = roundPart.nowMillis
        MapSessionUiState(
            gameUuid = gameUuid,
            gameName = info.gameName,
            markers = buildMarkers(players.roster, players.selfUuid, players.locations, players.selfGps),
            side = info.side,
            edition = info.edition,
            gameSize = info.gameSize,
            boundary = info.boundary,
            boundaryGeoJson = info.boundaryGeoJson,
            transitOverlayGeoJson = info.transitOverlayGeoJson,
            stadiaApiKey = info.stadiaApiKey,
            thunderforestApiKey = info.thunderforestApiKey,
            maptilerApiKey = info.maptilerApiKey,
            mapStyleAvailable = info.mapStyleAvailable,
            selectedMapStyle = style,
            selectedTransitLines = info.selectedTransitLines,
            roster = players.roster,
            selfPlayerUuid = players.selfUuid,
            locations = players.locations,
            selfGps = players.selfGps,
            roundStatus = currentRound?.let { effectiveStatus(it, nowMillis) },
            roundTimerSeconds = currentRound?.let { timerSecondsFor(it, nowMillis) },
            isEndingRound = roundPart.interaction.isEnding,
            roundError = roundPart.interaction.error,
            roundErrorKey = roundPart.interaction.errorKey,
            roundErrorArgs = roundPart.interaction.errorArgs,
            roundHasHidingZone = currentRound?.hasHidingZone == true,
            inMovePeriod = currentRound?.inMovePeriod == true,
            seekersAreHunting = currentRound?.let { seekersAreHunting(it, nowMillis) } == true,
            hidingRadiusMeters = currentRound?.hidingRadiusMeters,
        )
    }.stateIn(viewModelScope, SharingStarted.WhileSubscribed(STOP_TIMEOUT_MS), MapSessionUiState())

    init {
        loadGameInfo()
        observeLocations()
        observeReconnects()
        observeSessionChanges()
        observeTimerEvents()
        observeMapStyle()
    }

    private fun loadGameInfo(refresh: Boolean = false) {
        loadGameInfoJob?.cancel()
        loadGameInfoJob = viewModelScope.launch {
            val session = sessionEvents.sessionRepository.currentSession()
            selfPlayerUuid.value = session?.playerUuid
            gameInfo.update { it.copy(side = session?.side?.let(Side::fromWireValue)) }
            try {
                val game = if (refresh) {
                    gameInfoSource.gameStateCache.refreshGameSummary(gameUuid)
                } else {
                    gameInfoSource.gameStateCache.getGameSummary(gameUuid)
                }
                if (session != null && game.roundUuid != session.roundUuid) {
                    _navigateToLobby.tryEmit(Unit)
                }
                roster.value = rosterNames(refresh)
                gameInfo.update {
                    it.copy(
                        gameName = game.name,
                        edition = game.edition,
                        gameSize = game.size,
                        boundary = game.toMapBounds(),
                        boundaryGeoJson = game.boundaryGeoJson,
                        selectedTransitLines = game.selectedTransitLines,
                    )
                }
                loadTransitOverlay(game.uuid, game.transitTilesPath)?.let { overlay ->
                    gameInfo.update { it.copy(transitOverlayGeoJson = overlay) }
                }
            } catch (e: IOException) {
                Log.w(TAG, "Failed to load game info", e)
            } catch (e: HttpException) {
                sessionEvents.handleSessionExpiry(e)
                Log.w(TAG, "Failed to load game info", e)
            } catch (e: IllegalStateException) {
                Log.w(TAG, "Not connected, skipping game info load", e)
            }

            loadClientConfig()
        }
    }

    private suspend fun loadTransitOverlay(gameUuid: String, transitTilesPath: String?): String? {
        val cached = transitOverlayCache[gameUuid]
        if (transitTilesPath == null || cached != null) return cached
        return try {
            gameInfoSource.gameRepository.getTransitOverlay(gameUuid)?.also { transitOverlayCache[gameUuid] = it }
        } catch (e: IOException) {
            Log.w(TAG, "Transit overlay unavailable", e)
            null
        } catch (e: HttpException) {
            sessionEvents.handleSessionExpiry(e)
            Log.w(TAG, "Transit overlay unavailable", e)
            null
        }
    }

    private suspend fun rosterNames(refresh: Boolean): Map<String, String> {
        val players = if (refresh) {
            gameInfoSource.gameStateCache.refreshRoster(gameUuid)
        } else {
            gameInfoSource.gameStateCache.getRoster(gameUuid)
        }
        return players.associate { it.uuid to it.displayName }
    }

    private suspend fun loadClientConfig() {
        try {
            val conn = gameInfoSource.connectionStore.current() ?: return
            val config = gameInfoSource.gameStateCache.getClientConfig(conn.apiUrl)
            gameInfo.update {
                it.copy(
                    stadiaApiKey = config.stadiaApiKey,
                    thunderforestApiKey = config.thunderforestApiKey,
                    maptilerApiKey = config.maptilerApiKey,
                    mapStyleAvailable = config.mapStyleAvailable,
                )
            }
            val defaultStyle = when {
                gameInfo.value.stadiaApiKey != null -> MapStyle.OsmBright
                gameInfo.value.thunderforestApiKey != null -> MapStyle.Atlas
                else -> MapStyle.Standard
            }
            selectedMapStyle.value = defaultStyle
        } catch (e: IOException) {
            Log.w(TAG, "Failed to load client config", e)
        } catch (e: HttpException) {
            sessionEvents.handleSessionExpiry(e)
            Log.w(TAG, "Failed to load client config", e)
        }
    }

    private fun observeLocations() {
        viewModelScope.launch {
            try {
                locationRepository.observeLocationUpdates().collect { update ->
                    locations.update { current ->
                        val existing = current[update.playerUuid]
                        if (existing == null || update.recordedAt >= existing.recordedAt) {
                            current + (update.playerUuid to update)
                        } else {
                            current
                        }
                    }
                    healRosterIfUnknown(update.playerUuid)
                }
            } catch (e: IOException) {
                Log.w(TAG, "Location stream failed", e)
            }
        }
    }

    /** A player who joined after our roster fetch would otherwise show as a raw UUID forever. */
    private suspend fun healRosterIfUnknown(playerUuid: String) {
        if (playerUuid in roster.value || !rosterHealAttempted.add(playerUuid)) return
        try {
            roster.value = rosterNames(refresh = true)
        } catch (e: IOException) {
            Log.w(TAG, "Roster refresh for unknown player failed", e)
        } catch (e: HttpException) {
            sessionEvents.handleSessionExpiry(e)
            Log.w(TAG, "Roster refresh for unknown player failed", e)
        }
    }

    private fun observeReconnects() {
        viewModelScope.launch {
            sessionEvents.gameEventRepository.reconnectedEvents.collect {
                Log.d(TAG, "SSE reconnected, reloading game info")
                loadGameInfo(refresh = true)
            }
        }
    }

    private fun observeSessionChanges() {
        viewModelScope.launch {
            var lastRoundUuid = sessionEvents.sessionRepository.currentSession()?.roundUuid
            sessionEvents.sessionRepository.observeSession()
                .map { session -> session?.let { it.roundUuid to it.side } }
                .distinctUntilChanged()
                .drop(1)
                .collect { roundAndSide ->
                    locations.value = emptyMap()
                    gameInfo.update { it.copy(side = roundAndSide?.second?.let(Side::fromWireValue)) }
                    if (roundAndSide?.first != lastRoundUuid) {
                        lastRoundUuid = roundAndSide?.first
                        roundRefreshFlow.refresh.update { it + 1 }
                    }
                }
        }
    }

    fun endRound(score: ScoreDeclaration) {
        viewModelScope.launch {
            val session = sessionEvents.sessionRepository.currentSession() ?: return@launch
            roundInteraction.update { it.copy(isEnding = true, error = null, errorKey = null, errorArgs = null) }
            try {
                roundRepository.stopRound(session.roundUuid, score)
                roundInteraction.update { it.copy(isEnding = false) }
                roundRefreshFlow.refresh.update { it + 1 }
            } catch (e: IOException) {
                Log.w(TAG, "Failed to end round", e)
                roundInteraction.update { it.copy(isEnding = false, error = ErrorType.Network) }
            } catch (e: HttpException) {
                sessionEvents.handleSessionExpiry(e)
                Log.w(TAG, "Failed to end round", e)
                roundInteraction.update {
                    it.copy(
                        isEnding = false,
                        error = e.toErrorType(),
                        errorKey = e.serverErrorKey(),
                        errorArgs = e.serverErrorArgs(),
                    )
                }
            }
        }
    }

    /** A timer event for a different round is a freshly-created round: leave the map to pick a team. */
    private fun observeTimerEvents() {
        viewModelScope.launch {
            sessionEvents.gameEventRepository.timerEvents.collect { event ->
                val session = sessionEvents.sessionRepository.currentSession()
                if (event.roundUuid != null && session != null && event.roundUuid != session.roundUuid) {
                    _navigateToLobby.tryEmit(Unit)
                } else {
                    roundState.update { current -> event.toRound(current) ?: current }
                }
            }
        }
    }

    fun setMapStyle(style: MapStyle) {
        selectedMapStyle.value = style
        viewModelScope.launch {
            try {
                sessionEvents.sessionRepository.saveMapStyle(style.styleKey)
            } catch (_: IOException) {
            }
        }
    }

    private fun observeMapStyle() {
        viewModelScope.launch {
            try {
                sessionEvents.sessionRepository.observeMapStyle().collect { style ->
                    if (style != null) {
                        val mapStyle = MapStyle.entries.find { it.styleKey == style } ?: MapStyle.Standard
                        selectedMapStyle.value = mapStyle
                    }
                }
            } catch (_: IOException) {
            }
        }
    }

    private data class GameInfo(
        val gameName: String = "",
        val edition: Edition = Edition.Metric,
        val gameSize: GameSize = GameSize.Small,
        val side: Side? = null,
        val boundary: MapBounds? = null,
        val transitOverlayGeoJson: String? = null,
        val boundaryGeoJson: String? = null,
        val stadiaApiKey: String? = null,
        val thunderforestApiKey: String? = null,
        val maptilerApiKey: String? = null,
        val mapStyleAvailable: Boolean = false,
        val selectedTransitLines: List<TransitLine> = emptyList(),
    )

    private data class RoundInteraction(
        val isEnding: Boolean = false,
        val error: ErrorType? = null,
        val errorKey: String? = null,
        val errorArgs: Map<String, String>? = null,
    )

    private companion object {
        const val TAG = "MapSessionViewModel"
        const val STOP_TIMEOUT_MS = 5_000L
        const val POLL_INTERVAL_MS = 60_000L
        const val TICK_INTERVAL_MS = 1_000L
    }
}

data class MapSessionUiState(
    val gameUuid: String = "",
    val gameName: String = "",
    val markers: List<PlayerMarker> = emptyList(),
    val side: Side? = null,
    val edition: Edition = Edition.Metric,
    val gameSize: GameSize = GameSize.Small,
    val boundary: MapBounds? = null,
    val boundaryGeoJson: String? = null,
    val transitOverlayGeoJson: String? = null,
    val stadiaApiKey: String? = null,
    val thunderforestApiKey: String? = null,
    val maptilerApiKey: String? = null,
    val mapStyleAvailable: Boolean = false,
    val selectedMapStyle: MapStyle = MapStyle.Standard,
    val selectedTransitLines: List<TransitLine> = emptyList(),
    val roster: Map<String, String> = emptyMap(),
    val selfPlayerUuid: String? = null,
    val locations: Map<String, LocationUpdate> = emptyMap(),
    val selfGps: ZonePin? = null,
    val roundStatus: RoundStatus? = null,
    val roundTimerSeconds: Long? = null,
    val isEndingRound: Boolean = false,
    val roundError: ErrorType? = null,
    val roundErrorKey: String? = null,
    val roundErrorArgs: Map<String, String>? = null,
    val roundHasHidingZone: Boolean = false,
    val inMovePeriod: Boolean = false,
    val seekersAreHunting: Boolean = false,
    val hidingRadiusMeters: Double? = null,
)

/**
 * Mirrors the backend's RoundClock: an elapsed hiding period is already seeking, whatever the
 * stored status says. Nothing publishes a phase change at expiry, so keying the phase off the
 * stored status would pin the countdown at zero until a poll happened to refresh it.
 */
private fun effectiveStatus(round: Round, nowMillis: Long): RoundStatus? = when (round.status) {
    RoundStatus.Hiding ->
        if (round.hidingPeriodEndsAtMillis?.let { nowMillis >= it } == true) {
            RoundStatus.Seeking
        } else {
            RoundStatus.Hiding
        }
    else -> round.status
}

/** A Move rewinds hidingPeriodEndsAt, so the seeking counter has to add back what it banked. */
private fun timerSecondsFor(round: Round, nowMillis: Long): Long? = when (effectiveStatus(round, nowMillis)) {
    RoundStatus.Hiding ->
        round.hidingPeriodEndsAtMillis?.let { ((it - nowMillis) / MILLIS_PER_SECOND).coerceAtLeast(0L) }
    RoundStatus.Seeking -> round.hidingPeriodEndsAtMillis?.let {
        round.bankedSeekingSeconds + ((nowMillis - it) / MILLIS_PER_SECOND).coerceAtLeast(0L)
    }
    RoundStatus.Ended -> (round.scoreSeconds ?: round.hidingTimeSeconds)?.toLong()
    RoundStatus.Lobby, null -> null
}

private fun seekersAreHunting(round: Round, nowMillis: Long): Boolean =
    effectiveStatus(round, nowMillis) == RoundStatus.Seeking

private const val MILLIS_PER_SECOND = 1_000L

/**
 * The timer event is the whole round, bar the bonus split that only the leaderboard reads: keeping the
 * previous values for those means applying an event never blanks something the poll had already fetched.
 */
private fun TimerEvent.toRound(previous: Round?): Round? {
    val uuid = roundUuid ?: previous?.roundUuid ?: return null
    return Round(
        roundUuid = uuid,
        status = RoundStatus.fromWireValueOrNull(status),
        hidingPeriodStartedAtMillis = hidingPeriodStartedAt?.toEpochMillisOrNull(),
        hidingPeriodEndsAtMillis = hidingPeriodEndsAt?.toEpochMillisOrNull(),
        seekingEndedAtMillis = seekingEndedAt?.toEpochMillisOrNull(),
        hidingTimeSeconds = hidingTimeSeconds,
        hidingRadiusMeters = hidingRadiusMeters,
        hasHidingZone = hasHidingZone,
        bankedSeekingSeconds = bankedSeekingSeconds,
        inMovePeriod = inMovePeriod,
        bonusMinutes = previous?.bonusMinutes ?: 0,
        bonusPercent = previous?.bonusPercent ?: 0,
        scoreSeconds = scoreSeconds,
    )
}

private fun String.toEpochMillisOrNull(): Long? = try {
    OffsetDateTime.parse(this).toInstant().toEpochMilli()
} catch (_: DateTimeParseException) {
    null
}

private fun GameSummary.toMapBounds(): MapBounds? {
    val sw = coordOrNull(boundarySwLat, boundarySwLng)
    val ne = coordOrNull(boundaryNeLat, boundaryNeLng)
    return if (sw != null && ne != null) MapBounds(sw.first, sw.second, ne.first, ne.second) else null
}

private fun coordOrNull(lat: Double?, lng: Double?): Pair<Double, Double>? =
    if (lat != null && lng != null) lat to lng else null
