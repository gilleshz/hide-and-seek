package fr.gshz.hideandseek.domain.repository

import fr.gshz.hideandseek.domain.model.ChatMessage
import fr.gshz.hideandseek.domain.model.ChatMessageReader
import fr.gshz.hideandseek.domain.model.ChatReadCursor

interface ChatRepository {
    suspend fun getChatMessages(gameUuid: String): List<ChatMessage>
    suspend fun postChatMessage(
        gameUuid: String,
        playerUuid: String,
        body: String,
        replyToUuid: String? = null,
    ): ChatMessage
    suspend fun postChatImage(
        gameUuid: String,
        playerUuid: String,
        imageUri: String,
        caption: String?,
        replyToUuid: String? = null,
    ): ChatMessage
    suspend fun cacheMessages(gameUuid: String, messages: List<ChatMessage>)
    suspend fun markChatRead(gameUuid: String, playerUuid: String, upToUuid: String): ChatReadCursor
    suspend fun getChatReadCursors(gameUuid: String): List<ChatReadCursor>
    suspend fun getChatMessageReaders(gameUuid: String, messageUuid: String): List<ChatMessageReader>
    suspend fun deleteChatMessage(gameUuid: String, playerUuid: String, messageUuid: String)
}
