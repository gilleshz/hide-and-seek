package fr.gshz.hideandseek.domain.repository

import fr.gshz.hideandseek.domain.model.DeviceLocation
import fr.gshz.hideandseek.domain.model.LocationUpdate
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.StateFlow

interface LocationRepository {
    val lastKnownLocation: StateFlow<DeviceLocation?>
    /** Returns true when this ingest started the round's endgame (the ping-ack fail-safe when SSE is down). */
    suspend fun postLocationPing(
        roundUuid: String,
        playerUuid: String,
        lat: Double,
        lng: Double,
        altitude: Double? = null,
    ): Boolean
    fun observeLocationUpdates(): Flow<LocationUpdate>
    suspend fun getCurrentLocation(): DeviceLocation?
    fun updateLastKnownLocation(location: DeviceLocation)
}
