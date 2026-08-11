package fr.gshz.hideandseek.data

import android.Manifest
import android.annotation.SuppressLint
import android.content.Context
import android.content.pm.PackageManager
import android.location.Location
import android.location.LocationManager
import android.os.CancellationSignal
import android.util.Log
import androidx.core.content.ContextCompat
import dagger.hilt.android.qualifiers.ApplicationContext
import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.location.bestAltitudeMeters
import fr.gshz.hideandseek.core.model.NotConnectedException
import fr.gshz.hideandseek.data.remote.HideAndSeekApi
import fr.gshz.hideandseek.data.remote.dto.LocationPingRequest
import fr.gshz.hideandseek.domain.model.DeviceLocation
import fr.gshz.hideandseek.domain.model.LocationUpdate
import fr.gshz.hideandseek.domain.repository.GameEventRepository
import fr.gshz.hideandseek.domain.repository.LocationRepository
import javax.inject.Inject
import kotlin.coroutines.resume
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withTimeoutOrNull

class LocationRepositoryImpl @Inject constructor(
    private val api: HideAndSeekApi,
    private val connectionStore: ConnectionStore,
    private val gameEventRepository: GameEventRepository,
    @ApplicationContext private val context: Context,
) : LocationRepository {

    private val _lastKnownLocation = MutableStateFlow<DeviceLocation?>(null)
    override val lastKnownLocation: StateFlow<DeviceLocation?> = _lastKnownLocation

    private val locationManager: LocationManager by lazy {
        context.getSystemService(LocationManager::class.java)
    }

    override fun updateLastKnownLocation(location: DeviceLocation) {
        _lastKnownLocation.value = location
    }

    override suspend fun postLocationPing(
        roundUuid: String,
        playerUuid: String,
        lat: Double,
        lng: Double,
        altitude: Double?,
    ): Boolean =
        api.postLocation(
            url = urlFor("/api/rounds/$roundUuid/location"),
            body = LocationPingRequest(playerUuid = playerUuid, lat = lat, lng = lng, altitude = altitude),
        ).endgame == true

    @SuppressLint("MissingPermission")
    override suspend fun getCurrentLocation(): DeviceLocation? {
        if (ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) !=
            PackageManager.PERMISSION_GRANTED
        ) {
            return null
        }
        // A bound one-shot beats the previous unbounded Fused call: a cold GPS must not hang a question forever.
        return withTimeoutOrNull(ONE_SHOT_TIMEOUT_MS) { requestCurrentLocation() }
    }

    private suspend fun requestCurrentLocation(): DeviceLocation? {
        val cancellationSignal = CancellationSignal()
        return suspendCancellableCoroutine { continuation ->
            try {
                locationManager.getCurrentLocation(
                    LocationManager.GPS_PROVIDER,
                    cancellationSignal,
                    ContextCompat.getMainExecutor(context),
                ) { location ->
                    if (continuation.isActive) continuation.resume(location?.toDeviceLocation())
                }
            } catch (e: SecurityException) {
                Log.w(TAG, "Fresh GPS fix unavailable", e)
                continuation.resume(null)
            } catch (e: IllegalArgumentException) {
                Log.w(TAG, "Fresh GPS fix unavailable", e)
                continuation.resume(null)
            }
            continuation.invokeOnCancellation { cancellationSignal.cancel() }
        }
    }

    private fun Location.toDeviceLocation(): DeviceLocation =
        DeviceLocation(latitude, longitude, bestAltitudeMeters())

    override fun observeLocationUpdates(): Flow<LocationUpdate> = gameEventRepository.locationUpdates

    private suspend fun urlFor(path: String): String =
        (connectionStore.current() ?: throw NotConnectedException()).apiUrl.trimEnd('/') + path

    companion object {
        private const val TAG = "LocationRepository"
        private const val ONE_SHOT_TIMEOUT_MS = 30_000L
    }
}
