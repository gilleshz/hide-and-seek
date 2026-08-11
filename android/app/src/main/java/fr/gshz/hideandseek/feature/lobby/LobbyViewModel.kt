package fr.gshz.hideandseek.feature.lobby

import android.util.Log
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.data.GameStateCache
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.core.model.PlayerSession
import fr.gshz.hideandseek.core.navigation.HideAndSeekDestinations
import fr.gshz.hideandseek.core.navigation.NavigationRequestStore
import fr.gshz.hideandseek.core.util.ERROR_KEY_PLAYER_LEFT
import fr.gshz.hideandseek.core.util.isSessionExpired
import fr.gshz.hideandseek.core.util.serverDetail
import fr.gshz.hideandseek.core.util.serverErrorArgs
import fr.gshz.hideandseek.core.util.serverErrorKey
import fr.gshz.hideandseek.core.util.toErrorType
import fr.gshz.hideandseek.domain.model.GameSummary
import fr.gshz.hideandseek.domain.model.Player
import fr.gshz.hideandseek.domain.model.RoundStatus
import fr.gshz.hideandseek.domain.model.Side
import fr.gshz.hideandseek.domain.repository.GameEventRepository
import fr.gshz.hideandseek.domain.repository.GameRepository
import fr.gshz.hideandseek.domain.repository.RoundRepository
import fr.gshz.hideandseek.domain.repository.SessionRepository
import java.io.IOException
import javax.inject.Inject
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.serialization.Serializable
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import retrofit2.HttpException

@Serializable
private data class QrPayload(val apiUrl: String, val apiKey: String, val joinCode: String)

