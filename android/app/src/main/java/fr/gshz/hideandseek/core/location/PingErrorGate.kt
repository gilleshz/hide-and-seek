package fr.gshz.hideandseek.core.location

import java.util.concurrent.atomic.AtomicInteger

/**
 * Counts consecutive 4xx ping rejections per arming generation, so a stale in-flight
 * rejection from a torn-down GPS callback can never pause a freshly armed one.
 */
internal class PingErrorGate {

    @Volatile
    private var generation = 0L
    private val consecutiveErrors = AtomicInteger(0)

    @Synchronized
    fun arm(): Long {
        consecutiveErrors.set(0)
        return ++generation
    }

    fun reset() {
        consecutiveErrors.set(0)
    }

    fun isCurrent(pingGeneration: Long): Boolean = pingGeneration == generation

    /** Returns the consecutive-error count, or null when the ping predates the current arm. */
    @Synchronized
    fun countError(pingGeneration: Long): Int? =
        if (pingGeneration == generation) consecutiveErrors.incrementAndGet() else null
}
