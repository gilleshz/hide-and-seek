package fr.gshz.hideandseek.data.local

import androidx.room.Database
import androidx.room.RoomDatabase

@Database(entities = [CachedMessage::class], version = 1)
abstract class HideAndSeekDatabase : RoomDatabase() {
    abstract fun cachedMessageDao(): CachedMessageDao
}
