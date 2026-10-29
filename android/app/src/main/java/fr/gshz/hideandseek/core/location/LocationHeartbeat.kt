package fr.gshz.hideandseek.core.location

/**
 * A stationary player never passes the 3 m movement gate, so the last fix must be re-posted on a
 * timer; otherwise a map opened after that lone post never learns where they are. A fix that has
 * gone stale is not re-posted: a GPS-dead phone must not turn its pre-outage position into a fresh
 * ping, because the server's freshest-hider rule and question auto-reveal rely on real fixes.
 */
internal object LocationHeartbeat {
    const val INTERVAL_MS = 60_000L

    fun isDue(nowMs: Long, lastPostedMs: Long, intervalMs: Long = INTERVAL_MS): Boolean =
        nowMs - lastPostedMs >= intervalMs

    fun canRepost(
        nowMs: Long,
        lastPostedMs: Long,
        lastFixReceivedAtMs: Long,
        maxFixAgeMs: Long,
        intervalMs: Long = INTERVAL_MS,
    ): Boolean = lastFixReceivedAtMs != 0L &&
        nowMs - lastFixReceivedAtMs <= maxFixAgeMs &&
        isDue(nowMs, lastPostedMs, intervalMs)
}
