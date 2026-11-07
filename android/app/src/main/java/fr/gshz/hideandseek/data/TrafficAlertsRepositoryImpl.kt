package fr.gshz.hideandseek.data

import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.model.NotConnectedException
import fr.gshz.hideandseek.data.mapper.toDomain
import fr.gshz.hideandseek.data.remote.HideAndSeekApi
import fr.gshz.hideandseek.domain.model.TrafficAlerts
import fr.gshz.hideandseek.domain.repository.TrafficAlertsRepository
import javax.inject.Inject

class TrafficAlertsRepositoryImpl @Inject constructor(
    private val api: HideAndSeekApi,
    private val connectionStore: ConnectionStore,
) : TrafficAlertsRepository {

    override suspend fun getTrafficAlerts(gameUuid: String): TrafficAlerts =
        api.getTrafficAlerts(urlFor("/api/games/$gameUuid/traffic-alerts")).toDomain()

    private suspend fun urlFor(path: String): String =
        (connectionStore.current() ?: throw NotConnectedException()).apiUrl.trimEnd('/') + path
}
