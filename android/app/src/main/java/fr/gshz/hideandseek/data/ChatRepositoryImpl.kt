package fr.gshz.hideandseek.data

import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.model.NotConnectedException
import fr.gshz.hideandseek.core.security.CredentialCipher
import fr.gshz.hideandseek.data.local.CachedMessageDao
import fr.gshz.hideandseek.data.mapper.toDomain
import fr.gshz.hideandseek.data.mapper.toEntity
import fr.gshz.hideandseek.data.remote.ImageParts
import fr.gshz.hideandseek.data.remote.HideAndSeekApi
import fr.gshz.hideandseek.data.remote.dto.PostChatMessageRequest
import fr.gshz.hideandseek.data.remote.dto.PostChatReadRequest
import fr.gshz.hideandseek.di.ChatCipher
import fr.gshz.hideandseek.domain.model.ChatMessage
import fr.gshz.hideandseek.domain.model.ChatMessageReader
import fr.gshz.hideandseek.domain.model.ChatReadCursor
import fr.gshz.hideandseek.domain.repository.ChatRepository
import java.io.IOException
import javax.inject.Inject

class ChatRepositoryImpl @Inject constructor(
    private val api: HideAndSeekApi,
    private val connectionStore: ConnectionStore,
    private val cachedMessageDao: CachedMessageDao,
    private val imageParts: ImageParts,
    @ChatCipher private val chatCipher: CredentialCipher,
) : ChatRepository {

    override suspend fun getChatMessages(gameUuid: String): List<ChatMessage> {
        val cached = cachedMessageDao.getByGameUuid(gameUuid).map { it.toDomain(chatCipher) }
        return try {
            val fresh = api.getChatMessages(urlFor("/api/games/$gameUuid/chat")).map { it.toDomain() }
            cachedMessageDao.replaceForGame(gameUuid, fresh.mapNotNull { it.toEntity(gameUuid, chatCipher) })
            fresh
        } catch (e: IOException) {
            // Offline fallback only: a 4xx (revoked key, deleted game) must still surface as an error.
            cached.ifEmpty { throw e }
        }
    }

    override suspend fun postChatMessage(
        gameUuid: String,
        playerUuid: String,
        body: String,
        replyToUuid: String?,
    ): ChatMessage {
        val result = api.postChatMessage(
            url = urlFor("/api/games/$gameUuid/chat"),
            body = PostChatMessageRequest(playerUuid = playerUuid, body = body, replyToUuid = replyToUuid),
        ).toDomain()
        cachedMessageDao.upsert(listOfNotNull(result.toEntity(gameUuid, chatCipher)))
        return result
    }

    override suspend fun cacheMessages(gameUuid: String, messages: List<ChatMessage>) {
        cachedMessageDao.upsert(messages.mapNotNull { it.toEntity(gameUuid, chatCipher) })
    }

    override suspend fun postChatImage(
        gameUuid: String,
        playerUuid: String,
        imageUri: String,
        caption: String?,
        replyToUuid: String?,
    ): ChatMessage {
        val result = api.postChatImage(
            url = urlFor("/api/games/$gameUuid/chat/image"),
            image = imageParts.image(imageUri),
            playerUuid = imageParts.text(playerUuid),
            caption = caption?.let { imageParts.text(it) },
            replyToUuid = replyToUuid?.let { imageParts.text(it) },
        ).toDomain()
        cachedMessageDao.upsert(listOfNotNull(result.toEntity(gameUuid, chatCipher)))
        return result
    }

    override suspend fun markChatRead(gameUuid: String, playerUuid: String, upToUuid: String): ChatReadCursor =
        api.postChatRead(
            url = urlFor("/api/games/$gameUuid/chat/read"),
            body = PostChatReadRequest(playerUuid = playerUuid, upToUuid = upToUuid),
        ).toDomain()

    override suspend fun getChatReadCursors(gameUuid: String): List<ChatReadCursor> =
        api.getChatReadCursors(urlFor("/api/games/$gameUuid/chat/read-cursors")).map { it.toDomain() }

    override suspend fun getChatMessageReaders(gameUuid: String, messageUuid: String): List<ChatMessageReader> =
        api.getChatMessageReads(urlFor("/api/games/$gameUuid/chat/$messageUuid/reads")).map { it.toDomain() }

    override suspend fun deleteChatMessage(gameUuid: String, playerUuid: String, messageUuid: String) {
        api.deleteChatMessage(urlFor("/api/games/$gameUuid/chat/$messageUuid"), playerUuid)
        cachedMessageDao.markDeleted(messageUuid)
    }

    private suspend fun urlFor(path: String): String =
        (connectionStore.current() ?: throw NotConnectedException()).apiUrl.trimEnd('/') + path

}
