package fr.gshz.hideandseek.data.mapper

import fr.gshz.hideandseek.data.remote.dto.ChatMessageDto
import fr.gshz.hideandseek.data.remote.dto.PostChatMessageRequest
import fr.gshz.hideandseek.domain.model.ChatMessage
import fr.gshz.hideandseek.fake.FakeCredentialCipher
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test

class ChatMappersTest {

    private val json = Json { ignoreUnknownKeys = true }

    @Test
    fun `a payload without question fields decodes with null defaults`() {
        val dto = json.decodeFromString<ChatMessageDto>(
            """{"uuid":"msg-1","type":"text","body":"hello","createdAt":"2026-07-16T10:00:00Z"}""",
        )

        val message = dto.toDomain()

        assertNull(message.questionUuid)
        assertNull(message.replyToUuid)
        assertTrue(message.isText)
        assertFalse(message.isQuestion)
    }

    @Test
    fun `a question payload maps questionUuid and replyToUuid to the domain`() {
        val dto = json.decodeFromString<ChatMessageDto>(
            """
            {"uuid":"msg-2","senderUuid":"player-1","type":"answer","body":"Hotter",
             "createdAt":"2026-07-16T10:05:00Z","questionUuid":"q-1","replyToUuid":"msg-1"}
            """.trimIndent(),
        )

        val message = dto.toDomain()

        assertEquals("q-1", message.questionUuid)
        assertEquals("msg-1", message.replyToUuid)
        assertTrue(message.isAnswer)
    }

    @Test
    fun `history carries the sender name the server stamped on the message`() {
        val dto = json.decodeFromString<ChatMessageDto>(
            """
            {"uuid":"msg-4","senderUuid":"player-9","senderName":"Carol","type":"text",
             "body":"Just got here","createdAt":"2026-07-16T10:10:00Z"}
            """.trimIndent(),
        )

        assertEquals("Carol", dto.toDomain().senderName)
    }

    @Test
    fun `a payload without a sender name decodes to null`() {
        val dto = json.decodeFromString<ChatMessageDto>(
            """{"uuid":"msg-5","type":"system","bodyKey":"system.player_left","createdAt":"2026-07-16T10:11:00Z"}""",
        )

        assertNull(dto.toDomain().senderName)
    }

    @Test
    fun `the message type helpers recognize the new wire values`() {
        val base = ChatMessageDto(
            uuid = "msg-3",
            senderUuid = "player-1",
            type = "question",
            body = "Are you within 500 m of me?",
            imageRef = null,
            createdAt = "2026-07-16T10:00:00Z",
        )

        assertTrue(base.toDomain().isQuestion)
        assertTrue(base.copy(type = "question_info").toDomain().isQuestionInfo)
        assertTrue(base.copy(type = "answer").toDomain().isAnswer)
        assertFalse(base.copy(type = "system").toDomain().isQuestion)
    }

    private fun message(
        uuid: String = "msg-1",
        body: String? = "hello",
        bodyArgs: Map<String, String>? = null,
    ) = ChatMessage(
        uuid = uuid,
        senderUuid = "player-1",
        senderName = "Alice",
        type = "text",
        body = body,
        bodyArgs = bodyArgs,
        imageRef = null,
        createdAt = "2026-07-16T10:00:00Z",
    )

    @Test
    fun `the entity round-trip encrypts body and body args and restores them`() {
        val cipher = FakeCredentialCipher()
        val original = message(bodyArgs = mapOf("answer" to "yes"))

        val entity = checkNotNull(original.toEntity("game-1", cipher))

        assertEquals("enc:hello", entity.body)
        assertTrue(entity.bodyArgs.orEmpty().startsWith("enc:"))
        assertEquals(original, entity.toDomain(cipher))
    }

    @Test
    fun `the entity fields a query needs stay in plaintext`() {
        val entity = checkNotNull(message().toEntity("game-1", FakeCredentialCipher()))

        assertEquals("msg-1", entity.uuid)
        assertEquals("game-1", entity.gameUuid)
        assertEquals("player-1", entity.senderUuid)
        assertEquals("Alice", entity.senderName)
        assertEquals("text", entity.type)
        assertEquals("2026-07-16T10:00:00Z", entity.createdAt)
    }

    @Test
    fun `a decrypt failure reads as null body and args`() {
        val cipher = FakeCredentialCipher()
        val entity = checkNotNull(message(body = "hello").toEntity("game-1", cipher))
        cipher.failureMode = true

        val restored = entity.toDomain(cipher)

        assertNull(restored.body)
        assertNull(restored.bodyArgs)
    }

    @Test
    fun `an encrypt failure on write drops the message from the cache instead of storing NULL`() {
        val cipher = FakeCredentialCipher()
        cipher.failureMode = true

        val entity = message(body = "hello").toEntity("game-1", cipher)

        assertNull(entity)
    }

    @Test
    fun `an empty body args map stores no args and reads back as null`() {
        val cipher = FakeCredentialCipher()
        val original = message(bodyArgs = emptyMap())

        val entity = checkNotNull(original.toEntity("game-1", cipher))
        val restored = entity.toDomain(cipher)

        assertNull(entity.bodyArgs)
        assertNull(restored.bodyArgs)
    }

    @Test
    fun `PostChatMessageRequest with replyToUuid serializes the field`() {
        val json = Json
        val request = PostChatMessageRequest(
            playerUuid = "player-1",
            body = "Hello",
            replyToUuid = "msg-42",
        )
        val encoded = json.encodeToString(PostChatMessageRequest.serializer(), request)

        assertTrue(encoded.contains(""""replyToUuid":"msg-42""""))
    }

    @Test
    fun `PostChatMessageRequest without replyToUuid omits the field`() {
        val json = Json
        val request = PostChatMessageRequest(
            playerUuid = "player-1",
            body = "Hello",
        )
        val encoded = json.encodeToString(PostChatMessageRequest.serializer(), request)

        assertFalse(encoded.contains("replyToUuid"))
    }
}
