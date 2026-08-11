package fr.gshz.hideandseek.core.location

import android.os.SystemClock

/** Exponential backoff for the ping loop, mirroring the SSE reconnect pattern. */
internal class PingBackoff(private val clock: () -> Long = SystemClock::elapsedRealtime) {
    private var failures = 0

    @Volatile
    private var untilElapsedMs = 0L

    val active: Boolean
        get() = clock() < untilElapsedMs

    @Synchronized
    fun recordFailure() {
        val backoff = BASE_MS shl failures.coerceAtMost(MAX_SHIFT)
        failures++
        untilElapsedMs = clock() + backoff.coerceAtMost(MAX_MS)
    }

    @Synchronized
    fun reset() {
        failures = 0
        untilElapsedMs = 0L
    }

    private companion object {
        const val BASE_MS = 1_000L
        const val MAX_MS = 30_000L
        const val MAX_SHIFT = 5
    }
}
