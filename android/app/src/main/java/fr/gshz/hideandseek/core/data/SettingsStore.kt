package fr.gshz.hideandseek.core.data

import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.booleanPreferencesKey
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import fr.gshz.hideandseek.di.SettingsDataStore
import javax.inject.Inject
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.map

class SettingsStore @Inject constructor(
    @SettingsDataStore private val dataStore: DataStore<Preferences>,
) {
    private object Keys {
        val APP_LOCALE = stringPreferencesKey("app_locale")
        val THEME_MODE = stringPreferencesKey("theme_mode")
        val LOCATION_DISCLOSURE_ACCEPTED = booleanPreferencesKey("location_disclosure_accepted")
    }

    val locale: Flow<String?> = dataStore.data.map { prefs ->
        prefs[Keys.APP_LOCALE]
    }

    val themeMode: Flow<String> = dataStore.data.map { prefs ->
        prefs[Keys.THEME_MODE] ?: "system"
    }

    val locationDisclosureAccepted: Flow<Boolean> = dataStore.data.map { prefs ->
        prefs[Keys.LOCATION_DISCLOSURE_ACCEPTED] ?: false
    }

    suspend fun currentLocale(): String? = locale.first()

    suspend fun currentThemeMode(): String = themeMode.first()

    suspend fun saveLocale(tag: String) {
        dataStore.edit { prefs ->
            prefs[Keys.APP_LOCALE] = tag
        }
    }

    suspend fun clearLocale() {
        dataStore.edit { prefs ->
            prefs.remove(Keys.APP_LOCALE)
        }
    }

    suspend fun saveThemeMode(mode: String) {
        dataStore.edit { prefs ->
            prefs[Keys.THEME_MODE] = mode
        }
    }

    suspend fun saveLocationDisclosureAccepted() {
        dataStore.edit { prefs ->
            prefs[Keys.LOCATION_DISCLOSURE_ACCEPTED] = true
        }
    }
}
