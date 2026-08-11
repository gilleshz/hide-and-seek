package fr.gshz.hideandseek.core.token

import fr.gshz.hideandseek.core.model.PlayerSession
import fr.gshz.hideandseek.core.navigation.NavigationRequestStore
import fr.gshz.hideandseek.domain.model.TokenRefresh
import fr.gshz.hideandseek.fake.FakeRoundRepository
import fr.gshz.hideandseek.fake.FakeSessionRepository
import fr.gshz.hideandseek.fake.httpException
import java.io.IOException
import java.util.Base64
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.TestDispatcher
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.runTest
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNotNull
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.Test

@OptIn(ExperimentalCoroutinesApi::class)
class TokenRefresherTest {

    private val sessionRepository = FakeSessionRepository()
    private val roundRepository = FakeRoundRepository()
    private val navigationRequestStore = NavigationRequestStore()
    private lateinit var dispatcher: TestDispatcher
    private lateinit var refresher: TokenRefresher

    @BeforeEach
    fun setUp() {
        dispatcher = StandardTestDispatcher()
        refresher = TokenRefresher(sessionRepository, roundRepository, navigationRequestStore, dispatcher)
    }

    private fun session(token: String = expiredToken()) = PlayerSession(
        gameUuid = "game-1",
        roundUuid = "round-1",
        playerUuid = "player-1",
        displayName = "Alice",
        mercureToken = token,
        side = null,
        topics = listOf("roster"),
    )

    private fun expiredToken(): String {
        val header = Base64.getUrlEncoder().withoutPadding().encodeToString("""{"alg":"none"}""".toByteArray())
        val payload = Base64.getUrlEncoder().withoutPadding()
            .encodeToString("""{"exp":1000000000}""".toByteArray())
        return "$header.$payload.sig"
    }

    @Test
    fun `nextRefreshDelayMillis is zero for an expired token and at the window edge`() {
        val expMillis = 1_000_000L * 1000

        assertEquals(0L, nextRefreshDelayMillis(1_000_000, expMillis + REFRESH_WINDOW_MS))
        assertEquals(0L, nextRefreshDelayMillis(1_000_000, expMillis + REFRESH_WINDOW_MS + 1))
    }

    @Test
    fun `nextRefreshDelayMillis schedules the refresh two hours before expiry`() {
        val nowMillis = 1_000_000L * 1000

        assertEquals(REFRESH_WINDOW_MS, nextRefreshDelayMillis(1_000_000 + 4 * 60 * 60, nowMillis))
        assertEquals(REFRESH_WINDOW_MS + 60_000L, nextRefreshDelayMillis(1_000_000 + 4 * 60 * 60 + 60, nowMillis))
    }

    @Test
    fun `a refresh persists the fresh token and topics`() = runTest(dispatcher) {
        sessionRepository.seed(session())
        roundRepository.refreshSubscriberTokenResult =
            Result.success(TokenRefresh("fresh-token", listOf("roster", "timer")))

        refresher.start()
        advanceUntilIdle()

        assertEquals(listOf("round-1"), roundRepository.refreshSubscriberTokenCalls)
        assertEquals("fresh-token", sessionRepository.refreshedMercureToken)
        assertEquals(listOf("roster", "timer"), sessionRepository.refreshedTopics)
    }

    @Test
    fun `a second start call collects and refreshes only once`() = runTest(dispatcher) {
        sessionRepository.seed(session())
        roundRepository.refreshSubscriberTokenResult =
            Result.success(TokenRefresh("fresh-token", listOf("roster", "timer")))

        refresher.start()
        refresher.start()
        advanceUntilIdle()

        assertEquals(listOf("round-1"), roundRepository.refreshSubscriberTokenCalls)
        assertEquals("fresh-token", sessionRepository.refreshedMercureToken)
    }

    @Test
    fun `a session without an expiring token never refreshes`() = runTest(dispatcher) {
        sessionRepository.seed(session(token = "plain-token"))

        refresher.start()
        advanceUntilIdle()

        assertTrue(roundRepository.refreshSubscriberTokenCalls.isEmpty())
    }

