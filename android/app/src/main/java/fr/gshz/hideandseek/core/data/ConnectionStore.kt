package fr.gshz.hideandseek.core.data

import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import fr.gshz.hideandseek.core.model.ConnectionConfig
import fr.gshz.hideandseek.core.security.CredentialCipher
import fr.gshz.hideandseek.di.ApiKeyCipher
import fr.gshz.hideandseek.di.ConnectionDataStore
import javax.inject.Inject
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.map

class ConnectionStore @Inject constructor(
    @ConnectionDataStore private val dataStore: DataStore<Preferences>,
    @ApiKeyCipher private val apiKeyCipher: CredentialCipher,
) {
    private object Keys {
        val API_URL = stringPreferencesKey("api_url")
        val API_KEY = stringPreferencesKey("api_key")

        // Kept as "account_name" so an existing install keeps its name across the identity change.
        val PLAYER_NAME = stringPreferencesKey("account_name")
    }

    @Volatile
    private var cachedConfig: ConnectionConfig? = null

    val connectionConfig: Flow<ConnectionConfig?> = dataStore.data.map { prefs ->
        val apiUrl = prefs[Keys.API_URL]
        val apiKey = prefs[Keys.API_KEY]?.let(apiKeyCipher::decrypt)
        if (apiUrl != null && apiKey != null) ConnectionConfig(apiUrl, apiKey) else null
    }

    val playerName: Flow<String?> = dataStore.data.map { it[Keys.PLAYER_NAME] }

    /** The request interceptor needs the just-saved key without waiting for the DataStore flow. */
    fun currentCached(): ConnectionConfig? = cachedConfig

    suspend fun current(): ConnectionConfig? = connectionConfig.first()

    suspend fun currentPlayerName(): String? = playerName.first()

    suspend fun save(config: ConnectionConfig) {
        val encodedKey = apiKeyCipher.encrypt(config.apiKey) ?: return
        dataStore.edit { prefs ->
            prefs[Keys.API_URL] = config.apiUrl
            prefs[Keys.API_KEY] = encodedKey
        }
        cachedConfig = config
    }

    suspend fun savePlayerName(name: String) {
        dataStore.edit { prefs -> prefs[Keys.PLAYER_NAME] = name }
    }

    suspend fun clear() {
        dataStore.edit { it.clear() }
        cachedConfig = null
    }

    /** One-time upgrade cleanup: the password is no longer read, never leave its ciphertext behind. */
    suspend fun clearLegacyPassword() {
        dataStore.edit { prefs -> prefs.remove(LEGACY_ACCOUNT_PASSWORD) }
    }

    private companion object {
        val LEGACY_ACCOUNT_PASSWORD = stringPreferencesKey("account_password")
    }
}
