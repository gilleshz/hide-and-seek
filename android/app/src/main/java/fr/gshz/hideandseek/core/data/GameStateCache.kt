package fr.gshz.hideandseek.core.data

import fr.gshz.hideandseek.domain.model.ClientConfig
import fr.gshz.hideandseek.domain.model.GameSummary
import fr.gshz.hideandseek.domain.model.Player
import fr.gshz.hideandseek.domain.repository.ClientConfigRepository
import fr.gshz.hideandseek.domain.repository.GameRepository
import java.util.concurrent.atomic.AtomicInteger
import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock

@Singleton
class GameStateCache @Inject constructor(
    private val gameRepository: GameRepository,
    private val clientConfigRepository: ClientConfigRepository,
) {
    // Single immutable snapshot: readers on any thread always see a consistent key/value pair.
    @Volatile
    private var snapshot = Snapshot()
    private val lock = Any()

    private val rosterMutex = Mutex()
    private val gameMutex = Mutex()
    private val rosterFetches = AtomicInteger(0)
    private val gameFetches = AtomicInteger(0)

    suspend fun getGameSummary(gameUuid: String): GameSummary {
        snapshot.game?.takeIf { it.uuid == gameUuid }?.let { return it }
        return gameRepository.getGame(gameUuid).also { setGameSummary(it) }
    }

    suspend fun getRoster(gameUuid: String): List<Player> {
        val current = snapshot
        if (current.roster != null && current.rosterGameUuid == gameUuid) return current.roster
        return gameRepository.listPlayers(gameUuid).also { setRoster(gameUuid, it) }
    }

    /**
     * Re-reads the roster, unless another caller already did so while this one queued: several screens
     * resync off the same reconnect, and each invalidating the cache first cost each of them a request.
     */
    suspend fun refreshRoster(gameUuid: String): List<Player> {
        val seen = rosterFetches.get()
        return rosterMutex.withLock {
            reusableRoster(gameUuid, seen)
                ?: gameRepository.listPlayers(gameUuid).also {
                    setRoster(gameUuid, it)
                    rosterFetches.incrementAndGet()
                }
        }
    }

    /** Same coalescing as [refreshRoster]: a reconnect makes every screen want a fresh game summary. */
    suspend fun refreshGameSummary(gameUuid: String): GameSummary {
        val seen = gameFetches.get()
        return gameMutex.withLock {
            reusableGame(gameUuid, seen)
                ?: gameRepository.getGame(gameUuid).also {
                    setGameSummary(it)
                    gameFetches.incrementAndGet()
                }
        }
    }

    private fun reusableRoster(gameUuid: String, seenFetches: Int): List<Player>? =
        if (rosterFetches.get() == seenFetches) {
            null
        } else {
            snapshot.roster?.takeIf { snapshot.rosterGameUuid == gameUuid }
        }

    private fun reusableGame(gameUuid: String, seenFetches: Int): GameSummary? =
        if (gameFetches.get() == seenFetches) null else snapshot.game?.takeIf { it.uuid == gameUuid }

    suspend fun getClientConfig(apiUrl: String): ClientConfig {
        val current = snapshot
        if (current.clientConfig != null && current.configApiUrl == apiUrl) return current.clientConfig
        return clientConfigRepository.getClientConfig(apiUrl).also { config ->
            mutate { it.copy(clientConfig = config, configApiUrl = apiUrl) }
        }
    }

    fun invalidateGame() = mutate { it.copy(game = null) }

    fun invalidateRoster() = mutate { it.copy(roster = null, rosterGameUuid = null) }

    fun setGameSummary(game: GameSummary) = mutate { it.copy(game = game) }

    fun setRoster(gameUuid: String, roster: List<Player>) =
        mutate { it.copy(roster = roster, rosterGameUuid = gameUuid) }

    private fun mutate(transform: (Snapshot) -> Snapshot) {
        synchronized(lock) { snapshot = transform(snapshot) }
    }

    private data class Snapshot(
        val game: GameSummary? = null,
        val rosterGameUuid: String? = null,
        val roster: List<Player>? = null,
        val configApiUrl: String? = null,
        val clientConfig: ClientConfig? = null,
    )
}