@HiltViewModel
@Suppress("LongParameterList", "TooManyFunctions")
class LobbyViewModel @Inject constructor(
    private val gameRepository: GameRepository,
    private val sessionRepository: SessionRepository,
    private val roundRepository: RoundRepository,
    private val connectionStore: ConnectionStore,
    private val gameStateCache: GameStateCache,
    private val navigationRequestStore: NavigationRequestStore,
    gameEventRepository: GameEventRepository,
    savedStateHandle: SavedStateHandle,
) : ViewModel() {

    private val gameUuid: String = checkNotNull(savedStateHandle[HideAndSeekDestinations.LOBBY_ARG])

    private val _uiState = MutableStateFlow(LobbyUiState(gameUuid = gameUuid))
    val uiState: StateFlow<LobbyUiState> = _uiState.asStateFlow()

    init {
        load()
        // A timer event for a brand-new round means resync; otherwise another device just started or
        // stopped the current round, so swap this lobby's buttons.
        viewModelScope.launch {
            gameEventRepository.timerEvents.collect { event ->
                val session = sessionRepository.currentSession()
                if (event.roundUuid != null && session != null && event.roundUuid != session.roundUuid) {
                    load()
                } else {
                    RoundStatus.fromWireValueOrNull(event.status)?.let { status ->
                        _uiState.update { it.copy(roundStatus = status) }
                        if (status == RoundStatus.Ended) refreshLeaderboard()
                    }
                }
            }
        }
        viewModelScope.launch {
            gameEventRepository.rosterEvents.collect { event ->
                gameStateCache.setRoster(gameUuid, event.players)
                applyRoster(event.players)
            }
        }
        // An SSE gap can swallow a roster or timer event outright, so a fresh stream re-reads both.
        viewModelScope.launch {
            gameEventRepository.reconnectedEvents.collect { resync() }
        }
    }

    // Registering the observer replays ON_RESUME immediately, which would race init's load().
    fun onScreenResumed() {
        if (_uiState.value.isLoading) return
        viewModelScope.launch { resync() }
    }

    /**
     * The new-round timer event can die with the SSE stream, and this screen never re-inits while the map
     * is on top, so re-check here or every action keeps targeting the dead round.
     */
    private suspend fun resync() {
        val game = freshGameOrNull()
        val session = sessionRepository.currentSession()
        // The summary is handed on rather than re-read: the full reload wants the very one just fetched.
        if (game != null && session != null && game.roundUuid != session.roundUuid) {
            loadWith(game)
        } else {
            refreshRoster()
            refreshLeaderboard()
        }
    }

    private suspend fun freshGameOrNull(): GameSummary? = try {
        gameStateCache.refreshGameSummary(gameUuid)
    } catch (e: IOException) {
        Log.w(TAG, "Active-round check failed", e)
        null
    } catch (e: HttpException) {
        if (e.isSessionExpired()) navigationRequestStore.emitSessionExpired(e.serverErrorKey())
        Log.w(TAG, "Active-round check failed", e)
        null
    }

    private suspend fun refreshRoster() {
        try {
            applyRoster(gameStateCache.refreshRoster(gameUuid))
        } catch (e: IOException) {
            Log.w(TAG, "Roster refresh failed", e)
        } catch (e: HttpException) {
            if (e.isSessionExpired()) navigationRequestStore.emitSessionExpired(e.serverErrorKey())
            Log.w(TAG, "Roster refresh failed", e)
        }
    }

    private suspend fun applyRoster(roster: List<Player>) {
        val session = sessionRepository.currentSession()
        // The player's own row vanishing means the host removed them; self-leave/delete skip via their flags.
        if (session != null && roster.none { it.uuid == session.playerUuid } && !ownExitInProgress()) {
            navigationRequestStore.emitSessionExpired(ERROR_KEY_PLAYER_LEFT)
            return
        }
        _uiState.update {
            it.copy(
                roster = roster,
                isHost = session?.playerUuid != null && roster.firstOrNull()?.uuid == session.playerUuid,
            )
        }
    }

    private fun ownExitInProgress(): Boolean = _uiState.value.isLeaving || _uiState.value.isDeleting

    // Secondary read: a failed leaderboard must leave the roster and the error fields untouched.
    private suspend fun refreshLeaderboard() {
        try {
            val entries = gameRepository.leaderboard(gameUuid)
            _uiState.update { it.copy(leaderboard = entries) }
        } catch (e: IOException) {
            Log.w(TAG, "Leaderboard refresh failed", e)
        } catch (e: HttpException) {
            if (e.isSessionExpired()) navigationRequestStore.emitSessionExpired(e.serverErrorKey())
            Log.w(TAG, "Leaderboard refresh failed", e)
        }
    }

    fun load() {
        viewModelScope.launch { loadWith(null) }
    }

    @Suppress("LongMethod")
    private suspend fun loadWith(knownGame: GameSummary?) {
        _uiState.update { it.copy(isLoading = true, error = null, errorDetail = null) }
        try {
            // Self-heal needs the live roundUuid: bypass the cache here and warm it for Map/Chat.
            val game = knownGame ?: gameStateCache.refreshGameSummary(gameUuid)
            val roster = gameStateCache.refreshRoster(gameUuid)
            val session = reconcileSession(game.roundUuid, roster)
            val conn = connectionStore.current()
            val roundStatus = session?.let { roundRepository.getRound(it.roundUuid).status }
            val qr = if (conn != null && game.joinCode != null) {
                try {
                    Json.encodeToString(QrPayload(conn.apiUrl, conn.apiKey, game.joinCode))
                } catch (_: Exception) {
                    null
                }
            } else null
            _uiState.update {
                it.copy(
                    isLoading = false,
                    gameJoinCode = game.joinCode,
                    qrPayload = qr,
                    gameName = game.name,
                    gameSize = game.size,
                    gameEdition = game.edition,
                    roster = roster,
                    mySide = session?.side?.let(Side::fromWireValue),
                    roundStatus = roundStatus,
                    hasBoundary = game.boundarySet,
                    isHost = session?.playerUuid != null &&
                        roster.firstOrNull()?.uuid == session.playerUuid,
                    hidingTimeMinutesInput = it.hidingTimeMinutesInput.ifBlank {
                        game.defaultHidingPeriodMinutes?.toString().orEmpty()
                    },
                )
            }
        } catch (e: IOException) {
            Log.w(TAG, "Lobby request failed", e)
            _uiState.update { it.copy(isLoading = false, error = ErrorType.Network) }
        } catch (e: HttpException) {
            if (e.isSessionExpired()) navigationRequestStore.emitSessionExpired(e.serverErrorKey())
            Log.w(TAG, "Lobby request failed", e)
            _uiState.update {
                it.copy(
                    isLoading = false,
                    error = e.toErrorType(),
                    errorDetail = e.serverDetail(),
                    errorKey = e.serverErrorKey(),
                    errorArgs = e.serverErrorArgs(),
                )
            }
        }
        refreshLeaderboard()
    }

    // A device that missed the round-change SSE event self-heals on lobby entry.
    private suspend fun reconcileSession(activeRoundUuid: String, roster: List<Player>): PlayerSession? {
        val session = sessionRepository.currentSession()
        if (session == null || session.roundUuid == activeRoundUuid) return session
        val seededSide = roster.firstOrNull { it.uuid == session.playerUuid }?.side
        return if (seededSide == null) {
            sessionRepository.updateRoundUuid(activeRoundUuid)
            sessionRepository.clearSide()
            session.copy(roundUuid = activeRoundUuid, side = null)
        } else {
            reconfirmSeededSide(session, activeRoundUuid, seededSide)
        }
    }

    // Re-confirming the seeded swap mints a fresh side-scoped token; wiping it left hiders untracked all round.
    private suspend fun reconfirmSeededSide(
        session: PlayerSession,
        activeRoundUuid: String,
        seededSide: Side,
    ): PlayerSession {
        val team = gameRepository.chooseTeam(activeRoundUuid, session.playerUuid, seededSide)
        sessionRepository.updateRoundUuid(activeRoundUuid)
        sessionRepository.updateSide(team.side, team.mercureToken, team.topics)
        return session.copy(
            roundUuid = activeRoundUuid,
            side = team.side.wireValue,
            mercureToken = team.mercureToken,
            topics = team.topics,
        )
    }

    fun onHidingTimeChanged(value: String) {
        val filtered = value.filter(Char::isDigit).take(HIDING_TIME_MAX_DIGITS)
        _uiState.update { it.copy(hidingTimeMinutesInput = filtered) }
    }

    fun startRound() {
        if (!_uiState.value.allPlayersChoseSide) return
        viewModelScope.launch {
            val session = sessionRepository.currentSession() ?: return@launch
            _uiState.update { it.copy(isStartingRound = true, error = null, errorDetail = null) }
            try {
                val round = roundRepository.startRound(session.roundUuid, _uiState.value.hidingTimeMinutes)
                _uiState.update { it.copy(isStartingRound = false, roundStatus = round.status) }
            } catch (e: IOException) {
                Log.w(TAG, "Start round failed", e)
                _uiState.update { it.copy(isStartingRound = false, error = ErrorType.Network) }
            } catch (e: HttpException) {
                if (e.isSessionExpired()) navigationRequestStore.emitSessionExpired(e.serverErrorKey())
                Log.w(TAG, "Start round failed", e)
                _uiState.update {
                    it.copy(
                        isStartingRound = false,
                        error = e.toErrorType(),
                        errorDetail = e.serverDetail(),
                        errorKey = e.serverErrorKey(),
                        errorArgs = e.serverErrorArgs(),
                    )
                }
            }
        }
    }

    fun chooseSide(side: Side) {
        viewModelScope.launch {
            val session = sessionRepository.currentSession() ?: return@launch
            _uiState.update { it.copy(isLoading = true, error = null, errorDetail = null) }
            try {
                val team = gameRepository.chooseTeam(session.roundUuid, session.playerUuid, side)
                sessionRepository.updateSide(side, team.mercureToken, team.topics)
                val roster = gameStateCache.refreshRoster(gameUuid)
                _uiState.update { it.copy(isLoading = false, mySide = side, roster = roster) }
            } catch (e: IOException) {
                Log.w(TAG, "Lobby request failed", e)
                _uiState.update { it.copy(isLoading = false, error = ErrorType.Network) }
            } catch (e: HttpException) {
                if (e.isSessionExpired()) navigationRequestStore.emitSessionExpired(e.serverErrorKey())
                Log.w(TAG, "Lobby request failed", e)
                _uiState.update {
                    it.copy(
                        isLoading = false,
                        error = e.toErrorType(),
                        errorDetail = e.serverDetail(),
                        errorKey = e.serverErrorKey(),
                        errorArgs = e.serverErrorArgs(),
                    )
                }
            }
        }
    }

    fun createNewRound() {
        viewModelScope.launch {
            _uiState.update { it.copy(isCreatingRound = true, error = null, errorDetail = null) }
            try {
                val round = roundRepository.createRound(gameUuid)
                gameStateCache.invalidateGame()
                val roster = gameStateCache.refreshRoster(gameUuid)
                val session = reconcileSession(round.roundUuid, roster)
                _uiState.update {
                    it.copy(
                        isCreatingRound = false,
                        roundStatus = round.status,
                        mySide = session?.side?.let(Side::fromWireValue),
                        roster = roster,
                    )
                }
            } catch (e: IOException) {
                Log.w(TAG, "Create round failed", e)
                _uiState.update { it.copy(isCreatingRound = false, error = ErrorType.Network) }
            } catch (e: HttpException) {
                if (e.isSessionExpired()) navigationRequestStore.emitSessionExpired(e.serverErrorKey())
                Log.w(TAG, "Create round failed", e)
                _uiState.update {
                    it.copy(
                        isCreatingRound = false,
                        error = e.toErrorType(),
                        errorDetail = e.serverDetail(),
                        errorKey = e.serverErrorKey(),
                        errorArgs = e.serverErrorArgs(),
                    )
                }
            }
        }
    }

    fun stopRound() {
        viewModelScope.launch {
            val session = sessionRepository.currentSession() ?: return@launch
            _uiState.update { it.copy(isStoppingRound = true, error = null, errorDetail = null) }
            try {
                val round = roundRepository.stopRound(session.roundUuid)
                _uiState.update { it.copy(isStoppingRound = false, roundStatus = round.status) }
            } catch (e: IOException) {
                Log.w(TAG, "Stop round failed", e)
                _uiState.update { it.copy(isStoppingRound = false, error = ErrorType.Network) }
            } catch (e: HttpException) {
                if (e.isSessionExpired()) navigationRequestStore.emitSessionExpired(e.serverErrorKey())
                Log.w(TAG, "Stop round failed", e)
                _uiState.update {
                    it.copy(
                        isStoppingRound = false,
                        error = e.toErrorType(),
                        errorDetail = e.serverDetail(),
                        errorKey = e.serverErrorKey(),
                        errorArgs = e.serverErrorArgs(),
                    )
                }
            }
        }
    }

    @Suppress("TooGenericExceptionCaught")
    fun leaveGame() {
        viewModelScope.launch {
            val session = sessionRepository.currentSession() ?: return@launch
            _uiState.update { it.copy(isLeaving = true, error = null, errorDetail = null) }
            try {
                gameRepository.leaveGame(gameUuid, session.playerUuid)
                gameStateCache.invalidateGame()
                gameStateCache.invalidateRoster()
            } catch (e: IOException) {
                Log.w(TAG, "Leave game request failed, clearing local state anyway", e)
            } catch (e: HttpException) {
                if (e.isSessionExpired()) navigationRequestStore.emitSessionExpired(e.serverErrorKey())
                Log.w(TAG, "Leave game request failed, clearing local state anyway", e)
            } catch (e: Exception) {
                Log.e(TAG, "Leave game request crashed, clearing local state anyway", e)
            }
            sessionRepository.clear()
            _uiState.update { it.copy(isLeaving = false, navigatedHome = true) }
        }
    }

    @Suppress("TooGenericExceptionCaught")
    fun deleteGame() {
        viewModelScope.launch {
            val session = sessionRepository.currentSession() ?: return@launch
            _uiState.update { it.copy(isDeleting = true, error = null, errorDetail = null) }
            try {
                gameRepository.deleteGame(gameUuid, session.playerUuid)
                gameStateCache.invalidateGame()
                gameStateCache.invalidateRoster()
                sessionRepository.clear()
                _uiState.update { it.copy(isDeleting = false, navigatedHome = true) }
            } catch (e: IOException) {
                Log.w(TAG, "Delete game failed", e)
                _uiState.update { it.copy(isDeleting = false, error = ErrorType.Network) }
            } catch (e: HttpException) {
                if (e.isSessionExpired()) navigationRequestStore.emitSessionExpired(e.serverErrorKey())
                Log.w(TAG, "Delete game failed", e)
                _uiState.update {
                    it.copy(
                        isDeleting = false,
                        error = e.toErrorType(),
                        errorDetail = e.serverDetail(),
                        errorKey = e.serverErrorKey(),
                        errorArgs = e.serverErrorArgs(),
                    )
                }
            } catch (e: Exception) {
                Log.e(TAG, "Delete game crashed", e)
                _uiState.update { it.copy(isDeleting = false, error = ErrorType.Unknown) }
            }
        }
    }

    fun onNavigatedHome() {
        _uiState.update { it.copy(navigatedHome = false) }
    }

    fun onPlayerLongPress(player: Player) {
        if (!_uiState.value.isHost) return
        _uiState.update { it.copy(playerMenuTarget = player) }
    }

    fun dismissPlayerMenu() {
        _uiState.update { it.copy(playerMenuTarget = null) }
    }

    fun onRemovePlayerClick(player: Player) {
        if (!_uiState.value.isHost || _uiState.value.isRemovingPlayer) return
        _uiState.update {
            it.copy(
                removeConfirmTarget = player,
                removePlayerError = null,
                removePlayerErrorKey = null,
                playerMenuTarget = null,
            )
        }
    }

    fun dismissRemovePlayerConfirm() {
        _uiState.update {
            it.copy(removeConfirmTarget = null, removePlayerError = null, removePlayerErrorKey = null)
        }
    }

    fun confirmRemovePlayer() {
        val state = _uiState.value
        val target = state.removeConfirmTarget ?: return
        if (state.isRemovingPlayer) return
        viewModelScope.launch {
            _uiState.update { it.copy(isRemovingPlayer = true, removePlayerError = null, removePlayerErrorKey = null) }
            try {
                gameRepository.removePlayer(gameUuid, target.uuid)
                _uiState.update {
                    it.copy(isRemovingPlayer = false, removeConfirmTarget = null)
                }
                refreshRoster()
                refreshLeaderboard()
            } catch (e: IOException) {
                Log.w(TAG, "Remove player failed", e)
                _uiState.update { it.copy(isRemovingPlayer = false, removePlayerError = ErrorType.Network) }
            } catch (e: HttpException) {
                if (e.isSessionExpired()) navigationRequestStore.emitSessionExpired(e.serverErrorKey())
                _uiState.update {
                    it.copy(
                        isRemovingPlayer = false,
                        removePlayerError = e.toErrorType(),
                        removePlayerErrorKey = e.serverErrorKey(),
                    )
                }
            }
        }
    }

    private companion object {
        const val TAG = "LobbyViewModel"
        const val HIDING_TIME_MAX_DIGITS = 4
    }
}
