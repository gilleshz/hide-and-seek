package fr.gshz.hideandseek.core.data

import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.PreferenceDataStoreFactory
import androidx.datastore.preferences.core.Preferences
import fr.gshz.hideandseek.core.model.PlayerSession
import java.io.File
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.runTest
import org.junit.jupiter.api.AfterEach
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.Test

@OptIn(ExperimentalCoroutinesApi::class)
class SessionStoreTest {

    private lateinit var tempFile: File
    private lateinit var dataStore: DataStore<Preferences>
    private lateinit var store: SessionStore

    @BeforeEach
    fun setUp() {
        tempFile = File.createTempFile("session-store-test", ".preferences_pb")
        dataStore = PreferenceDataStoreFactory.create(scope = CoroutineScope(UnconfinedTestDispatcher())) {
            tempFile
        }
        store = SessionStore(dataStore)
    }

    @AfterEach
    fun tearDown() {
        tempFile.delete()
    }

    private fun session(side: String? = null) = PlayerSession(
        gameUuid = "game-1",
        roundUuid = "round-1",
        playerUuid = "player-1",
        displayName = "Alice",
        mercureToken = "token",
        side = side,
    )

    @Test
    fun `save persists the session and the flow returns it`() = runTest {
        store.save(session(side = "hider"))

        assertEquals("game-1", store.current()?.gameUuid)
        assertEquals("hider", store.current()?.side)
    }

    @Test
    fun `clear wipes the whole session`() = runTest {
        store.save(session())

        store.clear()

        assertNull(store.current())
    }

    @Test
    fun `updateMercureToken refreshes the session token and topics`() = runTest {
        store.save(session())

        store.updateMercureToken("fresh-token", listOf("roster", "timer"))

        val current = store.current()
        assertEquals("fresh-token", current?.mercureToken)
        assertEquals(listOf("roster", "timer"), current?.topics)
    }

    @Test
    fun `updateSide applies the side with the new token and topics`() = runTest {
        store.save(session())

        store.updateSide("hider", "fresh-token", listOf("roster"))

        val current = store.current()
        assertEquals("hider", current?.side)
        assertEquals("fresh-token", current?.mercureToken)
    }
}
