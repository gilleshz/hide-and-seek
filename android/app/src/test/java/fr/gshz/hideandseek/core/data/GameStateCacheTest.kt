package fr.gshz.hideandseek.core.data

import fr.gshz.hideandseek.domain.model.Player
import fr.gshz.hideandseek.domain.repository.ClientConfigRepository
import fr.gshz.hideandseek.fake.FakeGameRepository
import io.mockk.mockk
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.launch
import kotlinx.coroutines.test.runCurrent
import kotlinx.coroutines.test.runTest
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Test

@OptIn(ExperimentalCoroutinesApi::class)
class GameStateCacheTest {

    private val gameRepository = FakeGameRepository()
    private val clientConfigRepository = mockk<ClientConfigRepository>()
    private val cache = GameStateCache(gameRepository, clientConfigRepository)

    @Test
    fun `roster refreshes that overlap cost a single request`() = runTest {
        gameRepository.listPlayersResult = Result.success(listOf(Player("p1", "Alice")))
        val gate = CompletableDeferred<Unit>()
        gameRepository.listPlayersGate = gate

        val refreshes = List(3) { launch { cache.refreshRoster("game-1") } }
        runCurrent()
        gate.complete(Unit)
        refreshes.forEach { it.join() }

        assertEquals(1, gameRepository.listPlayersCalls)
        assertEquals(listOf(Player("p1", "Alice")), cache.getRoster("game-1"))
    }

    @Test
    fun `a refresh after the previous one settled reads the roster again`() = runTest {
        gameRepository.listPlayersResult = Result.success(listOf(Player("p1", "Alice")))

        cache.refreshRoster("game-1")
        gameRepository.listPlayersResult = Result.success(listOf(Player("p1", "Alice"), Player("p2", "Bob")))
        val second = cache.refreshRoster("game-1")

        assertEquals(2, gameRepository.listPlayersCalls)
        assertEquals(2, second.size)
    }

    @Test
    fun `game summary refreshes that overlap cost a single request`() = runTest {
        val gate = CompletableDeferred<Unit>()
        gameRepository.getGameGate = gate

        val refreshes = List(3) { launch { cache.refreshGameSummary("game-1") } }
        runCurrent()
        gate.complete(Unit)
        refreshes.forEach { it.join() }

        assertEquals(1, gameRepository.getGameCalls)
    }
}
