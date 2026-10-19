package fr.gshz.hideandseek.feature.connect

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import fr.gshz.hideandseek.core.model.ConnectionConfig
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.core.navigation.HideAndSeekDestinations
import fr.gshz.hideandseek.domain.repository.ConnectionRepository
import java.net.HttpURLConnection
import java.net.URL
import javax.inject.Inject
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json

@Serializable
private data class QrConnectPayload(val apiUrl: String, val apiKey: String, val joinCode: String? = null)

private val qrJson = Json { ignoreUnknownKeys = true }

sealed interface ProbeResult {
    data object Connected : ProbeResult
    data object WrongKey : ProbeResult
    data object Unreachable : ProbeResult
}

/**
 * Probes a real key-gated endpoint so a 401 no longer reads as "connected".
 * Raw HttpURLConnection on purpose: the probe never carries the subscriber token.
 */
internal suspend fun probeConnection(baseUrl: String, apiKey: String): ProbeResult =
    withContext(Dispatchers.IO) {
        try {
            val url = URL("${baseUrl.trimEnd('/')}/api/client-config")
            val conn = (url.openConnection() as HttpURLConnection).apply {
                requestMethod = "GET"
                setRequestProperty("X-API-Key", apiKey)
                connectTimeout = PROBE_TIMEOUT_MS
                readTimeout = PROBE_TIMEOUT_MS
                instanceFollowRedirects = false
            }
            val code = conn.responseCode
            conn.disconnect()
            when {
                code in HTTP_OK..HTTP_OK_RANGE_END -> ProbeResult.Connected
                code == HTTP_UNAUTHORIZED || code == HTTP_FORBIDDEN -> ProbeResult.WrongKey
                else -> ProbeResult.Unreachable
            }
        } catch (_: Exception) {
            ProbeResult.Unreachable
        }
    }

private const val PROBE_TIMEOUT_MS = 5_000
private const val HTTP_OK = 200
private const val HTTP_OK_RANGE_END = 299
private const val HTTP_UNAUTHORIZED = 401
private const val HTTP_FORBIDDEN = 403

@HiltViewModel
class ConnectViewModel @Inject constructor(
    private val connectionRepository: ConnectionRepository,
    savedStateHandle: SavedStateHandle,
) : ViewModel() {

    // Injectable seam so tests can stub the whole attempt without a live server.
    internal var connectAttempt: suspend (String, String) -> ProbeResult = ::probeConnection

    private val _uiState = MutableStateFlow(ConnectUiState())
    val uiState: StateFlow<ConnectUiState> = _uiState.asStateFlow()

    init {
        savedStateHandle.get<String>(HideAndSeekDestinations.CONNECT_ERROR_KEY_ARG)
            ?.takeIf { it.isNotBlank() }
            ?.let { key -> _uiState.update { it.copy(error = ErrorType.Validation, errorKey = key) } }

        savedStateHandle.get<String>(HideAndSeekDestinations.CONNECT_JOIN_CODE_ARG)
            ?.takeIf { it.isNotBlank() }
            ?.let { code -> _uiState.update { it.copy(pendingJoinCode = code) } }

        // Legacy update path: existing installs land here with a connection but no player name.
        viewModelScope.launch {
            val connection = connectionRepository.observeConnection().first()
            val name = connectionRepository.playerName()
            _uiState.update {
                it.copy(
                    apiUrl = connection?.apiUrl ?: it.apiUrl,
                    apiKey = connection?.apiKey ?: it.apiKey,
                    displayName = name ?: it.displayName,
                )
            }
        }
    }

    fun onApiUrlChange(value: String) {
        _uiState.update {
            it.copy(
                apiUrl = value,
                isPlainHttp = value.trim().startsWith("http://"),
                error = null,
                errorKey = null,
            )
        }
    }

    fun onApiKeyChange(value: String) {
        _uiState.update { it.copy(apiKey = value, error = null, errorKey = null) }
    }

    fun onDisplayNameChange(value: String) {
        _uiState.update { it.copy(displayName = value.take(NAME_MAX_LENGTH), error = null, errorKey = null) }
    }

    fun connect() {
        val state = _uiState.value
        val url = state.apiUrl.trim()
        val key = state.apiKey.trim()
        val name = state.displayName.trim()

        if (listOf(url, key, name).any { it.isBlank() } || name.length > NAME_MAX_LENGTH) {
            _uiState.update { it.copy(error = ErrorType.Validation, errorKey = null) }
            return
        }

        performConnect(url, key, name, scannedGameCode = state.pendingJoinCode)
    }

    fun onQrScanned(raw: String) {
        val payload = try {
            qrJson.decodeFromString<QrConnectPayload>(raw)
        } catch (_: IllegalArgumentException) {
            _uiState.update { it.copy(error = ErrorType.Validation, errorKey = null) }
            return
        }
        val url = payload.apiUrl.trim()
        val key = payload.apiKey.trim()
        val name = _uiState.value.displayName.trim()
        val code = payload.joinCode?.trim()?.uppercase()?.ifBlank { null }
        val isPlainHttp = url.lowercase().startsWith("http://")
        // A QR only carries the server and the game; the player name is the user's to type.
        _uiState.update {
            it.copy(
                apiUrl = url,
                apiKey = key,
                pendingJoinCode = code,
                isPlainHttp = isPlainHttp,
                error = null,
                errorKey = null,
            )
        }
        if (name.isBlank() || isPlainHttp) return

        performConnect(url, key, name, scannedGameCode = code)
    }

    private fun performConnect(url: String, key: String, name: String, scannedGameCode: String?) {
        val normalized = normalizedUrl(url)
        if (normalized == null) {
            _uiState.update { it.copy(error = ErrorType.Validation, errorKey = null) }
            return
        }
        viewModelScope.launch {
            val isPlainHttp = normalized.startsWith("http://")
            _uiState.update {
                it.copy(
                    apiUrl = normalized,
                    apiKey = key,
                    isConnecting = true,
                    isPlainHttp = isPlainHttp,
                    error = null,
                    errorKey = null,
                )
            }
            when (connectAttempt(normalized, key)) {
                ProbeResult.Connected -> onAttemptSuccess(normalized, key, name, scannedGameCode)
                ProbeResult.WrongKey -> onAttemptError(ErrorType.Unauthorized)
                ProbeResult.Unreachable -> onAttemptError(ErrorType.Network)
            }
        }
    }

    private suspend fun onAttemptSuccess(
        normalized: String,
        key: String,
        name: String,
        scannedGameCode: String?,
    ) {
        connectionRepository.connect(ConnectionConfig(apiUrl = normalized, apiKey = key))
        connectionRepository.savePlayerName(name)
        // A QR with a join code navigates to join, not to the home screen.
        _uiState.update {
            it.copy(
                isConnecting = false,
                connected = scannedGameCode == null,
                scannedGameCode = scannedGameCode,
            )
        }
    }

    private fun onAttemptError(error: ErrorType, errorKey: String? = null) {
        _uiState.update { it.copy(isConnecting = false, error = error, errorKey = errorKey) }
    }

    private fun normalizedUrl(raw: String): String? {
        val trimmed = raw.trim().trimEnd('/')
        if (trimmed.isBlank()) return null
        val scheme = runCatching { URL(trimmed).protocol }.getOrNull()?.lowercase()
        return if (scheme in setOf("http", "https")) trimmed else null
    }

    private companion object {
        const val NAME_MAX_LENGTH = 80
    }
}
