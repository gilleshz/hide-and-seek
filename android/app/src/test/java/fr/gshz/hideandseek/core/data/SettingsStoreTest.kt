package fr.gshz.hideandseek.core.data

import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.PreferenceDataStoreFactory
import androidx.datastore.preferences.core.Preferences
import java.io.File
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.runTest
import org.junit.jupiter.api.AfterEach
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.Test

@OptIn(ExperimentalCoroutinesApi::class)
class SettingsStoreTest {

    private lateinit var tempFile: File
    private lateinit var store: SettingsStore

    @BeforeEach
    fun setUp() {
        tempFile = File.createTempFile("settings-store-test", ".preferences_pb")
        val dataStore: DataStore<Preferences> = PreferenceDataStoreFactory.create(
            scope = CoroutineScope(UnconfinedTestDispatcher()),
        ) { tempFile }
        store = SettingsStore(dataStore)
    }

    @AfterEach
    fun tearDown() {
        tempFile.delete()
    }

    @Test
    fun `the location disclosure starts unaccepted and stays accepted`() = runTest {
        assertFalse(store.locationDisclosureAccepted.first())

        store.saveLocationDisclosureAccepted()

        assertTrue(store.locationDisclosureAccepted.first())
    }
}
