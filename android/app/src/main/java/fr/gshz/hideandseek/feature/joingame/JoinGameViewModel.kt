package fr.gshz.hideandseek.feature.joingame

import android.util.Log
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.model.AccountCredential
import fr.gshz.hideandseek.core.model.ConnectionConfig
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.core.model.PlayerSession
import fr.gshz.hideandseek.core.model.withoutLocationTopics
import fr.gshz.hideandseek.core.navigation.HideAndSeekDestinations
import fr.gshz.hideandseek.core.util.ERROR_KEY_JOIN_PASSWORD_INVALID
import fr.gshz.hideandseek.core.util.ERROR_KEY_JOIN_PASSWORD_REQUIRED
import fr.gshz.hideandseek.core.util.serverDetail
import fr.gshz.hideandseek.core.util.serverErrorArgs
import fr.gshz.hideandseek.core.util.serverErrorKey
import fr.gshz.hideandseek.core.util.toErrorType
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
    }

    fun onGameKeyChange(value: String) {
        _uiState.update { it.copy(gameKey = value, error = null) }
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
        if (state.gameKey.isBlank()) {
            _uiState.update { it.copy(error = ErrorType.Validation) }
            return
        }

        viewModelScope.launch {
            _uiState.update {
                it.copy(isLoading = true, error = null, errorDetail = null, errorKey = null, errorArgs = null)
            }
            val account = connectionStore.currentAccount()
            if (account == null) {
                _uiState.update { it.copy(isLoading = false, needsAccount = true) }
                return@launch
            }
            performJoin(state.gameKey.trim(), account)
        }
    }

    private suspend fun performJoin(gameKey: String, account: AccountCredential) {
        try {
            val join = gameRepository.joinGame(gameKey, account.name, account.password)
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
            val errorKey = e.serverErrorKey()
            if (errorKey == ERROR_KEY_JOIN_PASSWORD_REQUIRED || errorKey == ERROR_KEY_JOIN_PASSWORD_INVALID) {
                _uiState.update { it.copy(isLoading = false, needsAccount = true, needAccountErrorKey = errorKey) }
            } else {
                _uiState.update {
                    it.copy(
                        isLoading = false,
                        error = e.toErrorType(),
                        errorDetail = e.serverDetail(),
                        errorKey = errorKey,
                        errorArgs = e.serverErrorArgs(),
                    )
                }
            }
        }
    }

    private companion object {
        const val TAG = "JoinGameViewModel"
    }
}
