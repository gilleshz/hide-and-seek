package fr.gshz.hideandseek.core.location

/**
 * Raises the GPS listening interval one tier once the player has been still for a while, and drops
 * it back to the phase cadence as soon as a fix shows movement. The GPS chip cost scales with the
 * fix rate, so a still player spends far less time powering the receiver.
 */
class LocationCadenceSelector {

    private var consecutiveStationaryFixes = 0

    /** The listening interval to use when arming, given the phase cadence. */
    fun onArm(baseIntervalMs: Long, canEscalate: Boolean): Long {
        val tier = STATIONARY_TIER_MS[baseIntervalMs] ?: return baseIntervalMs
        return if (canEscalate && consecutiveStationaryFixes >= STATIONARY_FIXES_TO_ESCALATE) tier else baseIntervalMs
    }

    /** Feeds one fix; returns the interval the service should use next. */
    fun onFix(displacementMeters: Double, baseIntervalMs: Long, canEscalate: Boolean): Long {
        if (displacementMeters < LocationCadence.MOVE_THRESHOLD_M) {
            if (consecutiveStationaryFixes < STATIONARY_FIXES_TO_ESCALATE) consecutiveStationaryFixes++
        } else {
            consecutiveStationaryFixes = 0
        }
        return onArm(baseIntervalMs, canEscalate)
    }

    fun reset() {
        consecutiveStationaryFixes = 0
    }

    companion object {
        /** Consecutive sub-threshold fixes before the listening interval escalates. */
        const val STATIONARY_FIXES_TO_ESCALATE = 3

        /** Still-slower listening interval per phase cadence. */
        val STATIONARY_TIER_MS = mapOf(
            10_000L to 30_000L,
            30_000L to 90_000L,
            60_000L to 180_000L,
        )
    }
}
