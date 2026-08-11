package fr.gshz.hideandseek.di

import android.content.Context
import androidx.room.Room
import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.android.qualifiers.ApplicationContext
import dagger.hilt.components.SingletonComponent
import fr.gshz.hideandseek.data.local.CachedMessageDao
import fr.gshz.hideandseek.data.local.HideAndSeekDatabase
import javax.inject.Singleton

@Module
@InstallIn(SingletonComponent::class)
object DatabaseModule {

    @Provides
    @Singleton
    fun provideDatabase(@ApplicationContext context: Context): HideAndSeekDatabase =
        Room.databaseBuilder(context, HideAndSeekDatabase::class.java, "hideandseek.db")
            .build()

    @Provides
    fun provideCachedMessageDao(db: HideAndSeekDatabase): CachedMessageDao = db.cachedMessageDao()
}
