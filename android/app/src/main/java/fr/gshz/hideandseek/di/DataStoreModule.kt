package fr.gshz.hideandseek.di

import android.content.Context
import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.preferencesDataStore
import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.android.qualifiers.ApplicationContext
import dagger.hilt.components.SingletonComponent
import javax.inject.Singleton

private val Context.connectionDataStore by preferencesDataStore(name = "connection")
private val Context.sessionDataStore by preferencesDataStore(name = "session")
private val Context.settingsDataStore by preferencesDataStore(name = "settings")

@Module
@InstallIn(SingletonComponent::class)
object DataStoreModule {

    @Provides
    @Singleton
    @ConnectionDataStore
    fun provideConnectionDataStore(@ApplicationContext context: Context): DataStore<Preferences> =
        context.connectionDataStore

    @Provides
    @Singleton
    @SessionDataStore
    fun provideSessionDataStore(@ApplicationContext context: Context): DataStore<Preferences> =
        context.sessionDataStore

    @Provides
    @Singleton
    @SettingsDataStore
    fun provideSettingsDataStore(@ApplicationContext context: Context): DataStore<Preferences> =
        context.settingsDataStore
}
