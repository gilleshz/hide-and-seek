package fr.gshz.hideandseek.feature.chat

import fr.gshz.hideandseek.domain.model.ChatMessage
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Test

class ChatUiStateSenderNameTest {

    private val state = ChatUiState(roster = mapOf("p1" to "Alice"), selfPlayerUuid = "p1")

    private fun message(senderUuid: String?, senderName: String? = null) = ChatMessage(
        uuid = "m-1",
        senderUuid = senderUuid,
        senderName = senderName,
        type = if (senderUuid == null) "system" else "text",
        body = "Where are you?",
        imageRef = null,
        createdAt = "2026-07-05T12:00:00Z",
    )

    @Test
    fun `the roster wins over the name stamped on the message`() {
        assertEquals("Alice", state.senderNameOf(message("p1", senderName = "Alicia")))
    }

    @Test
    fun `a sender the roster has not caught up with falls back to the message's own name`() {
        assertEquals("Carol", state.senderNameOf(message("p3", senderName = "Carol")))
    }

    @Test
    fun `a sender with no name anywhere falls back to the raw uuid`() {
        assertEquals("p3", state.senderNameOf(message("p3")))
    }

    @Test
    fun `a message without a sender has no name at all`() {
        assertNull(state.senderNameOf(message(senderUuid = null)))
    }
}
