package fr.gshz.hideandseek.core.data

import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import fr.gshz.hideandseek.core.model.AccountCredential
import fr.gshz.hideandseek.core.model.ConnectionConfig
import fr.gshz.hideandseek.core.security.CredentialCipher
import fr.gshz.hideandseek.di.AccountPasswordCipher
import fr.gshz.hideandseek.di.ApiKeyCipher
import fr.gshz.hideandseek.di.ConnectionDataStore
import javax.inject.Inject
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.map

class ConnectionStore @Inject constructor(
    @ConnectionDataStore private val dataStore: DataStore<Preferences>,
    @ApiKeyCipher private val apiKeyCipher: CredentialCipher,
    @AccountPasswordCipher private val accountPasswordCipher: CredentialCipher,
) {
    private object Keys {
        val API_URL = stringPreferencesKey("api_url")
        val API_KEY = stringPreferencesKey("api_key")
        val ACCOUNT_NAME = stringPreferencesKey("account_name")
        val ACCOUNT_PASSWORD = stringPreferencesKey("account_password")
    }

    val connectionConfig: Flow<ConnectionConfig?> = dataStore.data.map { prefs ->
        val apiUrl = prefs[Keys.API_URL]
        val apiKey = prefs[Keys.API_KEY]?.let(apiKeyCipher::decrypt)
        if (apiUrl != null && apiKey != null) ConnectionConfig(apiUrl, apiKey) else null
    }

    val accountCredential: Flow<AccountCredential?> = dataStore.data.map { prefs ->
        val name = prefs[Keys.ACCOUNT_NAME]
        val password = prefs[Keys.ACCOUNT_PASSWORD]?.let(accountPasswordCipher::decrypt)
        if (name != null && password != null) AccountCredential(name, password) else null
    }

    suspend fun current(): ConnectionConfig? = connectionConfig.first()

    suspend fun currentAccount(): AccountCredential? = accountCredential.first()

    suspend fun save(config: ConnectionConfig) {
        val encodedKey = apiKeyCipher.encrypt(config.apiKey) ?: return
        dataStore.edit { prefs ->
            prefs[Keys.API_URL] = config.apiUrl
            prefs[Keys.API_KEY] = encodedKey
        }
    }

    suspend fun save(credential: AccountCredential) {
        val encodedPassword = accountPasswordCipher.encrypt(credential.password) ?: return
        dataStore.edit { prefs ->
            prefs[Keys.ACCOUNT_NAME] = credential.name
            prefs[Keys.ACCOUNT_PASSWORD] = encodedPassword
        }
    }

    suspend fun clear() {
        dataStore.edit { it.clear() }
    }
}
