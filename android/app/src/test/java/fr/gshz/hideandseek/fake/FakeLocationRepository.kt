package fr.gshz.hideandseek.fake

import fr.gshz.hideandseek.domain.model.DeviceLocation
import fr.gshz.hideandseek.domain.model.LocationUpdate
import fr.gshz.hideandseek.domain.repository.LocationRepository
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow

class FakeLocationRepository : LocationRepository {
    private val _lastKnownLocation = MutableStateFlow<DeviceLocation?>(null)
    override val lastKnownLocation: StateFlow<DeviceLocation?> = _lastKnownLocation

    private val updates = MutableSharedFlow<LocationUpdate>(extraBufferCapacity = 10)

    val postedPings = mutableListOf<LocationUpdate>()
    var currentLocationResult: DeviceLocation? = DeviceLocation(latitude = 1.0, longitude = 2.0)

    override fun updateLastKnownLocation(location: DeviceLocation) {
        _lastKnownLocation.value = location
    }

    val postedAltitudes = mutableListOf<Double?>()
    var endgameResult: Boolean = false

    override suspend fun postLocationPing(
        roundUuid: String,
        playerUuid: String,
        lat: Double,
        lng: Double,
        altitude: Double?,
    ): Boolean {
        postedPings += LocationUpdate(playerUuid, lat, lng, "")
        postedAltitudes += altitude
        return endgameResult
    }

    override fun observeLocationUpdates(): Flow<LocationUpdate> = updates

    override suspend fun getCurrentLocation(): DeviceLocation? = currentLocationResult

    suspend fun emit(update: LocationUpdate) = updates.emit(update)
}