    @Test
    fun `a refresh aborts when the round changed between the two re-reads`() = runTest(dispatcher) {
        sessionRepository.seed(session())
        roundRepository.refreshSubscriberTokenGate = CompletableDeferred()

        refresher.start()
        advanceUntilIdle()
        // The refresh is now parked on the gate, past the first re-read.
        assertEquals(listOf("round-1"), roundRepository.refreshSubscriberTokenCalls)

        sessionRepository.mutateSession { it.copy(roundUuid = "round-2") }
        roundRepository.refreshSubscriberTokenGate?.complete(Unit)
        advanceUntilIdle()

        assertNull(sessionRepository.refreshedMercureToken)
    }

    @Test
    fun `a refresh aborts when the token changed between the two re-reads`() = runTest(dispatcher) {
        sessionRepository.seed(session())
        roundRepository.refreshSubscriberTokenGate = CompletableDeferred()

        refresher.start()
        advanceUntilIdle()
        assertEquals(listOf("round-1"), roundRepository.refreshSubscriberTokenCalls)

        sessionRepository.mutateSession { it.copy(mercureToken = "other-token") }
        roundRepository.refreshSubscriberTokenGate?.complete(Unit)
        advanceUntilIdle()

        assertNull(sessionRepository.refreshedMercureToken)
    }

    @Test
    fun `a refresh aborts when the topics were scrubbed between the two re-reads`() = runTest(dispatcher) {
        sessionRepository.seed(session().copy(side = "hider", topics = listOf("roster", "game-1-locations")))
        roundRepository.refreshSubscriberTokenGate = CompletableDeferred()

        refresher.start()
        advanceUntilIdle()
        // The refresh is now parked on the gate, past the first re-read.
        assertEquals(listOf("round-1"), roundRepository.refreshSubscriberTokenCalls)

        sessionRepository.mutateSession { it.copy(side = null, topics = it.topicsWithoutLocations()) }
        roundRepository.refreshSubscriberTokenGate?.complete(Unit)
        advanceUntilIdle()

        assertNull(sessionRepository.refreshedMercureToken)
    }

    @Test
    fun `a cleared session cancels the pending refresh`() = runTest(dispatcher) {
        sessionRepository.seed(session())
        roundRepository.refreshSubscriberTokenGate = CompletableDeferred()

        refresher.start()
        advanceUntilIdle()
        assertEquals(listOf("round-1"), roundRepository.refreshSubscriberTokenCalls)

        sessionRepository.clear()
        roundRepository.refreshSubscriberTokenGate?.complete(Unit)
        advanceUntilIdle()

        assertNull(sessionRepository.refreshedMercureToken)
    }

    @Test
    fun `a network failure never persists and gives up after a few attempts`() = runTest(dispatcher) {
        sessionRepository.seed(session())
        roundRepository.refreshSubscriberTokenResult = Result.failure(IOException())

        refresher.start()
        advanceUntilIdle()

        assertEquals(MAX_REFRESH_ATTEMPTS, roundRepository.refreshSubscriberTokenCalls.size)
        assertNull(sessionRepository.refreshedMercureToken)
    }

    @Test
    fun `an identity rejection emits the session expired request`() = runTest(dispatcher) {
        sessionRepository.seed(session())
        roundRepository.refreshSubscriberTokenResult =
            Result.failure(httpException(401, """{"errorKey":"identity.token_invalid"}"""))

        refresher.start()
        advanceUntilIdle()

        assertNotNull(navigationRequestStore.sessionExpiredRequest.value)
        assertNull(sessionRepository.refreshedMercureToken)
    }

    @Test
    fun `a non-identity rejection retries instead of emitting session expired`() = runTest(dispatcher) {
        sessionRepository.seed(session())
        roundRepository.refreshSubscriberTokenResult =
            Result.failure(httpException(500, """{"errorKey":"something.else"}"""))

        refresher.start()
        advanceUntilIdle()

        assertEquals(MAX_REFRESH_ATTEMPTS, roundRepository.refreshSubscriberTokenCalls.size)
        assertNull(navigationRequestStore.sessionExpiredRequest.value)
    }
}
