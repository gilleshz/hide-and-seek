package fr.gshz.hideandseek.core.notification

import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow

@Singleton
class ChatVisibilityTracker @Inject constructor() {

    private val _visibleChatGameUuid = MutableStateFlow<String?>(null)
    val visibleChatGameUuid: StateFlow<String?> = _visibleChatGameUuid

    fun onChatVisible(gameUuid: String) {
        _visibleChatGameUuid.value = gameUuid
    }

    fun onChatHidden() {
        _visibleChatGameUuid.value = null
    }
}
