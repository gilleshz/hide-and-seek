package fr.gshz.hideandseek.core.notification

import fr.gshz.hideandseek.domain.repository.ChatEvent

object ChatNotificationText {

    sealed interface Line {
        data class Body(val text: String) : Line
        data object ImagePlaceholder : Line
        data object Skip : Line
    }

    // System messages are ambient game log: they belong in the chat, not on the lock screen.
    fun shouldNotify(
        event: ChatEvent,
        selfPlayerUuid: String,
        visibleChatGameUuid: String?,
        gameUuid: String,
    ): Boolean = event.senderUuid != selfPlayerUuid &&
        visibleChatGameUuid != gameUuid &&
        event.messageType != MESSAGE_TYPE_SYSTEM

    fun lineFor(event: ChatEvent, resolvedBody: String?): Line {
        val body = resolvedBody?.takeIf { it.isNotBlank() }
        return when {
            body != null -> Line.Body(body)
            event.messageType == MESSAGE_TYPE_IMAGE -> Line.ImagePlaceholder
            else -> Line.Skip
        }
    }

    private const val MESSAGE_TYPE_IMAGE = "image"
    private const val MESSAGE_TYPE_SYSTEM = "system"
}
