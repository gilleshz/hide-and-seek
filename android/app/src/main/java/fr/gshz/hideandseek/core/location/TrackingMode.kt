package fr.gshz.hideandseek.core.location

import fr.gshz.hideandseek.domain.model.Side

/** Which foreground service type the SSE host runs with: GPS tracking, or SSE only. */
internal enum class TrackingMode { Location, DataSync }

internal fun trackingModeFor(side: Side?): TrackingMode = when (side) {
    Side.Hider, Side.Seeker -> TrackingMode.Location
    Side.Spectator, null -> TrackingMode.DataSync
}
