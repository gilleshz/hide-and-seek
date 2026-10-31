package fr.gshz.hideandseek.core.notification

import fr.gshz.hideandseek.domain.repository.ChatEvent
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test

class ChatVisibilityTrackerTest {

    @Test
    fun `starts with no visible chat`() {
        assertNull(ChatVisibilityTracker().visibleChatGameUuid.value)
    }

    @Test
    fun `visible sets the game uuid and hidden clears it`() {
        val tracker = ChatVisibilityTracker()

        tracker.onChatVisible("game-1")
        assertEquals("game-1", tracker.visibleChatGameUuid.value)

        tracker.onChatHidden()
        assertNull(tracker.visibleChatGameUuid.value)
    }

    @Test
    fun `a newly visible chat replaces the previous one`() {
        val tracker = ChatVisibilityTracker()

        tracker.onChatVisible("game-1")
        tracker.onChatVisible("game-2")
        assertEquals("game-2", tracker.visibleChatGameUuid.value)
    }

    @Test
    fun `a message from someone else marks the chat unread`() {
        val tracker = ChatVisibilityTracker()

        tracker.onChatEvent(message(senderUuid = "player-2"), selfPlayerUuid = "player-1", gameUuid = "game-1")

        assertTrue(tracker.hasUnread.value)
    }

    @Test
    fun `my own message does not mark the chat unread`() {
        val tracker = ChatVisibilityTracker()

        tracker.onChatEvent(message(senderUuid = "player-1"), selfPlayerUuid = "player-1", gameUuid = "game-1")

        assertFalse(tracker.hasUnread.value)
    }

    @Test
    fun `a system message does not mark the chat unread`() {
        val tracker = ChatVisibilityTracker()

        tracker.onChatEvent(
            message(senderUuid = null, messageType = "system"),
            selfPlayerUuid = "player-1",
            gameUuid = "game-1",
        )

        assertFalse(tracker.hasUnread.value)
    }

    @Test
    fun `a message arriving while the chat is visible does not mark it unread`() {
        val tracker = ChatVisibilityTracker()
        tracker.onChatVisible("game-1")

        tracker.onChatEvent(message(senderUuid = "player-2"), selfPlayerUuid = "player-1", gameUuid = "game-1")

        assertFalse(tracker.hasUnread.value)
    }

    @Test
    fun `opening the chat clears the unread mark`() {
        val tracker = ChatVisibilityTracker()
        tracker.onChatEvent(message(senderUuid = "player-2"), selfPlayerUuid = "player-1", gameUuid = "game-1")

        tracker.onChatVisible("game-1")

        assertFalse(tracker.hasUnread.value)
    }

    private fun message(senderUuid: String?, messageType: String = "text") = ChatEvent(
        uuid = "message-1",
        senderUuid = senderUuid,
        messageType = messageType,
        body = "hello",
        imageRef = null,
        createdAt = "2026-10-31T10:00:00Z",
    )
}
