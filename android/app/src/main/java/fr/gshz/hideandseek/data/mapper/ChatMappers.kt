package fr.gshz.hideandseek.data.mapper

import fr.gshz.hideandseek.core.security.CredentialCipher
import fr.gshz.hideandseek.data.local.CachedMessage
import fr.gshz.hideandseek.data.remote.dto.ChatMessageDto
import fr.gshz.hideandseek.data.remote.dto.ChatMessageReadDto
import fr.gshz.hideandseek.data.remote.dto.ChatReadCursorDto
import fr.gshz.hideandseek.domain.model.ChatMessage
import fr.gshz.hideandseek.domain.model.ChatMessageReader
import fr.gshz.hideandseek.domain.model.ChatReadCursor
import java.net.URLDecoder
import java.net.URLEncoder
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive

fun ChatMessageDto.toDomain() = ChatMessage(
    uuid = uuid,
    senderUuid = senderUuid,
    senderName = senderName,
    type = type,
    body = body,
    bodyKey = bodyKey,
    bodyArgs = (bodyArgs as? JsonObject)?.mapValues { (_, v) -> (v as? JsonPrimitive)?.content ?: v.toString() },
    imageRef = imageRef,
    createdAt = createdAt,
    questionUuid = questionUuid,
    replyToUuid = replyToUuid,
    deleted = deleted,
)

fun CachedMessage.toDomain(chatCipher: CredentialCipher) = ChatMessage(
    uuid = uuid,
    senderUuid = senderUuid,
    senderName = senderName,
    type = type,
    body = body?.let(chatCipher::decrypt),
    bodyKey = bodyKey,
    bodyArgs = bodyArgs?.let(chatCipher::decrypt)?.let { args ->
        args.split("&").associate { pair ->
            val parts = pair.split("=", limit = 2)
            URLDecoder.decode(parts[0], "UTF-8") to URLDecoder.decode(parts.getOrElse(1) { "" }, "UTF-8")
        }
    },
    imageRef = imageRef,
    createdAt = createdAt,
    questionUuid = questionUuid,
    replyToUuid = replyToUuid,
    deleted = deleted,
)

fun ChatMessage.toEntity(gameUuid: String, chatCipher: CredentialCipher): CachedMessage? {
    // An encrypt failure drops the message from the cache instead of persisting a NULL body.
    val encryptedBody = body?.let(chatCipher::encrypt)
    val encodedArgs = bodyArgs?.takeIf { it.isNotEmpty() }?.let { args ->
        args.entries.joinToString("&") { (k, v) ->
            "${URLEncoder.encode(k, "UTF-8")}=${URLEncoder.encode(v, "UTF-8")}"
        }.let(chatCipher::encrypt)
    }
    val encryptFailed = (body != null && encryptedBody == null) ||
        (bodyArgs?.isNotEmpty() == true && encodedArgs == null)
    return if (encryptFailed) {
        null
    } else {
        CachedMessage(
            uuid = uuid,
            gameUuid = gameUuid,
            senderUuid = senderUuid,
            senderName = senderName,
            type = type,
            body = encryptedBody,
            bodyKey = bodyKey,
            bodyArgs = encodedArgs,
            imageRef = imageRef,
            createdAt = createdAt,
            questionUuid = questionUuid,
            replyToUuid = replyToUuid,
            deleted = deleted,
        )
    }
}

fun ChatReadCursorDto.toDomain() = ChatReadCursor(
    playerUuid = playerUuid,
    playerName = playerName,
    readUpTo = readUpTo,
)

fun ChatMessageReadDto.toDomain() = ChatMessageReader(
    playerUuid = playerUuid,
    playerName = playerName,
    readAt = readAt,
)
