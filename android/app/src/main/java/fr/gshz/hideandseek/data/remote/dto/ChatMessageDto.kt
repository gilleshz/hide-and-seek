package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement

@Serializable
data class ChatMessageDto(
    val uuid: String,
    val senderUuid: String? = null,
    val senderName: String? = null,
    val type: String,
    val body: String? = null,
    val bodyKey: String? = null,
    val bodyArgs: JsonElement? = null,
    val imageRef: String? = null,
    val createdAt: String,
    val questionUuid: String? = null,
    val replyToUuid: String? = null,
    val deleted: Boolean = false,
)

@Serializable
data class PostChatMessageRequest(
    val playerUuid: String,
    val body: String,
    val replyToUuid: String? = null,
)

@Serializable
data class PostChatReadRequest(
    val playerUuid: String,
    val upToUuid: String,
)

@Serializable
data class ChatReadCursorDto(
    val playerUuid: String,
    val playerName: String,
    val readUpTo: String? = null,
)

@Serializable
data class ChatMessageReadDto(
    val playerUuid: String,
    val playerName: String,
    val readAt: String,
)
