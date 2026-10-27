package fr.gshz.hideandseek.core.data

import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.PreferenceDataStoreFactory
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.longPreferencesKey
import androidx.datastore.preferences.core.stringPreferencesKey
import fr.gshz.hideandseek.core.model.ConnectionConfig
import fr.gshz.hideandseek.fake.FakeCredentialCipher
import java.io.File
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.runTest
import org.junit.jupiter.api.AfterEach
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNotEquals
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.Test

@OptIn(ExperimentalCoroutinesApi::class)
class ConnectionStoreTest {

    private lateinit var tempFile: File
    private lateinit var dataStore: DataStore<Preferences>
    private lateinit var cipher: FakeCredentialCipher
    private lateinit var store: ConnectionStore

    @BeforeEach
    fun setUp() {
        tempFile = File.createTempFile("connection-store-test", ".preferences_pb")
        dataStore = PreferenceDataStoreFactory.create(scope = CoroutineScope(UnconfinedTestDispatcher())) {
            tempFile
        }
        cipher = FakeCredentialCipher()
        store = ConnectionStore(dataStore, cipher)
    }

    @AfterEach
    fun tearDown() {
        tempFile.delete()
    }

    @Test
    fun `save round-trips through the cipher`() = runTest {
        store.save(ConnectionConfig("https://api.example.com", "secret"))

        assertEquals(ConnectionConfig("https://api.example.com", "secret"), store.current())
    }

    @Test
    fun `the stored api key is ciphertext, not plaintext`() = runTest {
        store.save(ConnectionConfig("https://api.example.com", "secret"))

        val stored = dataStore.data.first()[stringPreferencesKey("api_key")]
        assertEquals("enc:secret", stored)
        assertNotEquals("secret", stored)
    }

    @Test
    fun `the api url stays in plaintext`() = runTest {
        store.save(ConnectionConfig("https://api.example.com", "secret"))

        assertEquals("https://api.example.com", dataStore.data.first()[stringPreferencesKey("api_url")])
    }

    @Test
    fun `a lost keystore key reads back as no connection`() = runTest {
        store.save(ConnectionConfig("https://api.example.com", "secret"))
        cipher.failureMode = true

        assertNull(store.current())
        assertNull(store.connectionConfig.first())
    }

    @Test
    fun `an encrypt failure aborts the save instead of wiping the working key`() = runTest {
        store.save(ConnectionConfig("https://api.example.com", "secret"))
        cipher.failureMode = true

        store.save(ConnectionConfig("https://other.example.com", "new-secret"))

        assertEquals("https://api.example.com", dataStore.data.first()[stringPreferencesKey("api_url")])
        assertEquals("enc:secret", dataStore.data.first()[stringPreferencesKey("api_key")])
    }

    @Test
    fun `clear wipes the stored connection`() = runTest {
        store.save(ConnectionConfig("https://api.example.com", "secret"))

        store.clear()

        assertNull(store.current())
    }

    @Test
    fun `save exposes the new key synchronously for the request interceptor`() = runTest {
        store.save(ConnectionConfig("https://api.example.com", "secret"))

        assertEquals("secret", store.currentCached()?.apiKey)

        store.clear()

        assertNull(store.currentCached())
    }

    @Test
    fun `the player name round-trips and stays plaintext`() = runTest {
        store.savePlayerName("Alice")

        assertEquals("Alice", store.currentPlayerName())
        assertEquals("Alice", store.playerName.first())
        assertEquals("Alice", dataStore.data.first()[stringPreferencesKey("account_name")])
    }

    @Test
    fun `saving a connection does not wipe the player name`() = runTest {
        store.savePlayerName("Alice")

        store.save(ConnectionConfig("https://api.example.com", "secret"))

        assertEquals("Alice", store.currentPlayerName())
    }

    @Test
    fun `clear wipes the player name too`() = runTest {
        store.savePlayerName("Alice")

        store.clear()

        assertNull(store.currentPlayerName())
        assertNull(store.playerName.first())
    }

    @Test
    fun `the last join game round-trips`() = runTest {
        store.saveLastJoinGame("CXUW37", "game-1")

        assertEquals(LastJoinGame("CXUW37", "game-1"), store.currentLastJoinGame())
        assertEquals(LastJoinGame("CXUW37", "game-1"), store.lastJoinGame.first())
    }

    @Test
    fun `a last join game older than seven days is not offered`() = runTest {
        dataStore.edit { prefs ->
            prefs[stringPreferencesKey("last_join_code")] = "CXUW37"
            prefs[stringPreferencesKey("last_join_game_uuid")] = "game-1"
            prefs[longPreferencesKey("last_join_code_at")] = 0L
        }

        assertNull(store.currentLastJoinGame())
        assertNull(store.lastJoinGame.first())
    }

    @Test
    fun `clear wipes the last join game too`() = runTest {
        store.saveLastJoinGame("CXUW37", "game-1")

        store.clear()

        assertNull(store.currentLastJoinGame())
    }

    @Test
    fun `clearing the last join game keeps the connection and player name`() = runTest {
        store.save(ConnectionConfig("https://api.example.com", "secret"))
        store.savePlayerName("Alice")
        store.saveLastJoinGame("CXUW37", "game-1")

        store.clearLastJoinGame()

        assertNull(store.currentLastJoinGame())
        assertEquals("Alice", store.currentPlayerName())
        assertEquals("https://api.example.com", store.current()?.apiUrl)
    }

    @Test
    fun `saving the same server keeps the last join game`() = runTest {
        store.save(ConnectionConfig("https://api.example.com", "secret"))
        store.saveLastJoinGame("CXUW37", "game-1")

        store.save(ConnectionConfig("https://api.example.com/", "new-secret"))

        assertEquals(LastJoinGame("CXUW37", "game-1"), store.currentLastJoinGame())
    }

    @Test
    fun `saving a different server drops the last join game`() = runTest {
        store.save(ConnectionConfig("https://api.example.com", "secret"))
        store.saveLastJoinGame("CXUW37", "game-1")

        store.save(ConnectionConfig("https://other.example.com", "secret"))

        assertNull(store.currentLastJoinGame())
    }

    @Test
    fun `legacy password cleanup keeps the player name and wipes the old secret`() = runTest {
        dataStore.edit { prefs ->
            prefs[stringPreferencesKey("account_name")] = "Alice"
            prefs[stringPreferencesKey("account_password")] = "enc:old-password"
        }

        store.clearLegacyPassword()

        val prefs = dataStore.data.first()
        assertEquals("Alice", prefs[stringPreferencesKey("account_name")])
        assertNull(prefs[stringPreferencesKey("account_password")])
    }
}
