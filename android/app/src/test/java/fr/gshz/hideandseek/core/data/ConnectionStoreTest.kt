package fr.gshz.hideandseek.core.data

import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.PreferenceDataStoreFactory
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.stringPreferencesKey
import fr.gshz.hideandseek.core.model.AccountCredential
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
        store = ConnectionStore(dataStore, cipher, cipher)
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
    fun `account save round-trips through the cipher`() = runTest {
        store.save(AccountCredential("alice", "s3cret"))

        assertEquals(AccountCredential("alice", "s3cret"), store.currentAccount())
        assertEquals(AccountCredential("alice", "s3cret"), store.accountCredential.first())
    }

    @Test
    fun `the stored account password is ciphertext, not plaintext`() = runTest {
        store.save(AccountCredential("alice", "s3cret"))

        val stored = dataStore.data.first()[stringPreferencesKey("account_password")]
        assertEquals("enc:s3cret", stored)
        assertNotEquals("s3cret", stored)
    }

    @Test
    fun `the account name stays in plaintext`() = runTest {
        store.save(AccountCredential("alice", "s3cret"))

        assertEquals("alice", dataStore.data.first()[stringPreferencesKey("account_name")])
    }

    @Test
    fun `a lost keystore key reads the account back as absent`() = runTest {
        store.save(AccountCredential("alice", "s3cret"))
        cipher.failureMode = true

        assertNull(store.currentAccount())
        assertNull(store.accountCredential.first())
    }

    @Test
    fun `an account encrypt failure aborts the save instead of wiping the stored account`() = runTest {
        store.save(AccountCredential("alice", "s3cret"))
        cipher.failureMode = true

        store.save(AccountCredential("bob", "other-secret"))

        assertEquals("alice", dataStore.data.first()[stringPreferencesKey("account_name")])
        assertEquals("enc:s3cret", dataStore.data.first()[stringPreferencesKey("account_password")])
    }

    @Test
    fun `clear wipes the stored account too`() = runTest {
        store.save(AccountCredential("alice", "s3cret"))

        store.clear()

        assertNull(store.currentAccount())
        assertNull(store.accountCredential.first())
    }
}
