package fr.gshz.hideandseek.feature.joingame

import android.util.Log
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.model.ConnectionConfig
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.core.model.PlayerSession
import fr.gshz.hideandseek.core.model.withoutLocationTopics
import fr.gshz.hideandseek.core.navigation.HideAndSeekDestinations
import fr.gshz.hideandseek.core.util.ERROR_KEY_GAME_NOT_FOUND
import fr.gshz.hideandseek.core.util.serverDetail
import fr.gshz.hideandseek.core.util.serverErrorArgs
import fr.gshz.hideandseek.core.util.serverErrorKey
import fr.gshz.hideandseek.core.util.toErrorType
import fr.gshz.hideandseek.domain.repository.AvatarRepository
import fr.gshz.hideandseek.domain.repository.GameRepository
import fr.gshz.hideandseek.domain.repository.SessionRepository
import java.io.IOException
import javax.inject.Inject
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json
import retrofit2.HttpException

@Serializable
private data class QrJoinPayload(val apiUrl: String, val apiKey: String, val joinCode: String)

@HiltViewModel
class JoinGameViewModel @Inject constructor(
    private val gameRepository: GameRepository,
    private val sessionRepository: SessionRepository,
    private val connectionStore: ConnectionStore,
    private val avatarRepository: AvatarRepository,
    savedStateHandle: SavedStateHandle,
) : ViewModel() {

    private val _uiState = MutableStateFlow(JoinGameUiState())
    val uiState: StateFlow<JoinGameUiState> = _uiState.asStateFlow()

    init {
        savedStateHandle.get<String>(HideAndSeekDestinations.JOIN_GAME_CODE_ARG)
            ?.takeIf { it.isNotBlank() }
            // A prefilled game UUID keeps its case (server matching is case-sensitive); only codes are uppercased.
            ?.let { code -> _uiState.update { it.copy(gameKey = if ('-' in code) code else code.uppercase()) } }
        savedStateHandle.get<String>(HideAndSeekDestinations.JOIN_GAME_ERROR_KEY_ARG)
            ?.takeIf { it.isNotBlank() }
            ?.let { key -> _uiState.update { it.copy(error = ErrorType.Validation, errorKey = key) } }
        viewModelScope.launch {
            if (connectionStore.currentPlayerName().isNullOrBlank()) {
                _uiState.update { it.copy(needsDisplayName = true) }
            }
        }
        viewModelScope.launch { loadLastGameSuggestion() }
    }

    fun onGameKeyChange(value: String) {
        _uiState.update { it.copy(gameKey = value, error = null) }
    }

    fun onDisplayNameChange(value: String) {
        _uiState.update {
            it.copy(displayName = value.take(NAME_MAX_LENGTH), error = null, errorKey = null)
        }
    }

    fun onQrScanned(raw: String) {
        viewModelScope.launch {
            try {
                val payload = Json.decodeFromString<QrJoinPayload>(raw)
                val gameKey = payload.joinCode.uppercase()
                connectionStore.save(ConnectionConfig(payload.apiUrl, payload.apiKey))
                _uiState.update { it.copy(gameKey = gameKey, error = null) }
            } catch (e: IllegalArgumentException) {
                Log.w(TAG, "Failed to parse QR code", e)
                _uiState.update { it.copy(error = ErrorType.Validation) }
            }
        }
    }

    fun join() {
        val state = _uiState.value
        if (state.isLoading) return
        if (state.gameKey.isBlank()) {
            _uiState.update { it.copy(error = ErrorType.Validation) }
            return
        }

        viewModelScope.launch {
            _uiState.update {
                it.copy(isLoading = true, error = null, errorDetail = null, errorKey = null, errorArgs = null)
            }
            val name = resolveDisplayName(state.displayName) ?: return@launch
            performJoin(state.gameKey.trim(), name)
        }
    }

    private suspend fun resolveDisplayName(entered: String): String? {
        connectionStore.currentPlayerName()?.takeIf { it.isNotBlank() }?.let { return it }
        _uiState.update { it.copy(needsDisplayName = true) }
        val typed = entered.trim()
        return if (typed.isBlank()) {
            _uiState.update { it.copy(isLoading = false, error = ErrorType.Validation) }
            null
        } else {
            bootstrapIdentity(typed)
        }
    }

    private suspend fun bootstrapIdentity(name: String): String? = try {
        val profile = avatarRepository.bootstrap(name)
        connectionStore.savePlayerName(profile.name)
        _uiState.update { it.copy(displayName = profile.name, needsDisplayName = false) }
        profile.name
    } catch (e: IOException) {
        Log.w(TAG, "Failed to create the player identity", e)
        _uiState.update { it.copy(isLoading = false, error = ErrorType.Network) }
        null
    } catch (e: HttpException) {
        Log.w(TAG, "Failed to create the player identity", e)
        failJoin(e)
        null
    }

    private suspend fun performJoin(gameKey: String, name: String) {
        try {
            val join = gameRepository.joinGame(gameKey, name)
            sessionRepository.saveSession(
                PlayerSession(
                    gameUuid = join.gameUuid,
                    roundUuid = join.roundUuid,
                    playerUuid = join.playerUuid,
                    displayName = join.displayName,
                    mercureToken = join.mercureToken,
                    side = null,
                    topics = join.topics.withoutLocationTopics(),
                ),
            )
            _uiState.update { it.copy(isLoading = false, joinedGameUuid = join.gameUuid) }
        } catch (e: IOException) {
            Log.w(TAG, "Failed to join game", e)
            _uiState.update { it.copy(isLoading = false, error = ErrorType.Network) }
        } catch (e: HttpException) {
            Log.w(TAG, "Failed to join game", e)
            failJoin(e)
            dropStaleSuggestion(e, gameKey)
        }
    }

    private suspend fun loadLastGameSuggestion() {
        val suggestion = connectionStore.currentLastJoinGame() ?: return
        if (gameExists(suggestion.gameUuid)) {
            _uiState.update { it.copy(lastGameCode = suggestion.joinCode) }
        } else {
            try {
                connectionStore.clearLastJoinGame()
            } catch (readError: IOException) {
                Log.w(TAG, "Failed to clear the stale join game", readError)
            }
        }
    }

    private suspend fun gameExists(gameUuid: String): Boolean = try {
        gameRepository.getGame(gameUuid)
        true
    } catch (e: IOException) {
        Log.w(TAG, "Last game check failed, keeping the suggestion", e)
        true
    } catch (e: HttpException) {
        val gone = e.code() == HTTP_NOT_FOUND
        if (!gone) {
            Log.w(TAG, "Last game check failed, keeping the suggestion", e)
        }
        !gone
    }

    private suspend fun dropStaleSuggestion(e: HttpException, attemptedCode: String) {
        val suggested = _uiState.value.lastGameCode
        if (suggested == null ||
            e.serverErrorKey() != ERROR_KEY_GAME_NOT_FOUND ||
            !attemptedCode.equals(suggested, ignoreCase = true)
        ) {
            return
        }
        try {
            connectionStore.clearLastJoinGame()
        } catch (readError: IOException) {
            Log.w(TAG, "Failed to clear the stale join game", readError)
        }
        _uiState.update { it.copy(lastGameCode = null) }
    }

    private fun failJoin(e: HttpException) {
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

    private companion object {
        const val TAG = "JoinGameViewModel"
        const val NAME_MAX_LENGTH = 80
        const val HTTP_NOT_FOUND = 404
    }
}
