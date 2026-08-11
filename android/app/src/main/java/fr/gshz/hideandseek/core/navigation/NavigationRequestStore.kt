package fr.gshz.hideandseek.core.navigation

import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow

@Singleton
class NavigationRequestStore @Inject constructor() {

    private val _pendingChatGameUuid = MutableStateFlow<String?>(null)
    val pendingChatGameUuid: StateFlow<String?> = _pendingChatGameUuid

    private val _pendingTraceRequest = MutableStateFlow<TraceRequest?>(null)
    val pendingTraceRequest: StateFlow<TraceRequest?> = _pendingTraceRequest

    private val _sessionExpiredRequest = MutableStateFlow<String?>(null)
    val sessionExpiredRequest: StateFlow<String?> = _sessionExpiredRequest

    fun emitSessionExpired(errorKey: String? = null) {
        _sessionExpiredRequest.value = errorKey
    }

    fun consumeSessionExpired() {
        _sessionExpiredRequest.value = null
    }

    fun requestChat(gameUuid: String) {
        _pendingChatGameUuid.value = gameUuid
    }

    fun consumeChatRequest() {
        _pendingChatGameUuid.value = null
    }

    fun requestTrace(request: TraceRequest) {
        _pendingTraceRequest.value = request
    }

    fun consumeTraceRequest() {
        _pendingTraceRequest.value = null
    }
}
