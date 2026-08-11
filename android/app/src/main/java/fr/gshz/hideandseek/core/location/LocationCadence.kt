package fr.gshz.hideandseek.core.location

import fr.gshz.hideandseek.domain.model.RoundStatus
import fr.gshz.hideandseek.domain.model.Side

object LocationCadence {
    /** Fallback when the game phase is unknown (e.g. before the first SSE timer event). */
    const val DEFAULT_INTERVAL_MS = 10_000L

    /** Skip location POSTs below this displacement: saves modem wake-ups when stationary. */
    const val MOVE_THRESHOLD_M = 3.0

    /** Round is over: defensive slow floor; the service normally disarms GPS entirely on Ended. */
    const val ENDED_INTERVAL_MS = 60_000L

    object Hider {
        /** Moving to a hiding zone during the hiding period. */
        const val HIDING_PHASE_MS = 10_000L

        /** Stationary and hidden during the seeking period: maximum battery saving. */
        const val SEEKING_PHASE_MS = 60_000L

        /** A seeker is within endgame proximity: ramp back up for catch detection. */
        const val ENDGAME_MS = 10_000L
    }

    object Seeker {
        /** Waiting for the hider to reach their zone: low activity. */
        const val HIDING_PHASE_MS = 30_000L

        /** Active hunting on transit during the seeking period. */
        const val SEEKING_PHASE_MS = 10_000L
    }

    // The endgame signal is hider-only (a seeker signal would leak the zone), so only the hider ramps.
    fun selectInterval(side: Side?, phase: RoundStatus?, endgameProximity: Boolean = false): Long = when {
        phase == RoundStatus.Ended -> ENDED_INTERVAL_MS
        endgameProximity && side == Side.Hider -> Hider.ENDGAME_MS
        side == Side.Hider -> hiderInterval(phase)
        side == Side.Seeker -> seekerInterval(phase)
        else -> DEFAULT_INTERVAL_MS
    }

    private fun hiderInterval(phase: RoundStatus?): Long = when (phase) {
        RoundStatus.Hiding -> Hider.HIDING_PHASE_MS
        RoundStatus.Seeking -> Hider.SEEKING_PHASE_MS
        else -> DEFAULT_INTERVAL_MS
    }

    private fun seekerInterval(phase: RoundStatus?): Long = when (phase) {
        RoundStatus.Hiding -> Seeker.HIDING_PHASE_MS
        RoundStatus.Seeking -> Seeker.SEEKING_PHASE_MS
        else -> DEFAULT_INTERVAL_MS
    }
}
