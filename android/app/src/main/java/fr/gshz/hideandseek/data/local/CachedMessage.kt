package fr.gshz.hideandseek.data.local

import androidx.room.ColumnInfo
import androidx.room.Entity
import androidx.room.Index
import androidx.room.PrimaryKey

@Entity(tableName = "cached_messages", indices = [Index(value = ["game_uuid"])])
data class CachedMessage(
    @PrimaryKey val uuid: String,
    @ColumnInfo(name = "game_uuid") val gameUuid: String,
    @ColumnInfo(name = "sender_uuid") val senderUuid: String?,
    @ColumnInfo(name = "sender_name") val senderName: String?,
    val type: String,
    val body: String?,
    @ColumnInfo(name = "body_key") val bodyKey: String?,
    @ColumnInfo(name = "body_args") val bodyArgs: String?,
    @ColumnInfo(name = "image_ref") val imageRef: String?,
    @ColumnInfo(name = "created_at") val createdAt: String,
    @ColumnInfo(name = "question_uuid") val questionUuid: String?,
    @ColumnInfo(name = "reply_to_uuid") val replyToUuid: String?,
    @ColumnInfo(name = "deleted", defaultValue = "0") val deleted: Boolean = false,
)
