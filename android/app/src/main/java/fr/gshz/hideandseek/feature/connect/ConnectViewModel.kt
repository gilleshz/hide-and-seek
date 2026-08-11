package fr.gshz.hideandseek.feature.connect

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import fr.gshz.hideandseek.core.model.AccountCredential
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

@Serializable
private data class AccountBody(val name: String, val password: String)

@Serializable
private data class ProblemBody(val errorKey: String? = null)

private val qrJson = Json { ignoreUnknownKeys = true }
private val problemJson = Json { ignoreUnknownKeys = true }

sealed interface ProbeResult {
    data object Connected : ProbeResult
    data object WrongKey : ProbeResult
    data object Unreachable : ProbeResult
}

sealed interface ConnectAttemptResult {
    data object Connected : ConnectAttemptResult
    data object WrongKey : ConnectAttemptResult
    data object Unreachable : ConnectAttemptResult
    /** The server rejected the account; [errorKey] comes from the problem document. */
    data class AccountError(val errorKey: String?) : ConnectAttemptResult
    /** 404 on /api/accounts: the server predates server-wide accounts. */
    data object ServerTooOld : ConnectAttemptResult
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

/** Creates-or-verifies the account: 2xx means the pair is accepted, 404 means a pre-account server. */
private suspend fun verifyAccount(
    baseUrl: String,
    apiKey: String,
    name: String,
    password: String,
): ConnectAttemptResult = withContext(Dispatchers.IO) {
        try {
            val url = URL("${baseUrl.trimEnd('/')}/api/accounts")
            val conn = (url.openConnection() as HttpURLConnection).apply {
                requestMethod = "POST"
                doOutput = true
                setRequestProperty("Content-Type", "application/json")
                setRequestProperty("X-API-Key", apiKey)
                connectTimeout = PROBE_TIMEOUT_MS
                readTimeout = PROBE_TIMEOUT_MS
                instanceFollowRedirects = false
            }
            conn.outputStream.use { stream ->
                stream.write(problemJson.encodeToString(AccountBody(name, password)).toByteArray())
            }
            val code = conn.responseCode
            val problem = if (code in HTTP_OK..HTTP_OK_RANGE_END) {
                null
            } else {
                conn.errorStream?.readBytes()?.decodeToString()
            }
            conn.disconnect()
            when {
                code in HTTP_OK..HTTP_OK_RANGE_END -> ConnectAttemptResult.Connected
                code == HTTP_NOT_FOUND -> ConnectAttemptResult.ServerTooOld
                code == HTTP_BAD_REQUEST || code == HTTP_UNAUTHORIZED || code == HTTP_FORBIDDEN ->
                    ConnectAttemptResult.AccountError(parseErrorKey(problem))
                else -> ConnectAttemptResult.Unreachable
            }
        } catch (_: Exception) {
            ConnectAttemptResult.Unreachable
        }
    }

private fun parseErrorKey(body: String?): String? =
    body?.let { runCatching { problemJson.decodeFromString<ProblemBody>(it) }.getOrNull()?.errorKey }

internal suspend fun attemptConnection(
    baseUrl: String,
    apiKey: String,
    name: String,
    password: String,
): ConnectAttemptResult = when (probeConnection(baseUrl, apiKey)) {
        ProbeResult.Connected -> verifyAccount(baseUrl, apiKey, name, password)
        ProbeResult.WrongKey -> ConnectAttemptResult.WrongKey
        ProbeResult.Unreachable -> ConnectAttemptResult.Unreachable
    }

private const val PROBE_TIMEOUT_MS = 5_000
private const val HTTP_OK = 200
private const val HTTP_OK_RANGE_END = 299
private const val HTTP_BAD_REQUEST = 400
private const val HTTP_UNAUTHORIZED = 401
private const val HTTP_FORBIDDEN = 403
private const val HTTP_NOT_FOUND = 404

@HiltViewModel
class ConnectViewModel @Inject constructor(
    private val connectionRepository: ConnectionRepository,
    savedStateHandle: SavedStateHandle,
) : ViewModel() {

    // Injectable seam so tests can stub the whole attempt without a live server.
    internal var connectAttempt: suspend (String, String, String, String) -> ConnectAttemptResult = ::attemptConnection

    private val _uiState = MutableStateFlow(ConnectUiState())
    val uiState: StateFlow<ConnectUiState> = _uiState.asStateFlow()

    init {
        savedStateHandle.get<String>(HideAndSeekDestinations.CONNECT_ERROR_KEY_ARG)
            ?.takeIf { it.isNotBlank() }
            ?.let { key -> _uiState.update { it.copy(error = ErrorType.Validation, errorKey = key) } }

        // Legacy update path: existing installs land here with a connection but no account stored.
        viewModelScope.launch {
            val connection = connectionRepository.observeConnection().first()
            val account = connectionRepository.accountCredential()
            _uiState.update {
                it.copy(
                    apiUrl = connection?.apiUrl ?: it.apiUrl,
                    apiKey = connection?.apiKey ?: it.apiKey,
                    displayName = account?.name ?: it.displayName,
                )
            }
        }
    }

    fun onApiUrlChange(value: String) {
        _uiState.update { it.copy(apiUrl = value, error = null, errorKey = null) }
    }

    fun onApiKeyChange(value: String) {
        _uiState.update { it.copy(apiKey = value, error = null, errorKey = null) }
    }

    fun onDisplayNameChange(value: String) {
        _uiState.update { it.copy(displayName = value, error = null, errorKey = null) }
    }

    fun onPasswordChange(value: String) {
        _uiState.update { it.copy(passwordInput = value.take(PASSWORD_MAX_LENGTH), error = null, errorKey = null) }
    }

    fun connect() {
        val state = _uiState.value
        val url = state.apiUrl.trim()
        val key = state.apiKey.trim()
        val name = state.displayName.trim()
        val password = state.passwordInput

        if (listOf(url, key, name, password).any { it.isBlank() }) {
            _uiState.update { it.copy(error = ErrorType.Validation, errorKey = null) }
            return
        }

        performConnect(url, key, name, password, scannedGameCode = null)
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
        val state = _uiState.value
        val name = state.displayName.trim()
        val password = state.passwordInput
        val code = payload.joinCode?.trim()?.uppercase()?.ifBlank { null }
        if (listOf(url, key, name, password).any { it.isBlank() }) {
            _uiState.update { it.copy(error = ErrorType.Validation, errorKey = null) }
            return
        }

        performConnect(url, key, name, password, scannedGameCode = code)
    }

    private fun performConnect(url: String, key: String, name: String, password: String, scannedGameCode: String?) {
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
            when (val result = connectAttempt(normalized, key, name, password)) {
                ConnectAttemptResult.Connected -> onAttemptSuccess(normalized, key, name, password, scannedGameCode)
                ConnectAttemptResult.WrongKey -> onAttemptError(ErrorType.Unauthorized)
                ConnectAttemptResult.Unreachable -> onAttemptError(ErrorType.Network)
                is ConnectAttemptResult.AccountError -> onAttemptError(ErrorType.Unknown, result.errorKey)
                ConnectAttemptResult.ServerTooOld -> onAttemptError(ErrorType.Unknown, SERVER_TOO_OLD_KEY)
            }
        }
    }

    private suspend fun onAttemptSuccess(
        normalized: String,
        key: String,
        name: String,
        password: String,
        scannedGameCode: String?,
    ) {
        connectionRepository.connect(ConnectionConfig(apiUrl = normalized, apiKey = key))
        connectionRepository.saveAccount(AccountCredential(name = name.trim(), password = password))
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
        const val PASSWORD_MAX_LENGTH = 64
        const val SERVER_TOO_OLD_KEY = "connect.server_too_old"
    }
}
