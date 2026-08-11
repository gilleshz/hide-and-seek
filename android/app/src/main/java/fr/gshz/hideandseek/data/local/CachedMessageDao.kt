package fr.gshz.hideandseek.data.local

import androidx.room.Dao
import androidx.room.Query
import androidx.room.Transaction
import androidx.room.Upsert

@Dao
interface CachedMessageDao {

    @Upsert
    suspend fun upsert(messages: List<CachedMessage>)

    @Query("SELECT * FROM cached_messages WHERE game_uuid = :gameUuid ORDER BY created_at ASC")
    suspend fun getByGameUuid(gameUuid: String): List<CachedMessage>

    @Query("DELETE FROM cached_messages WHERE game_uuid = :gameUuid")
    suspend fun deleteByGameUuid(gameUuid: String)

    // Mirrors the server-side scrub: the cache must not keep content the sender retracted.
    @Query(
        """
        UPDATE cached_messages
        SET deleted = 1, body = NULL, body_key = NULL, body_args = NULL, image_ref = NULL
        WHERE uuid = :uuid
        """,
    )
    suspend fun markDeleted(uuid: String)

    // Atomic swap: an SSE message cached mid-refresh must not be erased between delete and upsert.
    @Transaction
    suspend fun replaceForGame(gameUuid: String, messages: List<CachedMessage>) {
        deleteByGameUuid(gameUuid)
        upsert(messages)
    }
}
