package fr.gshz.hideandseek.domain.model

/** Live departure source for a GTFS feed, sent to the server in its wire form. */
data class GtfsRealtimeSelection(
    val provider: String,
    val profile: String? = null,
    val url: String? = null,
    val alertsUrl: String? = null,
) {
    val hasRealtime: Boolean get() = provider != PROVIDER_NONE

    companion object {
        const val PROVIDER_NONE = "none"
        const val PROVIDER_GTFS_RT_URL = "gtfs_rt_url"
        const val PROVIDER_SIRI_LITE = "siri_lite"

        val None = GtfsRealtimeSelection(PROVIDER_NONE)
    }
}
