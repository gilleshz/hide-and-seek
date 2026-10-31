package fr.gshz.hideandseek.core.notification

import fr.gshz.hideandseek.domain.repository.ChatEvent
import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

@Singleton
class ChatVisibilityTracker @Inject constructor() {

    private val _visibleChatGameUuid = MutableStateFlow<String?>(null)
    val visibleChatGameUuid: StateFlow<String?> = _visibleChatGameUuid

    private val _hasUnread = MutableStateFlow(false)
    val hasUnread: StateFlow<Boolean> = _hasUnread.asStateFlow()

    fun onChatVisible(gameUuid: String) {
        _visibleChatGameUuid.value = gameUuid
        _hasUnread.value = false
    }

    fun onChatHidden() {
        _visibleChatGameUuid.value = null
    }

    fun onChatEvent(event: ChatEvent, selfPlayerUuid: String, gameUuid: String) {
        val visible = _visibleChatGameUuid.value
        if (ChatNotificationText.shouldNotify(event, selfPlayerUuid, visible, gameUuid)) {
            _hasUnread.value = true
        }
    }
}
