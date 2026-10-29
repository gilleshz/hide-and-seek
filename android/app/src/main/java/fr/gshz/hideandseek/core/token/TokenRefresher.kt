package fr.gshz.hideandseek.core.token

import android.util.Log
import fr.gshz.hideandseek.core.model.PlayerSession
import fr.gshz.hideandseek.core.navigation.NavigationRequestStore
import fr.gshz.hideandseek.core.util.isMercureV1AccessToken
import fr.gshz.hideandseek.core.util.isSessionExpired
import fr.gshz.hideandseek.core.util.serverErrorKey
import fr.gshz.hideandseek.core.util.jwtExpEpochSeconds
import fr.gshz.hideandseek.di.DefaultDispatcher
import fr.gshz.hideandseek.domain.repository.RoundRepository
import fr.gshz.hideandseek.domain.repository.SessionRepository
import java.io.IOException
import java.util.concurrent.TimeUnit
import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.CoroutineDispatcher
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import retrofit2.HttpException

/** How long before its `exp` a token should already be replaced. */
internal const val REFRESH_WINDOW_MS = 2 * 60 * 60 * 1000L

internal const val TOKEN_RETRY_DELAY_MS = 10 * 60 * 1000L

internal const val MAX_REFRESH_ATTEMPTS = 3

/** A still-rejecting stream reconnects faster than this; one re-mint per window is enough. */
internal const val REFRESH_NOW_COOLDOWN_MS = 30_000L

internal fun nextRefreshDelayMillis(exp: Long, nowMillis: Long): Long =
    (TimeUnit.SECONDS.toMillis(exp) - nowMillis - REFRESH_WINDOW_MS).coerceAtLeast(0)

/**
 * Proactively refreshes the Mercure subscriber token before it expires, so the SSE stream never
 * dies on a token that outlived its 12h TTL. The double re-read guard keeps a refresh that raced
 * a rekey, round change or side scrub from persisting round-N topics over a newer session.
 */
@Singleton
class TokenRefresher @Inject constructor(
    private val sessionRepository: SessionRepository,
    private val roundRepository: RoundRepository,
    private val navigationRequestStore: NavigationRequestStore,
    @DefaultDispatcher private val dispatcher: CoroutineDispatcher = Dispatchers.IO,
) {

    private val scope = CoroutineScope(SupervisorJob() + dispatcher)
    private var refreshJob: Job? = null

    @Volatile
    private var started = false

    /**
     * A legacy token is hub-rejected after the Mercure 1.0 cutover, so it must be re-minted at once
     * rather than hours later. One immediate attempt per process is enough: a server that still
     * hands out legacy tokens must not turn the session flow into a refresh loop.
     */
    @Volatile
    private var immediateLegacyRefreshAttempted = false

    @Volatile
    private var lastForcedRefreshMillis = 0L

    /**
     * A 401/403 from the hub means the stream's token is no longer accepted while the scheduled
     * refresh may be hours away. The cooldown stops a stream that keeps rejecting from turning every
     * reconnect into a refresh call.
     */
    suspend fun refreshNow() {
        val session = sessionRepository.currentSession()
        // start() already spent the one immediate attempt a legacy token gets.
        val legacyAlreadyAttempted =
            session != null && !session.mercureToken.isMercureV1AccessToken() && immediateLegacyRefreshAttempted
        val now = System.currentTimeMillis()
        synchronized(this) {
            if (legacyAlreadyAttempted || now - lastForcedRefreshMillis < REFRESH_NOW_COOLDOWN_MS) return
            lastForcedRefreshMillis = now
        }
        session?.let { attemptOnce(it) }
    }

    fun start() {
        if (started) return
        started = true
        scope.launch {
            sessionRepository.observeSession().collect { session ->
                refreshJob?.cancel()
                val token = session?.mercureToken ?: return@collect
                val exp = token.jwtExpEpochSeconds() ?: return@collect
                val immediate = !token.isMercureV1AccessToken() && !immediateLegacyRefreshAttempted
                if (immediate) immediateLegacyRefreshAttempted = true
                refreshJob = scope.launch {
                    if (!immediate) delay(nextRefreshDelayMillis(exp, System.currentTimeMillis()))
                    attemptRefresh(original = session)
                }
            }
        }
    }

    private suspend fun attemptRefresh(original: PlayerSession) {
        var attempt = 0
        while (attempt < MAX_REFRESH_ATTEMPTS && !attemptOnce(original)) {
            attempt++
            delay(TOKEN_RETRY_DELAY_MS)
        }
    }

    /** Returns true when the refresh finished, either persisted or abandoned. */
    private suspend fun attemptOnce(original: PlayerSession): Boolean {
        val current = currentUnchanged(original)
        if (current == null) return true
        return try {
            val refreshed = roundRepository.refreshSubscriberToken(current.roundUuid)
            val stillCurrent = currentUnchanged(original) != null
            if (stillCurrent) {
                sessionRepository.updateMercureToken(refreshed.mercureToken, refreshed.topics)
            }
            stillCurrent
        } catch (e: IOException) {
            Log.w(TAG, "Token refresh failed, retrying", e)
            false
        } catch (e: HttpException) {
            if (e.isSessionExpired()) {
                navigationRequestStore.emitSessionExpired(e.serverErrorKey())
                true
            } else {
                Log.w(TAG, "Token refresh rejected, retrying", e)
                false
            }
        }
    }

    private suspend fun currentUnchanged(original: PlayerSession): PlayerSession? {
        val current = sessionRepository.currentSession() ?: return null
        val unchanged = current.roundUuid == original.roundUuid &&
            current.mercureToken == original.mercureToken &&
            current.topics == original.topics
        return current.takeIf { unchanged }
    }

    private companion object {
        const val TAG = "TokenRefresher"
    }
}
