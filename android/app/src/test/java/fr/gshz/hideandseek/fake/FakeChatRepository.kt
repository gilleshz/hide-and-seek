package fr.gshz.hideandseek.fake

import fr.gshz.hideandseek.domain.model.ChatMessage
import fr.gshz.hideandseek.domain.model.ChatMessageReader
import fr.gshz.hideandseek.domain.model.ChatReadCursor
import fr.gshz.hideandseek.domain.repository.ChatRepository

class FakeChatRepository : ChatRepository {
    var messages: MutableList<ChatMessage> = mutableListOf()
    var postResult: Result<ChatMessage>? = null
    var postImageResult: Result<ChatMessage>? = null
    val postedMessages = mutableListOf<String>()
    val postedReplyToUuids = mutableListOf<String?>()
    val postedCaptions = mutableListOf<String?>()
    val postedImageReplyToUuids = mutableListOf<String?>()
    val markedReadUpTo = mutableListOf<String>()
    val requestedReaderMessageUuids = mutableListOf<String>()
    var readUpToOnMark: String? = null
    var readCursors: MutableList<ChatReadCursor> = mutableListOf()
    var readersByMessage: MutableMap<String, List<ChatMessageReader>> = mutableMapOf()
    val deletedMessageUuids = mutableListOf<String>()
    val deletedByPlayerUuids = mutableListOf<String>()
    var deleteResult: Result<Unit>? = null

    override suspend fun getChatMessages(gameUuid: String): List<ChatMessage> = messages.toList()

    override suspend fun postChatMessage(
        gameUuid: String,
        playerUuid: String,
        body: String,
        replyToUuid: String?,
    ): ChatMessage {
        postedMessages += body
        postedReplyToUuids += replyToUuid
        val msg = postResult?.getOrThrow() ?: ChatMessage(
            uuid = "msg-${messages.size + 1}",
            senderUuid = playerUuid,
            type = "text",
            body = body,
            imageRef = null,
            createdAt = "2026-07-05T12:00:00Z",
            replyToUuid = replyToUuid,
        )
        messages.add(msg)
        return msg
    }

    override suspend fun postChatImage(
        gameUuid: String,
        playerUuid: String,
        imageUri: String,
        caption: String?,
        replyToUuid: String?,
    ): ChatMessage {
        postedCaptions += caption
        postedImageReplyToUuids += replyToUuid
        val msg = postImageResult?.getOrThrow() ?: ChatMessage(
            uuid = "img-${messages.size + 1}",
            senderUuid = playerUuid,
            type = "image",
            body = caption,
            imageRef = imageUri,
            createdAt = "2026-07-05T12:00:00Z",
            replyToUuid = replyToUuid,
        )
        messages.add(msg)
        return msg
    }

    override suspend fun cacheMessages(gameUuid: String, messages: List<ChatMessage>) = Unit

    override suspend fun markChatRead(gameUuid: String, playerUuid: String, upToUuid: String): ChatReadCursor {
        markedReadUpTo += upToUuid
        return ChatReadCursor(playerUuid = playerUuid, playerName = "Self", readUpTo = readUpToOnMark ?: upToUuid)
    }

    override suspend fun getChatReadCursors(gameUuid: String): List<ChatReadCursor> = readCursors.toList()

    override suspend fun getChatMessageReaders(gameUuid: String, messageUuid: String): List<ChatMessageReader> {
        requestedReaderMessageUuids += messageUuid
        return readersByMessage[messageUuid].orEmpty()
    }

    override suspend fun deleteChatMessage(gameUuid: String, playerUuid: String, messageUuid: String) {
        deleteResult?.getOrThrow()
        deletedMessageUuids += messageUuid
        deletedByPlayerUuids += playerUuid
    }
}
