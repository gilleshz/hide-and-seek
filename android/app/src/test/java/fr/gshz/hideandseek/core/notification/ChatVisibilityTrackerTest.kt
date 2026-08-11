package fr.gshz.hideandseek.core.notification

import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNull
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
}
