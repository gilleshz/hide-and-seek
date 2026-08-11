package fr.gshz.hideandseek.feature.chat

import fr.gshz.hideandseek.domain.model.ChatMessage
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Test

class ChatUiStateReceiptsTest {

    private val roster = mapOf("p1" to "Alice", "p2" to "Bob", "p3" to "Carol")

    private fun ownMessage(createdAt: String = "2026-07-05T12:00:00Z") = ChatMessage(
        uuid = "m-1",
        senderUuid = "p1",
        type = "text",
        body = "Where are you?",
        imageRef = null,
        createdAt = createdAt,
    )

    private fun stateWith(cursors: Map<String, String>) = ChatUiState(
        messages = listOf(ownMessage()),
        roster = roster,
        selfPlayerUuid = "p1",
        readCursors = cursors,
    )

    @Test
    fun `a message nobody has read is only sent`() {
        assertEquals(ReadState.Sent, stateWith(emptyMap()).readStateOf(ownMessage()))
    }

    @Test
    fun `a cursor older than the message does not count as read`() {
        val state = stateWith(mapOf("p2" to "2026-07-05T11:59:00Z"))
        assertEquals(ReadState.Sent, state.readStateOf(ownMessage()))
    }

    @Test
    fun `one reader out of two recipients reads as partially read`() {
        val state = stateWith(mapOf("p2" to "2026-07-05T12:01:00Z"))
        assertEquals(ReadState.SomeRead, state.readStateOf(ownMessage()))
        assertEquals(listOf("p2"), state.readerUuidsOf(ownMessage()))
    }

    @Test
    fun `every recipient having read it reads as fully read`() {
        val state = stateWith(
            mapOf("p2" to "2026-07-05T12:01:00Z", "p3" to "2026-07-05T12:30:00Z"),
        )
        assertEquals(ReadState.AllRead, state.readStateOf(ownMessage()))
    }

    @Test
    fun `the sender's own cursor is never counted as a reader`() {
        val state = stateWith(
            mapOf("p1" to "2026-07-05T13:00:00Z", "p2" to "2026-07-05T12:01:00Z"),
        )
        assertEquals(listOf("p2"), state.readerUuidsOf(ownMessage()))
        assertEquals(ReadState.SomeRead, state.readStateOf(ownMessage()))
    }

    @Test
    fun `a cursor written in a different offset notation still compares by instant`() {
        val state = stateWith(mapOf("p2" to "2026-07-05T14:01:00+02:00"))
        assertEquals(ReadState.SomeRead, state.readStateOf(ownMessage()))
    }

    @Test
    fun `a departed player's cursor no longer counts towards read by everyone`() {
        val state = stateWith(mapOf("p4" to "2026-07-05T12:05:00Z"))

        assertEquals(emptyList<String>(), state.readerUuidsOf(ownMessage()))
        assertEquals(ReadState.Sent, state.readStateOf(ownMessage()))
    }

    @Test
    fun `pending recipients exclude the sender and everyone who read it`() {
        val state = stateWith(mapOf("p2" to "2026-07-05T12:01:00Z"))
        assertEquals(listOf("Carol"), state.unreadRecipientNames(ownMessage(), setOf("p2")))
    }
}
