package fr.gshz.hideandseek.core.notification

import fr.gshz.hideandseek.core.notification.ChatNotificationText.Line
import fr.gshz.hideandseek.domain.repository.ChatEvent
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test

class ChatNotificationTextTest {

    private fun chatEvent(
        senderUuid: String? = "p2",
        messageType: String = "text",
        body: String? = "hello",
        imageRef: String? = null,
    ) = ChatEvent(
        uuid = "m1",
        senderUuid = senderUuid,
        senderName = "Bob",
        messageType = messageType,
        body = body,
        imageRef = imageRef,
        createdAt = "2026-07-05T12:00:00Z",
    )

    @Test
    fun `own messages do not notify`() {
        assertFalse(ChatNotificationText.shouldNotify(chatEvent(senderUuid = "p1"), "p1", null, "game-1"))
    }

    @Test
    fun `system messages never notify`() {
        assertFalse(
            ChatNotificationText.shouldNotify(
                chatEvent(senderUuid = null, messageType = "system"),
                "p1",
                null,
                "game-1",
            ),
        )
    }

    @Test
    fun `every message type except system notifies when it comes from another player`() {
        val types = listOf("text", "image", "question", "answer", "question_info")
        types.forEach { type ->
            assertTrue(
                ChatNotificationText.shouldNotify(chatEvent(messageType = type), "p1", null, "game-1"),
                type,
            )
        }
    }

    @Test
    fun `suppressed while the chat for this game is visible`() {
        assertFalse(ChatNotificationText.shouldNotify(chatEvent(), "p1", "game-1", "game-1"))
    }

    @Test
    fun `not suppressed while another game's chat is visible`() {
        assertTrue(ChatNotificationText.shouldNotify(chatEvent(), "p1", "game-2", "game-1"))
    }

    @Test
    fun `image without caption becomes a placeholder line`() {
        val event = chatEvent(messageType = "image", body = null, imageRef = "ref")
        assertEquals(Line.ImagePlaceholder, ChatNotificationText.lineFor(event, resolvedBody = null))
    }

    @Test
    fun `image with caption uses the caption`() {
        val event = chatEvent(messageType = "image", body = "look", imageRef = "ref")
        assertEquals(Line.Body("look"), ChatNotificationText.lineFor(event, resolvedBody = "look"))
    }

    @Test
    fun `non-image without body is skipped`() {
        assertEquals(Line.Skip, ChatNotificationText.lineFor(chatEvent(body = null), resolvedBody = null))
    }

    @Test
    fun `non-image with blank body is skipped`() {
        assertEquals(Line.Skip, ChatNotificationText.lineFor(chatEvent(body = "   "), resolvedBody = "   "))
    }

    @Test
    fun `text with body becomes a body line`() {
        assertEquals(Line.Body("hello"), ChatNotificationText.lineFor(chatEvent(), resolvedBody = "hello"))
    }

    @Test
    fun `resolved key body is used for question notifications`() {
        val event = chatEvent(messageType = "question", body = null)
        assertEquals(Line.Body("Are you within 500m?"), ChatNotificationText.lineFor(event, "Are you within 500m?"))
    }
}
