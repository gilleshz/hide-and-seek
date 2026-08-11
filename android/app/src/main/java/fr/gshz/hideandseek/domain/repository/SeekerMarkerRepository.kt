package fr.gshz.hideandseek.domain.repository

import fr.gshz.hideandseek.domain.model.SeekerMarker

interface SeekerMarkerRepository {
    suspend fun listMarkers(roundUuid: String): List<SeekerMarker>
    suspend fun addMarker(roundUuid: String, playerUuid: String, lat: Double, lng: Double): SeekerMarker
    suspend fun deleteMarker(roundUuid: String, markerUuid: String)
}
